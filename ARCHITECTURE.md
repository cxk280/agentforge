# Architecture: Clinical Co-Pilot

## Summary (~500 words)

The Clinical Co-Pilot is a Python FastAPI sidecar service that runs alongside OpenEMR and provides a multi-turn conversational AI agent to physicians. It is not embedded in the PHP codebase — it runs as a separate container, communicates with OpenEMR exclusively via its FHIR R4 REST API, and authenticates using OAuth 2.0 with patient-scoped access tokens. A thin chat panel is injected into the OpenEMR patient record view via a new PHP page registered through `PatientMenuEvent`, which iframes the agent's React UI.

**Why a sidecar service, not a PHP module:** OpenEMR is a synchronous PHP monolith. Streaming LLM responses and managing multi-turn conversation state in PHP is possible but painful. A Python sidecar gives us async-native streaming (FastAPI + httpx), clean tool use patterns with the Anthropic SDK, and a deployment unit we can iterate on independently of OpenEMR's PHP build. The PHP footprint in OpenEMR is minimal: one new page (`interface/patient_file/summary/copilot.php`) that renders the iframe and passes the patient ID and session identity.

**Why FHIR over direct DB access:** The FHIR R4 endpoints normalize OpenEMR's messy underlying data (free-text dosages, mixed date formats, inconsistent nulls) into structured FHIR resources. They enforce OAuth scopes, so the agent's data access is explicitly bounded at the API layer. They also make the agent portable — if a future deployment uses a different FHIR-compliant EHR, the tools need only a URL change. Direct DB access would tie us to OpenEMR's MySQL schema details and bypass the ACL system.

**Authorization design:** The agent backend validates the physician's OpenEMR session by calling OpenEMR's token introspection endpoint. It never issues its own tokens — it inherits the session user's identity and resolves it to an OAuth access token with only the scopes needed for that user's role. A physician gets `user/Patient.rs user/Observation.rs user/MedicationRequest.rs user/Condition.rs user/AllergyIntolerance.rs user/Encounter.rs`. Tool calls are gated by a middleware check that confirms the requested patient is accessible to the requesting user.

**Verification layer:** Every agent response passes through a two-step verification before reaching the physician. First, source attribution: after tool calls complete, a fast secondary Claude call inspects the draft response and annotates each factual claim with the FHIR resource ID and field that supports it. Any claim without a traceable source is flagged or removed. Second, a domain constraint check validates that no medication or dosage claim in the response contradicts the retrieved `MedicationRequest` records.

**Observability from day one:** Langfuse is wired in as the tracing backend. Every request creates a trace with spans for: session validation, tool calls (name, duration, success/failure), Claude API calls (token counts, latency), and verification pass. PHI is never included in trace payloads — tool call arguments log the FHIR resource type and ID, not the content. This gives full visibility into agent behavior without creating a PHI logging liability.

**Key tradeoffs:** Using the FHIR API adds ~50–100ms latency per tool call versus direct DB access. We accept this to gain scope enforcement, data normalization, and portability. The verification pass adds ~1–2 seconds per response. We accept this because hallucinations in clinical settings are unacceptable. The iframe injection is less elegant than a native PHP panel but keeps the agent codebase decoupled and independently deployable.

---

## Agent Location & Deployment

```
┌─────────────────────────────────────────────────────┐
│  Docker Compose Stack                               │
│                                                     │
│  ┌──────────────┐    ┌──────────────┐              │
│  │  openemr     │    │  copilot     │              │
│  │  (PHP+Apache)│    │  (Python     │              │
│  │  :8300/:9300 │    │   FastAPI)   │              │
│  │              │    │  :8400       │              │
│  └──────┬───────┘    └──────┬───────┘              │
│         │                   │                       │
│         │  FHIR REST API    │ Claude API            │
│         │◄──────────────────┘ (external)            │
│         │                                           │
│  ┌──────▼───────┐    ┌──────────────┐              │
│  │  mysql 9.4   │    │  langfuse    │              │
│  │   :8320      │    │  (traces)    │              │
│  └──────────────┘    └──────────────┘              │
└─────────────────────────────────────────────────────┘
```

**Files created in OpenEMR:**
- `interface/patient_file/summary/copilot.php` — iframe container + session passthrough
- `src/Events/CopilotMenuEvent.php` — registers the Co-Pilot tab in patient navigation
- `copilot/` — Python sidecar service (not part of OpenEMR PHP codebase)

---

## Data Access Strategy

The agent backend accesses patient data exclusively via OpenEMR's FHIR R4 API. No direct database connections from the agent.

**Tool → FHIR mapping:**

