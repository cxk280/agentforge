"""Langfuse observability helpers.

Per ARCHITECTURE.md: every request creates a trace with spans for session
validation, tool calls, Claude API calls, and verification. PHI is never
included in trace payloads — tool call arguments log the FHIR resource
type and ID hash, not the content.

Falls back to a no-op when Langfuse credentials aren't configured so the
agent still runs locally without a Langfuse account.
"""

from __future__ import annotations

import hashlib
import logging
import os
from contextlib import contextmanager, asynccontextmanager
from typing import Any, AsyncIterator, Iterator, Optional

from config import settings

log = logging.getLogger(__name__)

_initialized = False
_client: Any = None


def _init() -> None:
    global _initialized, _client
    if _initialized:
        return
    _initialized = True

    if not (settings.langfuse_public_key and settings.langfuse_secret_key):
        log.info("Langfuse keys not configured — observability disabled")
        return

    # Langfuse SDK reads from env vars; populate them from settings so the
    # rest of the SDK works without us holding onto a client instance.
    os.environ.setdefault("LANGFUSE_PUBLIC_KEY", settings.langfuse_public_key)
    os.environ.setdefault("LANGFUSE_SECRET_KEY", settings.langfuse_secret_key)
    os.environ.setdefault("LANGFUSE_HOST", settings.langfuse_host)

    try:
        from langfuse import get_client  # type: ignore[import-not-found]

        _client = get_client()
        log.info("Langfuse initialized: host=%s", settings.langfuse_host)
    except Exception as exc:  # pragma: no cover — defensive
        log.warning("Langfuse init failed: %s", exc)
        _client = None


def get_langfuse() -> Any:
    """Return the singleton Langfuse client, or None when disabled."""
    if not _initialized:
        _init()
    return _client


def hash_id(value: str | int | None) -> str:
    """One-way truncated SHA-256.

    PHI constraint: never log raw patient or user IDs. The hash is stable
    for a given input so traces can be correlated across spans without
    exposing identifiers.
    """
    if value is None or value == "":
        return ""
    return hashlib.sha256(str(value).encode("utf-8")).hexdigest()[:12]


@asynccontextmanager
async def trace_request(
    *,
    name: str,
    session_id: str,
    user_id: str,
    patient_id: str,
    extra: Optional[dict] = None,
) -> AsyncIterator[Any]:
    """Async context manager that opens a top-level trace for one chat turn.

    Yields the Langfuse observation (or None when disabled) so callers
    can call `span.update(output=...)` before the trace closes.
    """
    lf = get_langfuse()
    if lf is None:
        yield None
        return

    metadata = {
        "patient_id_hash": hash_id(patient_id),
        "model": settings.model,
    }
    if extra:
        metadata.update(extra)

    # Langfuse v4 unifies spans and generations under
    # start_as_current_observation. as_type="span" for the request root.
    with lf.start_as_current_observation(
        name=name,
        as_type="span",
        input={"messages_in_request": True},  # boolean, not the content
    ) as span:
        try:
            lf.update_current_trace(
                user_id=user_id or "anonymous",
                session_id=session_id,
                metadata=metadata,
            )
        except Exception as exc:  # pragma: no cover
            log.debug("update_current_trace failed: %s", exc)
        yield span


@contextmanager
def span_tool_call(tool_name: str, *, fhir_resource: str = "") -> Iterator[Any]:
    """Span around a single tool execution.

    Records the tool name and (optional) FHIR resource type queried —
    never the patient data the tool returned.
    """
    lf = get_langfuse()
    if lf is None:
        yield None
        return

    inputs = {"tool": tool_name}
    if fhir_resource:
        inputs["fhir_resource"] = fhir_resource

    with lf.start_as_current_observation(
        name=f"tool:{tool_name}",
        as_type="span",
        input=inputs,
    ) as span:
        yield span


@contextmanager
def span_generation(
    name: str,
    *,
    model: str,
    input_summary: Optional[dict] = None,
) -> Iterator[Any]:
    """Span around a Claude model call.

    Records the model name and a non-PHI summary of the inputs (counts,
    not content). Token usage is recorded by the caller via
    `span.update(usage_details={...})` once the response arrives.
    """
    lf = get_langfuse()
    if lf is None:
        yield None
        return

    with lf.start_as_current_observation(
        name=name,
        as_type="generation",
        model=model,
        input=input_summary or {},
    ) as gen:
        yield gen


@contextmanager
def span_graph_node(name: str) -> Iterator[Any]:
    """Span around a single LangGraph worker node invocation.

    Wrap the body of supervisor_node / intake_extractor_node /
    evidence_retriever_node / final_answer_node / critic_node so the
    supervisor → worker structure shows up in Langfuse as nested
    observations under the request-level trace (see trace_request).

    Without this the trace tree was flat — only the inner
    `copilot_chat_turn_stream` span (opened by run_agent_stream inside
    final_answer_node) was visible, and reviewers couldn't see which
    worker fired or in what order.
    """
    lf = get_langfuse()
    if lf is None:
        yield None
        return
    with lf.start_as_current_observation(
        name=f"node:{name}",
        as_type="span",
        input={"node": name},
    ) as span:
        yield span


def flush() -> None:
    """Force-flush queued events. Call at process shutdown."""
    lf = get_langfuse()
    if lf is None:
        return
    try:
        lf.flush()
    except Exception as exc:  # pragma: no cover
        log.debug("Langfuse flush failed: %s", exc)
