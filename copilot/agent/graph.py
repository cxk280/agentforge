"""Week 2 multi-agent graph — supervisor + intake-extractor + evidence-retriever + final-answer.

Layout:

    START
      │
      ▼
    supervisor ◄─────────────┐
      │                       │
      ├──▶ intake_extractor ──┤   (when pending_doc_uploads non-empty)
      │
      ├──▶ evidence_retriever ┤   (when latest user msg looks like a clinical/
      │                       │    guideline question and we haven't fetched yet)
      │
      └──▶ final_answer ──▶ END

The supervisor is a deterministic router today (state flags + a small
keyword-heuristic for "needs evidence?"). The plan documents a Day-4
upgrade to an LLM-driven supervisor with formal tool calls; nothing
about the streaming-bridge or the final_answer wiring needs to change
when that lands.

Handoffs are explicit:
- Every supervisor decision appends one entry to AgentState.handoff_log
  with {from, to, reason, ts}, so post-hoc trace inspection can answer
  "why did the supervisor route to evidence_retriever on turn 3?"
- Each handoff also emits a NDJSON `handoff` event the chat UI can
  render as a step indicator.
"""

from __future__ import annotations

import time
from typing import Any, AsyncIterator, Literal, TypedDict

from langgraph.graph import END, START, StateGraph

from agent import run_agent_stream


# ---------------------------------------------------------------------------
# State
# ---------------------------------------------------------------------------

class DocUpload(TypedDict, total=False):
    """A queued document for the intake extractor to pick up.

    Either document_id (preferred — the file lives in OpenEMR's
    `documents` table after the legacy upload pipe) or file_path
    (eval / demo only). doc_type is one of lab_pdf | intake_form |
    medication_list.
    """
    document_id: int
    file_path: str
    doc_type: str


class AgentState(TypedDict, total=False):
    # --- Conversation ---
    session_id: str
    patient_id: str
    fhir_patient_id: str
    active_user: str             # OpenEMR user identity (for trace attribution)
    messages: list[dict[str, Any]]

    # --- Worker inputs ---
    pending_doc_uploads: list[DocUpload]

    # --- Worker outputs (accumulate across turns) ---
    extracted_facts: list[dict[str, Any]]   # one entry per past extraction run
    evidence_chunks: list[dict[str, Any]]   # latest top-k from retriever

    # --- Per-turn flags so the supervisor doesn't re-dispatch on the same turn ---
    intake_done: bool
    evidence_done: bool

    # --- Critic (extension) ---
    retry_count: int          # number of times critic has bounced final_answer
    critic_pass: bool         # True once the critic has approved the reply
    critic_feedback: str      # last failure note, fed back to final_answer

    # --- Audit + streaming ---
    handoff_log: list[dict[str, Any]]
    events: list[dict[str, Any]]
    reply_text: str


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_EVIDENCE_KEYWORDS = (
    "guideline", "evidence", "recommend", "should", "target",
    "treatment", "indication", "contraindicat", "dose", "first-line",
    "preferred", "consider", "appropriate", "criteria",
)


def _needs_evidence(state: AgentState) -> bool:
    """Cheap keyword heuristic for when to consult the guideline corpus.

    Day-4 upgrade: replace with an LLM-driven supervisor that decides
    per-turn. The current heuristic errs on the side of NOT retrieving —
    plain lookup questions ("what meds is this patient on?") don't need
    guidelines, and pulling chunks for them just costs latency.
    """
    if state.get("evidence_done"):
        return False
    msgs = state.get("messages") or []
    if not msgs:
        return False
    last = msgs[-1]
    if last.get("role") != "user":
        return False
    text = str(last.get("content") or "").lower()
    return any(k in text for k in _EVIDENCE_KEYWORDS)


def _log_handoff(state: AgentState, *, from_node: str, to_node: str, reason: str) -> dict[str, Any]:
    return {
        "from": from_node,
        "to": to_node,
        "reason": reason,
        "ts": int(time.time() * 1000),
    }


