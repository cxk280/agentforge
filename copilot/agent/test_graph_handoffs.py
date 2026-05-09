"""Tests for the worker-handoff schema (HandoffLogEntry).

Locks in the audit-log contract for AgentState.handoff_log called out
in the W2 reviewer feedback (2026-05-09). Two-pronged:

  1. Direct schema tests — HandoffLogEntry rejects malformed entries.
  2. Worker-emission tests — drive each worker through a unit-test
     invocation and assert every handoff_log entry it emits validates
     against the schema.

Because `_log_handoff()` is the sole funnel for handoff_log appends
(verified by `grep handoff_log graph.py` — every append wraps a
_log_handoff() call), routing through the Pydantic model means every
emitted entry is valid by construction. The worker tests just verify
that property end-to-end.
"""

from __future__ import annotations

import asyncio
import sys
import types
import unittest
from pathlib import Path


def _install_stubs() -> None:
    """Stub `agent` + `langgraph` + `observability` so graph.py imports
    in CI's agent-unit-test executor (which only has rank-bm25 +
    pydantic).

    `rag.retriever` is intentionally NOT stubbed at the module level —
    that would leak across test modules (test_retriever_hybrid /
    test_retriever_rerank both import the real retriever). Tests that
    need to control retriever output do it via a per-test
    monkey-patch (see _patch_search_with_meta below)."""
    import contextlib

    agent_stub = types.ModuleType("agent")

    async def _run_agent_stream(*args, **kwargs):
        yield {"type": "delta", "text": "stub"}
        yield {"type": "done", "history": []}

    agent_stub.run_agent_stream = _run_agent_stream
    sys.modules["agent"] = agent_stub

    lg = types.ModuleType("langgraph")
    lg_graph = types.ModuleType("langgraph.graph")
    lg_graph.END = "__end__"
    lg_graph.START = "__start__"

    class _StateGraph:
        def __init__(self, *a, **kw): pass
        def add_node(self, *a, **kw): pass
        def add_edge(self, *a, **kw): pass
        def add_conditional_edges(self, *a, **kw): pass
        def compile(self): return self

    lg_graph.StateGraph = _StateGraph
    sys.modules["langgraph"] = lg
    sys.modules["langgraph.graph"] = lg_graph

    obs = types.ModuleType("observability")

    @contextlib.asynccontextmanager
    async def _trace_request(*args, **kwargs):
        yield None

    @contextlib.contextmanager
    def _span_graph_node(*args, **kwargs):
        yield None

    obs.trace_request = _trace_request
    obs.span_graph_node = _span_graph_node
    sys.modules["observability"] = obs


_install_stubs()
sys.path.insert(0, str(Path(__file__).resolve().parent))
import graph  # noqa: E402
from pydantic import ValidationError  # noqa: E402


def _fake_search_with_meta(query, *, top_k=5, candidate_pool=30):
    return {
        "results": [{
            "chunk_id": "ada-2024-9",
            "source_id": "ADA-2024",
            "page": 12,
            "section": "Glycemic Targets",
            "quote": "A1c target <7.0% for most non-pregnant adults.",
            "score": 0.9,
            "rerank_score": 0.9,
            "rrf_score": 0.0167,
            "bm25_score": 1.4,
            "dense_score": 0.7,
            "source": "both",
        }],
        "meta": {
            "retrieval_mode": "hybrid_sparse_dense",
            "dense_enabled": True,
            "dense_model": "voyage-3",
            "sparse_model": "bm25-okapi",
            "fusion": "rrf",
            "contributors": ["both"],
        },
    }


def _state(**overrides) -> dict:
    base = {
        "session_id": "s",
        "patient_id": "1",
        "fhir_patient_id": "Patient/1",
        "messages": [],
        "pending_doc_uploads": [],
        "extracted_facts": [],
        "evidence_chunks": [],
        "intake_done": False,
        "evidence_done": False,
        "events": [],
        "handoff_log": [],
    }
    base.update(overrides)
    return base


def _validate_log(entries: list[dict]) -> list[graph.HandoffLogEntry]:
    """Re-validate every entry through the Pydantic model. Raises
    ValidationError if any entry is malformed."""
    return [graph.HandoffLogEntry.model_validate(e) for e in entries]


class HandoffLogEntrySchemaTests(unittest.TestCase):
    """Direct exercise of the Pydantic model — fast, no graph import."""

    def test_canonical_entry_validates(self):
        entry = graph.HandoffLogEntry.model_validate({
            "from": "supervisor", "to": "evidence_retriever",
            "reason": "clinical query", "ts": 1715200000000,
        })
        self.assertEqual(entry.from_node, "supervisor")
        self.assertEqual(entry.to_node, "evidence_retriever")

    def test_dump_round_trips_to_canonical_dict(self):
        entry = graph.HandoffLogEntry(from_node="a", to_node="b",
                                      reason="r", ts=1)
        self.assertEqual(entry.model_dump(by_alias=True),
                         {"from": "a", "to": "b", "reason": "r", "ts": 1})

    def test_empty_strings_rejected(self):
        for field in ("from", "to", "reason"):
            with self.subTest(field=field):
                payload = {"from": "a", "to": "b", "reason": "r", "ts": 1}
                payload[field] = ""
                with self.assertRaises(ValidationError):
                    graph.HandoffLogEntry.model_validate(payload)

    def test_negative_timestamp_rejected(self):
        with self.assertRaises(ValidationError):
            graph.HandoffLogEntry.model_validate(
                {"from": "a", "to": "b", "reason": "r", "ts": 0}
            )

    def test_extra_fields_rejected(self):
        with self.assertRaises(ValidationError):
            graph.HandoffLogEntry.model_validate(
                {"from": "a", "to": "b", "reason": "r", "ts": 1,
                 "rogue": "field"}
            )

    def test_log_handoff_helper_emits_valid_dict(self):
        entry_dict = graph._log_handoff(
            _state(), from_node="supervisor", to_node="final_answer",
            reason="ready",
        )
        # The helper must return a plain dict (not a model) so existing
        # consumers that iterate handoff_log keep working.
        self.assertIsInstance(entry_dict, dict)
        self.assertEqual(set(entry_dict.keys()), {"from", "to", "reason", "ts"})
        # And the dict must round-trip through the schema.
        graph.HandoffLogEntry.model_validate(entry_dict)


