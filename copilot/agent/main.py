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
from fastapi.responses import FileResponse, JSONResponse, Response, StreamingResponse
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


# Same allowlist that drives the CSP frame-ancestors directive — partial
# mitigation for residual risk R1 in SECURITY.md (no per-patient care-
# relationship check on /copilot/extractions and /copilot/lab-trend).
# Cross-origin browser hits from anywhere outside the OpenEMR iframe
# now get rejected at the CORS layer; same-origin / direct-curl still
# work for ops + eval-harness use.
_CORS_ORIGINS = [
    o for o in os.environ.get(
        "ALLOWED_IFRAME_ORIGINS", _DEFAULT_IFRAME_ORIGINS,
    ).split() if o
] or ["*"]

app.add_middleware(
    CORSMiddleware,
    allow_origins=_CORS_ORIGINS,
    allow_credentials=True,
    allow_methods=["POST", "GET", "DELETE"],
    allow_headers=["*"],
)

# In-memory session store: session_id → message history
# Replace with Redis for multi-worker deployments
_sessions: dict[str, list[dict]] = {}

# Per-session AgentState carried across /chat/graph turns. Holds the
# accumulated extracted_facts so follow-up turns can reason over previously
# uploaded documents without re-extracting (Decision #12 — session-scoped
# LangGraph state with extracted-fact cache; 30-min TTL aligned with W1).
# Only the W2 graph path uses this; W1 /chat and /chat/stream don't.
_graph_extracted_facts: dict[str, list[dict]] = {}

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

    # W2-only: optional uploads for the LangGraph intake_extractor to
    # consume on this turn. Each entry is { document_id | file_path,
    # doc_type } where doc_type is lab_pdf | intake_form | medication_list.
    # Ignored by the W1 /chat and /chat/stream routes.
    pending_doc_uploads: list[dict] = Field(default_factory=list, max_length=8)

    # W2: active OpenEMR user. Closes residual risk R4 in SECURITY.md —
    # without this, every Langfuse trace lands as actor "anonymous"
    # which makes per-user audit attribution impossible. Passed through
    # to run_agent_stream → trace_request as user_id. When the OpenEMR
    # side hasn't been updated yet to pass it, falls back to anonymous.
    active_user: str | None = Field(default=None, max_length=128)


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

    # Empty/whitespace-only — short-circuit. Anthropic's API rejects
    # whitespace-only text content with `messages: text content blocks
    # must contain non-whitespace text` (HTTP 400), which the agent
    # currently bubbles up as a 500. A clarification ask is both
    # cheaper and more useful than a generic error.
    if not req.message or not req.message.strip():
        history = _sessions.get(req.session_id, [])
        return ChatResponse(
            session_id=req.session_id,
            patient_id=req.patient_id,
            reply="It looks like your message was empty. What would you like to know about this patient?",
            history_length=len(history),
        )

    history = _sessions.get(req.session_id, [])
    history.append({"role": "user", "content": req.message})
    fhir_patient_id = await _resolve_fhir_id(req.patient_id)

    try:
        reply, updated_history = await run_agent(
            fhir_patient_id,
            history,
            session_id=req.session_id,
            user_id=req.active_user or "anonymous",
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

    # Empty/whitespace short-circuit — see /chat for rationale. Stream a
    # single delta + done so the UI gets the same shape it expects.
    if not req.message or not req.message.strip():
        history = _sessions.get(req.session_id, [])
        clarification = "It looks like your message was empty. What would you like to know about this patient?"

        async def empty_stream():
            yield json.dumps({"type": "delta", "text": clarification}) + "\n"
            yield json.dumps({"type": "done", "history_length": len(history)}) + "\n"

        return StreamingResponse(empty_stream(), media_type="application/x-ndjson")

    history = _sessions.get(req.session_id, [])
    history.append({"role": "user", "content": req.message})
    fhir_patient_id = await _resolve_fhir_id(req.patient_id)

    async def event_stream():
        try:
            async for event in run_agent_stream(
                fhir_patient_id,
                history,
                session_id=req.session_id,
                user_id=req.active_user or "anonymous",
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


@app.post("/chat/graph")
@limiter.limit("30/minute")
async def chat_graph_stream(request: Request, req: ChatRequest):
    """Stream the agent response through the LangGraph state machine.

    Routes through supervisor → (intake_extractor | evidence_retriever)*
    → final_answer with explicit handoff logging. Behavior diverges
    from /chat/stream when:
      - the request includes pending_doc_uploads (intake_extractor fires)
      - the user message looks like a clinical/guideline question
        (evidence_retriever fires; see graph._needs_evidence)
    Otherwise the supervisor routes straight to final_answer and the
    streamed reply is functionally equivalent to /chat/stream.

    Per-session graph state (extracted_facts cache) lives in
    _graph_extracted_facts so follow-up turns reason over previously
    uploaded documents without re-extracting.

    NDJSON event types yielded:
      handoff             — supervisor routing decision
      extraction_done     — intake_extractor finished a doc
      retrieval_hit       — evidence_retriever returned top-k
      tool_start          — W1 FHIR tool dispatch
      tool_end            — W1 FHIR tool completion
      delta               — streaming text delta
      done                — final history attached for caller persistence
      graph_summary       — handoff_log + accumulated counts
    """
    from graph import run_graph_stream

    if not req.patient_id:
        raise HTTPException(status_code=400, detail="patient_id is required")

    request.state.rate_limit_session_id = req.session_id

    history = _sessions.get(req.session_id, [])
    history.append({"role": "user", "content": req.message})
    fhir_patient_id = await _resolve_fhir_id(req.patient_id)

    # Pull prior extractions for this session — Decision #12 (session-scoped
    # cache, 30-min TTL aligned with W1's _sessions). The W1 chat-history
    # TTL applies here transitively since both stores are pruned together.
    prior_facts = _graph_extracted_facts.get(req.session_id, [])

    async def event_stream():
        try:
            new_facts: list[dict] = []
            async for event in run_graph_stream(
                session_id=req.session_id,
                patient_id=req.patient_id,
                fhir_patient_id=fhir_patient_id,
                messages=history,
                active_user=req.active_user,
                pending_doc_uploads=req.pending_doc_uploads,
                prior_extracted_facts=prior_facts,
            ):
                if event.get("type") == "extraction_done":
                    new_facts.append({
                        "run_id": event.get("run_id"),
                        "doc_type": event.get("doc_type"),
                        "fact_count": event.get("fact_count"),
                        "schema_valid": event.get("schema_valid"),
                    })
                if event.get("type") == "done":
                    final_history = event.pop("history", history)
                    _sessions[req.session_id] = final_history
                    event["history_length"] = len(final_history)
                yield json.dumps(event) + "\n"
            # Persist new extraction summaries into the session cache so
            # follow-up turns get them as prior_extracted_facts.
            if new_facts:
                _graph_extracted_facts[req.session_id] = prior_facts + new_facts
        except Exception as exc:
            yield json.dumps({"type": "error", "detail": str(exc)}) + "\n"

    return StreamingResponse(
        event_stream(),
        media_type="application/x-ndjson",
        headers={
            "Cache-Control": "no-cache, no-transform",
            "X-Accel-Buffering": "no",
        },
    )


@app.delete("/chat/{session_id}")
async def clear_session(session_id: str):
    _sessions.pop(session_id, None)
    return {"cleared": session_id}


# ---------------------------------------------------------------------------
# Week 2: document ingestion
# ---------------------------------------------------------------------------

class ExtractRequest(BaseModel):
    patient_id: int
    doc_type: str  # 'lab_pdf' | 'intake_form' | 'medication_list'
    document_id: int | None = Field(
        default=None,
        description=(
            "OpenEMR documents.id of an already-uploaded PDF. Preferred path: "
            "the user uploads via the existing Documents tab, then triggers "
            "extraction by document_id."
        ),
    )
    file_path: str | None = Field(
        default=None,
        description=(
            "Local file path. Demo / eval-harness path only — disabled in "
            "production deploys via DISABLE_FILE_PATH_EXTRACT=1."
        ),
    )


class SearchRequest(BaseModel):
    query: str
    top_k: int = Field(default=5, ge=1, le=20)


@app.post("/search")
@limiter.limit("60/minute")
async def search_route(request: Request, req: SearchRequest):
    """Hybrid retrieval over the clinical-guideline corpus.

    Stack: BM25 sparse + Voyage-AI dense → Reciprocal Rank Fusion →
    optional Cohere Rerank. The response includes a `meta` block
    (retrieval_mode, sparse_model, dense_model, dense_enabled, fusion,
    contributors) so reviewers can verify the dense layer is live
    without reading architecture docs. Each result carries per-component
    scores (bm25_score, dense_score, rrf_score, rerank_score) and a
    `source` tag of 'sparse' | 'dense' | 'both'.
    """
    from rag.retriever import search_with_meta

    if not req.query.strip():
        raise HTTPException(status_code=400, detail="query must be non-empty")

    bundle = search_with_meta(req.query, top_k=req.top_k)
    return {
        "query": req.query,
        "top_k": req.top_k,
        "results": bundle["results"],
        "meta": bundle["meta"],
    }


@app.post("/extract")
@limiter.limit("12/minute")
async def extract_route(request: Request, req: ExtractRequest):
    """Run vision extraction on a clinical PDF and persist derived facts."""
    from ingest.attach_and_extract import attach_and_extract

    if req.document_id is None and req.file_path is None:
        raise HTTPException(
            status_code=400,
            detail="Provide at least one of document_id or file_path.",
        )

    if req.file_path is not None and os.environ.get("DISABLE_FILE_PATH_EXTRACT", "0") == "1":
        raise HTTPException(
            status_code=403,
            detail="file_path extraction is disabled in this environment.",
        )

    try:
        pool = await get_db_pool()
        result = await attach_and_extract(
            patient_id=req.patient_id,
            doc_type=req.doc_type,
            document_id=req.document_id,
            file_path=req.file_path,
            pool=pool,
        )
    except (ValueError, LookupError, PermissionError) as exc:
        raise HTTPException(status_code=400, detail=str(exc))
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))

    return {
        "run_id": result.run_id,
        "document_id": result.document_id,
        "patient_id": result.patient_id,
        "doc_type": result.doc_type,
        "schema_valid": result.schema_valid,
        "fact_count": result.fact_count,
        "citation_count": result.citation_count,
        "latency_ms": result.latency_ms,
        "input_tokens": result.input_tokens,
        "output_tokens": result.output_tokens,
        "validation_error": result.validation_error,
        "payload": result.payload,
    }


