"""FHIR-backed tool implementations and Claude tool schemas."""

import asyncio
from fhir_client import fhir_get, bundle_entries


# ---------------------------------------------------------------------------
# Tool implementations
# ---------------------------------------------------------------------------

async def get_patient_summary(patient_id: str) -> dict:
    """Demographics, active conditions, and allergies for a patient."""
    patient, conditions, allergies = await asyncio.gather(
        fhir_get(f"Patient/{patient_id}"),
        fhir_get("Condition", {"patient": patient_id}),
        fhir_get("AllergyIntolerance", {"patient": patient_id}),
    )

    name = patient.get("name", [{}])[0]
    full_name = " ".join(name.get("given", []) + [name.get("family", "")]).strip()

    # OpenEMR's FHIR server doesn't support clinical-status search param reliably;
    # filter active conditions in Python instead.
    active = [
        c for c in bundle_entries(conditions)
        if c.get("clinicalStatus", {}).get("coding", [{}])[0].get("code") == "active"
    ]

    return {
        "patient_id": patient_id,
        "name": full_name,
        "dob": patient.get("birthDate"),
        "gender": patient.get("gender"),
        "active_conditions": [_condition_text(c) for c in active],
        "allergies": [_allergy_text(a) for a in bundle_entries(allergies)],
    }


async def get_medications(patient_id: str) -> dict:
    """Active medication requests for a patient."""
    bundle = await fhir_get(
        "MedicationRequest",
        {"patient": patient_id, "status": "active"},
    )
    meds = []
    for r in bundle_entries(bundle):
        med = r.get("medicationCodeableConcept", {})
        dosage = r.get("dosageInstruction", [{}])[0]
        meds.append({
            "name": med.get("text") or _coding_display(med),
            "dosage": dosage.get("text"),
            "authored": r.get("authoredOn"),
        })
    return {"patient_id": patient_id, "medications": meds}


# LOINC code sets for splitting OpenEMR's flat Observation stream into
# vitals vs labs. OpenEMR doesn't reliably populate FHIR's `category`
# field for either, so we filter in Python by code. The vitals set
# covers the standard core panel (BP systolic/diastolic + the 85354-9
# panel wrapper, HR, RR, temp, height, weight, BMI, O2 sat, head circ);
# anything outside it is treated as a lab.
_VITAL_LOINC_CODES = frozenset({
    "8480-6",   # Systolic BP
    "8462-4",   # Diastolic BP
    "85354-9",  # BP panel
    "85353-1",  # Vital signs, weight, height, head circ, O2 sat and BMI panel
    "8867-4",   # Heart rate
    "9279-1",   # Respiratory rate
    "8310-5",   # Body temperature
    "8302-2",   # Body height
    "29463-7",  # Body weight
    "39156-5",  # BMI
    "2708-6",   # O2 saturation
    "59408-5",  # SpO2
    "9843-4",   # Head circumference
})

# Big enough to cover several years of mixed observations for a patient
# with frequent labs. We still cap caller-visible results below; this
# is just the upper bound on what we pull from FHIR before filtering.
_OBSERVATION_FETCH_BATCH = 200


def _obs_loinc_code(obs: dict) -> str:
    for c in obs.get("code", {}).get("coding", []):
        sys_uri = c.get("system") or ""
        if "loinc" in sys_uri.lower() or sys_uri == "":
            code = c.get("code")
            if code:
                return code
    return ""


def _is_vital(obs: dict) -> bool:
    code = _obs_loinc_code(obs)
    if code in _VITAL_LOINC_CODES:
        return True
    # Some panels carry sub-vitals only inside component[]; also count those.
    for comp in obs.get("component", []) or []:
        for c in comp.get("code", {}).get("coding", []):
            if c.get("code") in _VITAL_LOINC_CODES:
                return True
    return False


