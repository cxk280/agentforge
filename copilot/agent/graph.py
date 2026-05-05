"""Week 2 multi-agent graph — minimal scaffolding.

This file is intentionally a thin wrapper around the existing W1
single-loop today. The shape — `AgentState`, the supervisor entry node,
the streaming bridge into the FastAPI NDJSON event format — is what we
will expand into the full supervisor + intake-extractor + evidence-
retriever + critic graph as Day 3 / Day 4 work lands.

What's here now:
  AgentState               — declared TypedDict the rest of the graph
                             will mutate
  agent_node               — single node that delegates to the W1
                             run_agent_stream(); preserves W1 streaming
                             semantics so the chat UI doesn't regress
  build_graph()            — compiles the StateGraph and caches it
  run_graph_stream(...)    — same yield contract as run_agent_stream:
                             tool_start / tool_end / delta / done

What lands later this week:
  • intake_extractor node  — calls attach_and_extract on
                             pending_doc_uploads, populates
                             extracted_facts_cache
  • evidence_retriever node — calls rag.retriever.search, populates
                              evidence_cache
  • critic node            — validates citations + safety on the draft
                             reply, loops back to the supervisor on
                             failure (max 1 retry)
  • supervisor routing     — replaces the no-op agent_node with a
                             tool-using router that decides which
                             worker to dispatch on each turn
"""

from __future__ import annotations

from typing import Any, AsyncIterator, TypedDict

from langgraph.graph import END, START, StateGraph

from agent import run_agent_stream


# ---------------------------------------------------------------------------
# State
# ---------------------------------------------------------------------------

class AgentState(TypedDict, total=False):
    """Carried across every node.

    Every field is total=False because the supervisor decides what
    is populated on a given turn — for instance, evidence_cache stays
    empty unless the supervisor routes to evidence_retriever.
    """

    # --- Conversation ---
    session_id: str
    patient_id: str
    messages: list[dict[str, Any]]            # Anthropic-format conversation
    fhir_patient_id: str                      # FHIR UUID (resolved from pid)

    # --- Day-3 worker outputs (placeholders today; populated by future nodes) ---
    pending_doc_uploads: list[dict[str, Any]]
    extracted_facts_cache: dict[str, Any]
    evidence_cache: dict[str, Any]
    citations: list[dict[str, Any]]

    # --- Routing audit trail ---
    handoff_log: list[dict[str, Any]]

    # --- Output drained by the streaming bridge ---
    events: list[dict[str, Any]]
    reply_text: str


# ---------------------------------------------------------------------------
# Nodes
# ---------------------------------------------------------------------------

async def agent_node(state: AgentState) -> AgentState:
    """Single supervisor node — for now, delegates to the W1 single loop.

    Drains the underlying run_agent_stream into state['events'] so the
    HTTP layer can replay them as NDJSON. State updates produced by W1
    (final history, reply text) are propagated back into AgentState.
    """
    events: list[dict[str, Any]] = list(state.get("events") or [])
    reply_parts: list[str] = []
    final_history = state.get("messages", [])

    async for event in run_agent_stream(
        state["fhir_patient_id"],
        state.get("messages", []),
        session_id=state.get("session_id", ""),
    ):
        events.append(event)
        if event.get("type") == "delta":
            reply_parts.append(event.get("text", ""))
        elif event.get("type") == "done":
            final_history = event.get("history", final_history)

    return {
        **state,
        "events": events,
        "reply_text": "".join(reply_parts),
        "messages": final_history,
        "handoff_log": [
            *(state.get("handoff_log") or []),
            {"from": "supervisor", "to": "agent_node", "reason": "w1_single_loop_passthrough"},
        ],
    }


# ---------------------------------------------------------------------------
# Graph
# ---------------------------------------------------------------------------

_compiled = None


def build_graph():
    """Compile + cache the StateGraph.

    Single-node today. As we add intake-extractor / evidence-retriever /
    critic, this is where they get wired in (and the agent_node turns
    into a routing supervisor).
    """
    global _compiled
    if _compiled is not None:
        return _compiled
    g = StateGraph(AgentState)
    g.add_node("agent", agent_node)
    g.add_edge(START, "agent")
    g.add_edge("agent", END)
    _compiled = g.compile()
    return _compiled


# ---------------------------------------------------------------------------
# Public streaming bridge
# ---------------------------------------------------------------------------

async def run_graph_stream(
    *,
    session_id: str,
    patient_id: str,
    fhir_patient_id: str,
    messages: list[dict[str, Any]],
) -> AsyncIterator[dict[str, Any]]:
    """Yield W1-compatible NDJSON events from a single graph turn.

    Today this is functionally identical to run_agent_stream(), but it
    runs through the LangGraph compiled state machine. Adding workers
    later turns this into the multi-agent path without changing the
    HTTP-level event contract.
    """
    graph = build_graph()
    initial: AgentState = {
        "session_id": session_id,
        "patient_id": patient_id,
        "fhir_patient_id": fhir_patient_id,
        "messages": list(messages),
        "events": [],
        "handoff_log": [],
        "pending_doc_uploads": [],
        "extracted_facts_cache": {},
        "evidence_cache": {},
        "citations": [],
    }

    final_state: AgentState | None = None
    async for chunk in graph.astream(initial, stream_mode="values"):
        final_state = chunk

    if final_state is None:
        return
    for event in final_state.get("events", []):
        yield event