| Tool | FHIR Endpoint | Data Returned |
|---|---|---|
| `get_patient_summary` | `GET /fhir/Patient/:id` | Demographics, active problems |
| `get_medications` | `GET /fhir/MedicationRequest?patient=:pid&status=active` | Active prescriptions |
| `get_recent_labs` | `GET /fhir/Observation?patient=:pid&category=laboratory&_sort=-date&_count=10` | Lab results with dates and units |
| `get_vitals` | `GET /fhir/Observation?patient=:pid&category=vital-signs&_sort=-date&_count=5` | Recent vitals |
| `get_conditions` | `GET /fhir/Condition?patient=:pid&clinical-status=active` | Active problem list |
| `get_allergies` | `GET /fhir/AllergyIntolerance?patient=:pid` | Allergy list |
| `get_encounters` | `GET /fhir/Encounter?patient=:pid&_sort=-date&_count=10` | Recent visits |

**Context pre-fetch pattern:** At conversation start, the agent fires all tool calls in parallel (via `asyncio.gather`) to fetch the full patient context. This single fetch primes the conversation. Subsequent turns reason over cached context unless the physician asks a question that requires a fresher or deeper query.

---

## Authorization Boundaries

**Layer 1 — OpenEMR OAuth scopes:** The agent authenticates with the minimum scopes required for the requesting user's role. A physician gets read-only user/* scopes. No write scopes are ever requested.

**Layer 2 — Patient access validation:** Before any tool call is executed, the agent middleware checks that the requesting physician has a care relationship with the patient being queried. This is implemented by checking the `provider_id` field on `patient_data` against the session user, or checking if the physician has an encounter in the patient's record within the past 12 months.

**Layer 3 — Tool-level guard:** Each tool function validates its inputs and checks authorization before calling the FHIR API. A tool call that fails authorization returns a structured error, never silently returns empty results.

**Trust boundary diagram:**
```
Physician browser session
    → copilot.php (OpenEMR session validated)
        → Agent backend (session introspected, role resolved)
            → FHIR API (OAuth token with role-appropriate scopes)
                → MySQL (ACL enforced by OpenEMR service layer)
```

---

## Verification Strategy

Every agent response passes through two checks before delivery:

**Step 1: Source Attribution**
A fast secondary Claude Haiku call receives:
- The tool call results (as FHIR resource IDs + extracted values)
- The agent's draft response

It outputs an annotated version where each factual claim is tagged with its source `resourceType/id/field`. Claims that cannot be attributed are flagged with `[UNVERIFIED]`. The agent either removes unverified claims or explicitly states "I don't have data on this in the record."

**Step 2: Domain Constraint Check**
A rules-based pass (no LLM call) validates:
- Medication names in the response match active `MedicationRequest` resources exactly
- Lab values match `Observation` resource values exactly (no rounding or reformatting)
- No clinical recommendations are present (pattern matching for prescriptive language: "should", "must", "recommend" flagged for review)

**Known limitations:**
- Source attribution catches hallucinated facts but cannot catch reasoning errors — if the agent correctly quotes a value but draws a wrong conclusion, the verification layer won't catch it
- Domain constraint check is rules-based — it will miss constraint violations that aren't pattern-matchable
- Verification adds ~1–2 seconds to response time; acceptable given the safety requirement

---

## LLM & Framework Selection

**Model:** Claude Sonnet 4.6 (`claude-sonnet-4-6`)
- Fast enough for the 90-second use case
- Strong structured output support for tool use
- Long context window (200K tokens) — can hold full patient context without truncation
- Supports streaming for progressive response display

**Verification model:** Claude Haiku 4.5 (`claude-haiku-4-5-20251001`)
- Fast and cheap for the narrow verification task
- Low latency adds <1 second to response time

**Framework:** Anthropic Python SDK (direct tool use)
- No LangChain / LangGraph overhead
- Tool use pattern is clean and well-documented
- `stream=True` for streaming responses to the frontend

**Conversation state:** Stored in server-side memory keyed by session ID, with a 30-minute TTL. No persistent conversation storage (PHI constraint — do not write conversation content to DB).

---

## Observability Approach

**Tool:** Langfuse (self-hosted via Docker container in the stack)

**What is traced:**
- Each physician request: span tree from session validation → tool calls → Claude call → verification → response
- Tool call spans: tool name, FHIR resource type queried, duration, success/failure, error type (never PHI content)
- Claude API spans: model, token counts (input/output), duration, streaming latency to first token
- Verification span: which claims were attributed vs flagged, duration

**What is NOT traced (PHI constraint):**
- Tool call arguments/responses (contain patient data)
- Raw Claude prompt/response content
- Patient IDs in plain form (hashed in traces)

**Metrics derivable from traces:**
- P50/P95 response latency per request type
- Tool call failure rates by tool and error type
- Token cost per request, per day
- Verification flag rate (what % of responses had unverified claims stripped)

---

## Known Tradeoffs & Risks