class WorkerHandoffEmissionTests(unittest.TestCase):
    """Drive worker nodes with stubbed externals. Each worker that
    appends to handoff_log must produce schema-valid entries."""

    def setUp(self):
        # evidence_retriever_node does `from rag.retriever import search_with_meta`
        # at call-time. Install a transient stub for the duration of this
        # test class only — removing it in tearDown so test_retriever_*
        # modules can still import the real rag.retriever afterwards.
        self._saved_rag = sys.modules.get("rag")
        self._saved_rag_retriever = sys.modules.get("rag.retriever")
        rag_pkg = types.ModuleType("rag")
        rag_retriever = types.ModuleType("rag.retriever")
        rag_retriever.search_with_meta = _fake_search_with_meta
        rag_pkg.retriever = rag_retriever
        sys.modules["rag"] = rag_pkg
        sys.modules["rag.retriever"] = rag_retriever

    def tearDown(self):
        for name, saved in (("rag", self._saved_rag),
                            ("rag.retriever", self._saved_rag_retriever)):
            if saved is None:
                sys.modules.pop(name, None)
            else:
                sys.modules[name] = saved

    def _run(self, coro):
        return asyncio.run(coro)

    def test_evidence_retriever_emits_valid_entry(self):
        s = _state(messages=[{"role": "user",
                              "content": "what is the recommended A1c target?"}])
        out = self._run(graph.evidence_retriever_node(s))
        self.assertEqual(len(out["handoff_log"]), 1)
        entries = _validate_log(out["handoff_log"])
        self.assertEqual(entries[0].from_node, "supervisor")
        self.assertEqual(entries[0].to_node, "evidence_retriever")
        self.assertGreater(len(entries[0].reason), 0)

    def test_evidence_retriever_empty_query_skips_log(self):
        # Empty user query → early return without handoff log entry.
        s = _state(messages=[{"role": "assistant", "content": "hi"}])
        out = self._run(graph.evidence_retriever_node(s))
        self.assertEqual(out["handoff_log"], [])

    def test_final_answer_emits_valid_entry(self):
        s = _state(messages=[{"role": "user", "content": "summarize meds"}])
        out = self._run(graph.final_answer_node(s))
        self.assertEqual(len(out["handoff_log"]), 1)
        entries = _validate_log(out["handoff_log"])
        self.assertEqual(entries[0].from_node, "supervisor")
        self.assertEqual(entries[0].to_node, "final_answer")

    def test_critic_pass_emits_valid_entry(self):
        # Critic considers the reply cited iff `citation_present` rubric
        # passes. A reply with a Sources: line + an inline tool ref is
        # cited under the deterministic rubric.
        cited_reply = (
            "Patient is on metformin per get_medications: 500mg BID.\n"
            "Sources: Medications"
        )
        s = _state(
            messages=[{"role": "user", "content": "what meds is the patient on?"}],
            reply_text=cited_reply,
        )
        out = self._run(graph.critic_node(s))
        self.assertGreaterEqual(len(out["handoff_log"]), 1)
        _validate_log(out["handoff_log"])  # raises if any entry is bad

    def test_critic_retry_emits_valid_entry(self):
        # Reply with a clinical claim but no citations → critic should
        # bounce back to final_answer with a retry handoff.
        uncited = "The patient's HbA1c is 8.2% — start metformin 500mg BID."
        s = _state(
            messages=[{"role": "user", "content": "what should we do?"}],
            reply_text=uncited,
        )
        out = self._run(graph.critic_node(s))
        # Critic emitted at least one handoff entry; all must validate.
        self.assertGreaterEqual(len(out["handoff_log"]), 1)
        _validate_log(out["handoff_log"])
        # And the retry path must mention the retry in the reason.
        last = out["handoff_log"][-1]
        self.assertIn("retry", last["reason"].lower())

    def test_critic_max_retries_clears_feedback(self):
        # Regression: when retries are exhausted, critic_node took the
        # warning path but left `critic_feedback` set. route_after_critic
        # then routed back to final_answer (because its predicate
        # `if state.get("critic_feedback")` was True), causing
        # final_answer to be invoked a 3rd time with messages ending in
        # `assistant` — Anthropic 400'd as "model does not support
        # assistant message prefill". 2026-05-09. critic_node now
        # explicitly clears critic_feedback in the warning path.
        uncited = "The patient's HbA1c is 8.2% — start metformin 500mg BID."
        s = _state(
            messages=[{"role": "user", "content": "what should we do?"}],
            reply_text=uncited,
            retry_count=graph._MAX_CRITIC_RETRIES,
            critic_feedback="citation_required: stale from prior bounce",
        )
        out = self._run(graph.critic_node(s))
        self.assertEqual(out["critic_feedback"], "",
                         "critic must clear feedback when retries are "
                         "exhausted so route_after_critic ends the graph")
        self.assertFalse(out["critic_pass"])


if __name__ == "__main__":
    unittest.main()