@app.get("/copilot/lab-trend/{patient_id}")
async def lab_trend_svg(patient_id: int, test_name: str, unit: str = "", title: str = ""):
    """Return an inline-SVG sparkline of a single lab over time.

    Combines extracted facts (cp_extracted_facts where fact_type =
    'lab_result' and fact_json.test_name matches) with any existing
    FHIR Observations on the chart, sorted by date. The chat UI can
    drop this in via markdown image syntax (`![](.../lab-trend/...)`)
    when the agent decides a longitudinal trend is worth showing.
    """
    from charts import TrendPoint, render_sparkline

    pool = await get_db_pool()
    if pool is None:
        raise HTTPException(status_code=500, detail="DB pool unavailable")

    points: list[TrendPoint] = []

    # Source 1: cp_extracted_facts (lab_result rows for this patient).
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                # MySQL JSON_EXTRACT — works on MySQL 5.7+. Strip enclosing quotes.
                await cur.execute(
                    "SELECT JSON_UNQUOTE(JSON_EXTRACT(fact_json, '$.collection_date')) AS dt, "
                    "       JSON_UNQUOTE(JSON_EXTRACT(fact_json, '$.value')) AS val, "
                    "       JSON_UNQUOTE(JSON_EXTRACT(fact_json, '$.abnormal_flag')) AS flg "
                    "FROM cp_extracted_facts "
                    "WHERE patient_id = %s AND fact_type = 'lab_result' "
                    "  AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(fact_json, '$.test_name'))) "
                    "      LIKE LOWER(%s) "
                    "ORDER BY dt ASC",
                    (patient_id, f"%{test_name}%"),
                )
                for dt, val, flg in await cur.fetchall():
                    if not val:
                        continue
                    try:
                        v = float(str(val).split()[0])
                    except (ValueError, IndexError):
                        continue
                    points.append(TrendPoint(
                        date=str(dt or ""),
                        value=v,
                        flag=str(flg or "").lower() if flg and flg != "unknown" else "",
                    ))
    except Exception:
        # Swallow DB-shape mismatches; the SVG still renders from the
        # FHIR side and from any successful rows.
        pass

    svg = render_sparkline(
        points,
        title=title or f"{test_name} trend",
        unit=unit,
        ref_low=None,
        ref_high=None,
    )
    return Response(content=svg, media_type="image/svg+xml",
                    headers={"Cache-Control": "private, max-age=60"})


