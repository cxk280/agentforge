"""attach_and_extract — the public ingestion entry point.

Two modes, same shape:

    A) by document_id  — the clinician already uploaded the file via
       OpenEMR's existing Documents tab; we resolve the on-disk path
       from the `documents` table and run vision against it.

    B) by file_path    — a local file path. Useful for demos, tests,
       and the eval harness; not used in the production hot path.

In both modes the result is the same: facts + citations are written
to cp_extracted_facts / cp_extraction_citations, and a summary dict
suitable for returning to the agent loop is produced.
"""

from __future__ import annotations

import os
import tempfile
from dataclasses import dataclass
from typing import Any

import anthropic
import httpx

from config import settings
from .persistence import (
    PersistedExtraction,
    finish_run,
    flatten,
    new_run_id,
    start_run,
    write_facts,
)
from .vision import ExtractionResult, extract_from_pdf


@dataclass
class AttachAndExtractResult:
    document_id: int | None
    patient_id: int
    doc_type: str
    run_id: str
    schema_valid: bool
    fact_count: int
    citation_count: int
    latency_ms: int
    input_tokens: int
    output_tokens: int
    payload: dict[str, Any]
    validation_error: str | None = None


# ---------------------------------------------------------------------------
# OpenEMR document path resolution.
# ---------------------------------------------------------------------------

# OpenEMR's documents.url column historically stores either an absolute path
# under sites/<site>/documents/, or a "file://" URI. We resolve both.
_DOC_ROOT_FALLBACK = "/var/www/localhost/htdocs/openemr/sites/default/documents"


async def resolve_document_owner(pool, document_id: int) -> int:
    """Look up the patient_id (foreign_id) for an OpenEMR documents row.

    The agent uses this to enforce that the requested document belongs to
    the patient the user said they're working with — orthogonal to where
    the bytes live on disk. Raises LookupError if the document doesn't
    exist.
    """
    if pool is None:
        raise RuntimeError(
            "Cannot resolve document_id without a DB pool. "
            "Set DB_HOST/DB_USER/DB_PASS or use file_path mode instead."
        )
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT foreign_id FROM documents WHERE id = %s",
                (document_id,),
            )
            row = await cur.fetchone()
    if row is None:
        raise LookupError(f"OpenEMR document_id {document_id} not found")
    return int(row[0] or 0)


async def fetch_document_bytes(document_id: int) -> bytes:
    """Pull the raw file bytes for an OpenEMR document via HTTP.

    Goes through `copilot_documents_serve.php` on the OpenEMR side using
    the shared `COPILOT_INTERNAL_TOKEN` header for auth. This is the only
    portable way for the agent to reach uploaded files — agent and
    OpenEMR live in separate containers (Mac docker dev / Railway
    dev / qa / prod), so direct filesystem reads of `sites/<site>/
    documents/<pid>/<id>_<name>` would only work if the agent ran inside
    the OpenEMR container itself, which it never does.

    Raises RuntimeError on missing config or non-200 response.
    """
    base = (settings.openemr_base_url or "").rstrip("/")
    token = settings.copilot_internal_token or ""
    if not base:
        raise RuntimeError("OPENEMR_BASE_URL is not configured.")
    if not token:
        raise RuntimeError(
            "COPILOT_INTERNAL_TOKEN is not configured — cannot fetch "
            "document bytes from OpenEMR. Set it in both the agent's env "
            "AND the OpenEMR container's env."
        )
    # `site` is required by OpenEMR's globals.php when there's no
    # session — otherwise the bootstrap aborts with "Site ID is missing
    # from session." `default` is the only site in our deploys.
    url = (
        f"{base}/interface/patient_file/documents/copilot_documents_serve.php"
        f"?site=default&docref={document_id}"
    )
    headers = {"X-Copilot-Internal-Token": token}
    async with httpx.AsyncClient(timeout=30.0) as client:
        resp = await client.get(url, headers=headers)
    if resp.status_code != 200:
        raise RuntimeError(
            f"copilot_documents_serve.php returned HTTP {resp.status_code} "
            f"for document_id={document_id}: {resp.text[:200]}"
        )
    return resp.content


# ---------------------------------------------------------------------------
# Public entry point.
# ---------------------------------------------------------------------------

