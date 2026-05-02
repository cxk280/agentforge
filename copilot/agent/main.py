"""FastAPI entry point for the Clinical Co-Pilot agent."""

import json
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, StreamingResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

from agent import run_agent, run_agent_stream
from fhir_client import fhir_get, bundle_entries, aclose_http_client
from observability import flush as langfuse_flush

STATIC_DIR = Path(__file__).parent / "static"


@asynccontextmanager
async def lifespan(app: FastAPI):
    yield
    # Drain queued Langfuse events on shutdown so traces aren't lost
    langfuse_flush()
    # Close the shared httpx client cleanly so keep-alive connections
    # are torn down rather than orphaned at SIGTERM.
    await aclose_http_client()


app = FastAPI(title="Clinical Co-Pilot", lifespan=lifespan)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # tighten to OpenEMR origin in production
    allow_methods=["POST", "GET", "DELETE"],
    allow_headers=["*"],
)

# In-memory session store: session_id → message history
# Replace with Redis for multi-worker deployments
_sessions: dict[str, list[dict]] = {}

# pid → FHIR UUID cache. The mapping is global (a given pid resolves to
# the same UUID for the life of the patient_data row), so caching across
# sessions is safe and saves a DB + FHIR lookup on every chat turn.
_pid_to_fhir_id: dict[str, str] = {}


# ── Models ────────────────────────────────────────────────────────────────

class ChatRequest(BaseModel):
    session_id: str
    patient_id: str
    message: str


class ChatResponse(BaseModel):
    session_id: str
    patient_id: str
    reply: str
    history_length: int


# ── Routes ────────────────────────────────────────────────────────────────

@app.get("/health")
async def health():
    return {"status": "ok"}


@app.get("/")
async def serve_chat_ui():
    """Serve the chat UI. Pass ?pid=<openemr_pid> to pre-load a patient."""
    return FileResponse(STATIC_DIR / "chat.html")


@app.get("/api/patient-fhir-id/{pid}")
async def resolve_patient(pid: str):
    """Resolve an OpenEMR internal pid (integer) to a FHIR UUID and name.

    Uses a direct DB lookup (reliable) then fetches the name from FHIR.
    """
    import aiomysql
    from config import settings

    try:
        # Direct DB lookup: pid → UUID (binary 16 → hex string)
        conn = await aiomysql.connect(
            host=settings.db_host,
            port=settings.db_port,
            user=settings.db_user,
            password=settings.db_password,
            db=settings.db_name,
        )
        async with conn.cursor() as cur:
            await cur.execute(
                "SELECT HEX(uuid), fname, lname FROM patient_data WHERE pid = %s",
                (int(pid),),
            )
            row = await cur.fetchone()
        conn.close()

        if not row or not row[0]:
            raise HTTPException(status_code=404, detail=f"Patient pid={pid} not found or has no UUID")

        hex_uuid = row[0]
        # Format hex as standard UUID: 8-4-4-4-12
        fhir_id = f"{hex_uuid[0:8]}-{hex_uuid[8:12]}-{hex_uuid[12:16]}-{hex_uuid[16:20]}-{hex_uuid[20:32]}".lower()
        full_name = f"{row[1]} {row[2]}".strip()

        return {"pid": pid, "fhir_id": fhir_id, "name": full_name}
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))


@app.post("/chat", response_model=ChatResponse)
async def chat(req: ChatRequest):
    if not req.patient_id:
        raise HTTPException(status_code=400, detail="patient_id is required")

    history = _sessions.get(req.session_id, [])
    history.append({"role": "user", "content": req.message})
    fhir_patient_id = await _resolve_fhir_id(req.patient_id)

    try:
        reply, updated_history = await run_agent(
            fhir_patient_id,
            history,
            session_id=req.session_id,
        )
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))

    _sessions[req.session_id] = updated_history

    return ChatResponse(
        session_id=req.session_id,
        patient_id=req.patient_id,
        reply=reply,
        history_length=len(updated_history),
    )


async def _resolve_fhir_id(patient_id: str) -> str:
    """Convert numeric pid → FHIR UUID, hitting a process-level cache first."""
    if not patient_id.isdigit():
        return patient_id
    cached = _pid_to_fhir_id.get(patient_id)
    if cached is not None:
        return cached
    try:
        resolved = await resolve_patient(patient_id)
        if isinstance(resolved, dict) and resolved.get("fhir_id"):
            _pid_to_fhir_id[patient_id] = resolved["fhir_id"]
            return resolved["fhir_id"]
    except HTTPException:
        # Fall through; downstream FHIR call will surface a clear error.
        pass
    return patient_id


@app.post("/chat/stream")
async def chat_stream(req: ChatRequest):
    """Stream the agent response as NDJSON events.

    Each line is one JSON object. Event types:
      tool_start, tool_end, delta, done, error.
    """
    if not req.patient_id:
        raise HTTPException(status_code=400, detail="patient_id is required")

    history = _sessions.get(req.session_id, [])
    history.append({"role": "user", "content": req.message})
    fhir_patient_id = await _resolve_fhir_id(req.patient_id)

    async def event_stream():
        try:
            async for event in run_agent_stream(
                fhir_patient_id,
                history,
                session_id=req.session_id,
            ):
                if event.get("type") == "done":
                    final_history = event.pop("history", history)
                    _sessions[req.session_id] = final_history
                    event["history_length"] = len(final_history)
                yield json.dumps(event) + "\n"
        except Exception as exc:
            yield json.dumps({"type": "error", "detail": str(exc)}) + "\n"

    return StreamingResponse(
        event_stream(),
        media_type="application/x-ndjson",
        headers={
            # Defeat any intermediary that buffers — Cloudflare/nginx in
            # particular won't flush small chunks without this hint.
            "Cache-Control": "no-cache, no-transform",
            "X-Accel-Buffering": "no",
        },
    )


@app.delete("/chat/{session_id}")
async def clear_session(session_id: str):
    _sessions.pop(session_id, None)
    return {"cleared": session_id}


# Mount static directory last so API routes take precedence
app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")