@app.get("/copilot/extractions/{patient_id}")
async def list_extractions(patient_id: int, doc_type: str | None = None):
    """Return derived facts + citations for a patient.

    The bbox-overlay UI (copilot_doc_viewer.php) consumes this to render
    the click-to-source layer. Each fact carries its document_id so the
    PDF can be deep-linked, plus the page+bbox needed to highlight the
    region.

    Optional doc_type filter: lab_pdf | intake_form | medication_list.
    """
    pool = await get_db_pool()
    if pool is None:
        raise HTTPException(status_code=500, detail="DB pool unavailable")

    where_extra = ""
    params: list = [patient_id]
    if doc_type:
        where_extra = " AND f.doc_type = %s"
        params.append(doc_type)

    sql = (
        "SELECT f.id, f.document_id, f.doc_type, f.fact_type, "
        "       f.fact_json, f.confidence, f.source_quote, f.extraction_run_id, f.created_at, "
        "       c.id, c.page, c.bbox_x, c.bbox_y, c.bbox_w, c.bbox_h, c.field_path, c.quote "
        "FROM cp_extracted_facts f "
        "LEFT JOIN cp_extraction_citations c ON c.fact_id = f.id "
        "WHERE f.patient_id = %s" + where_extra + " "
        "ORDER BY f.created_at DESC, f.id DESC, c.id ASC"
    )

    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(sql, params)
                rows = await cur.fetchall()
    except Exception as exc:
        raise HTTPException(status_code=500, detail=str(exc))

    # Group rows back into one fact per id with a citations[] list.
    facts: dict[int, dict] = {}
    for row in rows:
        (fact_id, document_id, dtype, fact_type, fact_json,
         confidence, source_quote, run_id, created_at,
         cit_id, page, bx, by, bw, bh, field_path, quote) = row
        f = facts.setdefault(int(fact_id), {
            "fact_id": int(fact_id),
            "document_id": int(document_id) if document_id is not None else None,
            "doc_type": dtype,
            "fact_type": fact_type,
            "fact_json": json.loads(fact_json) if isinstance(fact_json, str) else fact_json,
            "confidence": float(confidence) if confidence is not None else None,
            "source_quote": source_quote,
            "extraction_run_id": run_id,
            "created_at": created_at.isoformat() if hasattr(created_at, "isoformat") else str(created_at),
            "derived_from": (
                f"DocumentReference/{document_id}" if document_id else None
            ),
            "citations": [],
        })
        if cit_id is not None:
            f["citations"].append({
                "citation_id": int(cit_id),
                "page": int(page),
                "bbox": {
                    "x": float(bx), "y": float(by),
                    "w": float(bw), "h": float(bh),
                },
                "field_path": field_path,
                "quote": quote,
            })

    return {
        "patient_id": patient_id,
        "doc_type_filter": doc_type,
        "count": len(facts),
        "facts": list(facts.values()),
    }


# Mount static directory last so API routes take precedence
app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")
