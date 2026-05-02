"""Tests for phi_redaction.py.

Run directly: `python copilot/agent/test_phi_redaction.py`
Or via pytest if installed: `pytest copilot/agent/test_phi_redaction.py`
"""

from __future__ import annotations

import io
import logging
import unittest

import phi_redaction


class TestRedactPatterns(unittest.TestCase):
    """Direct tests of the redact() function — patterns only, no logging path."""

    def test_mrn_with_hash_and_long_digits(self):
        self.assertEqual(
            phi_redaction.redact("Patient MRN #004821 viewed"),
            "Patient MRN [REDACTED] viewed",
        )

    def test_mrn_with_hash_and_short_digits(self):
        # Demo dataset includes "MRN #001" — must be caught.
        self.assertEqual(
            phi_redaction.redact("Patient MRN #001 viewed"),
            "Patient MRN [REDACTED] viewed",
        )

    def test_mrn_no_separator(self):
        self.assertEqual(
            phi_redaction.redact("MRN12345 lookup"),
            "MRN [REDACTED] lookup",
        )

    def test_mrn_case_insensitive(self):
        self.assertEqual(
            phi_redaction.redact("mrn #4821"),
            "MRN [REDACTED]",
        )

    def test_mrn_word_with_no_digits_is_safe(self):
        # "MRN guide" without adjacent digits should not be touched.
        self.assertEqual(
            phi_redaction.redact("Chapter 12 of MRN guide"),
            "Chapter 12 of MRN guide",
        )

    def test_ssn(self):
        self.assertEqual(
            phi_redaction.redact("SSN 123-45-6789 found"),
            "SSN [SSN REDACTED] found",
        )

    def test_phone_parens_form(self):
        self.assertEqual(
            phi_redaction.redact("Call (512) 555-0142"),
            "Call [PHONE REDACTED]",
        )

    def test_phone_dash_form(self):
        self.assertEqual(
            phi_redaction.redact("Reach 512-555-0142"),
            "Reach [PHONE REDACTED]",
        )

    def test_phone_dot_form(self):
        self.assertEqual(
            phi_redaction.redact("Contact 512.555.0142 today"),
            "Contact [PHONE REDACTED] today",
        )

    def test_email(self):
        self.assertEqual(
            phi_redaction.redact("Email m.chen@example.com replied"),
            "Email [EMAIL REDACTED] replied",
        )

    def test_multiple_patterns_in_one_line(self):
        self.assertEqual(
            phi_redaction.redact("Pt MRN #001 SSN 555-12-3456 phone (512) 555-0142"),
            "Pt MRN [REDACTED] SSN [SSN REDACTED] phone [PHONE REDACTED]",
        )

    def test_no_phi_passthrough(self):
        self.assertEqual(
            phi_redaction.redact("Routine status check, no PHI"),
            "Routine status check, no PHI",
        )


class TestLogRecordFactory(unittest.TestCase):
    """Tests that install() actually scrubs records emitted via the logging module."""

    def setUp(self):
        # install() is idempotent and global — fine to call here.
        phi_redaction.install()
        self.buf = io.StringIO()
        self.handler = logging.StreamHandler(self.buf)
        self.handler.setFormatter(logging.Formatter("%(message)s"))
        self.root = logging.getLogger()
        self.root.addHandler(self.handler)
        self.root.setLevel(logging.DEBUG)

    def tearDown(self):
        self.root.removeHandler(self.handler)

    def _emit_and_read(self, logger_name: str, msg: str, *args) -> str:
        self.buf.seek(0)
        self.buf.truncate()
        logging.getLogger(logger_name).warning(msg, *args)
        return self.buf.getvalue().strip()

    def test_root_logger_is_scrubbed(self):
        self.assertEqual(
            self._emit_and_read("root_test", "Patient MRN #004821 lab"),
            "Patient MRN [REDACTED] lab",
        )

    def test_named_logger_is_scrubbed(self):
        # Named loggers (uvicorn.access, app.fhir, etc.) use the same
        # LogRecord factory, so they're scrubbed too.
        self.assertEqual(
            self._emit_and_read("uvicorn.access", "GET /api/MRN12345"),
            "GET /api/MRN [REDACTED]",
        )

    def test_args_substitution_is_scrubbed(self):
        # The PHI value is in args, not the format string. Scrub still applies
        # because we redact the *formatted* message after %-substitution.
        self.assertEqual(
            self._emit_and_read("any.logger", "patient %s contacted", "m.chen@example.com"),
            "patient [EMAIL REDACTED] contacted",
        )


if __name__ == "__main__":
    unittest.main(verbosity=2)
