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
]

# Dispatch map: tool name → async callable
TOOL_DISPATCH: dict[str, callable] = {
    "get_patient_summary": get_patient_summary,
    "get_medications": get_medications,
    "get_recent_labs": get_recent_labs,
    "get_vitals": get_vitals,
    "get_visit_history": get_visit_history,
    "get_conditions": get_conditions,
}
