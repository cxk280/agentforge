"""Tests for tools._resolve_to_pid.

The agent's system prompt advertises the FHIR UUID (32-char hex) as
"Active patient ID", so Claude calls get_extracted_facts with that
UUID. cp_extracted_facts is keyed on the numeric OpenEMR pid; the
resolver bridges the two. Without it, /chat/graph silently returned
zero facts even though extraction had succeeded — the bug surfaced on
2026-05-09 in prod when an agent answer claimed "no extracted facts"
for a doc with 23 facts already in the DB.
"""

from __future__ import annotations

import asyncio
import os
import sys
import types
import unittest
from pathlib import Path

# Set required env so importing tools.py / config.py doesn't fail at
# Settings instantiation time.
os.environ.setdefault("ANTHROPIC_API_KEY", "test")
os.environ.setdefault("OPENEMR_BASE_URL", "http://localhost:8300")
os.environ.setdefault("OPENEMR_PASSWORD", "pass")
os.environ.setdefault("COPILOT_INTERNAL_TOKEN", "test")


def _install_fhir_client_stub() -> None:
    """Stub `fhir_client` so importing `tools` doesn't pull in
    aiomysql.

    CI's agent-unit-test executor (.circleci/config.yml) only installs
    rank-bm25 + pydantic. tools.py does
    `from fhir_client import fhir_get, bundle_entries` at module
    import time, and fhir_client.py imports aiomysql at the top —
    which 404s in CI. The stub satisfies the import without dragging
    in DB drivers; _resolve_to_pid takes its `pool` argument
    directly, so the test fakes a pool per-test rather than going
    through the stubbed module."""
    if "fhir_client" in sys.modules:
        return
    stub = types.ModuleType("fhir_client")

    async def _fhir_get(*a, **kw):  # noqa: D401, ARG001
        return {}

    def _bundle_entries(*a, **kw):  # noqa: ARG001
        return []

    async def _get_db_pool(*a, **kw):  # noqa: ARG001
        return None

    async def _resolve_patient(*a, **kw):  # noqa: ARG001
        return {"fhir_id": "stub", "fname": "stub", "lname": "stub"}

    stub.fhir_get = _fhir_get
    stub.bundle_entries = _bundle_entries
    stub.get_db_pool = _get_db_pool
    stub.resolve_patient = _resolve_patient
    sys.modules["fhir_client"] = stub


_install_fhir_client_stub()
sys.path.insert(0, str(Path(__file__).resolve().parent))

import tools  # noqa: E402


class _FakeCursor:
    """Async-context-manager cursor that records the SQL it was asked
    to execute and returns a canned row."""

    def __init__(self, rows_by_query):
        self._rows_by_query = rows_by_query
        self._last_row = None
        self.executed: list[tuple[str, list]] = []

    async def __aenter__(self):
        return self

    async def __aexit__(self, *a):
        return False

    async def execute(self, sql, params=None):
        self.executed.append((sql, list(params or [])))
        # Match by the lowercased uuid hex passed in params (the
        # _resolve_to_pid call is the only one the resolver uses).
        if params and len(params) == 1:
            self._last_row = self._rows_by_query.get(params[0])
        else:
            self._last_row = None

    async def fetchone(self):
        return self._last_row


class _FakeConn:
    def __init__(self, cursor):
        self._cursor = cursor

    async def __aenter__(self):
        return self

    async def __aexit__(self, *a):
        return False

    def cursor(self):
        return self._cursor


class _FakePool:
    def __init__(self, rows_by_query=None):
        self._cursor = _FakeCursor(rows_by_query or {})

    def acquire(self):
        return _FakeConn(self._cursor)


def _run(coro):
    return asyncio.run(coro)


class ResolveToPidTests(unittest.TestCase):
    def setUp(self):
        # Each test starts with a clean cache so cross-test order
        # doesn't matter.
        tools._FHIR_TO_PID.clear()

    def test_digit_string_returns_int_without_db(self):
        pool = _FakePool()  # no rows configured — DB must NOT be hit
        self.assertEqual(_run(tools._resolve_to_pid(pool, "8")), 8)
        self.assertEqual(pool._cursor.executed, [])

    def test_hex_uuid_resolves_via_db(self):
        # cp_extracted_facts is keyed on pid; FHIR UUID hex → pid 8
        uuid_hex = "abcd1234ef5678901234abcdef567890"
        pool = _FakePool(rows_by_query={uuid_hex: (8,)})
        self.assertEqual(_run(tools._resolve_to_pid(pool, uuid_hex)), 8)
        # Hits the DB exactly once.
        self.assertEqual(len(pool._cursor.executed), 1)

    def test_uuid_with_dashes_resolves(self):
        # Some callers may include UUID dashes — strip before lookup.
        uuid_hex = "abcd1234ef5678901234abcdef567890"
        dashed = "abcd1234-ef56-7890-1234-abcdef567890"
        pool = _FakePool(rows_by_query={uuid_hex: (8,)})
        self.assertEqual(_run(tools._resolve_to_pid(pool, dashed)), 8)

    def test_patient_resource_prefix_stripped(self):
        # Claude occasionally sends "Patient/<id>"; resolve still works.
        uuid_hex = "abcd1234ef5678901234abcdef567890"
        pool = _FakePool(rows_by_query={uuid_hex: (8,)})
        self.assertEqual(
            _run(tools._resolve_to_pid(pool, f"Patient/{uuid_hex}")),
            8,
        )

    def test_uppercase_hex_resolves(self):
        # _resolve_to_pid lowercases before querying so callers can
        # pass either case.
        uuid_hex = "ABCD1234EF5678901234ABCDEF567890"
        pool = _FakePool(rows_by_query={uuid_hex.lower(): (8,)})
        self.assertEqual(_run(tools._resolve_to_pid(pool, uuid_hex)), 8)

    def test_unknown_uuid_returns_none(self):
        pool = _FakePool(rows_by_query={})
        self.assertIsNone(_run(
            tools._resolve_to_pid(pool, "abcd1234ef5678901234abcdef567890")
        ))

    def test_non_hex_garbage_short_circuits_no_db(self):
        # "Patient/foo-bar" — not a valid hex UUID, must NOT hit the DB.
        pool = _FakePool()
        self.assertIsNone(_run(tools._resolve_to_pid(pool, "Patient/foo-bar")))
        self.assertEqual(pool._cursor.executed, [])

    def test_empty_string_returns_none(self):
        self.assertIsNone(_run(tools._resolve_to_pid(_FakePool(), "")))

    def test_cached_lookup_skips_db(self):
        uuid_hex = "abcd1234ef5678901234abcdef567890"
        pool = _FakePool(rows_by_query={uuid_hex: (8,)})
        self.assertEqual(_run(tools._resolve_to_pid(pool, uuid_hex)), 8)
        self.assertEqual(len(pool._cursor.executed), 1)
        # Second call must hit the cache, not the DB.
        self.assertEqual(_run(tools._resolve_to_pid(pool, uuid_hex)), 8)
        self.assertEqual(len(pool._cursor.executed), 1)


if __name__ == "__main__":
    unittest.main()
