"""FastAPI entry point for the Clinical Co-Pilot agent."""

# Install the PHI-scrubbing LogRecord factory before any other import that
# may emit a log line at import time (uvicorn, fastapi, anthropic SDK, the
# NR Python agent — all of these touch the logging module on import). This
# guarantees the scrub applies to every log record from the very first byte
# the process emits.
import phi_redaction  # noqa: E402  (must precede other imports)

phi_redaction.install()

import json
import os
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, JSONResponse, StreamingResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field
from slowapi import Limiter
from slowapi.errors import RateLimitExceeded
from slowapi.util import get_remote_address

from agent import run_agent, run_agent_stream
from fhir_client import (
    aclose_db_pool,
    aclose_http_client,
    bundle_entries,
    fhir_get,
    get_db_pool,
)
from observability import flush as langfuse_flush

STATIC_DIR = Path(__file__).parent / "static"


def _rate_limit_key(request: Request) -> str:
    """Limit per session_id when present in the request body, else per IP.

    Rate-limiting by IP alone gets ineffective behind a CDN / shared
    NAT — every clinician at the same clinic looks like one limit
    bucket. Pulling session_id out of the JSON body keeps each chat
    session in its own bucket and keeps a noisy session from starving
    its neighbours.
    """
    sid = getattr(request.state, "rate_limit_session_id", None)
    if sid:
        return f"sid:{sid}"
    return f"ip:{get_remote_address(request)}"


limiter = Limiter(key_func=_rate_limit_key)


@asynccontextmanager
async def lifespan(app: FastAPI):
    yield
    # Drain queued Langfuse events on shutdown so traces aren't lost
    langfuse_flush()
    # Close the shared httpx client + db pool cleanly so keep-alive
    # connections are torn down rather than orphaned at SIGTERM.
    await aclose_http_client()
    await aclose_db_pool()


app = FastAPI(title="Clinical Co-Pilot", lifespan=lifespan)

# Rate limiter — see _rate_limit_key for the per-session vs per-IP logic.
app.state.limiter = limiter


@app.exception_handler(RateLimitExceeded)
async def _rate_limit_handler(request: Request, exc: RateLimitExceeded):
    return JSONResponse(
        status_code=429,
        content={"detail": f"Rate limit exceeded: {exc.detail}"},
    )


# Origins allowed to iframe the Co-Pilot chat surface. The agent is
# embedded inside OpenEMR's `interface/copilot/index.php`, which lives
# on a different port (or in any deployed env, a different host) than
# the agent — so 'self' is not enough.
#
# Defaults cover **local dev only** (localhost:8300/9300). Each
# Railway env (dev/qa/prod) MUST set ALLOWED_IFRAME_ORIGINS to its own
# OpenEMR origin via env var. We deliberately keep prod URLs out of
# the defaults so a misconfigured qa/dev container can't accept iframe
# embeds from prod openemr — that would violate environment
# isolation. See `feedback_environment_isolation.md`.
_DEFAULT_IFRAME_ORIGINS = (
    "http://localhost:8300 https://localhost:9300"
)
_FRAME_ANCESTORS = "'self' " + os.environ.get(
    "ALLOWED_IFRAME_ORIGINS", _DEFAULT_IFRAME_ORIGINS
).strip()


@app.middleware("http")
async def _no_cache_for_chat_assets(request: Request, call_next):
    """Force no-store on the chat UI HTML + JS so the browser never
    serves a stale copy. The /static mount otherwise sets ETag-based
    revalidation, which can pin a browser to a broken version when
    we ship a security-header or CSP change.
    """
    response = await call_next(request)
    path = request.url.path
    if path == "/" or path.startswith("/static/"):
        response.headers["Cache-Control"] = "no-store, must-revalidate"
    return response


@app.middleware("http")
async def _security_headers(request: Request, call_next):
    """Add CSP + standard hardening headers on every response.

    The chat surface only renders model-generated text (sanitized via
    structural-only markdown — see SECURITY.md S1) and never needs
    inline scripts or remote origins. CSP gives us a backstop in case
    a future change reintroduces an XSS sink.

    Note on `frame-ancestors`: the Co-Pilot is iframed by OpenEMR's
    `interface/copilot/index.php`, which is served from a different
    origin (different port locally, different host in prod). `'self'`
    is not enough — we explicitly allow the OpenEMR origins via the
    `ALLOWED_IFRAME_ORIGINS` env var (defaults cover local + the
    Railway prod URLs). We do NOT also send `X-Frame-Options` because
    that header has no allow-list form (only DENY / SAMEORIGIN /
    deprecated ALLOW-FROM); modern browsers prefer CSP's
    `frame-ancestors` and ignore X-Frame-Options when CSP is present.
    """
    response = await call_next(request)
    response.headers.setdefault(
        "Content-Security-Policy",
        # script-src 'self' blocks inline <script>; img/style 'self' +
        # data: covers the inline SVG icons the chat UI already uses.
        # Google Fonts (Inter) needs the stylesheet origin in style-src
        # and the font-file origin in font-src; the `display=swap` font
        # gracefully falls back to the system stack if either is blocked,
        # so this is a polish concern, not a functional one.
        "default-src 'self'; "
        "script-src 'self'; "
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        "font-src 'self' https://fonts.gstatic.com; "
        "img-src 'self' data:; "
        "connect-src 'self'; "
        f"frame-ancestors {_FRAME_ANCESTORS}; "
        "base-uri 'self'; "
        "form-action 'self'",
    )
    response.headers.setdefault("X-Content-Type-Options", "nosniff")
    response.headers.setdefault("Referrer-Policy", "no-referrer")
    return response


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
    # Tight bounds on every field — defends T1 / T4 from SECURITY.md.
    # session_id and patient_id are short identifiers; message is the
    # user-typed prompt and gets a 4 KB ceiling (well above any
    # plausible clinician question, well below anything that would
    # blow up token spend).
    session_id: str = Field(..., min_length=1, max_length=128)
    patient_id: str = Field(..., min_length=1, max_length=128)
    message: str = Field(..., min_length=1, max_length=4000)