def _user_query(state: AgentState) -> str:
    msgs = state.get("messages") or []
    if not msgs:
        return ""
    last = msgs[-1]
    if last.get("role") != "user":
        return ""
    return str(last.get("content") or "")


def _format_facts_context(extracted_facts: list[dict[str, Any]]) -> str:
    """Render accumulated extractions as a system-prompt snippet.

    Kept compact so it doesn't blow the context window for long sessions.
    """
    if not extracted_facts:
        return ""
    lines = ["Recently uploaded clinical documents (extracted on this session):"]
    for entry in extracted_facts[-3:]:
        lines.append(
            f"  · run {entry.get('run_id', '')[:8]} · {entry.get('doc_type')} · "
            f"{entry.get('fact_count', 0)} facts · schema_valid={entry.get('schema_valid')}"
        )
    return "\n".join(lines)


def _format_evidence_context(chunks: list[dict[str, Any]]) -> str:
    if not chunks:
        return ""
    lines = ["Guideline evidence retrieved for this turn (cite via source_id + page when used):"]
    for c in chunks:
        lines.append(
            f"  · [{c.get('source_id')} p{c.get('page')}, {c.get('section', '')}]\n"
            f"      {c.get('quote', '')}"
        )
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Worker: intake_extractor
# ---------------------------------------------------------------------------

async def intake_extractor_node(state: AgentState) -> AgentState:
    """Run attach_and_extract for any queued doc uploads.

    Each completed extraction appends to AgentState.extracted_facts so
    later turns see it without re-extracting.
    """
    pending: list[DocUpload] = list(state.get("pending_doc_uploads") or [])
    if not pending:
        return {**state, "intake_done": True}

    # Local imports to avoid pulling ingest deps unless this node fires.
    from fhir_client import get_db_pool
    from ingest.attach_and_extract import attach_and_extract

    pool = await get_db_pool()
    new_extractions: list[dict[str, Any]] = []
    new_events: list[dict[str, Any]] = list(state.get("events") or [])
    new_events.append({"type": "handoff", "from": "supervisor", "to": "intake_extractor",
                       "reason": f"{len(pending)} pending upload(s)"})

    for upload in pending:
        try:
            result = await attach_and_extract(
                patient_id=int(state["patient_id"]),
                doc_type=upload["doc_type"],
                document_id=upload.get("document_id"),
                file_path=upload.get("file_path"),
                pool=pool,
            )
            new_extractions.append({
                "run_id": result.run_id,
                "doc_type": result.doc_type,
                "document_id": result.document_id,
                "fact_count": result.fact_count,
                "citation_count": result.citation_count,
                "schema_valid": result.schema_valid,
                "latency_ms": result.latency_ms,
                "payload": result.payload,
            })
            new_events.append({
                "type": "extraction_done",
                "run_id": result.run_id,
                "doc_type": result.doc_type,
                "fact_count": result.fact_count,
                "schema_valid": result.schema_valid,
            })
        except Exception as exc:
            new_events.append({
                "type": "extraction_error",
                "doc_type": upload.get("doc_type"),
                "detail": str(exc)[:200],
            })

    return {
        **state,
        "extracted_facts": (state.get("extracted_facts") or []) + new_extractions,
        "pending_doc_uploads": [],     # consumed
        "intake_done": True,
        "events": new_events,
        "handoff_log": (state.get("handoff_log") or []) + [
            _log_handoff(state, from_node="supervisor", to_node="intake_extractor",
                         reason=f"{len(pending)} pending upload(s)"),
        ],
    }


# ---------------------------------------------------------------------------
# Worker: evidence_retriever
# ---------------------------------------------------------------------------

