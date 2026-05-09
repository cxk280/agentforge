"""Tests for agent_schemas — the runtime citation-required schema.

Locks in the schema invariants the W2 reviewer asked us to tighten
(2026-05-09): clinical claims must come with citations, validated at
the model boundary instead of as a soft post-hoc warning.

Run directly: `python copilot/agent/test_agent_schemas.py`
"""

from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from agent_schemas import (  # noqa: E402
    Citation,
    CitedReply,
    parse_reply,
)
from pydantic import ValidationError  # noqa: E402


class CitationTests(unittest.TestCase):
    def test_empty_text_rejected(self):
        with self.assertRaises(ValidationError):
            Citation(kind="inline_ref", text="")

    def test_unknown_kind_rejected(self):
        with self.assertRaises(ValidationError):
            Citation(kind="bogus", text="x")  # type: ignore[arg-type]

    def test_extra_field_rejected(self):
        with self.assertRaises(ValidationError):
            Citation.model_validate({"kind": "inline_ref", "text": "x", "rogue": 1})


class ParseReplyTests(unittest.TestCase):
    def test_empty_reply_rejected(self):
        with self.assertRaises(ValidationError):
            parse_reply("")

    def test_free_chat_reply_no_citations(self):
        reply = "Hi! What patient would you like to discuss?"
        cr = parse_reply(reply)
        self.assertEqual(cr.citations, [])
        self.assertFalse(cr.claims_present)

    def test_sources_line_citation_extracted(self):
        reply = (
            "The patient is on metformin and lisinopril.\n"
            "Sources: Medications"
        )
        cr = parse_reply(reply)
        kinds = [c.kind for c in cr.citations]
        self.assertIn("sources_line", kinds)

    def test_inline_resource_citation_extracted(self):
        reply = "Lab from [Observation/abc-123] on 2024-11-10."
        cr = parse_reply(reply)
        kinds = [c.kind for c in cr.citations]
        self.assertIn("inline_ref", kinds)

    def test_provider_tool_ref_extracted(self):
        reply = "Patient is on metformin per get_medications: 500mg BID."
        cr = parse_reply(reply)
        kinds = [c.kind for c in cr.citations]
        self.assertIn("tool_ref", kinds)

    def test_multiple_citation_types_all_extracted(self):
        reply = (
            "HbA1c 8.2% per get_recent_labs on 2024-11-10 "
            "[Observation/lab-99].\n"
            "Sources: Lab Results"
        )
        cr = parse_reply(reply)
        kinds = {c.kind for c in cr.citations}
        self.assertSetEqual(kinds, {"sources_line", "inline_ref", "tool_ref"})

    def test_numeric_claim_without_citation_raises(self):
        # 8.2% is a numeric clinical claim with a unit; no Sources line,
        # no inline ref, no `per get_*` marker → schema must reject.
        reply = "The patient's HbA1c is 8.2% — start metformin 500mg BID."
        with self.assertRaises(ValidationError) as ctx:
            parse_reply(reply)
        self.assertIn("citation", str(ctx.exception).lower())

    def test_lab_name_near_number_without_citation_raises(self):
        # `creatinine 1.4` is matched by _LAB_NAME_NEAR_NUMBER even
        # without a unit attached.
        reply = "Patient's creatinine 1.4 last visit."
        with self.assertRaises(ValidationError):
            parse_reply(reply)

    def test_lab_name_near_number_with_citation_passes(self):
        reply = "Creatinine 1.4 per get_recent_labs."
        cr = parse_reply(reply)
        self.assertTrue(cr.claims_present)
        self.assertGreater(len(cr.citations), 0)


class HandConstructedCitedReplyTests(unittest.TestCase):
    """Direct construction (bypassing parse_reply) must still enforce
    the invariants — a caller can't lie about claims_present or pass
    extra fields."""

    def test_hand_constructed_with_correct_flag_and_citations(self):
        cr = CitedReply(
            content="HbA1c 8.2% per get_recent_labs.",
            citations=[Citation(kind="tool_ref", text="per get_recent_labs")],
            claims_present=True,
        )
        self.assertTrue(cr.claims_present)
        self.assertEqual(len(cr.citations), 1)

    def test_claims_present_lie_rejected(self):
        # content clearly has a claim, but caller passes claims_present=False.
        with self.assertRaises(ValidationError):
            CitedReply(
                content="HbA1c is 8.2% — concerning.",
                citations=[],
                claims_present=False,   # spoofing — must be rejected
            )

    def test_no_claim_with_citations_validates(self):
        # Free chat with stray citations is allowed (citations don't hurt).
        cr = CitedReply(
            content="Hi there!",
            citations=[Citation(kind="sources_line", text="Sources: X")],
            claims_present=False,
        )
        self.assertEqual(len(cr.citations), 1)

    def test_extra_field_rejected(self):
        with self.assertRaises(ValidationError):
            CitedReply.model_validate({
                "content": "x",
                "citations": [],
                "claims_present": False,
                "rogue": True,
            })

    def test_empty_content_rejected(self):
        with self.assertRaises(ValidationError):
            CitedReply(content="", citations=[], claims_present=False)


if __name__ == "__main__":
    unittest.main()
