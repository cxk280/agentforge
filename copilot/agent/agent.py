"""Claude tool-use agent loop."""

import json
import anthropic
from tools import TOOL_SCHEMAS, TOOL_DISPATCH
from config import settings
from observability import (
    hash_id,
    span_generation,
    span_tool_call,
    trace_request,
)

client = anthropic.AsyncAnthropic(api_key=settings.anthropic_api_key)

_SYSTEM_BASE = """You are a Clinical Co-Pilot embedded in OpenEMR, an electronic health record system.

Your role is to help physicians quickly understand their patients by retrieving and summarizing \
real clinical data — medications, labs, vitals, conditions, allergies, and visit history.

Rules:
- The active patient ID is already set — use it directly when calling tools. Never ask the physician for a patient ID.
- Always retrieve data before making clinical statements. Never fabricate lab values, medications, or diagnoses.
- Cite your source for every clinical claim: name the tool and the data field (e.g. "per get_recent_labs: HbA1c 8.2% on 2024-11-10").
- When data is missing or a field is empty, say so explicitly. Do not infer or estimate.
- **Do not invent historical prescription changes (dose increases, switches, discontinuations, taper plans) unless the underlying tool call explicitly returns that history.** If asked about Rx changes and only current-state data is available, say so — do NOT manufacture a timeline.
- You do not write prescriptions, diagnose patients, or give treatment recommendations.
- Keep responses concise — the physician has 90 seconds between patient rooms. Aim for 2–3 sentences for narrative summaries; 4 sentences absolute max. Bulleted lists or tables are fine when explicitly requested.
- If a tool returns no results, report that clearly rather than speculating.

Format:
- Use markdown for structure: short paragraphs, bulleted lists, or `>` blockquotes for highlight rows.
- End every response that draws on tool data with a single line listing the data sources used, in this exact form:
  `Sources: <Source 1>, <Source 2>, ...`
  Use short human-readable labels — Vitals, Medications, Lab Results, Visit History, Conditions, Allergies — not raw tool names. Omit this line entirely when no tool was called."""


def _system_prompt(patient_id: str) -> str:
    return f"{_SYSTEM_BASE}\n\nActive patient ID: {patient_id}"


async def run_agent(
    patient_id: str,
    messages: list[dict],
    *,
    session_id: str = "",
    user_id: str = "anonymous",
) -> tuple[str, list[dict]]:
    """
    Run one turn of the agent loop.

    Returns (response_text, updated_messages).
    messages should be the full conversation history in Claude format.

    `session_id` and `user_id` correlate Langfuse traces across turns;
    `patient_id` is hashed before being included in trace metadata.
    """
    working_messages = list(messages)

    async with trace_request(
        name="copilot_chat_turn",
        session_id=session_id,
        user_id=user_id,
        patient_id=patient_id,
    ) as turn_span:
        loop_index = 0
        while True:
            loop_index += 1
            with span_generation(
                f"claude_messages_create_{loop_index}",
                model=settings.model,
                input_summary={
                    "messages_count": len(working_messages),
                    "tools_count": len(TOOL_SCHEMAS),
                },
            ) as gen:
                response = await client.messages.create(
                    model=settings.model,
                    max_tokens=1024,
                    system=_system_prompt(patient_id),
                    tools=TOOL_SCHEMAS,
                    messages=working_messages,
                )
                if gen is not None:
                    try:
                        gen.update(
                            usage_details={
                                "input": getattr(response.usage, "input_tokens", 0),
                                "output": getattr(response.usage, "output_tokens", 0),
                            },
                            output={"stop_reason": response.stop_reason},
                        )
                    except Exception:
                        pass

            # Append assistant turn to history
            working_messages.append({"role": "assistant", "content": response.content})

            if response.stop_reason == "end_turn":
                text = _extract_text(response.content)
                if turn_span is not None:
                    try:
                        turn_span.update(
                            output={
                                "stop_reason": "end_turn",
                                "loops": loop_index,
                                "reply_length": len(text),
                            }
                        )
                    except Exception:
                        pass
                return text, working_messages

            if response.stop_reason == "tool_use":
                tool_results = await _execute_tools(response.content, patient_id)
                working_messages.append({"role": "user", "content": tool_results})
                continue

            # Unexpected stop reason — surface it
            if turn_span is not None:
                try:
                    turn_span.update(
                        level="WARNING",
                        output={"stop_reason": response.stop_reason, "loops": loop_index},
                    )
                except Exception:
                    pass
            return f"[Agent stopped: {response.stop_reason}]", working_messages


async def _execute_tools(content: list, patient_id: str) -> list[dict]:
    """Run all tool_use blocks in parallel and return tool_result blocks."""
    import asyncio

    tool_blocks = [b for b in content if b.type == "tool_use"]

    async def call_one(block) -> dict:
        with span_tool_call(block.name) as span:
            tool_fn = TOOL_DISPATCH.get(block.name)
            error_type: str | None = None
            success = True
            if tool_fn is None:
                result = {"error": f"Unknown tool: {block.name}"}
                error_type = "unknown_tool"
                success = False
            else:
                try:
                    inputs = dict(block.input)
                    # Enforce the active patient — tool cannot query other patients
                    inputs["patient_id"] = patient_id
                    result = await tool_fn(**inputs)
                    if isinstance(result, dict) and "error" in result:
                        error_type = "tool_error"
                        success = False
                except Exception as exc:
                    result = {"error": str(exc)}
                    error_type = type(exc).__name__
                    success = False

            # Record outcome (no PHI — only counts and error types)
            if span is not None:
                try:
                    output_summary: dict = {"success": success}
                    if error_type:
                        output_summary["error_type"] = error_type
                    if isinstance(result, dict) and not error_type:
                        # Just the shape, never the values
                        output_summary["result_keys"] = list(result.keys())
                    span.update(
                        output=output_summary,
                        level="ERROR" if not success else "DEFAULT",
                    )
                except Exception:
                    pass

        return {
            "type": "tool_result",
            "tool_use_id": block.id,
            "content": json.dumps(result),
        }

    return await asyncio.gather(*[call_one(b) for b in tool_blocks])


def _extract_text(content: list) -> str:
    parts = [b.text for b in content if hasattr(b, "text")]
    return "\n".join(parts)