async def evidence_retriever_node(state: AgentState) -> AgentState:
    """Hybrid sparse+dense retrieval over the guideline corpus
    (BM25 + Voyage → RRF → optional Cohere Rerank).

    Caches its output in AgentState.evidence_chunks for the final-answer
    node and any subsequent supervisor decisions on this turn.
    """
    query = _user_query(state)
    new_events = list(state.get("events") or [])
    new_events.append({
        "type": "handoff", "from": "supervisor", "to": "evidence_retriever",
        "reason": "clinical-question keyword match",
    })

    if not query:
        return {**state, "evidence_done": True, "evidence_chunks": [], "events": new_events}

    from rag.retriever import search_with_meta
    bundle = search_with_meta(query, top_k=5)
    chunks = bundle["results"]
    meta = bundle["meta"]

    new_events.append({
        "type": "retrieval_hit",
        "n_results": len(chunks),
        "top_source_ids": [c["source_id"] for c in chunks[:3]],
        "retrieval_mode": meta.get("retrieval_mode"),
        "dense_enabled": meta.get("dense_enabled"),
        "dense_model": meta.get("dense_model"),
        "sparse_model": meta.get("sparse_model"),
        "fusion": meta.get("fusion"),
        "contributors": meta.get("contributors"),
        # Compact per-result provenance — { chunk_id: 'sparse'|'dense'|'both' } —
        # keeps the SSE payload small but lets the demo UI badge each chip.
        "result_sources": {c["chunk_id"]: c.get("source") for c in chunks},
    })

    return {
        **state,
        "evidence_chunks": chunks,
        "evidence_done": True,
        "events": new_events,
        "handoff_log": (state.get("handoff_log") or []) + [
            _log_handoff(state, from_node="supervisor", to_node="evidence_retriever",
                         reason="clinical-question keyword match"),
        ],
    }


# ---------------------------------------------------------------------------
# Worker: final_answer
# ---------------------------------------------------------------------------

async def final_answer_node(state: AgentState) -> AgentState:
    """Stream the final reply through the W1 single-loop, augmented with
    extraction + evidence context in the system prompt.

    Re-uses run_agent_stream so we keep tool-use, streaming, Langfuse
    spans, and rate-limit semantics that already work in /chat/stream.
    The W2-specific bit is the extra_system_context block, populated
    from AgentState.extracted_facts + AgentState.evidence_chunks.
    """
    fact_block = _format_facts_context(state.get("extracted_facts") or [])
    evidence_block = _format_evidence_context(state.get("evidence_chunks") or [])
    extra = "\n\n".join(b for b in (fact_block, evidence_block) if b)

    events = list(state.get("events") or [])
    events.append({"type": "handoff", "from": "supervisor", "to": "final_answer",
                   "reason": "ready to respond"})

    reply_parts: list[str] = []
    final_history = state.get("messages", [])

    async for event in run_agent_stream(
        state["fhir_patient_id"],
        state.get("messages", []),
        session_id=state.get("session_id", ""),
        user_id=state.get("active_user") or "anonymous",
        extra_system_context=extra,
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
        "handoff_log": (state.get("handoff_log") or []) + [
            _log_handoff(state, from_node="supervisor", to_node="final_answer",
                         reason="ready to respond"),
        ],
    }


# ---------------------------------------------------------------------------
# Worker: critic (extension)
# ---------------------------------------------------------------------------

# Hard cap on critic retries — one bounce, max. Without this the graph
# could loop indefinitely on an irreducible citation gap.
_MAX_CRITIC_RETRIES = 1


