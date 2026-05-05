"""Strict-schema Pydantic models for document extraction.

These models are exported as JSON Schemas to Anthropic's tool-use system.
The model is FORCED into the schema via `tool_choice` — it cannot return
free-form text. Required citation fields make every extracted value
traceable to a region of the source PDF.
"""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


# ---------------------------------------------------------------------------
# Citation contract — shared across all doc types.
# ---------------------------------------------------------------------------

class BBox(BaseModel):
    """Normalized bounding box, page-relative (0..1 on each axis)."""
    model_config = ConfigDict(extra="forbid")

    x: float = Field(ge=0, le=1, description="Left edge, 0..1 of page width")
    y: float = Field(ge=0, le=1, description="Top edge, 0..1 of page height")
    w: float = Field(gt=0, le=1, description="Width, 0..1 of page width")
    h: float = Field(gt=0, le=1, description="Height, 0..1 of page height")


class Citation(BaseModel):
    """Pointer back to a region of the source document.

    The model returns this for every extracted field. It is what the UI
    overlay renders, and what the `citation_present` eval rubric checks.
    """
    model_config = ConfigDict(extra="forbid")

    page: int = Field(ge=1, description="1-indexed page number")
    bbox: BBox
    field_path: str = Field(
        description=(
            "Dotted path identifying the field this citation supports, "
            "e.g. 'results[2].value' or 'demographics.dob'."
        )
    )
    quote: str = Field(
        description=(
            "The exact substring of the document that supports the field. "
            "Used by the citation_present eval rubric and the click-to-source UI."
        )
    )


# ---------------------------------------------------------------------------
# Lab report schema.
# ---------------------------------------------------------------------------

class LabResult(BaseModel):
    """One line item on a lab report. Maps loosely to FHIR Observation."""
    model_config = ConfigDict(extra="forbid")

    test_name: str = Field(description="Name of the lab test, e.g. 'HbA1c', 'Creatinine'.")
    value: str = Field(
        description=(
            "Measured value as printed (preserve units inside the string when ambiguous, "
            "e.g. '8.2', '110/70'). Unit is broken out separately."
        )
    )
    unit: str | None = Field(
        default=None,
        description="Unit of measure if printed, e.g. '%', 'mg/dL', 'mmol/L'.",
    )
    reference_range: str | None = Field(
        default=None,
        description="Reference range as printed, e.g. '4.0-5.6', '<140'.",
    )
    collection_date: str | None = Field(
        default=None,
        description="ISO 8601 date if printed (YYYY-MM-DD); null if not stated.",
    )
    abnormal_flag: Literal["normal", "high", "low", "critical", "unknown"] = Field(
        default="unknown",
        description=(
            "Standard abnormality flag, mapped from H/L/HH/LL/* markers on the report. "
            "Use 'unknown' if not indicated."
        ),
    )
    confidence: float = Field(
        ge=0.0,
        le=1.0,
        description=(
            "Self-reported extraction confidence 0..1. Use <0.5 when the field "
            "is illegible, partially obscured, or inferred rather than read."
        ),
    )
    source_citation: Citation


class LabReport(BaseModel):
    """Top-level extraction schema for a lab PDF."""
    model_config = ConfigDict(extra="forbid")

    doc_type: Literal["lab_pdf"] = "lab_pdf"
    patient_name_on_doc: str | None = Field(
        default=None,
        description="Patient name as printed on the report, for cross-check against the chart.",
    )
    ordering_provider: str | None = Field(default=None)
    collection_date: str | None = Field(
        default=None,
        description="ISO 8601 date for the overall collection if printed at the top of the report.",
    )
    results: list[LabResult] = Field(
        default_factory=list,
        description=(
            "Each lab line item. Empty list if the document contains no lab values "
            "(treat that as a refusal-to-fabricate, not as an error)."
        ),
    )


# ---------------------------------------------------------------------------
# Intake form schema.
# ---------------------------------------------------------------------------

class CitedString(BaseModel):
    """A string field paired with its citation. Used for free-text intake fields."""
    model_config = ConfigDict(extra="forbid")

    value: str
    confidence: float = Field(ge=0.0, le=1.0)
    source_citation: Citation


class Demographics(BaseModel):
    model_config = ConfigDict(extra="forbid")

    first_name: CitedString | None = None
    last_name: CitedString | None = None
    dob: CitedString | None = Field(
        default=None,
        description="ISO 8601 date if extractable.",
    )
    sex: CitedString | None = None
    phone: CitedString | None = None
    email: CitedString | None = None


class IntakeForm(BaseModel):
    """Top-level extraction schema for a patient intake form."""
    model_config = ConfigDict(extra="forbid")

    doc_type: Literal["intake_form"] = "intake_form"
    demographics: Demographics
    chief_concern: CitedString | None = None
    current_medications: list[CitedString] = Field(default_factory=list)
    allergies: list[CitedString] = Field(default_factory=list)
    family_history: list[CitedString] = Field(default_factory=list)


# ---------------------------------------------------------------------------
# Medication list schema (extension — third document type).
# ---------------------------------------------------------------------------

class MedicationLine(BaseModel):
    model_config = ConfigDict(extra="forbid")

    medication_name: str
    dose: str | None = None
    frequency: str | None = None
    route: str | None = None
    prescriber: str | None = None
    confidence: float = Field(ge=0.0, le=1.0)
    source_citation: Citation


class MedicationList(BaseModel):
    model_config = ConfigDict(extra="forbid")

    doc_type: Literal["medication_list"] = "medication_list"
    patient_name_on_doc: str | None = None
    medications: list[MedicationLine] = Field(default_factory=list)


# ---------------------------------------------------------------------------
# Public dispatch.
# ---------------------------------------------------------------------------

DOC_TYPE_TO_SCHEMA: dict[str, type[BaseModel]] = {
    "lab_pdf": LabReport,
    "intake_form": IntakeForm,
    "medication_list": MedicationList,
}


def schema_for(doc_type: str) -> type[BaseModel]:
    if doc_type not in DOC_TYPE_TO_SCHEMA:
        raise ValueError(
            f"Unknown doc_type {doc_type!r}; "
            f"expected one of {sorted(DOC_TYPE_TO_SCHEMA)}"
        )
    return DOC_TYPE_TO_SCHEMA[doc_type]