async def get_recent_labs(patient_id: str, limit: int = 10) -> dict:
    """Most recent laboratory observations for a patient.

    Pulls a wide batch of Observations and filters OUT anything that
    looks like a vital sign (by LOINC code), so labs can't be crowded
    out by a busy vitals visit. Caller-visible results are capped at
    ``limit``.
    """
    bundle = await fhir_get(
        "Observation",
        {
            "patient": patient_id,
            "_sort": "-date",
            "_count": str(_OBSERVATION_FETCH_BATCH),
        },
    )
    labs = []
    for obs in bundle_entries(bundle):
        if _is_vital(obs):
            continue
        labs.append({
            "name": _coding_display(obs.get("code", {})),
            "value": _obs_value(obs),
            "unit": obs.get("valueQuantity", {}).get("unit"),
            "reference_range": _ref_range(obs),
            "interpretation": _interpretation(obs),
            "date": obs.get("effectiveDateTime") or obs.get("effectivePeriod", {}).get("start"),
            "status": obs.get("status"),
        })
        if len(labs) >= limit:
            break
    return {"patient_id": patient_id, "labs": labs}


async def get_vitals(patient_id: str, limit: int = 50) -> dict:
    """Recent vital signs for a patient.

    Pulls a wide batch of Observations and filters IN only vital-sign
    LOINC codes, so trend questions ("has BP improved?") see the full
    history of BP readings instead of being capped by interleaved lab
    results. Caller-visible results are capped at ``limit``.

    Each visit produces ~11 vital-sign observations (BP panel + 10
    sub-codes), so ``limit=50`` covers roughly 4-5 visits — enough
    for most trend questions. Bump higher when the user asks about
    long-term trends or when the first call returns < 4 distinct dates.
    """
    bundle = await fhir_get(
        "Observation",
        {
            "patient": patient_id,
            "_sort": "-date",
            "_count": str(_OBSERVATION_FETCH_BATCH),
        },
    )
    vitals = []
    for obs in bundle_entries(bundle):
        if not _is_vital(obs):
            continue
        vitals.append({
            "name": _coding_display(obs.get("code", {})),
            "value": _obs_value(obs),
            "unit": obs.get("valueQuantity", {}).get("unit"),
            "date": obs.get("effectiveDateTime") or obs.get("effectivePeriod", {}).get("start"),
        })
        if len(vitals) >= limit:
            break
    return {"patient_id": patient_id, "vitals": vitals}


async def get_visit_history(patient_id: str, limit: int = 5) -> dict:
    """Recent encounters with SOAP note text for a patient."""
    bundle = await fhir_get(
        "Encounter",
        {
            "patient": patient_id,
            "_sort": "-date",
            "_count": str(limit),
        },
    )
    encounters = []
    for enc in bundle_entries(bundle):
        period = enc.get("period", {})
        encounters.append({
            "encounter_id": enc.get("id"),
            "date": period.get("start"),
            "status": enc.get("status"),
            "type": _encounter_type(enc),
            "reason": _encounter_reason(enc),
        })
    return {"patient_id": patient_id, "encounters": encounters}


async def get_conditions(patient_id: str) -> dict:
    """All recorded conditions (active and inactive) for a patient."""
    bundle = await fhir_get("Condition", {"patient": patient_id})
    conditions = []
    for c in bundle_entries(bundle):
        conditions.append({
            "name": _condition_text(c),
            "status": c.get("clinicalStatus", {}).get("coding", [{}])[0].get("code"),
            "onset": c.get("onsetDateTime") or c.get("onsetPeriod", {}).get("start"),
            "recorded": c.get("recordedDate"),
        })
    return {"patient_id": patient_id, "conditions": conditions}


# ---------------------------------------------------------------------------
# Week 2 tools — guideline retrieval + extracted-fact read
# ---------------------------------------------------------------------------

async def search_guidelines(patient_id: str, query: str, top_k: int = 5) -> dict:
    """W2: hybrid sparse+dense retrieval over the clinical-guideline
    corpus — BM25 + Voyage embeddings fused via Reciprocal Rank Fusion,
    with optional Cohere Rerank on top. Returns up to top_k snippets
    with source metadata + per-component scores so the agent can quote
    them with the citation contract intact.

    The patient_id arg is enforced by the agent loop but not used for
    the corpus query — guideline retrieval is patient-agnostic.
    """
    try:
        from rag.retriever import search_with_meta
    except ImportError:
        return {
            "patient_id": patient_id,
            "query": query,
            "results": [],
            "error": "RAG retriever module not available",
        }
    top_k = max(1, min(int(top_k or 5), 20))
    bundle = search_with_meta(query, top_k=top_k)
    return {
        "patient_id": patient_id,
        "query": query,
        "results": bundle["results"],
        "retrieval": bundle["meta"],
    }