async def critic_node(state: AgentState) -> AgentState:
    """Reject uncited clinical claims and unsafe-action surfaces.

    Reuses the deterministic rubrics from `copilot.agent.evals.rubrics`
    so the critic and the eval gate are checking the same shape. If
    `citation_present` or `safe_refusal` fail (and we have retries
    remaining), append structured feedback to the messages list and
    route back to final_answer. Otherwise approve the reply and end.
    """
    reply = state.get("reply_text", "")
    events = list(state.get("events") or [])
    events.append({"type": "handoff", "from": "final_answer", "to": "critic",
                   "reason": "validate citations + safety on draft"})

    # Soft-import rubrics (the critic is optional and we don't want a
    # missing eval-suite checkout to break /chat/graph).
    try:
        try:
            from copilot.agent.evals.rubrics import (  # type: ignore
                citation_present, safe_refusal,
            )
        except ImportError:
            import sys
            from pathlib import Path as _P
            sys.path.insert(0, str(_P(__file__).resolve().parent / "evals"))
            from rubrics import citation_present, safe_refusal  # type: ignore[no-redef]
    except Exception as exc:
        events.append({"type": "critic_skip", "reason": f"rubrics import failed: {exc}"})
        return {**state, "critic_pass": True, "events": events,
                "handoff_log": (state.get("handoff_log") or []) + [
                    _log_handoff(state, from_node="final_answer", to_node="critic",
                                 reason="skipped — rubrics unavailable"),
                ]}

    # We synthesize a "case" so we can call the rubric functions directly.
    last_user = None
    for m in reversed(state.get("messages") or []):
        if m.get("role") == "user":
            last_user = m
            break
    is_refusal_target = False
    if last_user:
        text = str(last_user.get("content") or "").lower()
        is_refusal_target = any(p in text for p in (
            "falsify", "delete the diagnosis", "remove the diagnosis",
            "email", "send to", "share with my friend",
        ))
    synthetic_case = {
        "category": "refusal" if is_refusal_target else "clinical_lookup",
        "expected": {"must_refuse": is_refusal_target},
    }

    cit_result = citation_present(synthetic_case, reply)
    refusal_result = safe_refusal(synthetic_case, reply) if is_refusal_target else None

    failures: list[str] = []
    if not cit_result.passed:
        failures.append(f"citation_present: {cit_result.reason}")
    if refusal_result is not None and not refusal_result.passed:
        failures.append(f"safe_refusal: {refusal_result.reason}")

    retries_used = int(state.get("retry_count") or 0)
    if not failures:
        events.append({"type": "critic_pass"})
        return {
            **state,
            "critic_pass": True,
            "events": events,
            "handoff_log": (state.get("handoff_log") or []) + [
                _log_handoff(state, from_node="final_answer", to_node="critic",
                             reason="approved"),
            ],
        }

    if retries_used >= _MAX_CRITIC_RETRIES:
        events.append({"type": "critic_warn", "failures": failures,
                       "detail": "max retries exhausted; surfacing reply with warning"})
        return {
            **state,
            "critic_pass": False,
            "events": events,
            "handoff_log": (state.get("handoff_log") or []) + [
                _log_handoff(state, from_node="final_answer", to_node="critic",
                             reason="max retries — emit with warning"),
            ],
        }

    # Retry with structured feedback baked into the conversation.
    feedback = (
        "[Critic feedback — please revise the previous draft]\n"
        + "\n".join(f"• {f}" for f in failures)
        + "\n\nRevise the response to add citations for every clinical claim "
          "(name the tool used or include the source as Sources: …). "
          "Do not fabricate sources — if a claim has no support, omit it."
    )
    new_messages = list(state.get("messages") or []) + [
        # Synthetic user-role turn carrying the critic's feedback. We
        # use the user role because the assistant has already finished
        # its response in the prior turn; the next final_answer pass
        # will treat this as the new prompt to refine the answer.
        {"role": "user", "content": feedback},
    ]
    events.append({"type": "critic_retry", "failures": failures, "retries_used": retries_used + 1})
    return {
        **state,
        "messages": new_messages,
        "retry_count": retries_used + 1,
        "critic_pass": False,
        "critic_feedback": "\n".join(failures),
        "intake_done": True,        # don't re-extract on retry
        "evidence_done": True,      # don't re-retrieve on retry
        "events": events,
        "handoff_log": (state.get("handoff_log") or []) + [
            _log_handoff(state, from_node="final_answer", to_node="critic",
                         reason=f"retry {retries_used + 1} of {_MAX_CRITIC_RETRIES}"),
        ],
    }


def route_after_critic(state: AgentState) -> Literal["final_answer", "__end__"]:
    if state.get("critic_pass"):
        return END
    if (state.get("retry_count") or 0) > _MAX_CRITIC_RETRIES:
        return END
    if state.get("critic_feedback"):
        return "final_answer"
    return END


