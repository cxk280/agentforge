# Architecture: Clinical Co-Pilot

## Summary (~500 words)

The Clinical Co-Pilot is a Python FastAPI sidecar service that runs alongside OpenEMR and provides a multi-turn conversational AI agent to physicians. It is not embedded in the PHP codebase — it runs as a separate container, communicates with OpenEMR exclusively via its FHIR R4 REST API, and authenticates using OAuth 2.0 with patient-scoped access tokens. A thin chat panel is injected into the OpenEMR patient record view via a new PHP page registered through `PatientMenuEvent`, which iframes the agent's React UI.

**Why a sidecar service, not a PHP module:** OpenEMR is a synchronous PHP monolith. Streaming LLM responses and managing multi-turn conversation state in PHP is possible but painful. A Python sidecar gives us async-native streaming (FastAPI + httpx), clean tool use patterns with the Anthropic SDK, and a deployment unit we can iterate on independently of OpenEMR's PHP build. The PHP footprint in OpenEMR is minimal: one new page (`interface/patient_file/summary/copilot.php`) that renders the iframe and passes the patient ID and session identity.

**Why FHIR over direct DB access:** The FHIR R4 endpoints normalize OpenEMR's messy underlying data (free-text dosages, mixed date formats, inconsistent nulls) into structured FHIR resources. They enforce OAuth scopes, so the agent's data access is explicitly bounded at the API layer. They also make the agent portable — if a future deployment uses a different FHIR-compliant EHR, the tools need only a URL change. Direct DB access would tie us to MariaDB schema details and bypass the ACL system.

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
│  │   mariadb    │    │  langfuse    │              │
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
                → MariaDB (ACL enforced by OpenEMR service layer)
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
