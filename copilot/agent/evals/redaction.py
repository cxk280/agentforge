"""PHI redaction for eval-trace uploads to Langfuse.

The production agent's `observability.py` already keeps PHI out of
trace payloads (patient_id is hashed, request body is a boolean,
tool spans log only resource type). The eval harness, by contrast,
*does* upload the full reply text so engineers can review which
case failed and why. This module scrubs the reply before that upload.

Two layers:
  1. Pattern-based — regex matches for emails, phone numbers, SSN-like
     and MRN-like digit runs, ISO dates within a plausible DOB window.
  2. Roster-based — replaces the seeded demo patient names + DOBs
     so the most common PHI shape ("Ted Shaw, born 1954-07-12") is
     blanked out even when the regex misses it.

The redaction is intentionally conservative — it might over-redact
(a date that happens to be a lab date gets `<date>`), which is fine
for trace review. We never want to under-redact.
"""

from __future__ import annotations

import re
from pathlib import Path
import json

HERE = Path(__file__).resolve().parent
CASES_PATH = HERE / "cases.json"

_EMAIL_RE = re.compile(r"\b[\w._%+-]+@[\w.-]+\.[A-Za-z]{2,}\b")
# US-style phone number: optional +1, area code in parens or not, separators
_PHONE_RE = re.compile(
    r"\b(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]\d{3}[-.\s]\d{4}\b"
)
# 9-digit run that LOOKS like an SSN — 3-2-4 with separators or solid
_SSN_RE = re.compile(r"\b\d{3}-\d{2}-\d{4}\b|\b\d{9}\b")
# Long bare digit runs (>= 7 digits) frequently encode MRNs
_MRN_RE = re.compile(r"\b\d{7,}\b")
# ISO-shape dates 1900..2099 — wide enough to catch DOBs and visit dates
_DATE_RE = re.compile(r"\b(?:19|20)\d{2}-\d{2}-\d{2}\b")


def _build_roster() -> tuple[list[re.Pattern], list[re.Pattern]]:
    """Pre-compile patterns for the seeded patient names + DOBs.

    Pulled from cases.json's `_meta.patient_roster` if present, else
    falls back to a static list keyed on the cases used in the suite.
    Returning compiled patterns keeps the hot path cheap.
    """
    names: list[re.Pattern] = []
    dobs: list[re.Pattern] = []
    try:
        meta = json.loads(CASES_PATH.read_text()).get("_meta", {})
        for entry in meta.get("patient_roster", []):
            name = entry.get("name") or ""
            dob = entry.get("dob") or ""
            if name:
                # Match the full name AND each component (Ted Shaw / Ted / Shaw)
                names.append(re.compile(re.escape(name), re.IGNORECASE))
                for part in name.split():
                    if len(part) >= 3:
                        names.append(
                            re.compile(rf"\b{re.escape(part)}\b", re.IGNORECASE)
                        )
            if dob:
                dobs.append(re.compile(re.escape(dob)))
    except Exception:  # pragma: no cover — redaction must never raise
        pass

    # Fallback list — seeded demo patients in the eval suite.
    if not names:
        for full_name in [
            "Ted Shaw",
            "Farrah Patel",
            "Maria Lopez",
            "James Anderson",
        ]:
            names.append(re.compile(re.escape(full_name), re.IGNORECASE))
            for part in full_name.split():
                if len(part) >= 3:
                    names.append(
                        re.compile(rf"\b{re.escape(part)}\b", re.IGNORECASE)
                    )
    return names, dobs


_NAME_PATTERNS, _DOB_PATTERNS = _build_roster()


def redact(text: str) -> str:
    """Return a scrubbed copy of `text` safe to upload to Langfuse."""
    if not text:
        return text
    s = text
    s = _EMAIL_RE.sub("<email>", s)
    s = _PHONE_RE.sub("<phone>", s)
    s = _SSN_RE.sub("<ssn>", s)
    s = _MRN_RE.sub("<id>", s)
    s = _DATE_RE.sub("<date>", s)
    for pat in _DOB_PATTERNS:
        s = pat.sub("<date>", s)
    for pat in _NAME_PATTERNS:
        s = pat.sub("<name>", s)
    return s
