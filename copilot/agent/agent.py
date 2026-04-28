"""Claude tool-use agent loop."""

import json
import anthropic
from tools import TOOL_SCHEMAS, TOOL_DISPATCH
from config import settings

client = anthropic.AsyncAnthropic(api_key=settings.anthropic_api_key)

_SYSTEM_BASE = """You are a Clinical Co-Pilot embedded in OpenEMR, an electronic health record system.

Your role is to help physicians quickly understand their patients by retrieving and summarizing \
real clinical data — medications, labs, vitals, conditions, allergies, and visit history.

Rules:
- The active patient ID is already set — use it directly when calling tools. Never ask the physician for a patient ID.
- Always retrieve data before making clinical statements. Never fabricate lab values, medications, or diagnoses.
- Cite your source for every clinical claim: name the tool and the data field (e.g. "per get_recent_labs: HbA1c 8.2% on 2024-11-10").
- When data is missing or a field is empty, say so explicitly. Do not infer or estimate.
- You do not write prescriptions, diagnose patients, or give treatment recommendations.
- Keep responses concise — the physician has 90 seconds between patient rooms.
- If a tool returns no results, report that clearly rather than speculating."""


def _system_prompt(patient_id: str) -> str:
    return f"{_SYSTEM_BASE}\n\nActive patient ID: {patient_id}"


async def run_agent(
    patient_id: str,
    messages: list[dict],
) -> tuple[str, list[dict]]:
    """
    Run one turn of the agent loop.

    Returns (response_text, updated_messages).
    messages should be the full conversation history in Claude format.
    """
    working_messages = list(messages)

    while True:
        response = await client.messages.create(
            model=settings.model,
            max_tokens=1024,
            system=_system_prompt(patient_id),
            tools=TOOL_SCHEMAS,
            messages=working_messages,
        )

        # Append assistant turn to history
        working_messages.append({"role": "assistant", "content": response.content})

        if response.stop_reason == "end_turn":
            text = _extract_text(response.content)
            return text, working_messages

        if response.stop_reason == "tool_use":
            tool_results = await _execute_tools(response.content, patient_id)
            working_messages.append({"role": "user", "content": tool_results})
            continue

        # Unexpected stop reason — surface it
        return f"[Agent stopped: {response.stop_reason}]", working_messages


async def _execute_tools(content: list, patient_id: str) -> list[dict]:
    """Run all tool_use blocks in parallel and return tool_result blocks."""
    import asyncio

    tool_blocks = [b for b in content if b.type == "tool_use"]

    async def call_one(block) -> dict:
        tool_fn = TOOL_DISPATCH.get(block.name)
        if tool_fn is None:
            result = {"error": f"Unknown tool: {block.name}"}
        else:
            try:
                inputs = dict(block.input)
                # Enforce the active patient — tool cannot query other patients
                inputs["patient_id"] = patient_id
                result = await tool_fn(**inputs)
            except Exception as exc:
                result = {"error": str(exc)}

        return {
            "type": "tool_result",
            "tool_use_id": block.id,
            "content": json.dumps(result),
        }

    return await asyncio.gather(*[call_one(b) for b in tool_blocks])


def _extract_text(content: list) -> str:
    parts = [b.text for b in content if hasattr(b, "text")]
    return "\n".join(parts)
