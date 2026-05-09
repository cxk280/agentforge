"""Tests for the supervisor's deterministic router.

Locks in the routing contract for `route_after_supervisor` and
`route_after_critic` in graph.py — given a state shape, which worker
runs next? This is the "tightening proof" for supervisor routing
called out in the W2 reviewer feedback (2026-05-09).

The tests stub the `agent` module so graph.py imports without pulling
anthropic / observability into the agent-unit-test executor (CI
installs only rank-bm25; see .circleci/config.yml agent-unit-test job).

Run directly: `python copilot/agent/test_graph_routing.py`
"""

from __future__ import annotations

import sys
import types
import unittest
from pathlib import Path


def _install_stubs() -> None:
    """Inject no-op `agent` + `langgraph` + `observability` modules so
    graph.py imports without anthropic / langgraph / langfuse installed.

    The CI agent-unit-test executor only installs rank-bm25 + pydantic
    (see .circleci/config.yml). We exercise pure routing functions here —
    no real graph compilation, no real LLM calls, no real telemetry —
    so deterministic stubs are enough."""
    import contextlib

    agent_stub = types.ModuleType("agent")

    async def _run_agent_stream(*args, **kwargs):
        if False:
            yield {}

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


class RouteAfterSupervisorTests(unittest.TestCase):
    def test_empty_messages_routes_to_final_answer(self):
        self.assertEqual(graph.route_after_supervisor(_state()), "final_answer")

    def test_pending_uploads_routes_to_intake(self):
        s = _state(pending_doc_uploads=[{"doc_type": "lab_pdf", "document_id": 7}])
        self.assertEqual(graph.route_after_supervisor(s), "intake_extractor")

    def test_pending_uploads_after_intake_done_falls_through(self):
        s = _state(
            pending_doc_uploads=[{"doc_type": "lab_pdf", "document_id": 7}],
            intake_done=True,
        )
        self.assertEqual(graph.route_after_supervisor(s), "final_answer")

    def test_clinical_keyword_routes_to_evidence(self):
        s = _state(messages=[{"role": "user",
                              "content": "What is the A1c target for adults with diabetes?"}])
        self.assertEqual(graph.route_after_supervisor(s), "evidence_retriever")

    def test_record_lookup_does_not_route_to_evidence(self):
        s = _state(messages=[{"role": "user",
                              "content": "Show me the patient's current medications."}])
        self.assertEqual(graph.route_after_supervisor(s), "final_answer")

    def test_evidence_done_skips_retrieval(self):
        s = _state(
            messages=[{"role": "user", "content": "what's the recommended A1c target?"}],
            evidence_done=True,
        )
        self.assertEqual(graph.route_after_supervisor(s), "final_answer")

    def test_assistant_only_message_does_not_route_to_evidence(self):
        s = _state(messages=[{"role": "assistant", "content": "guideline target is 7%"}])
        self.assertEqual(graph.route_after_supervisor(s), "final_answer")

    def test_pending_uploads_take_priority_over_evidence(self):
        s = _state(
            pending_doc_uploads=[{"doc_type": "intake_form", "document_id": 1}],
            messages=[{"role": "user",
                       "content": "what does the guideline recommend for this dose?"}],
        )
        self.assertEqual(graph.route_after_supervisor(s), "intake_extractor")

    def test_keyword_match_is_case_insensitive(self):
        s = _state(messages=[{"role": "user", "content": "TREATMENT for stage-3 CKD?"}])
        self.assertEqual(graph.route_after_supervisor(s), "evidence_retriever")

    def test_partial_keyword_match_contraindicat_triggers(self):
        # `_EVIDENCE_KEYWORDS` includes the prefix "contraindicat" so both
        # "contraindicated" and "contraindication" trigger retrieval.
        s = _state(messages=[{"role": "user",
                              "content": "Is metformin contraindicated at eGFR 28?"}])
        self.assertEqual(graph.route_after_supervisor(s), "evidence_retriever")


class RouteAfterCriticTests(unittest.TestCase):
    def test_pass_ends_graph(self):
        from langgraph.graph import END
        self.assertEqual(graph.route_after_critic(_state(critic_pass=True)), END)

    def test_max_retries_ends_graph(self):
        from langgraph.graph import END
        s = _state(critic_pass=False, retry_count=graph._MAX_CRITIC_RETRIES + 1)
        self.assertEqual(graph.route_after_critic(s), END)

    def test_feedback_with_retries_left_loops_back(self):
        s = _state(critic_pass=False, retry_count=0,
                   critic_feedback="citation_present: missing")
        self.assertEqual(graph.route_after_critic(s), "final_answer")

    def test_no_feedback_ends_graph(self):
        from langgraph.graph import END
        s = _state(critic_pass=False, retry_count=0, critic_feedback="")
        self.assertEqual(graph.route_after_critic(s), END)


class NeedsEvidenceTests(unittest.TestCase):
    """Direct exercise of the `_needs_evidence` heuristic so a regression
    in the keyword list shows up here, not just downstream in routing."""

    def test_returns_false_for_empty_state(self):
        self.assertFalse(graph._needs_evidence(_state()))

    def test_returns_false_for_evidence_already_done(self):
        s = _state(messages=[{"role": "user", "content": "guideline target?"}],
                   evidence_done=True)
        self.assertFalse(graph._needs_evidence(s))

    def test_returns_true_on_first_keyword_match(self):
        for kw in graph._EVIDENCE_KEYWORDS:
            with self.subTest(keyword=kw):
                s = _state(messages=[{"role": "user",
                                      "content": f"does the {kw} apply here?"}])
                self.assertTrue(graph._needs_evidence(s),
                                msg=f"keyword {kw!r} should trigger retrieval")


if __name__ == "__main__":
    unittest.main()
