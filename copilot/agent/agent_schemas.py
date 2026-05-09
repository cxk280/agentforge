"""Runtime schemas for agent replies.

Today this module owns the citation-required schema (CitedReply). The
existing `evals/rubrics.py:citation_present` rubric runs the same regex
checks post-hoc as part of the eval suite — but a soft "warn and surface
anyway" rubric is not the same as a schema invariant. CitedReply makes
the rule a structural constraint: clinical claim → citations required,
or ValidationError.

Wired into graph.py's critic_node: every draft reply is run through
parse_reply(); a ValidationError from the schema is the critic's signal
to bounce the draft back to final_answer with structured feedback.

The regex patterns are deliberately mirrored from evals/rubrics.py
(_SOURCES_LINE, _INLINE_CITATION, _PROVIDER_TOOL_REF, _NUMERIC_CLAIM,
_LAB_NAME_NEAR_NUMBER). If you change one set, change the other —
test_agent_schemas.py and test_rubrics_citation_present (when it lands)
both lock in the contract.
"""

from __future__ import annotations

import re
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator


# Citation-marker patterns — mirrored from evals/rubrics.py.
_SOURCES_LINE = re.compile(r"^\s*sources?\s*:\s*\S", re.IGNORECASE | re.MULTILINE)
_INLINE_CITATION = re.compile(
    r"\[(Observation|DocumentReference|GuidelineChunk|MedicationRequest|"
    r"Condition|Patient|Encounter|AllergyIntolerance)/[\w\-\.]+\]",
)
_PROVIDER_TOOL_REF = re.compile(
    r"per (?:get_\w+|search_guidelines|get_extracted_facts)\b", re.IGNORECASE
)

# Clinical-claim shape — mirrored from evals/rubrics.py.
_NUMERIC_CLAIM = re.compile(
    r"\b\d+(?:\.\d+)?\s?(?:mg|mcg|g|kg|mL|mmol/L|mg/dL|%|bpm|mm\s?Hg|mEq/L|U/L|K/uL|"
    r"mL/min(?:/1\.73)?)\b",
    re.IGNORECASE,
)
_LAB_NAME_NEAR_NUMBER = re.compile(
    r"\b(?:a1c|hba1c|glucose|creatinine|egfr|bp|blood\s+pressure|"
    r"cholesterol|ldl|hdl|bun|sodium|potassium|chloride|tsh|wbc|"
    r"hemoglobin|hematocrit|platelets|systolic|diastolic|metformin|"
    r"lisinopril|atorvastatin|empagliflozin|aspirin)\b.{0,30}\b\d+(?:\.\d+)?\b",
    re.IGNORECASE,
)


CitationKind = Literal["sources_line", "inline_ref", "tool_ref"]


class Citation(BaseModel):
    """One citation marker extracted from an agent reply."""

    kind: CitationKind
    text: str = Field(min_length=1)

    model_config = ConfigDict(extra="forbid", frozen=True)


class CitedReply(BaseModel):
    """Schema-enforced agent reply.

    When `claims_present` is True, `citations` must be non-empty.
    Pydantic raises ValidationError otherwise — this is the schema-level
    counterpart to the existing post-hoc `citation_present` rubric.

    `claims_present` is a derived flag (see _has_clinical_claim) baked
    into the model so a hand-constructed reply can't sidestep the
    requirement by lying about whether it has claims — the validator
    re-runs the heuristic against `content` and rejects mismatches.
    """

    content: str = Field(min_length=1)
    citations: list[Citation] = Field(default_factory=list)
    claims_present: bool

    model_config = ConfigDict(extra="forbid")

    @model_validator(mode="after")
    def _enforce_required_citations(self) -> "CitedReply":
        if self.claims_present and not self.citations:
            raise ValueError(
                "reply contains a clinical claim (numeric value with unit "
                "or lab name near a number) but no citation marker "
                "(Sources: line, inline [Resource/id] reference, or "
                "'per get_*' tool reference)"
            )
        # Re-derive claims_present from content to prevent spoofing.
        actual = _has_clinical_claim(self.content)
        if actual != self.claims_present:
            raise ValueError(
                f"claims_present={self.claims_present} but heuristic on "
                f"content yields {actual}; do not hand-construct this "
                f"flag — use parse_reply()"
            )
        return self


def _extract_citations(text: str) -> list[Citation]:
    out: list[Citation] = []
    if _SOURCES_LINE.search(text):
        match = _SOURCES_LINE.search(text)
        if match is not None:
            line_end = text.find("\n", match.start())
            line_end = len(text) if line_end == -1 else line_end
            out.append(Citation(kind="sources_line",
                                text=text[match.start():line_end].strip()))
    for m in _INLINE_CITATION.finditer(text):
        out.append(Citation(kind="inline_ref", text=m.group(0)))
    for m in _PROVIDER_TOOL_REF.finditer(text):
        out.append(Citation(kind="tool_ref", text=m.group(0)))
    return out


def _has_clinical_claim(text: str) -> bool:
    return bool(_NUMERIC_CLAIM.search(text)) or bool(_LAB_NAME_NEAR_NUMBER.search(text))


def parse_reply(text: str) -> CitedReply:
    """Parse an agent reply into a schema-validated CitedReply.

    Raises ValidationError when the reply contains a clinical claim but
    no citation marker. Free-chat replies (no clinical claims) validate
    with an empty citations list.
    """
    return CitedReply(
        content=text,
        citations=_extract_citations(text),
        claims_present=_has_clinical_claim(text),
    )
