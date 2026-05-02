"""Logging filter that scrubs PHI patterns from log records before emit.

Attached to the root logger at startup so every log line — uvicorn access,
uvicorn error, app, NR agent, third-party libs — passes through here on its
way to stderr (and from there to NR's log forwarder). Scrubbing at the
source means PHI never crosses the wire to NR or any other log sink, which
is tighter than NR-side obfuscation rules and works regardless of NR plan
tier.

Patterns covered:
- OpenEMR-style MRN identifiers (`MRN #004821`, `MRN12345`)
- US SSN (`123-45-6789`)
- US phone numbers (`(512) 555-0142`, `512-555-0142`)
- Email addresses

Patterns NOT covered (deliberately):
- Patient names: would need a name list and risk false positives on words
  like "John" appearing in non-PHI context.
- Dates of birth: `01/14/1958` overlaps with too many other date uses
  (visit dates, lab dates) — would over-mask.
- Free-form narrative PHI: addresses, conditions, etc.

Per the README "Compliance & HIPAA" section, this is one layer of defense
behind the BAA assumption — it is not sufficient on its own for production
PHI handling, but it dramatically reduces casual leakage through log
forwarding.
"""

from __future__ import annotations

import logging
import re

# Patterns are applied in order; longer/more-specific ones first so they
# can't be partially eaten by a shorter pattern that fires earlier.
_REDACTIONS: tuple[tuple[re.Pattern[str], str], ...] = (
    # OpenEMR-style MRN: "MRN #004821", "MRN 12345", "MRN12345", "MRN #001"
    # Allow 1-8 digits because the demo dataset includes short MRNs like "MRN #001".
    (re.compile(r"MRN[\s#]*\d{1,8}", re.IGNORECASE), "MRN [REDACTED]"),
    # US SSN
    (re.compile(r"\b\d{3}-\d{2}-\d{4}\b"), "[SSN REDACTED]"),
    # US phone: (512) 555-0142 | 512.555.0142 | 512-555-0142 | 512 555 0142
    (re.compile(r"\(?\b\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}\b"), "[PHONE REDACTED]"),
    # Email
    (re.compile(r"\b[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}\b"), "[EMAIL REDACTED]"),
)


def redact(text: str) -> str:
    """Apply every PHI redaction pattern to `text` and return the scrubbed string."""
    for pattern, replacement in _REDACTIONS:
        text = pattern.sub(replacement, text)
    return text


_INSTALLED = False


def install() -> None:
    """Replace the LogRecord factory so every log record everywhere is pre-scrubbed.

    We install at the LogRecord factory level (rather than as a Filter on a
    specific logger) so the scrub applies uniformly across every named logger
    in the process — uvicorn.access, uvicorn.error, app code, the NR Python
    agent, and any third-party library that uses Python logging — without
    needing to walk the logger tree at startup or hook future logger
    creation. Filters attached to a single logger only see records logged
    directly to that logger; records from child loggers propagate up but
    are not filtered by ancestor-logger filters, which is the common
    footgun this avoids.

    Idempotent: safe to call multiple times.
    """
    global _INSTALLED
    if _INSTALLED:
        return

    previous_factory = logging.getLogRecordFactory()

    def scrubbing_factory(*args, **kwargs):  # type: ignore[no-untyped-def]
        record = previous_factory(*args, **kwargs)
        try:
            scrubbed = redact(record.getMessage())
        except Exception:
            # Formatter blowups (e.g. mismatched %-args) shouldn't suppress
            # the log line — fall back to scrubbing the raw msg field.
            scrubbed = redact(str(record.msg))
        record.msg = scrubbed
        record.args = ()
        return record

    logging.setLogRecordFactory(scrubbing_factory)
    _INSTALLED = True
