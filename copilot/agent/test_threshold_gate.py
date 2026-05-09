"""Tests for the eval threshold gate.

Replaces (and tightens) the bash grep+awk+cut one-liner that used to
gate eval pass counts in .circleci/config.yml. This locks in the
"exact eval threshold behavior" the W2 reviewer asked us to prove
(2026-05-09).

Run directly: `python copilot/agent/test_threshold_gate.py`
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent / "evals"))

import threshold_gate  # noqa: E402


# Canonical run_evals.py output shape — taken straight from the print()
# at run_evals.py:354 plus the surrounding category rows.
_CANONICAL_OUTPUT = """\
... (run_evals output preamble omitted for brevity)
────────────────────────────────────────────────────────────────────────
  clinical_lookup         3/3 passed   avg score 1.00
  refusal                 1/1 passed   avg score 1.00
  extraction              1/1 passed   avg score 1.00
────────────────────────────────────────────────────────────────────────
  TOTAL                  5/5 passed   avg score 1.00
────────────────────────────────────────────────────────────────────────
"""

_CANONICAL_FAILING = """\
────────────────────────────────────────────────────────────────────────
  clinical_lookup         2/3 passed   avg score 0.67
  refusal                 1/1 passed   avg score 1.00
────────────────────────────────────────────────────────────────────────
  TOTAL                  3/4 passed   avg score 0.75
"""


class ParseTotalTests(unittest.TestCase):
    def test_parses_canonical_total_line(self):
        self.assertEqual(threshold_gate.parse_total(_CANONICAL_OUTPUT), (5, 5))

    def test_parses_failing_total_line(self):
        self.assertEqual(threshold_gate.parse_total(_CANONICAL_FAILING), (3, 4))

    def test_returns_none_when_total_absent(self):
        text = "  clinical_lookup         3/3 passed\n"  # no TOTAL row
        self.assertIsNone(threshold_gate.parse_total(text))

    def test_returns_none_on_empty_input(self):
        self.assertIsNone(threshold_gate.parse_total(""))

    def test_does_not_match_category_rows(self):
        # `clinical_lookup 5/5 passed` is the per-category shape — it
        # must NOT be parsed as the totals line.
        text = "  clinical_lookup         5/5 passed   avg score 1.00\n"
        self.assertIsNone(threshold_gate.parse_total(text))

    def test_handles_extra_whitespace_around_slash(self):
        # Defensive: tolerate cosmetic format drift like "5 / 5 passed".
        text = "  TOTAL                  5 / 5 passed\n"
        self.assertEqual(threshold_gate.parse_total(text), (5, 5))


class GateTests(unittest.TestCase):
    def test_passed_above_threshold(self):
        code, msg = threshold_gate.gate(_CANONICAL_OUTPUT, min_pass=4)
        self.assertEqual(code, 0)
        self.assertIn("5/5", msg)

    def test_passed_exactly_meets_threshold(self):
        # Boundary condition — passed == min_pass should pass, not fail.
        code, _msg = threshold_gate.gate(_CANONICAL_OUTPUT, min_pass=5)
        self.assertEqual(code, 0)

    def test_passed_below_threshold(self):
        code, msg = threshold_gate.gate(_CANONICAL_FAILING, min_pass=4)
        self.assertEqual(code, 1)
        self.assertIn("3/4", msg)
        self.assertIn(">= 4", msg)

    def test_missing_total_fails(self):
        code, msg = threshold_gate.gate("nothing useful here", min_pass=1)
        self.assertEqual(code, 1)
        self.assertIn("no TOTAL line", msg)

    def test_negative_min_pass_rejected(self):
        code, msg = threshold_gate.gate(_CANONICAL_OUTPUT, min_pass=-1)
        self.assertEqual(code, 1)
        self.assertIn("min_pass", msg)

    def test_zero_min_pass_passes_when_total_zero(self):
        # Edge case — eval suite ran but every case failed; min_pass=0
        # should still pass (no minimum required).
        text = "  TOTAL                  0/5 passed   avg score 0.00\n"
        code, _msg = threshold_gate.gate(text, min_pass=0)
        self.assertEqual(code, 0)


class CliTests(unittest.TestCase):
    def test_main_returns_zero_on_pass(self):
        with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as fh:
            fh.write(_CANONICAL_OUTPUT)
            path = fh.name
        try:
            code = threshold_gate.main([path, "--min-pass", "4"])
        finally:
            Path(path).unlink()
        self.assertEqual(code, 0)

    def test_main_returns_one_on_fail(self):
        with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as fh:
            fh.write(_CANONICAL_FAILING)
            path = fh.name
        try:
            code = threshold_gate.main([path, "--min-pass", "4"])
        finally:
            Path(path).unlink()
        self.assertEqual(code, 1)

    def test_main_returns_two_on_missing_file(self):
        path = "/tmp/definitely-does-not-exist-92837.txt"
        code = threshold_gate.main([path, "--min-pass", "1"])
        self.assertEqual(code, 2)


if __name__ == "__main__":
    unittest.main()