| Tradeoff | Decision | Rationale |
|---|---|---|
| FHIR API adds 50–100ms latency per tool | Accept | Scope enforcement + data normalization worth the cost |
| Verification pass adds 1–2s | Accept | Clinical hallucination risk too high to skip |
| Iframe injection vs native PHP panel | Accept | Keeps agent codebase decoupled and independently deployable |
| No persistent conversation storage | Accept | PHI constraint; 30-min in-memory TTL sufficient for visit duration |
| Self-hosted Langfuse vs cloud | Self-host | Avoids PHI in third-party trace storage |

**Highest risk items:**
1. **OAuth flow complexity:** Getting client credentials flow working correctly with OpenEMR's OAuth server is the most failure-prone integration step. Must be validated early.
2. **Patient access authorization:** The care relationship check (physician → patient) is not exposed as a single FHIR query — requires combining `Encounter` history with `patient_data.provider_id`. Must be tested with edge cases (patient seen once 2 years ago, patient just transferred in).
3. **FHIR data quality:** OpenEMR's FHIR layer normalizes the data but can return empty or malformed resources when underlying data is incomplete. Every tool must handle empty FHIR bundles gracefully.

---
---

# Week 2: Multimodal Evidence Agent

## Architectural decisions at a glance

The twelve load-bearing decisions for Week 2, with rationale. Every subsequent subsection in this document expands on one of these.