async def get_extracted_facts(patient_id: str, doc_type: str | None = None) -> dict:
    """W2: structured facts extracted from uploaded documents for this
    patient (cp_extracted_facts joined with cp_extraction_citations).

    Each fact carries a derivedFrom: DocumentReference/{id} field so
    citations on the agent's reply round-trip through the bbox viewer.

    `patient_id` may be either the numeric OpenEMR pid OR a 32-char
    hex FHIR UUID (the form the agent's system prompt advertises as
    "Active patient ID"). cp_extracted_facts is keyed on the numeric
    pid; we resolve hex UUIDs back to a pid via patient_data.uuid
    before querying. Without this, an agent call from /chat/graph
    (where the system prompt holds the FHIR UUID) silently returned
    zero facts even though extraction had populated the table.
    """
    try:
        from fhir_client import get_db_pool
    except ImportError:
        return {"patient_id": patient_id, "facts": [], "error": "DB pool unavailable"}
    pool = await get_db_pool()
    if pool is None:
        return {"patient_id": patient_id, "facts": [],
                "error": "DB not configured (no DB_HOST)"}

    pid = await _resolve_to_pid(pool, patient_id)
    if pid is None:
        return {"patient_id": patient_id, "facts": [],
                "error": (f"Could not resolve patient_id={patient_id!r} to an "
                          f"OpenEMR pid (neither numeric nor a known FHIR UUID).")}

    where_extra = ""
    params: list = [pid]
    if doc_type:
        where_extra = " AND doc_type = %s"
        params.append(doc_type)

    facts: list[dict] = []
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "SELECT id, document_id, doc_type, fact_type, fact_json, "
                    "       confidence, source_quote, extraction_run_id "
                    "FROM cp_extracted_facts "
                    "WHERE patient_id = %s" + where_extra + " "
                    "ORDER BY created_at DESC, id DESC LIMIT 200",
                    params,
                )
                rows = await cur.fetchall()
        import json as _json
        for r in rows:
            facts.append({
                "fact_id": int(r[0]),
                "document_id": int(r[1]) if r[1] is not None else None,
                "doc_type": r[2],
                "fact_type": r[3],
                "fact_json": _json.loads(r[4]) if isinstance(r[4], str) else r[4],
                "confidence": float(r[5]) if r[5] is not None else None,
                "source_quote": r[6],
                "extraction_run_id": r[7],
                "derived_from": (f"DocumentReference/{int(r[1])}" if r[1] else None),
            })
    except Exception as exc:
        return {"patient_id": patient_id, "facts": [], "error": str(exc)[:200]}
    return {"patient_id": patient_id, "doc_type_filter": doc_type, "facts": facts}


# Cache reverse lookups (FHIR-hex → pid). Patient UUIDs are stable for
# the life of the patient_data row, so this is safe to keep for the
# process lifetime.
_FHIR_TO_PID: dict[str, int] = {}


async def _resolve_to_pid(pool, patient_id: str) -> int | None:
    """Return the numeric OpenEMR pid for either a digit string or a
    32-char hex FHIR UUID. Returns None if neither shape resolves."""
    if not patient_id:
        return None
    if patient_id.isdigit():
        return int(patient_id)
    # Strip any FHIR resource prefix the model might have sent
    # ("Patient/abc..."), then any dashes (UUIDs sometimes carry them).
    candidate = patient_id.split("/")[-1].replace("-", "").lower()
    if not candidate or any(c not in "0123456789abcdef" for c in candidate):
        return None
    cached = _FHIR_TO_PID.get(candidate)
    if cached is not None:
        return cached
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "SELECT pid FROM patient_data WHERE LOWER(HEX(uuid)) = %s LIMIT 1",
                    [candidate],
                )
                row = await cur.fetchone()
    except Exception:
        return None
    if not row or row[0] is None:
        return None
    pid = int(row[0])
    _FHIR_TO_PID[candidate] = pid
    return pid


