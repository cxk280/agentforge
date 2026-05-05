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
from dataclasses import dataclass
from typing import Any

import anthropic

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


async def resolve_document_path(pool, document_id: int) -> tuple[int, str]:
    """Return (patient_id, on_disk_path) for an OpenEMR document_id.

    Raises LookupError if the document doesn't exist.
    """
    if pool is None:
        raise RuntimeError(
            "Cannot resolve document_id without a DB pool. "
            "Set DB_HOST/DB_USER/DB_PASS or use file_path mode instead."
        )
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT foreign_id, url FROM documents WHERE id = %s",
                (document_id,),
            )
            row = await cur.fetchone()
    if row is None:
        raise LookupError(f"OpenEMR document_id {document_id} not found")
    patient_id, url = row
    path = _url_to_path(url)
    return int(patient_id or 0), path


def _url_to_path(url: str) -> str:
    """Convert OpenEMR's documents.url to a local file path.

    The url is typically `file://<absolute_path>`. Handle the bare-path
    case too (older rows). Returns the path unchanged if it already exists.
    """
    if url.startswith("file://"):
        return url[len("file://"):]
    if os.path.isabs(url):
        return url
    return os.path.join(_DOC_ROOT_FALLBACK, url)


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
    if (document_id is None) == (file_path is None):
        raise ValueError("Provide exactly one of document_id or file_path.")

    # Resolve path + cross-check patient_id when going through OpenEMR.
    if document_id is not None:
        resolved_pid, file_path = await resolve_document_path(pool, document_id)
        if resolved_pid and resolved_pid != patient_id:
            raise PermissionError(
                f"document_id {document_id} belongs to patient {resolved_pid}, "
                f"not {patient_id} — refusing extraction."
            )

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