| # | Decision | Choice | Why |
|---|---|---|---|
| 1 | Orchestration | **LangGraph** `StateGraph` (supervisor + 3 workers + critic) | Spec names it; inspectable handoffs; native Langfuse callbacks |
| 2 | Vector store | **pgvector on a new Railway Postgres service** (one per env: Dev/QA/Prod) | Persistence; SQL-side hybrid (cosine + ts_rank); clean env story |
| 3 | Persistence | **Hybrid** — legacy `addNewDocument()` writes the source PDF (auto-readable via `GET /fhir/DocumentReference?patient=…` since OpenEMR's read-side reads the same `documents` table); derived facts in side-tables `cp_extracted_facts` + `cp_extraction_citations` + `cp_extraction_runs`, each row carrying explicit `derivedFrom: DocumentReference/{id}` | OpenEMR's FHIR R4 surface has **no** `POST /fhir/Binary`, **no** `POST /fhir/Observation`, only `POST /fhir/DocumentReference/$docref` (a CCDA operation, not a create). Verified 2026-05-05. Spec explicitly allows "FHIR resources OR OpenEMR records." |
| 4 | Vision pipeline | **Sonnet 4.6 native PDF input** + tool-use forced extraction | Single API call sees rendered + text; `tool_choice` guarantees JSON; bbox via image grounding |
| 5 | Eval rubric | **Per-rubric booleans** — `schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs` — implemented **deterministically wherever possible**, plus **adversarial + replay** case categories and **system-level metrics** (tool-call accuracy, latency p50/p95) tracked over time | Spec calls out exact category names. Final-submission grader feedback (2026-05-03) explicitly pushed for "deterministic checks over LLM-judge wherever possible" + adversarial + replay-from-real-sessions + system-level metrics — what gets you from good coverage to production trust |
| 6 | CI gate | **GitHub Actions on PR** (`agent-evals.yml`), required check via branch protection | Cleanest "PR-blocking" story; CircleCI keeps deploy gating downstream |
| 7 | Citation UI | **Documents tab + chat side-panel** — PDF.js viewer with bbox overlay rail | Reuses existing `copilot_documents.php` mock; click-to-source from chat |
| 8 | Scope | **Core + all 3 extensions** — critic agent, lab trend chart, third doc type | Ambitious; explicit Thursday stop-loss to drop extensions if Early Submission isn't green |
| 9 | Embeddings | **Voyage-3 (1024-dim)** | Anthropic-recommended; biomedical-strong; HIPAA-eligible under BAA |
| 10 | 3rd doc type | **External medication list** | Common workflow; reuses MedicationStatement shape; lower extraction risk than fax |
| 11 | Guideline corpus | **ADA + ACC/AHA HTN + USPSTF + GINA + KDIGO** (~10 PDFs, ~250 chunks) | Direct hit on every seeded patient's conditions; public-domain; citation-stable |
| 12 | Memory | **Session-scoped LangGraph state** with `cache: {doc_id: extracted_facts_json}`; 30-min TTL aligned with W1 | Avoids re-extraction on follow-ups; preserves the 90-second goal |

**Out of scope (stay narrow):** ColQwen2 / multi-vector indexing, contextual-retrieval rewrites, fax document type, imaging report.

---

## Summary (~400 words)

Week 1 shipped a tool-using Co-Pilot that reads structured FHIR data and answers physician questions in the 90-second-between-rooms window. Week 2 extends that agent in three directions: it can now **see** real clinical documents (scanned lab PDFs, intake forms, external medication lists), **route** work across an inspectable supervisor + worker graph, and **prove** quality with a 50-case eval suite that gates every PR.

**What changed architecturally.** The single-tool Anthropic loop from Week 1 is wrapped in a LangGraph `StateGraph` with a Sonnet-4.6 supervisor and four worker nodes: `intake_extractor`, `evidence_retriever`, `critic`, and `final_answer`. The supervisor's routing decisions (and every handoff) are written into LangGraph state and emitted as Langfuse spans, making them inspectable rather than a black box. Week 1's six FHIR read tools are still available and the legacy single-loop path stays operational as a fallback.

**Document ingestion.** A new `attach_and_extract(patient_id, doc_type, document_id|file_path)` tool accepts a PDF, ships it to Sonnet 4.6 as a native `document` content block, and uses forced tool-use to coerce a Pydantic-validated JSON extraction. The source PDF is persisted via OpenEMR's legacy `addNewDocument()` pipeline (`library/documents.php`) — the same `documents` table that backs `GET /fhir/DocumentReference?patient=:pid`, so the source round-trips through FHIR for free with no new controller code. Each derived fact lives in `cp_extracted_facts` with an explicit `derivedFrom: DocumentReference/{id}` field; bounding-box metadata sits alongside in `cp_extraction_citations`. We pivoted to this hybrid path on 2026-05-05 after a Day-1 grep of `apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php` confirmed OpenEMR has no `POST /fhir/Binary`, no `POST /fhir/Observation`, and only the `$docref` operation for DocumentReference — so a "full FHIR write" path would have required ~300 lines of new REST controller plumbing for no behavioral gain over the hybrid.

**Hybrid RAG.** A new `pg-rag` Railway service (Postgres 16 + pgvector, one per env) holds a small clinical-guideline corpus (~250 chunks across ADA, ACC/AHA HTN, USPSTF, GINA, KDIGO). Retrieval is hybrid: cosine top-30 (Voyage-3 embeddings) ∪ ts_rank top-30 (BM25-style sparse) → Cohere Rerank → top-5 evidence chunks fed to the answer model. Every retrieved chunk carries source_id, page, section, and exact quote to satisfy the citation contract.

**Eval gate.** The Week-1 25-case suite grows to 50 and switches from float scoring to per-rubric booleans (`schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs`). A `gate.py` compares each run against a checked-in `baseline.json` and fails CI if any rubric category drops more than 5% or below an absolute threshold. The gate runs as a required GitHub Actions check on every PR; CircleCI keeps doing deploy gating downstream.

**Key tradeoffs:** LangGraph adds a dependency and some streaming friction, but its handoff inspectability is the right answer for the spec's "no black-box supervisor" pitfall. pgvector adds a new Railway service per env, but persistence and SQL-side hybrid search are worth it. Sonnet-4.6 native PDF input means one round-trip and one set of bounding boxes; we accept the 32-page-per-call cap.

---

## Multi-agent graph

```
                          ┌────────────────────┐
            user msg ───▶ │     Supervisor     │  Sonnet 4.6
                          │                    │  Sees: messages, pending uploads,
                          │  Decides routing   │         extraction cache, evidence cache
                          └─────────┬──────────┘
              ┌────────────┬────────┴────────┬─────────────┐
              ▼            ▼                  ▼             ▼
       ┌────────────┐ ┌──────────┐    ┌─────────────┐ ┌──────────┐
       │  Intake    │ │ Evidence │    │   Critic    │ │  Final   │
       │ Extractor  │ │Retriever │    │ (extension) │ │  Answer  │
       └─────┬──────┘ └────┬─────┘    └──────┬──────┘ └─────┬────┘
             │             │                  │              │
       Sonnet 4.6     BM25 + Voyage-3    Rejects uncited   Stream
       PDF + tool      + Cohere Rerank    or unsafe         to UI
       FHIR write      → top-5 evidence   claims; loops
                                          back ≤1×
```

**Framework:** [LangGraph](https://langchain-ai.github.io/langgraph/) `StateGraph`. Chosen over a roll-your-own Anthropic supervisor for three reasons: (1) the Week-2 spec calls it out by name, (2) handoffs and routing decisions live in declared state rather than emerging from prompt engineering, and (3) Langfuse has first-class LangChain/LangGraph callbacks so every node becomes a labeled span automatically.

**State shape (`AgentState`):**

| Field | Purpose |
|---|---|
| `session_id` | Carries through to Langfuse trace + chat history |
| `patient_id` | Server-enforced; never sourced from prompt |
| `messages` | Full conversation in Anthropic format |
| `pending_doc_uploads` | Queue from `/upload` endpoint, drained by supervisor |
| `extracted_facts_cache` | `{doc_id: ExtractedFacts}` — avoids re-extracting on follow-up turns |
| `evidence_cache` | `{query_hash: list[EvidenceChunk]}` — avoids re-retrieval |
| `citations` | All citations referenced in the in-progress reply (patient-record + guideline) |
| `handoff_log` | Append-only `[(from_node, to_node, reason, ts)]` — emitted to Langfuse |

**Streaming integration:** LangGraph's `astream_events()` is bridged into the existing FastAPI NDJSON event stream. New event types: `handoff` (supervisor routing decisions), `extraction_progress`, `retrieval_hit`. Existing W1 event types (`tool_start`, `tool_end`, `delta`, `done`) remain.

**Memory:** Session-scoped, in-process, 30-min TTL — same TTL as W1 chat history. Caching extracted facts and retrieval results avoids re-running expensive workers on follow-up questions ("now check that against guidelines"), which is critical for the 90-second goal.

---

## Document ingestion flow

```
┌───────────────────────────────────────────────────────────────────────┐
│ 1. Browser uploads PDF via OpenEMR's existing Documents tab           │
│    → multipart POST → library/ajax/upload.php                         │
│    → addNewDocument()  (controllers/C_Document::upload_action_process)│
│    → row in `documents` (id, foreign_id=patient, url=file://…)        │
│                                                                       │
│ 2. UI / agent triggers extraction                                     │
│    POST /extract on the agent  { patient_id, document_id, doc_type }  │
│    → attach_and_extract(...) resolves document_id → on-disk path      │
│    → cross-checks foreign_id == patient_id (PermissionError otherwise)│
│                                                                       │
│ 3. Vision extraction                                                  │
│    Sonnet 4.6 with `document` content block + tool_choice forces      │
│    extract_lab_report / extract_intake_form / extract_medication_list │
│    Pydantic re-validates the model's tool_use input on our side       │
│                                                                       │
│ 4. Persist derived facts                                              │
│    INSERT cp_extraction_runs                                          │
│        run_id, document_id, patient_id, doc_type, model               │
│    For each extracted clinical fact:                                  │
│      INSERT cp_extracted_facts                                        │
│         doc_id, patient_id, doc_type, fact_type, fact_json,           │
│         confidence, source_quote, extraction_run_id                   │
│      INSERT cp_extraction_citations                                   │
│         fact_id, page, bbox_{x,y,w,h}, field_path, quote              │
│    UPDATE cp_extraction_runs SET status, fact_count, latency_ms,      │
│         input_tokens, output_tokens, schema_valid                     │
│                                                                       │
│ 5. Return                                                             │
│    { run_id, document_id, patient_id, doc_type, schema_valid,         │
│      fact_count, citation_count, latency_ms, payload }                │
└───────────────────────────────────────────────────────────────────────┘
```

**Round-trip integrity contract.** The source PDF lands in OpenEMR's stock `documents` table — the exact storage `GET /fhir/DocumentReference?patient=…` reads from — so any FHIR client (the agent, an external tool, a clinician's mobile app) can discover uploaded sources without our agent in the loop. Derived facts sit alongside in `cp_extracted_facts` keyed by `document_id` with an explicit `derivedFrom: DocumentReference/{id}` field on every fact; the `cp_extraction_citations` foreign key cascades on delete, so removing a fact removes its citations. A custom `GET /copilot/extractions/{patient_id}` route on the agent surfaces the derived layer.

**Why not full FHIR POST.** Verified 2026-05-05 against `apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php` and `src/Services/FHIR/`: OpenEMR has no `POST /fhir/Binary`, no `POST /fhir/Observation`, no `FhirBinary*` service class at all, and `POST /fhir/DocumentReference/$docref` is only the CCDA-export operation. A full FHIR-write story would have required ~300 lines of new REST controller plumbing, new OAuth scopes, and a multi-week PR upstream — for no behavioral gain over the hybrid the spec explicitly permits ("FHIR resources OR OpenEMR records").

---

## Schemas

Pydantic models live in `copilot/agent/ingest/schemas.py`. Each model has a JSON-schema export consumed directly by Anthropic's tool-use system.

| Schema | Required fields |
|---|---|
| `LabReport.results[]` | `test_name, value, unit, reference_range, collection_date, abnormal_flag, source_citation` |
| `IntakeForm` | `demographics{first_name,last_name,dob,sex}, chief_concern, current_medications[], allergies[], family_history[]`, all with per-field `source_citation` |
| `MedicationList.medications[]` | `medication_name, dose, frequency, route, prescriber, source_citation` |
| `Citation` (shared) | `source_type, source_id, page_or_section, field_or_chunk_id, bbox: [x,y,w,h], quote_or_value` |

Schema validation runs **inside** the extraction tool — the model is forced into the schema by `tool_choice`, not asked to "please return JSON." Any field the model is unsure of is marked `null` with `confidence < 0.5`; downstream code treats null + low-confidence as "not present" rather than guessing.

---

## Hybrid RAG

**Service:** `pg-rag` — a new Railway Postgres service (one per Dev/QA/Prod env) running Postgres 16 + pgvector. Held separate from the OpenEMR DB and from Langfuse Postgres so corpus refreshes never touch clinical or trace data.

**Schema:**

```sql
CREATE TABLE corpus_chunks (
  id          SERIAL PRIMARY KEY,
  source_id   TEXT NOT NULL,
  source_url  TEXT,
  page        INT,
  section     TEXT,
  text        TEXT NOT NULL,
  embedding   VECTOR(1024) NOT NULL,
  tsv         TSVECTOR GENERATED ALWAYS AS (to_tsvector('english', text)) STORED
);
CREATE INDEX ON corpus_chunks USING ivfflat (embedding vector_cosine_ops);
CREATE INDEX ON corpus_chunks USING gin (tsv);
```

**Retrieval algorithm:**

1. **Sparse:** `ts_rank_cd(tsv, plainto_tsquery(query))` top-30
2. **Dense:** `1 - (embedding <=> voyage_3_embed(query))` top-30
3. **Union, dedupe**, keep up to 60 candidates
4. **Cohere Rerank v3.5** → top-5
5. Each result returns `{source_id, source_url, page, section, text, score}` — all five fields are required for the citation contract.

**Embedding model:** Voyage-3 (1024 dim). Chosen for biomedical retrieval performance and Anthropic-recommended status. Falls under the assumed-signed BAA posture documented in the README.

**Corpus inventory (initial):**

| Source | Coverage | Pages | Chunks (≈) |
|---|---|---|---|
| ADA Standards of Care 2024 | T2DM | 250+ | 80 |
| ACC/AHA Hypertension 2017 | HTN | 100+ | 40 |
| USPSTF: statins for CVD prevention | Hyperlipidemia | 30 | 15 |
| GINA Asthma 2024 | Asthma | 200+ | 60 |
| KDIGO CKD 2024 | CKD | 100+ | 40 |
| (room for ~3 more) | | | ~15 |
| **Total** | | | **~250** |

Every chunk has its source URL stored so the UI can offer a "Read this guideline" deep-link, never relying on the model to remember the URL.

**Refresh process:** `python -m copilot.agent.rag.ingest_corpus` runs locally. Output is committed as a deployable artifact (`seed_corpus.sh`) and idempotently reapplied on each environment's `pg-rag` service when CircleCI deploys.

---

## Persistence & round-trip integrity

Week 1 used only FHIR R4 *read* scopes. Week 2 needs a write surface for uploaded documents and the structured facts the agent extracts from them. We pivoted on Day-1 (2026-05-05) from a full-FHIR-write design to a **hybrid** persistence model after grep'ing the FHIR layer for missing POST handlers.

**What FHIR exposes (read side).** Unchanged from Week 1: `GET /fhir/DocumentReference`, `GET /fhir/Observation`, `GET /fhir/Patient`, etc. These read from OpenEMR's stock tables (`documents`, `procedure_result`, `patient_data`, etc.).

**What FHIR does NOT expose (write side).** Verified by grep against `apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php`:

- No `POST /fhir/Binary` route. No `FhirBinary*` service class.
- No `POST /fhir/Observation` route. `FhirObservationService` exists but only its read methods are wired up.
- The only DocumentReference POST is `POST /fhir/DocumentReference/$docref` — a CCDA *operation*, not a resource create.

**Hybrid persistence model.**

| Artifact | Where it lives | How it's written | How it's read |
|---|---|---|---|
| Source PDF | OpenEMR `documents` table + `sites/<site>/documents/<pid>/<file>` on disk | `library/documents.php::addNewDocument()` (the legacy upload pipe) | `GET /fhir/DocumentReference?patient=:pid` (auto — same table) |
| Derived facts | `cp_extracted_facts` (new) | Agent INSERTs from `copilot/agent/ingest/persistence.py` | `GET /copilot/extractions/{patient_id}` (custom read route on agent) |
| Bbox citations | `cp_extraction_citations` (new) | Agent INSERTs in same transaction; FK cascades | Joined with facts by the agent's read route |
| Extraction run summary | `cp_extraction_runs` (new) | Agent INSERTs at start, UPDATEs at finish | Used by the eval gate's `schema_valid` rubric and the cost/latency report |

**Linkage.** Every row in `cp_extracted_facts` carries a `document_id` FK to `documents.id` and an explicit `derivedFrom: DocumentReference/{id}` field in its `fact_json`. A clinician who's looking at a derived fact can pivot to the source PDF in one query; a custodian who deletes a `cp_extracted_facts` row cascades the citations automatically.

**No new FHIR scopes needed.** The agent's existing read-only `user/*.rs` scopes plus its direct DB access to the `cp_*` tables (via the existing `aiomysql` pool) are sufficient. We avoided adding `*.cu` write scopes, which would have widened the OAuth attack surface for no spec-mandated reason.

**Spec compliance.** "Uploaded documents and derived observations must round-trip through OpenEMR without creating duplicate or untraceable records." Our source PDF is a stock OpenEMR `documents` row — no parallel storage, no duplication. The derived facts are explicitly attributable via FK + `derivedFrom`. The spec lets us pick "FHIR resources OR OpenEMR records"; we picked FHIR for the source and OpenEMR-records for the derived layer.

---

## Citation contract

Every clinical claim in a final response carries machine-readable citation metadata in the agent's NDJSON event stream:

```jsonc
{
  "source_type":          "DocumentReference" | "Observation" | "GuidelineChunk",
  "source_id":            "DocumentReference/abc-123",
  "page_or_section":      "Page 2" | "Section 4.1",
  "field_or_chunk_id":    "results[3].value" | "chunk-87",
  "quote_or_value":       "HbA1c 8.2%" | "Initiate metformin if eGFR ≥ 30",
  "bbox":                 [x, y, w, h]  // present when source is a PDF
}
```

**UI rendering rules:**

- Patient-record citations (DocumentReference, Observation) render as **blue chips**.
- Guideline-evidence citations (GuidelineChunk) render as **green chips**.
- Both expand on click; PDF-backed citations open `copilot_doc_viewer.php` with the bbox highlighted, GuidelineChunks open the source URL in a new tab.

**Click-to-source:** The Documents tab (`copilot_documents.php`, scaffolded in W1) is wired to FHIR DocumentReferences for the active patient. A new `copilot_doc_viewer.php` renders the Binary contents through PDF.js with bounding-box overlays sourced from `cp_extraction_citations`. The chat side-panel can deep-link into this viewer via `?docref={id}&bbox={citation_id}`.

---

## Eval gate

**Direction (post final-submission feedback, 2026-05-03).** The Week-1 grader praised our 25/25 pass rate + Dev/QA/Prod CI separation but pushed for *eval quality, not quantity*: more adversarial cases, replay from real sessions, system-level metrics tracked over time, and **deterministic checks over LLM-judge wherever possible**. We rebuilt the W2 eval design around that pushback rather than just doubling the case count.

**Case categories (50 total).** Goldens are no longer the majority of the suite.

| Category | Count target | Source |
|---|---|---|
| Goldens (deterministic ground-truth) | ~15 | Hand-authored, locked to seed snapshot |
| Labeled (open-ended; LLM-judge) | ~10 | Hand-authored, narrative outputs |
| **Adversarial** | ~10 | Corrupt PDFs, malformed FHIR, missing fields, fuzzed inputs, prompt-injection in extracted text, dropped tool results |
| **Replay** | ~10 | PHI-redacted production traces converted to cases via the existing `harvest_failures.py`. Set grows weekly from real traffic. |
| **System-level metrics** (assertions over the whole run, not cases) | n/a | `tool_call_accuracy`, `latency_p50_ms`, `latency_p95_ms`, drift-vs-baseline |

**Rubrics — boolean per case, deterministic-first.** Each rubric implements its deterministic check first; LLM-judge only handles the residual cases where deterministic checks can't reach.

| Rubric | Implementation |
|---|---|
| `schema_valid` | **Deterministic.** Pydantic re-validation of the extraction tool's `tool_use.input` against the relevant model — already wired in `copilot/agent/ingest/vision.py`. |
| `citation_present` | **Deterministic.** State-based — every clinical claim in the reply text must regex-match a citation_id surfaced earlier in the same trace. No judge call. |
| `factually_consistent` | **Deterministic-first.** Exact-match the cited value against the source (extracted `cp_extracted_facts.fact_json` or guideline chunk text). LLM-judge only for paraphrased synthesis. |
| `safe_refusal` | **Deterministic-first.** Pattern-match against a refusal classifier (existing `phi_redaction.py` patterns + a small refusal-signature list). LLM-judge only when the classifier is uncertain. |
| `no_phi_in_logs` | **Deterministic.** `redact()` pass over every Langfuse trace payload from the run; any hit fails the rubric. No judge. |

A case passes only if every applicable rubric returns true. Judge model when used: Claude Haiku 4.5.

**Regression gate.** `gate.py` compares the current run's per-rubric pass rate against `baseline.json` (committed) and **fails CI if any of**:

- Any rubric drops > 5 percentage points vs baseline
- Any rubric falls below its absolute floor: `schema_valid ≥ 0.95`, `citation_present ≥ 0.95`, `factually_consistent ≥ 0.85`, `safe_refusal = 1.00`, `no_phi_in_logs = 1.00`
- `tool_call_accuracy` < 0.90 OR drops > 5 pp vs baseline
- `latency_p95_ms` increases > 25% vs baseline

**Where it runs.** A new GitHub Actions workflow `.github/workflows/agent-evals.yml` runs on every PR, executes the full 50-case suite + system-metric assertions against the dev agent endpoint, and reports a required check. Branch protection on `main` makes the check non-bypassable. CircleCI's existing dev/qa/prod deploy gates remain in place downstream.

---

## Observability & cost

`copilot/agent/observability.py` extends the W1 instrumentation with:

- **Per-step latency rollups** emitted as Langfuse span scores: `extraction_ms`, `retrieval_ms`, `supervisor_ms`, `critic_ms`, `total_ms`.
- **Token usage → cost.** A new `cost_table.py` maps `(model, token_type)` → USD. Every Langfuse generation span gets a derived `usd_cost` score so the dashboard surfaces cost-per-encounter.
- **Retrieval hits.** Each top-5 chunk is logged with score, source_id, page — never the full text.
- **Extraction confidence distribution** summarized per encounter.
- **PHI-in-logs guard.** A post-hoc pass over every Langfuse trace runs the existing `redaction.py` PHI patterns; any hit fails the `no_phi_in_logs` rubric and gets surfaced in the eval report.

---

## Known tradeoffs & risks

| Tradeoff | Decision | Rationale |
|---|---|---|
| LangGraph dependency + streaming friction | Accept | Inspectable handoffs are non-negotiable per spec |
| pgvector adds 3rd Railway service per env | Accept | SQL-side hybrid + persistence is worth env duplication |
| Sonnet 4.6 PDF cap = 32 pages | Accept | Lab/intake docs are 1–3 pages; corpus chunker splits big PDFs |
| Voyage + Cohere added to BAA boundary | Accept | Both are HIPAA-eligible; W1 already assumes BAAs signed |
| Per-rubric boolean loses partial-credit nuance | Accept | Spec mandates booleans; partial credit hides regressions |
| Side-table for bbox citations | Accept | FHIR Observation has no native bbox shape |
| Critic + trend chart + 3rd doc type extensions | Stop-loss | Drop extensions if Thursday Early Submission isn't green |

**Highest risk items (extending the W1 risk register):**

1. ~~**OpenEMR FHIR write completeness.**~~ **GRADUATED 2026-05-05.** Verified by grep that no `POST /fhir/Binary`, no `POST /fhir/Observation`, and no DocumentReference-create exist. Pivoted to the hybrid-persistence path documented above (legacy `addNewDocument()` + `cp_*` side-tables). Risk closed.
2. **LangGraph + Anthropic streaming.** `astream_events()` v1 vs v2 and partial tool-call streaming are both finicky. Plan: build a minimal streaming smoke before migrating chat off the W1 single-loop path.
3. **pgvector parity across envs.** Three Railway services to provision, three sets of credentials, three idempotent corpus seeds. Mitigation: corpus PDFs committed; `seed_corpus.sh` is idempotent and runs on every deploy. (MVP today uses an in-memory BM25 retriever over a hand-curated `seed_corpus.json` to avoid this provisioning blocker.)
4. **Scope sprawl.** All three extensions chosen against the spec's "narrower is better" warning. Hard stop-loss: if Thursday Early Submission is not green, the critic and trend-chart get dropped before the third doc type does (medication list reuses ingestion infra).

---

## Week 2 vs Week 1 boundary

Graders should be able to run the W2 flow without guessing what's new. Quick reference:

| Capability | Week 1 (baseline) | Week 2 (new) |
|---|---|---|
| Read patient FHIR data | ✅ 6 tools | ✅ unchanged |
| Single-loop Anthropic agent | ✅ | ✅ available as fallback |
| Multi-agent supervisor + workers | — | ✅ LangGraph |
| Upload + extract clinical PDF | — | ✅ lab + intake + med-list |
| Strict-schema extraction | — | ✅ Pydantic + tool_choice |
| Persist source + facts | — | ✅ Source via legacy `addNewDocument()` (auto FHIR-readable); facts in `cp_*` side-tables |
| Hybrid RAG over guidelines | — | ✅ pgvector + Voyage + Cohere |
| Bounding-box citation overlay | — | ✅ Documents tab + PDF.js |
| 50-case eval suite | 25 cases, float scoring | 50 cases, per-rubric booleans |
| PR-blocking eval gate | Pre-push smoke (5 cases) | GitHub Actions required check |
| PHI redaction in traces | ✅ existing patterns | ✅ + `no_phi_in_logs` rubric |