# ---------------------------------------------------------------------------
# Private helpers
# ---------------------------------------------------------------------------

def _coding_display(cc: dict) -> str:
    codings = cc.get("coding", [])
    if codings:
        return codings[0].get("display") or codings[0].get("code", "")
    return cc.get("text", "")


def _obs_value(obs: dict) -> str | None:
    if "valueQuantity" in obs:
        v = obs["valueQuantity"].get("value")
        u = obs["valueQuantity"].get("unit") or ""
        return f"{v} {u}".strip() if v is not None else None
    if "valueString" in obs:
        return obs["valueString"]
    if "valueCodeableConcept" in obs:
        return _coding_display(obs["valueCodeableConcept"])
    # Panel-style observations (BP) carry their values in component[] —
    # one entry per sub-code (systolic, diastolic). Render as "S/D unit".
    components = obs.get("component", []) or []
    if components:
        parts = []
        for c in components:
            vq = c.get("valueQuantity") or {}
            val = vq.get("value")
            if val is not None:
                parts.append(str(val))
        if parts:
            unit = (components[0].get("valueQuantity") or {}).get("unit", "")
            return f"{'/'.join(parts)} {unit}".strip()
    return None


def _ref_range(obs: dict) -> str | None:
    rr = obs.get("referenceRange", [{}])[0]
    low = rr.get("low", {}).get("value")
    high = rr.get("high", {}).get("value")
    if low is not None and high is not None:
        return f"{low}–{high}"
    if low is not None:
        return f">{low}"
    if high is not None:
        return f"<{high}"
    return rr.get("text")


def _interpretation(obs: dict) -> str | None:
    interps = obs.get("interpretation", [])
    if interps:
        return _coding_display(interps[0])
    return None


def _condition_text(c: dict) -> str:
    return _coding_display(c.get("code", {})) or c.get("code", {}).get("text", "Unknown")


def _allergy_text(a: dict) -> str:
    # Prefer coded substance name; fall back to narrative div text which OpenEMR
    # populates with the free-text substance name when no code is available.
    substance = _coding_display(a.get("code", {}))
    if not substance or substance.lower() == "unknown":
        div = a.get("text", {}).get("div", "")
        import re
        match = re.search(r"<div[^>]*>([^<]+)</div>", div)
        substance = match.group(1).strip() if match else "Unknown substance"

    reactions = [
        _coding_display(r.get("manifestation", [{}])[0])
        or r.get("manifestation", [{}])[0].get("text", "")
        for r in a.get("reaction", [])
        if r.get("manifestation")
    ]
    reactions = [r for r in reactions if r]
    if reactions:
        return f"{substance} → {', '.join(reactions)}"
    return substance


def _encounter_type(enc: dict) -> str | None:
    types = enc.get("type", [])
    if types:
        return _coding_display(types[0])
    return None


def _encounter_reason(enc: dict) -> str | None:
    reasons = enc.get("reasonCode", [])
    if reasons:
        return _coding_display(reasons[0])
    return None


# ---------------------------------------------------------------------------
# Claude tool schemas
# ---------------------------------------------------------------------------