async def attach_and_extract(
    *,
    patient_id: int,
    doc_type: str,
    document_id: int | None = None,
    file_path: str | None = None,
    pool=None,
    client: anthropic.AsyncAnthropic | None = None,
    model: str | None = None,
) -> AttachAndExtractResult:
    """Run vision extraction on a clinical PDF and persist the facts.

    Exactly one of `document_id` or `file_path` must be provided.

    `pool` is the agent's aiomysql pool (None when running locally without
    MySQL — the function still returns the extracted payload, just doesn't
    persist).
    """
    if document_id is None and file_path is None:
        raise ValueError("Provide at least one of document_id or file_path.")

    # `document_id` mode: agent and OpenEMR live in separate containers
    # in every deploy (local docker dev + Railway dev/qa/prod). The
    # agent therefore fetches the file bytes via `copilot_documents_serve.php`
    # over HTTP rather than trying to read the OpenEMR sitesvolume off
    # its own filesystem. We confirm the doc belongs to the asserted
    # patient via DB lookup (cheap + works without HTTP), then download.
    # An explicit `file_path` overrides this — used by the eval harness
    # and unit tests where the bytes already live next to the agent.
    tempfile_path: str | None = None
    if file_path is None:
        assert document_id is not None  # by ValueError above
        resolved_pid = await resolve_document_owner(pool, document_id)
        if resolved_pid and resolved_pid != patient_id:
            raise PermissionError(
                f"document_id {document_id} belongs to patient {resolved_pid}, "
                f"not {patient_id} — refusing extraction."
            )
        pdf_bytes = await fetch_document_bytes(document_id)
        # Stage the bytes on the agent's local FS so vision.py's existing
        # path-based read pipeline can consume them unchanged. Cleaned
        # up in the finally block below regardless of extraction outcome.
        with tempfile.NamedTemporaryFile(prefix=f"copilot_doc_{document_id}_", suffix=".pdf", delete=False) as tf:
            tf.write(pdf_bytes)
            tempfile_path = tf.name
        file_path = tempfile_path

    assert file_path is not None  # narrowed for type checkers
    chosen_model = model or settings.model
    chosen_client = client or anthropic.AsyncAnthropic(api_key=settings.anthropic_api_key)

    run_id = new_run_id()
    await start_run(
        pool,
        run_id=run_id,
        document_id=document_id or 0,
        patient_id=patient_id,
        doc_type=doc_type,
        model=chosen_model,
    )

    try:
        try:
            result: ExtractionResult = await extract_from_pdf(
                client=chosen_client,
                pdf_path=file_path,
                doc_type=doc_type,
                model=chosen_model,
            )
        except Exception as exc:
            await finish_run(
                pool,
                run_id=run_id,
                schema_valid=False,
                fact_count=0,
                latency_ms=0,
                input_tokens=0,
                output_tokens=0,
                error_message=str(exc)[:1024],
            )
            raise

        fact_records = flatten(doc_type, result.payload) if result.schema_valid else []

        persisted: PersistedExtraction = await write_facts(
            pool,
            run_id=run_id,
            document_id=document_id or 0,
            patient_id=patient_id,
            doc_type=doc_type,
            model=chosen_model,
            facts=fact_records,
        )

        await finish_run(
            pool,
            run_id=run_id,
            schema_valid=result.schema_valid,
            fact_count=len(persisted.fact_ids),
            latency_ms=result.latency_ms,
            input_tokens=result.input_tokens,
            output_tokens=result.output_tokens,
            error_message=result.validation_error,
        )

        return AttachAndExtractResult(
            document_id=document_id,
            patient_id=patient_id,
            doc_type=doc_type,
            run_id=run_id,
            schema_valid=result.schema_valid,
            fact_count=len(persisted.fact_ids),
            citation_count=persisted.citation_count,
            latency_ms=result.latency_ms,
            input_tokens=result.input_tokens,
            output_tokens=result.output_tokens,
            payload=result.raw_payload,
            validation_error=result.validation_error,
        )
    finally:
        # Clean up the staged-bytes temp file from `document_id` mode.
        # `file_path` mode never creates one, so this is a no-op there.
        if tempfile_path is not None:
            try:
                os.unlink(tempfile_path)
            except OSError:
                pass
