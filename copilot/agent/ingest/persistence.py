"""Persist extracted facts + citations to OpenEMR-side cp_* tables.

Side-tables (cp_extracted_facts, cp_extraction_citations, cp_extraction_runs)
are created idempotently via sql/copilot_w2.sql. We assume that file has been
applied — the agent does not run DDL on hot paths.

The source PDF is NOT stored here. It already lives in OpenEMR's `documents`
table (uploaded via the legacy addNewDocument() pipeline) and is reachable
through `GET /fhir/DocumentReference?patient=:pid` automatically. Each row
in cp_extracted_facts links back via document_id, preserving the round-trip
contract.
"""

from __future__ import annotations

import json
import uuid
from dataclasses import dataclass
from typing import Any, Iterable

from .schemas import Citation


@dataclass
class PersistedFact:
    """A single row written to cp_extracted_facts (post-insert)."""
    fact_id: int
    fact_type: str
    citation_ids: list[int]


@dataclass
class PersistedExtraction:
    """Summary returned to the agent after a full extraction is persisted."""
    run_id: str
    document_id: int
    patient_id: int
    fact_ids: list[int]
    citation_count: int


def new_run_id() -> str:
    return uuid.uuid4().hex


# ---------------------------------------------------------------------------
# Run lifecycle.
# ---------------------------------------------------------------------------

async def start_run(
    pool,
    *,
    run_id: str,
    document_id: int,
    patient_id: int,
    doc_type: str,
    model: str,
) -> None:
    """Insert a row in cp_extraction_runs with status='in_progress'.

    Also clears any prior facts/citations for the same document_id so a
    re-extract supersedes the old result instead of appending. The bbox
    viewer doesn't filter by run_id (it shows every fact tied to the
    document), so without this every Extract click would stack a fresh
    set of overlapping rectangles on top of the previous ones.
    Document_id=0 is the "anonymous file_path mode" sentinel — never
    clear there, since runs against arbitrary local files are
    unrelated to each other.
    """
    if pool is None:
        return
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            if document_id and document_id > 0:
                # Citations FK to facts; delete those first.
                await cur.execute(
                    """
                    DELETE FROM cp_extraction_citations
                    WHERE fact_id IN (
                        SELECT id FROM cp_extracted_facts WHERE document_id = %s
                    )
                    """,
                    (document_id,),
                )
                await cur.execute(
                    "DELETE FROM cp_extracted_facts WHERE document_id = %s",
                    (document_id,),
                )
            await cur.execute(
                """
                INSERT INTO cp_extraction_runs
                    (run_id, document_id, patient_id, doc_type, status, model)
                VALUES (%s, %s, %s, %s, 'in_progress', %s)
                """,
                (run_id, document_id, patient_id, doc_type, model),
            )
            await conn.commit()


async def finish_run(
    pool,
    *,
    run_id: str,
    schema_valid: bool,
    fact_count: int,
    latency_ms: int,
    input_tokens: int,
    output_tokens: int,
    error_message: str | None = None,
) -> None:
    if pool is None:
        return
    status = "success" if schema_valid and error_message is None else "failed"
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """
                UPDATE cp_extraction_runs
                SET status = %s,
                    schema_valid = %s,
                    fact_count = %s,
                    latency_ms = %s,
                    input_tokens = %s,
                    output_tokens = %s,
                    error_message = %s,
                    completed_at = CURRENT_TIMESTAMP
                WHERE run_id = %s
                """,
                (
                    status,
                    1 if schema_valid else 0,
                    fact_count,
                    latency_ms,
                    input_tokens,
                    output_tokens,
                    error_message,
                    run_id,
                ),
            )
            await conn.commit()


# ---------------------------------------------------------------------------
# Per-fact insert helpers.
# ---------------------------------------------------------------------------

@dataclass
class FactRecord:
    """Internal: one structured fact + its citation, before DB write."""
    fact_type: str
    fact_json: dict[str, Any]
    confidence: float | None
    source_quote: str | None
    citation: Citation | None
    field_path: str  # used as the citation's field_path (and to disambiguate facts)