TOOL_SCHEMAS = [
    {
        "name": "get_patient_summary",
        "description": (
            "Retrieve demographics, active conditions, and allergies for a patient. "
            "Call this first to establish patient context."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "patient_id": {"type": "string", "description": "OpenEMR patient ID (pid)"},
            },
            "required": ["patient_id"],
        },
    },
    {
        "name": "get_medications",
        "description": "Retrieve active medication requests (prescriptions) for a patient.",
        "input_schema": {
            "type": "object",
            "properties": {
                "patient_id": {"type": "string", "description": "OpenEMR patient ID (pid)"},
            },
            "required": ["patient_id"],
        },
    },
    {
        "name": "get_recent_labs",
        "description": "Retrieve the most recent laboratory results for a patient, newest first.",
        "input_schema": {
            "type": "object",
            "properties": {
                "patient_id": {"type": "string", "description": "OpenEMR patient ID (pid)"},
                "limit": {"type": "integer", "description": "Max results to return (default 10)", "default": 10},
            },
            "required": ["patient_id"],
        },
    },
    {
        "name": "get_vitals",
        "description": (
            "Retrieve recent vital signs for a patient (BP, HR, temp, weight, "
            "height, BMI, O2 sat). Each visit produces ~11 observations "
            "(BP panel + sub-codes), so the default limit of 50 covers about "
            "4-5 visits. For trend questions ('has BP improved over time?', "
            "'show weight history'), pass limit=100 or higher to ensure the "
            "full longitudinal view across all relevant encounters."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "patient_id": {"type": "string", "description": "OpenEMR patient ID (pid)"},
                "limit": {
                    "type": "integer",
                    "description": "Max vital observations to return (default 50; use 100+ for trend questions)",
                    "default": 50,
                },
            },
            "required": ["patient_id"],
        },
    },
    {
        "name": "get_visit_history",
        "description": "Retrieve recent encounter records for a patient, newest first.",
        "input_schema": {
            "type": "object",
            "properties": {
                "patient_id": {"type": "string", "description": "OpenEMR patient ID (pid)"},
                "limit": {"type": "integer", "description": "Number of encounters to return (default 5)", "default": 5},
            },
            "required": ["patient_id"],
        },
    },
    {
        "name": "get_conditions",
        "description": "Retrieve all recorded conditions (active and historical) for a patient.",
        "input_schema": {
            "type": "object",
            "properties": {
                "patient_id": {"type": "string", "description": "OpenEMR patient ID (pid)"},
            },
            "required": ["patient_id"],
        },
    },
    {
        "name": "search_guidelines",
        "description": (
            "W2: hybrid sparse+dense retrieval (BM25 + Voyage embeddings, "
            "fused via RRF, optionally reranked by Cohere) over a curated "
            "clinical-guideline corpus (ADA Standards of Care, ACC/AHA "
            "hypertension, USPSTF, GINA asthma, KDIGO CKD). Use when the "
            "user's question turns on what guidelines say (e.g. \"what's "
            "the A1c target?\", \"is metformin appropriate at this "
            "eGFR?\"). Returns up to top_k evidence chunks; cite source_id "
            "+ page in the reply."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "patient_id": {"type": "string", "description": "OpenEMR patient ID (pid)"},
                "query": {"type": "string", "description": "Concise clinical query, e.g. 'metformin contraindicated CKD' or 'aspirin primary prevention adults 60+'."},
                "top_k": {"type": "integer", "description": "Max results (default 5, max 20).", "default": 5},
            },
            "required": ["patient_id", "query"],
        },
    },
    {
        "name": "get_extracted_facts",
        "description": (
            "W2: structured facts extracted from uploaded clinical documents "
            "(lab PDFs, intake forms, medication lists) for this patient. "
            "Each fact carries a derivedFrom: DocumentReference/{id} link "
            "so citations on your reply round-trip through the bbox viewer. "
            "Use when the user references something on a recently-uploaded "
            "document, OR to cross-check chart-native data (FHIR) against "
            "what the patient brought in."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "patient_id": {"type": "string", "description": "OpenEMR patient ID (pid)"},
                "doc_type": {
                    "type": "string",
                    "description": "Optional filter: lab_pdf | intake_form | medication_list.",
                    "enum": ["lab_pdf", "intake_form", "medication_list"],
                },
            },
            "required": ["patient_id"],
        },
    },
]

# Dispatch map: tool name → async callable
TOOL_DISPATCH: dict[str, callable] = {
    "get_patient_summary": get_patient_summary,
    "get_medications": get_medications,
    "get_recent_labs": get_recent_labs,
    "get_vitals": get_vitals,
    "get_visit_history": get_visit_history,
    "get_conditions": get_conditions,
    "search_guidelines": search_guidelines,
    "get_extracted_facts": get_extracted_facts,
}