class ChatResponse(BaseModel):
    session_id: str
    patient_id: str
    reply: str
    history_length: int


# ── Routes ────────────────────────────────────────────────────────────────

_BUILD_MARKER = "loinc+limit50+stream-2026-05-02"


@app.get("/health")
async def health():
    return {"status": "ok"}


@app.get("/version")
async def version():
    """Returns a build marker so we can verify which code is actually running."""
    return {
        "build_marker": _BUILD_MARKER,
        "build_rev": os.environ.get("BUILD_REV", "unknown"),
    }


@app.get("/")
async def serve_chat_ui():
    """Serve the chat UI. Pass ?pid=<openemr_pid> to pre-load a patient.

    Cache-Control: no-store so iframe reloads always pick up the latest
    chat.html/chat.js. Without this, browsers happily serve a stale
    version that may reference an older API or a CSP-blocked inline
    script — exactly the state the user reported as "infinite spinner."
    """
    return FileResponse(
        STATIC_DIR / "chat.html",
        headers={"Cache-Control": "no-store, must-revalidate"},
    )


@app.get("/api/patient-fhir-id/{pid}")
async def resolve_patient(pid: str):
    """Resolve an OpenEMR internal pid (integer) to a FHIR UUID and name.

    Uses a direct DB lookup against `patient_data` via the shared
    aiomysql connection pool (`get_db_pool`). The chat UI hits this on
    every iframe load to map the OpenEMR pid in its query string to
    the FHIR UUID it needs for the agent's tool calls — opening a
    fresh MySQL connection on every call cost 40-1200ms which the user
    saw as a multi-second "Loading patient…" spinner. Pool reuse drops
    that to ~10-30ms once warm.
    """
    pool = await get_db_pool()
    if pool is None:
        raise HTTPException(
            status_code=500,
            detail="DB pool unavailable — db_host is not configured",
        )

    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    "SELECT HEX(uuid), fname, lname, DOB, sex FROM patient_data WHERE pid = %s",
                    (int(pid),),
                )
                row = await cur.fetchone()
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))

    if not row or not row[0]:
        raise HTTPException(status_code=404, detail=f"Patient pid={pid} not found or has no UUID")

    hex_uuid = row[0]
    # Format hex as standard UUID: 8-4-4-4-12
    fhir_id = f"{hex_uuid[0:8]}-{hex_uuid[8:12]}-{hex_uuid[12:16]}-{hex_uuid[16:20]}-{hex_uuid[20:32]}".lower()
    full_name = f"{row[1]} {row[2]}".strip()
    dob_raw = row[3]
    # DOB comes back as date/datetime/str depending on driver; emit ISO YYYY-MM-DD
    dob_str = dob_raw.strftime("%Y-%m-%d") if hasattr(dob_raw, "strftime") else (str(dob_raw)[:10] if dob_raw else "")
    sex_raw = (row[4] or "").strip()
    sex = sex_raw[:1].upper() if sex_raw else ""

    return {"pid": pid, "fhir_id": fhir_id, "name": full_name, "dob": dob_str, "sex": sex}


@app.post("/chat", response_model=ChatResponse)
@limiter.limit("30/minute")
async def chat(request: Request, req: ChatRequest):
    if not req.patient_id:
        raise HTTPException(status_code=400, detail="patient_id is required")

    # Hand the limiter the session_id so the rate window is per session
    # rather than per IP (see _rate_limit_key docstring).
    request.state.rate_limit_session_id = req.session_id

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
@limiter.limit("30/minute")
async def chat_stream(request: Request, req: ChatRequest):
    """Stream the agent response as NDJSON events.

    Each line is one JSON object. Event types:
      tool_start, tool_end, delta, done, error.
    """
    if not req.patient_id:
        raise HTTPException(status_code=400, detail="patient_id is required")

    request.state.rate_limit_session_id = req.session_id

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