# ---------------------------------------------------------------------------
# Supervisor — deterministic router
# ---------------------------------------------------------------------------

async def supervisor_node(state: AgentState) -> AgentState:
    """No-op state pass-through; routing is handled by route_after_supervisor.

    Keeping a real node here (instead of routing straight from START)
    lets us emit a single 'supervisor' span in Langfuse that wraps the
    full graph turn, plus gives a stable hook for the Day-4 LLM-driven
    supervisor upgrade — the routing function changes, the node stays.
    """
    return state


def route_after_supervisor(
    state: AgentState,
) -> Literal["intake_extractor", "evidence_retriever", "final_answer"]:
    if state.get("pending_doc_uploads") and not state.get("intake_done"):
        return "intake_extractor"
    if _needs_evidence(state):
        return "evidence_retriever"
    return "final_answer"


# ---------------------------------------------------------------------------
# Graph
# ---------------------------------------------------------------------------

_compiled = None


def build_graph():
    global _compiled
    if _compiled is not None:
        return _compiled
    g = StateGraph(AgentState)
    g.add_node("supervisor", supervisor_node)
    g.add_node("intake_extractor", intake_extractor_node)
    g.add_node("evidence_retriever", evidence_retriever_node)
    g.add_node("final_answer", final_answer_node)
    g.add_node("critic", critic_node)

    g.add_edge(START, "supervisor")
    g.add_conditional_edges("supervisor", route_after_supervisor, {
        "intake_extractor": "intake_extractor",
        "evidence_retriever": "evidence_retriever",
        "final_answer": "final_answer",
    })
    # Workers loop back through the supervisor so it can re-dispatch
    # (e.g. after intake completes, supervisor may still want evidence).
    g.add_edge("intake_extractor", "supervisor")
    g.add_edge("evidence_retriever", "supervisor")
    # final_answer flows into the critic; critic either approves (END)
    # or bounces back to final_answer with feedback baked into messages.
    g.add_edge("final_answer", "critic")
    g.add_conditional_edges("critic", route_after_critic, {
        "final_answer": "final_answer",
        END: END,
    })
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
    active_user: str | None = None,
    pending_doc_uploads: list[DocUpload] | None = None,
    prior_extracted_facts: list[dict[str, Any]] | None = None,
) -> AsyncIterator[dict[str, Any]]:
    """One graph turn → stream of W1-compatible NDJSON events.

    Event types yielded today:
      handoff             — supervisor routing decision (W2-new)
      extraction_done     — intake_extractor finished a doc (W2-new)
      retrieval_hit       — evidence_retriever returned top-k (W2-new)
      tool_start          — W1 FHIR tool dispatch
      tool_end            — W1 FHIR tool completion
      delta               — W1 streaming text delta
      done                — final state, history attached for caller persistence
    """
    graph = build_graph()
    initial: AgentState = {
        "session_id": session_id,
        "patient_id": patient_id,
        "fhir_patient_id": fhir_patient_id,
        "active_user": active_user or "anonymous",
        "messages": list(messages),
        "pending_doc_uploads": list(pending_doc_uploads or []),
        "extracted_facts": list(prior_extracted_facts or []),
        "evidence_chunks": [],
        "intake_done": False,
        "evidence_done": False,
        "events": [],
        "handoff_log": [],
    }

    final_state: AgentState | None = None
    async for chunk in graph.astream(initial, stream_mode="values"):
        final_state = chunk

    if final_state is None:
        return

    for event in final_state.get("events", []):
        # The W1 'done' event's history is the canonical updated chat
        # history; surface it so the HTTP layer can persist it.
        yield event

    # Trailing summary for the caller — handoff trail + accumulated facts.
    yield {
        "type": "graph_summary",
        "handoff_log": final_state.get("handoff_log", []),
        "extracted_facts_count": len(final_state.get("extracted_facts") or []),
        "evidence_chunk_count": len(final_state.get("evidence_chunks") or []),
    }