async def write_facts(
    pool,
    *,
    run_id: str,
    document_id: int,
    patient_id: int,
    doc_type: str,
    model: str,
    facts: Iterable[FactRecord],
) -> PersistedExtraction:
    """Insert all facts + their citations in one transaction."""
    fact_ids: list[int] = []
    citation_count = 0
    if pool is None:
        # Local-only mode (no DB) — still return a sensible shape so callers
        # can demo end-to-end without MySQL.
        return PersistedExtraction(
            run_id=run_id,
            document_id=document_id,
            patient_id=patient_id,
            fact_ids=[],
            citation_count=0,
        )

    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute("START TRANSACTION")
            try:
                for f in facts:
                    await cur.execute(
                        """
                        INSERT INTO cp_extracted_facts
                            (document_id, patient_id, doc_type, fact_type,
                             fact_json, confidence, source_quote,
                             extraction_run_id, model)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                        """,
                        (
                            document_id,
                            patient_id,
                            doc_type,
                            f.fact_type,
                            json.dumps(f.fact_json),
                            f.confidence,
                            f.source_quote,
                            run_id,
                            model,
                        ),
                    )
                    fact_id = cur.lastrowid
                    fact_ids.append(int(fact_id))

                    if f.citation is not None:
                        await cur.execute(
                            """
                            INSERT INTO cp_extraction_citations
                                (fact_id, page, bbox_x, bbox_y, bbox_w, bbox_h,
                                 field_path, quote)
                            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                            """,
                            (
                                fact_id,
                                f.citation.page,
                                f.citation.bbox.x,
                                f.citation.bbox.y,
                                f.citation.bbox.w,
                                f.citation.bbox.h,
                                f.field_path,
                                f.citation.quote,
                            ),
                        )
                        citation_count += 1
                await conn.commit()
            except Exception:
                await conn.rollback()
                raise

    return PersistedExtraction(
        run_id=run_id,
        document_id=document_id,
        patient_id=patient_id,
        fact_ids=fact_ids,
        citation_count=citation_count,
    )


# ---------------------------------------------------------------------------
# Schema → FactRecord flatteners.
# ---------------------------------------------------------------------------

def lab_report_to_facts(payload) -> list[FactRecord]:  # type: LabReport
    """Flatten a LabReport into one FactRecord per LabResult."""
    facts: list[FactRecord] = []
    for i, r in enumerate(payload.results):
        facts.append(FactRecord(
            fact_type="lab_result",
            fact_json={
                "test_name": r.test_name,
                "value": r.value,
                "unit": r.unit,
                "reference_range": r.reference_range,
                "collection_date": r.collection_date,
                "abnormal_flag": r.abnormal_flag,
            },
            confidence=r.confidence,
            source_quote=r.source_citation.quote,
            citation=r.source_citation,
            field_path=f"results[{i}]",
        ))
    return facts


def intake_form_to_facts(payload) -> list[FactRecord]:  # type: IntakeForm
    """Flatten an IntakeForm. Each demographic field, chief concern, and list
    item becomes its own FactRecord so each carries its own citation."""
    facts: list[FactRecord] = []

    demo_fields = ["first_name", "last_name", "dob", "sex", "phone", "email"]
    for fname in demo_fields:
        cs = getattr(payload.demographics, fname, None)
        if cs is None:
            continue
        facts.append(FactRecord(
            fact_type=f"demographic.{fname}",
            fact_json={"value": cs.value},
            confidence=cs.confidence,
            source_quote=cs.source_citation.quote,
            citation=cs.source_citation,
            field_path=f"demographics.{fname}",
        ))

    if payload.chief_concern is not None:
        facts.append(FactRecord(
            fact_type="chief_concern",
            fact_json={"value": payload.chief_concern.value},
            confidence=payload.chief_concern.confidence,
            source_quote=payload.chief_concern.source_citation.quote,
            citation=payload.chief_concern.source_citation,
            field_path="chief_concern",
        ))

    for list_field in ("current_medications", "allergies", "family_history"):
        items = getattr(payload, list_field, []) or []
        for i, cs in enumerate(items):
            facts.append(FactRecord(
                fact_type=list_field.rstrip("s") if list_field != "family_history" else "family_history_item",
                fact_json={"value": cs.value},
                confidence=cs.confidence,
                source_quote=cs.source_citation.quote,
                citation=cs.source_citation,
                field_path=f"{list_field}[{i}]",
            ))
    return facts


def medication_list_to_facts(payload) -> list[FactRecord]:  # type: MedicationList
    facts: list[FactRecord] = []
    for i, m in enumerate(payload.medications):
        facts.append(FactRecord(
            fact_type="medication_line",
            fact_json={
                "medication_name": m.medication_name,
                "dose": m.dose,
                "frequency": m.frequency,
                "route": m.route,
                "prescriber": m.prescriber,
            },
            confidence=m.confidence,
            source_quote=m.source_citation.quote,
            citation=m.source_citation,
            field_path=f"medications[{i}]",
        ))
    return facts


_FLATTENERS = {
    "lab_pdf": lab_report_to_facts,
    "intake_form": intake_form_to_facts,
    "medication_list": medication_list_to_facts,
}


def flatten(doc_type: str, payload) -> list[FactRecord]:
    if doc_type not in _FLATTENERS:
        raise ValueError(f"No flattener for doc_type {doc_type!r}")
    return _FLATTENERS[doc_type](payload)
