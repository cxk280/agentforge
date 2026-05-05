"""Sonnet 4.6 PDF extraction with forced tool_choice.

Hands a PDF as an Anthropic `document` content block straight to Sonnet 4.6
and forces the model to fill out the relevant Pydantic-derived JSON schema
via tool_choice. The model literally cannot return free-form text — it must
call the extraction tool with arguments that conform to the schema.

Why this shape:
- One API call sees both rendered pages AND embedded text
- tool_choice="tool" eliminates the "please return JSON" failure mode
- Pydantic validates again on our side; an invalid response surfaces
  the schema_valid eval rubric as False
"""

from __future__ import annotations

import base64
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from pydantic import BaseModel, ValidationError

from .schemas import schema_for


# Tool name → doc_type. Kept simple so the model only needs to know one tool
# per extraction call.
_TOOL_NAME_BY_DOC_TYPE = {
    "lab_pdf": "extract_lab_report",
    "intake_form": "extract_intake_form",
    "medication_list": "extract_medication_list",
}


_SYSTEM_PROMPT = (
    "You are a clinical-document extraction tool. You will be shown a single "
    "PDF and must populate the provided structured schema with values you can "
    "actually read from the document.\n\n"
    "Hard rules:\n"
    "- Never invent values. If a field is not on the document, omit it (when "
    "  the schema allows null) or skip the row (when it's a list item).\n"
    "- Every populated field MUST carry a `source_citation` whose `quote` is the "
    "  exact substring you read from the document. The `bbox` is a normalized "
    "  page-relative rectangle (x, y, w, h all in 0..1) that contains that quote.\n"
    "- For each value, set `confidence` honestly: <0.5 if the text is illegible, "
    "  partially obscured, ambiguous, or you had to infer it from context.\n"
    "- Do not output Markdown, prose, or commentary. The only acceptable output "
    "  is a single tool call to the extraction tool.\n"
    "- Patient identity is not your concern — capture what is written; the "
    "  surrounding system handles cross-checks against the chart."
)


@dataclass
class ExtractionResult:
    """Output of a single vision extraction call."""

    payload: BaseModel  # validated against the doc_type's schema
    raw_payload: dict[str, Any]  # what Sonnet returned, before our validation
    model: str
    input_tokens: int
    output_tokens: int
    latency_ms: int
    schema_valid: bool
    validation_error: str | None = None


def _read_pdf_b64(pdf_path: str | Path) -> str:
    data = Path(pdf_path).read_bytes()
    return base64.standard_b64encode(data).decode("ascii")


def _build_tool_definition(doc_type: str) -> dict[str, Any]:
    schema_cls = schema_for(doc_type)
    json_schema = schema_cls.model_json_schema()
    tool_name = _TOOL_NAME_BY_DOC_TYPE[doc_type]
    return {
        "name": tool_name,
        "description": (
            f"Emit the extracted {doc_type} fields. Every populated value must "
            "carry a source_citation pointing to the region of the PDF it came from."
        ),
        "input_schema": json_schema,
    }


async def extract_from_pdf(
    *,
    client,  # anthropic.AsyncAnthropic — passed in to share connection pooling
    pdf_path: str | Path,
    doc_type: str,
    model: str,
    max_tokens: int = 4096,
) -> ExtractionResult:
    """Run one PDF → schema-valid JSON extraction.

    Raises ValueError if doc_type is unknown. Anthropic API errors propagate.
    """
    schema_cls = schema_for(doc_type)
    tool_def = _build_tool_definition(doc_type)
    tool_name = tool_def["name"]

    pdf_b64 = _read_pdf_b64(pdf_path)

    started = time.monotonic()
    response = await client.messages.create(
        model=model,
        max_tokens=max_tokens,
        system=_SYSTEM_PROMPT,
        tools=[tool_def],
        tool_choice={"type": "tool", "name": tool_name},
        messages=[{
            "role": "user",
            "content": [
                {
                    "type": "document",
                    "source": {
                        "type": "base64",
                        "media_type": "application/pdf",
                        "data": pdf_b64,
                    },
                },
                {
                    "type": "text",
                    "text": (
                        f"Extract every {doc_type.replace('_', ' ')} field present in this PDF. "
                        "Cite each field with a quote and bounding box."
                    ),
                },
            ],
        }],
    )
    latency_ms = int((time.monotonic() - started) * 1000)

    raw_payload = _extract_tool_input(response, tool_name)
    schema_valid = True
    validation_error: str | None = None
    try:
        payload = schema_cls.model_validate(raw_payload)
    except ValidationError as exc:
        schema_valid = False
        validation_error = str(exc)
        # Best-effort partial parse so callers can see what came back.
        payload = schema_cls.model_construct(**raw_payload)  # type: ignore[arg-type]

    usage = response.usage
    return ExtractionResult(
        payload=payload,
        raw_payload=raw_payload,
        model=model,
        input_tokens=getattr(usage, "input_tokens", 0),
        output_tokens=getattr(usage, "output_tokens", 0),
        latency_ms=latency_ms,
        schema_valid=schema_valid,
        validation_error=validation_error,
    )


def _extract_tool_input(response: Any, tool_name: str) -> dict[str, Any]:
    """Pull the forced tool_use block from an Anthropic response.

    With tool_choice={"type":"tool", ...} the response is guaranteed to
    contain exactly one tool_use block matching our tool. If the API
    contract changes, surface that loudly rather than silently returning
    an empty dict.
    """
    for block in response.content:
        if getattr(block, "type", None) == "tool_use" and block.name == tool_name:
            return dict(block.input)
    raise RuntimeError(
        f"Sonnet response did not contain a tool_use block for {tool_name!r}; "
        f"got blocks: {[getattr(b, 'type', '?') for b in response.content]}"
    )
