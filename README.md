# AgentForge — Clinical Co-Pilot

> An AI agent embedded directly inside an EHR, designed to give clinicians
> patient-specific clinical context in the **90-second window between
> exam-room visits**. Built as a one-week sprint deliverable for
> Gauntlet AI on top of [OpenEMR](https://open-emr.org).

## Live demo

| Service | URL | Credentials |
|---|---|---|
| OpenEMR + Co-Pilot UI | https://openemr-production-971e.up.railway.app | `admin` / `pass` |
| Co-Pilot agent (FastAPI) | https://copilot-agent-production-41de.up.railway.app | — |
| Langfuse (observability) | https://langfuse-web-production-368f.up.railway.app | see operator |

CI/CD also runs against two pre-production environments. Each carries
the same seeded patient data as Prod; QA runs the full Langfuse stack
for eval traces, while Dev is intentionally minimal.

| Env | OpenEMR | Co-Pilot agent |
|---|---|---|
| Dev | https://openemr-dev-59c5.up.railway.app | https://copilot-agent-dev.up.railway.app |
| QA  | https://openemr-qa.up.railway.app | https://copilot-agent-qa.up.railway.app |

Once signed in, the Co-Pilot tab on any patient chart opens the chat
view backed by the agent. The "Mock Index" page at
`/interface/main/copilot_mock_index.php` lists every redesigned screen
and is the fastest way to walk the breadth of the demo.

---

## The problem

Primary-care clinicians spend roughly 30% of clinical time on chart
review and documentation. Between exam rooms they have ~90 seconds to
re-orient themselves on the next patient: open problems, current meds,
recent labs, last visit's plan. The standard EHR makes this a
multi-tab, multi-click affair. The cognitive context-switch tax is
real and measured.

The Co-Pilot is a chat-style assistant that already knows which
patient you're looking at and can answer in plain language: *"What's
been happening with this patient's diabetes?"*, *"Are any of their
current meds a concern given the CKD?"*, *"Refill Lisinopril 90 days
to CVS."* It's tool-using — it pulls real records via the EHR's FHIR
API rather than guessing — and every interaction is traced in
Langfuse for review.

---

## What's in this repo

This is a **fork of OpenEMR** with three layered pieces of new work:

1. **Clinical Co-Pilot agent** (`copilot/agent/`) — FastAPI service
   running an Anthropic Claude agent with FHIR-backed retrieval tools.
2. **Co-Pilot UI redesign** (`interface/.../copilot_*.php`) — a
   complete reskin of the OpenEMR UI built to a Figma design system.
   ~50 pages covering every clickable view in the application,
   each backed by a shared archetype CSS file
   (`public/copilot-archetype.css`). The redesign is screen-faithful
   to the Figma reference.
3. **Eval suite** (`copilot/agent/evals/`) — 53 golden + labeled
   cases scored by Claude Haiku 4.5 as judge, results uploaded to
   Langfuse Datasets. Runs against the production agent.

The OpenEMR skeleton underneath provides the database schema
(MySQL), the auth/session layer, and the surrounding clinical
workflow primitives. The fork is intentionally light-touch on the
core OpenEMR code — almost all new files live in their own
namespaces (`copilot_*.php`, `copilot/agent/`, `tests/.../Copilot/`)
so the fork stays mergeable with upstream.

---

## Tech stack

| Layer | Technology |
|---|---|
| EHR shell | OpenEMR 7.x (PHP 8.2+, MySQL 9.4) |
| Agent runtime | Python 3.13, FastAPI, Anthropic SDK |
| Agent model | Claude Sonnet 4.6 (production), Haiku 4.5 (eval judge) |
| Patient data API | OpenEMR FHIR R4 endpoints |
| Observability | Self-hosted [Langfuse](https://langfuse.com) (Postgres + ClickHouse + Redis + S3) for agent traces; [New Relic](https://newrelic.com) APM + log forwarding for both services |
| Frontend (mocks) | Inline CSS + shared `public/copilot-archetype.css` (no framework) |
| Hosting | [Railway](https://railway.com) (8 services in one project) |
| Tests | PHPUnit 11 (isolated suite) + Python eval harness |

---

## Architecture

```
                        ┌──────────────────────────────────┐
                        │  Browser (clinician)             │
                        │  https://openemr-production...   │
                        └──────────────┬───────────────────┘
                                       │
                  ┌────────────────────┴────────────────────┐
                  │                                         │
        ┌─────────▼──────────┐                   ┌─────────▼──────────┐
        │  OpenEMR PHP app   │  iframe + HTTP    │  Co-Pilot FastAPI  │
        │  (apache, php-fpm) │ ◄────────────────►│   chat endpoint    │
        └─────────┬──────────┘                   └─────────┬──────────┘
                  │                                        │
                  │ JDBC                          FHIR R4  │  Anthropic
                  ▼                                        ▼  Messages API
        ┌────────────────────┐                   ┌────────────────────┐
        │  MySQL 9.4         │                   │ Claude (Sonnet 4.6)│
        │  (patients, encs,  │                   └────────────────────┘
        │   Rxs, vitals,     │                              │
        │   audit log, ...)  │                              │ trace
        └────────────────────┘                              ▼
                                                  ┌────────────────────┐
                                                  │ Langfuse           │
                                                  │ (Postgres + CH +   │
                                                  │  Redis + S3 traces)│
                                                  └────────────────────┘
```

The Co-Pilot agent is **stateless across sessions** but maintains
chat-history per `session_id` in process memory. Each tool call
hits the OpenEMR FHIR API as the patient's authorized provider —
i.e. the same auth layer that gates the rest of the EHR.

---

## What's real vs what's mocked

This is a one-week demo. Some honesty about scope:

**Real (queries live data — every render reflects current DB state):**
- Patient demographics, encounter history, vitals, prescriptions,
  conditions, allergies, immunizations — all read from the OpenEMR
  schema.
- React pages with their tables / cards driven by live SQL (the PHP
  wrapper queries the DB and JSON-encodes the typed payload onto a
  `data-*` attribute on `#cp-root`):
  - Patient Finder (Screen 21 → `patient_data` join `users`)
  - Patient Dashboard / History / Issues / Transactions / Ledger /
    Documents / Immunization Registry / Assessments / Report /
    Patient Modules (Screens 11–17 → patient-context joins)
  - Visit History (Screen 30 → `form_encounter` join `users` +
    `openemr_postcalendar_categories`)
  - Encounter Detail (Screen 23 → `form_encounter` + most-recent
    `form_vitals` row)
  - Office Notes (Screen 49 → `onotes`)
  - Recalls (Screen 33 → `medex_recalls` join `patient_data`)
  - Authorizations (Screen 34 → `cp_authorizations`)
  - Patient Education (Screen 48 → `lists` ICD-10 problems)
  - Record Request (Screen 31 → `pharmacies` recipients)
  - Patient List Report (Screen 43 → cohort SQL with last-visit /
    primary-dx subqueries)
  - Prescription Report (Screen 92 → `prescriptions` join `users`)
  - Audit Log (Screen 55 → `log`, ~27k real entries)
  - Users & Groups (Screen 52 → `users` + `groups` + last-login)
  - Facilities (Screen 54 → `facility`)
  - Module Installer (Screen 106 → `modules`)
  - Coding & Lists (Screen 53 → `list_options` with per-list
    COUNT(\*))
- CRUD flows that write to the DB and persist across reload:
  - Create patient (Screen 22 → `patient_data` insert)
  - Post office note (Screen 49 → `onotes`)
  - Take payment (Screen 58 → `ar_session`)
  - Send e-Rx (Screen 25 → `prescriptions`)
  - Sign & lock encounter (Screen 23 → `form_encounter` update)
  - Invite / deactivate user (Screen 52 → `users`)
  - Add facility / add drug to inventory (Screens 54 / 60)
  - **Calendar (W2):** create / edit / delete events backed by
    `openemr_postcalendar_events`, filtered to the logged-in
    user via `pc_aid`.
- The Co-Pilot agent answers from real FHIR data for the active
  patient.

**Mocked (visually faithful but synthetic / static):**
- Pages whose backing tables aren't seeded in the demo DB show
  plausible synthetic data instead of empty state — Patient Tracker
  (no recent encounters), Lab Overview / Patient Results / Pending
  Review / Lab Documents (`procedure_result` empty), Aging /
  Billing Manager (`billing` empty), Inventory (`drug_inventory`
  empty), Quality Measures, Electronic Reports, e-Rx queue.
- KPI tiles on dashboard archetypes (Recalls KPIs, Aging buckets,
  Pending Review counts) — the underlying schema doesn't carry
  these aggregates.
- Lab trends / quality measures pages show illustrative numbers.
- The "Co-Pilot suggestion" card on encounter views is static text
  in the mock; the agent itself answers in the chat surface.

Per-page real-vs-mocked verification scripts live in
`frontend/.fidelity-references/<page>-verify.mjs` (gitignored;
each runs Playwright headless, parses the rendered DOM, and
cross-checks at least two values against `mysql -e ...`).

---

## Tool calling

The Co-Pilot agent uses **Anthropic-native tool use** (Claude's
`tool_use` / `tool_result` content blocks) — the model decides which
tool to call, the agent dispatches it locally, and the loop continues
until the model emits `stop_reason="end_turn"`. There's no LangChain
or hand-rolled function-calling planner in the W1 hot path; the
single-loop is in `copilot/agent/agent.py` and is ~120 lines start to
finish. The W2 LangGraph path (`graph.py`) wraps the same single-loop
inside a Sonnet-4.6 supervisor + worker graph, but every leaf still
calls Claude with the same tool schemas.

### Declared tools

All schemas live in `copilot/agent/tools.py::TOOL_SCHEMAS` and are
sent verbatim on every model request. Six are W1, two are W2.

| Tool | Backed by | Returns |
|---|---|---|
| `get_patient_summary` | `GET /Patient/{id}` + `Condition` + `AllergyIntolerance` | demographics, active conditions, allergies |
| `get_medications` | `GET /MedicationRequest?patient={id}` | active prescriptions |
| `get_recent_labs` | `GET /Observation?patient={id}&category=laboratory` | newest-first lab observations |
| `get_vitals` | `GET /Observation?patient={id}&category=vital-signs` | BP / HR / temp / weight / BMI / O2 sat |
| `get_visit_history` | `GET /Encounter?patient={id}` | encounters newest-first |
| `get_conditions` | `GET /Condition?patient={id}` | all problem-list entries (active + historical) |
| `search_guidelines` *(W2)* | `rag.retriever.search_with_meta()` | guideline chunks from hybrid sparse+dense retrieval |
| `get_extracted_facts` *(W2)* | `cp_extracted_facts` ⨝ `cp_extraction_citations` | structured facts from uploaded PDFs with bbox citations |

### Dispatch flow

```
client → /chat/stream
   ↓
agent.run_agent_stream()
   ↓
  loop:
    Anthropic Messages API (tool_use enabled, schemas attached)
    ↓
    stop_reason == "end_turn"  →  yield {type: "done"}
    stop_reason == "tool_use"  →  for each tool_use block:
                                    yield {type: "tool_start", name}
                                    TOOL_DISPATCH[name](**inputs)
                                    yield {type: "tool_end", ...}
                                  feed tool_result blocks back, continue
```

`patient_id` is **server-injected** on every tool call — the model
cannot redirect a tool to a different patient by hallucinating an ID
in the args. This is the W1 authorization invariant; it didn't change
for W2.

### What the demo UI sees (NDJSON event stream)

```
{"type":"tool_start","name":"get_recent_labs"}
{"type":"tool_end","name":"get_recent_labs","success":true,"error_type":null}
{"type":"delta","text":"The most recent A1C..."}
{"type":"done","history_length":7}
```

For `search_guidelines` specifically, `tool_end` now also carries a
**`retrieval` metadata block** plus `result_count` and `result_sources`:

```jsonc
{
  "type": "tool_end",
  "name": "search_guidelines",
  "success": true,
  "error_type": null,
  "result_count": 5,
  "result_sources": {"ada-2024-glycemic-target-most": "both", "...": "dense"},
  "retrieval": {
    "retrieval_mode":  "hybrid_sparse_dense",
    "sparse_model":    "bm25-okapi",
    "dense_model":     "voyage-3",
    "dense_enabled":   true,
    "fusion":          "rrf",
    "rrf_k":           60,
    "rerank_enabled":  false,
    "contributors":    ["both", "dense"],
    "corpus_size":     12
  }
}
```

The chat demo (`copilot/agent/static/chat.html` + `chat.js`) renders
this as an inline pill — green `Hybrid · sparse + dense` when the
dense leg is live, yellow `Sparse only` when only BM25 ran. Reviewers
can verify the dense layer fired without inspecting architecture
notes; this was added 2026-05-07 in response to early-submission
feedback.

### Tool-result shape: hybrid retrieval

`search_guidelines` now returns:

```jsonc
{
  "patient_id": "1",
  "query": "what's the blood sugar target?",
  "results": [
    {
      "chunk_id":      "ada-2024-glycemic-target-most",
      "source_type":   "GuidelineChunk",
      "source_id":     "ada-standards-of-care-2024",
      "source_title":  "ADA Standards of Medical Care in Diabetes 2024",
      "source_url":    "https://diabetesjournals.org/...",
      "section":       "6. Glycemic Goals",
      "page":          6,
      "quote":         "An A1C goal of less than 7% is reasonable for...",
      "score":         0.0325,        // final (rerank if used, else rrf)
      "bm25_score":    6.7056,
      "dense_score":   0.9941,        // null when VOYAGE_API_KEY unset
      "rrf_score":     0.032522,
      "rerank_score":  null,          // populated when COHERE_API_KEY set
      "source":        "both"         // "sparse" | "dense" | "both"
    }
  ],
  "retrieval": { /* same meta block as above */ }
}
```

The model only consumes `quote`, `source_id`, `source_url`, `section`,
and `page` — those satisfy the citation contract. The score block is
purely for the demo UI, `/search` API clients, and evals; the model
itself is not asked to reason over scores.

### W2 LangGraph path (`/chat/graph`)

Same six FHIR tools + same two W2 tools, but called by **worker
nodes** instead of the bare single-loop. The supervisor decides
whether `intake_extractor` and/or `evidence_retriever` should fire
before handing control to `final_answer`. When `evidence_retriever`
runs, it emits a dedicated `retrieval_hit` SSE event with the same
metadata shape `tool_end` uses — so the chat UI's hybrid pill works
on both paths uniformly.

### Reliability

- **Patient-scope guardrail.** The active `patient_id` is forced by
  `_execute_tools` after the model returns its `tool_use` args, so
  cross-patient queries are structurally impossible.
- **Per-tool spans + redaction.** Every tool call is wrapped in a
  Langfuse span (`span_tool_call`); the span output records only
  `success`, `error_type`, and `result_keys` — never values, never
  PHI.
- **Graceful retrieval degradation.** Hybrid retrieval falls back to
  BM25-only when `VOYAGE_API_KEY` is unset; the agent never 500s on
  a missing optional secret. The fallback is reported as
  `dense_enabled=false` in the retrieval block so it's still
  observable.
- **Tested.** The hybrid sparse+dense contract (per-component scores,
  `source` tag, cache invalidation, fallback metadata) is pinned by
  `copilot/agent/test_retriever_hybrid.py` (6 cases). The
  CircleCI `agent-unit-test` job runs that suite plus
  `test_phi_redaction.py` on every push and gates
  `deploy-agent-{dev,qa,prod}`.

---

## Week 2 — Multimodal Evidence Agent

> Graders should be able to run the W2 core flow without guessing.
> This section is the entry point.

Week 2 extends the Week 1 agent in three directions: it can now
**see** real clinical documents (lab PDFs, intake forms, external
medication lists), **route** work across an inspectable supervisor +
worker graph, and **prove** quality with a 53-case eval suite that
gates every PR.

### What's new in W2 vs the W1 baseline

| Capability | Where |
|---|---|
| Document ingestion (lab + intake + medication-list PDFs → strict-schema JSON) | `copilot/agent/ingest/` |
| Sample PDFs covering 3 lab + 2 intake + 2 med-list layouts | `copilot/agent/ingest/test_fixtures/samples/` |
| True hybrid sparse+dense RAG over a curated guideline corpus (BM25 + Voyage-3 → RRF → optional Cohere rerank) | `copilot/agent/rag/retriever.py` + `copilot/agent/guidelines/seed_corpus.json` |
| LangGraph supervisor + intake_extractor + evidence_retriever + critic + final_answer | `copilot/agent/graph.py` |
| New agent tools: `search_guidelines`, `get_extracted_facts` (visible to /chat directly) | `copilot/agent/tools.py` |
| Click-to-source bbox-overlay PDF viewer | `interface/patient_file/documents/copilot_doc_viewer.php` |
| Documents tab live data → bbox viewer link | `interface/patient_file/documents/copilot_documents.php` |
| 53-case eval suite with deterministic-first rubrics + per-rubric booleans (incl. semantic-only cases targeting the dense retrieval leg) | `copilot/agent/evals/` |
| `gate.py` + `.github/workflows/agent-evals.yml` PR-blocking eval gate | required check on `master` (enable via branch protection) |
| Cost / latency report generator | `copilot/agent/cost_table.py` + `scripts/cost_latency_report.py` |
| Lab-trend SVG sparkline endpoint | `GET /copilot/lab-trend/{patient_id}?test_name=…` |

### W2 architecture documents

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — full architecture, W1 + W2 in one place
- [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md) — focused W2-only view (submission deliverable)
- [`w2_architecture_deck.pptx`](w2_architecture_deck.pptx) — 11-slide architecture-defense deck

### How to run the W2 flow end-to-end

Prereqs: local dev stack up (`docker compose up --detach --wait` from
`docker/development-easy-light/`), agent running on `:8400` with
`ANTHROPIC_API_KEY` set, the W2 schema applied:

```bash
docker exec -i development-easy-light-mysql-1 mysql -uroot -proot openemr \
    < sql/copilot_w2.sql
```

Then:

```bash
# 1. Generate a sample lab PDF (one of seven layouts):
ls copilot/agent/ingest/test_fixtures/samples/

# 2. Run extraction + retrieval end-to-end:
python copilot/agent/scripts/mvp_demo.py \
    --pdf copilot/agent/ingest/test_fixtures/samples/lab_quest_style.pdf \
    --patient-id 1 \
    --query "metformin contraindicated CKD"

# 3. Open the Documents tab on the active patient — uploaded PDFs
#    appear in the "LIVE — uploaded on this chart" section linked
#    to the bbox viewer.

# 4. Send a clinical question to /chat — search_guidelines and
#    get_extracted_facts are now native agent tools, so the answer
#    can quote both chart-native FHIR data and W2 extractions /
#    guideline chunks with proper citations.
```

### Multi-user demo logins

After running the seeder (`/interface/super/copilot_seed_demo_data.php?confirm=1`), the seeded provider / nurse / front-desk / billing users can each log in with their own credentials. This drives the per-user calendar filter and per-user audit trail — different users now see different event sets:

| Username | Role | Password |
|---|---|---|
| `admin` | Administrator | `pass` |
| `erivera`, `apark`, `jpatel`, `llee`, `kkim` | Provider (MD/DO) | `demopass` |
| `mnunez` | Front desk | `demopass` |
| `schoi` | RN | `demopass` |
| `bhudson` | Billing | `demopass` |

> The seeder writes both `users.password` (legacy column) and `users_secure.password` (modern auth column). Existing deploys where the seed pre-dates the multi-user fix get their `users_secure` rows backfilled idempotently on the next visit to the seeder URL.

### W2 endpoints (agent)

- `POST /extract` — run extraction on an uploaded PDF
  (`{patient_id, doc_type, document_id|file_path}`)
- `POST /search` — hybrid sparse+dense RAG over the guideline corpus
  (`{query, top_k}`). Response includes a `meta` block with
  `retrieval_mode`, `sparse_model`, `dense_model`, `dense_enabled`,
  `fusion`, `contributors`, and `corpus_size`.
- `POST /chat/graph` — multi-agent LangGraph path (parallel to W1's
  `/chat/stream`); accepts `pending_doc_uploads` to drive
  intake_extractor mid-conversation
- `GET /copilot/extractions/{patient_id}[?doc_type=…]` — derived
  facts + bbox citations consumed by the doc viewer
- `GET /copilot/lab-trend/{patient_id}?test_name=…[&unit=…]` —
  inline-SVG sparkline over time

---

## Compliance & HIPAA

This is a **demo deployment**. Real-world deployment would require
several compliance steps that are intentionally simplified here:

- **Langfuse + Anthropic + New Relic** — each is a third-party
  data processor. Sending PHI to any of them in production requires
  a signed Business Associate Agreement (BAA) with that vendor.
  For the purposes of this demo, we proceed under the **premise
  that BAAs have been executed**. None have actually been signed
  for this deployment. Specifically:
    - **Anthropic**'s commercial Claude API is HIPAA-eligible under
      a signed BAA via the Enterprise plan.
    - **Langfuse** is self-hosted in this deployment, so traces stay
      inside the Railway project (no external processor) — but the
      managed Langfuse Cloud tier would require a BAA.
    - **New Relic** offers a HIPAA-compliant tier that requires a
      signed BAA before any PHI may be transmitted. The PHP and
      Python agents in this deployment scrub PHI via
      `attributes.exclude` patterns (request headers, parameters,
      response cookies), `transaction_tracer.record_sql=obfuscated`
      (SQL literals are replaced with `?`), and
      `strip_exception_messages.enabled=true` (exception bodies
      stripped before send). NR's account-level **High Security
      Mode** is *not* enabled (it would require a separate
      irreversible NR account toggle and supersedes per-agent
      config). URL paths and error messages can still leak PHI
      fragments, so the BAA is load-bearing in any real
      deployment. We assume one is in place for the demo.

      **Operational note for future operators:** Setting
      `NEW_RELIC_HIGH_SECURITY=true` on the agent without first
      enabling HSM at the NR account level causes the agent to
      silently fail registration in a retry loop ("agent run was
      None"). If you see APM apps not appearing in NR despite the
      Infrastructure entities reporting correctly, check this env
      var first. To turn HSM on properly: enable it in NR UI →
      Settings → Account → High Security (irreversible), then set
      the agent env var. We chose not to do this so the toggle
      stays reversible during the demo period.

      **NR Logs obfuscation rules (planned, not in place):** The
      cleanest way to mask MRN / SSN / phone / email patterns in
      forwarded logs is via NR's account-level Log obfuscation
      rules (Logs → Manage data → Obfuscation rules). This is a
      paid-tier feature and is **not enabled** during the Gauntlet
      demo period to avoid the upgrade cost. For a real
      production deployment under the BAA assumption above, the
      first hardening step is to upgrade to NR's paid tier and
      add obfuscation rules with this filter
      (`entity.name LIKE 'AgentForge%'`) and these patterns:
      `MRN[\s#]*\d{4,8}`, `\b\d{3}-\d{2}-\d{4}\b` (SSN),
      `\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}` (US phone),
      `[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}` (email),
      with method `MASK`.
- **Audit logging** — every tool call from the Co-Pilot agent
  flows through OpenEMR's existing `log` table, so PHI access is
  traceable to the authenticated user.
- **Encryption in transit** — TLS for browser → OpenEMR, browser →
  Co-Pilot agent, and agent → Anthropic API (Railway-managed
  certificates).
- **Encryption at rest** — provided by Railway's underlying
  Google Cloud Platform infrastructure: GCE persistent-disk
  encryption is on by default for every Railway volume. Per
  Railway's official statement: *"All data that you hold is
  encrypted at-rest on the storage level... at the lowest
  level."* Railway is **HIPAA-certified** and **SOC 2 Type 2 +
  SOC 3 attested** (Trust Center: https://trust.railway.com).

  **HIPAA compliance note:** under the HIPAA Security Rule,
  encryption at rest is an *addressable* implementation
  specification (§164.312(a)(2)(iv) and §164.312(e)(2)(ii)),
  not a strict requirement. Cloud-provider-managed disk
  encryption — combined with a signed BAA covering the
  storage layer — satisfies the addressable spec for the vast
  majority of HIPAA-aligned cloud deployments, and is the
  posture used by the major HIPAA-eligible cloud-EHR vendors.

  **Why a customer-managed key was scoped out of this demo:**
  the database runs on the official `mysql:9.4` Community
  image (matched across local dev + Railway dev/qa/prod).
  MySQL Community's keyring components do not include native
  HashiCorp Vault, AWS KMS, or KMIP integration — those
  plugins are MySQL Enterprise features. Adding a real
  KMS-backed customer-managed key therefore requires
  (1) switching the image to MariaDB 11.x (which ships
  `hashicorp_key_management`, `aws_key_management`, and
  `kmip_key_management` in Community), (2) deploying
  HashiCorp Vault as an additional Railway service to hold
  the master key, (3) building a custom MariaDB Dockerfile to
  load the plugin and configure `innodb_encrypt_tables`,
  (4) migrating the existing data via `mysqldump` and
  re-running `ALTER TABLE ... ENCRYPTION='Y'` on each app
  table, and (5) repeating the rollout across Dev → QA →
  Prod with a rollback path. End-to-end estimate: 7–10 hours
  of careful work plus a maintenance window per environment.

  Doing this on top of provider-managed disk encryption is
  worthwhile when a tenant's policy requires the customer (not
  the cloud provider) to hold the data-encryption key — but
  the seal-key bootstrap problem follows you even into Vault:
  Vault's own unseal keys have to live somewhere, and on a
  pure-Railway deployment they end up in Railway env vars,
  which moves the trust boundary from "Railway-managed disk
  encryption" to "Railway-managed env-var storage." A
  meaningful step beyond that requires sealing Vault with an
  external KMS (AWS KMS, GCP KMS), which adds a separate cloud
  signup + IAM surface area. For a one-week demo with no real
  patient data, the marginal security gain didn't justify the
  rollout risk in the remaining time. The path is documented
  here so a future operator can pick it up.
- **Access control** — uses OpenEMR's native ACL. The demo seeds
  realistic provider / nurse / front-desk / billing roles.
- **Database least-privilege (QA + Prod)** — the Co-Pilot agent
  connects to MySQL as a dedicated `copilot_agent` user with
  exactly the privileges it needs and nothing else: `SELECT` on
  `patient_data` (for OpenEMR-pid → FHIR-UUID resolution) and
  column-level `UPDATE (login_fail_counter)` on `users_secure` (for
  the post-token fail-counter reset). It cannot read other tables,
  cannot write any clinical data, and cannot escalate. Dev keeps
  root-level access for fast iteration.
- **Database network isolation (QA + Prod)** — Railway's public
  TCP proxy is disabled on both MySQL instances. The DB is
  reachable only on `mysql.railway.internal` from inside the
  Railway project's private network. Compromising any DB
  credential now requires first compromising the Railway-side
  network — not just acquiring the password.

If you are evaluating this for production use, the remaining
homework is signing the BAAs (with Anthropic, Langfuse, and
New Relic). Application-level InnoDB tablespace encryption
with a customer-managed key is only needed if your tenancy
or policy requires it — provider-managed encryption-at-rest
plus a BAA satisfies baseline HIPAA on its own.

### AI-specific threat model

LLM-backed agents introduce a threat surface that classic
EHR controls don't cover (XSS via model output, prompt
injection-driven data exfiltration, excessive agency, model
DoS). [`SECURITY.md`](./SECURITY.md) documents how each of
those four threat classes maps to AgentForge controls. At a
glance, the agent already gets the architectural defenses
right:

- **Excessive agency:** the agent has six read-only FHIR
  tools and zero write tools; the active patient_id is
  enforced in the agent loop, not in the prompt — so the
  model literally cannot query a different patient.
- **PHI leakage:** production Langfuse traces contain only
  hashed patient IDs and structural metadata (counts, tool
  names, latencies), never the patient data itself.
  Eval-trace uploads run a separate redaction pass for
  names / DOBs / MRN-shaped digit runs.
- **XSS via model output:** the chat UI's markdown renderer
  escapes input first and only injects a fixed set of
  structural tags, with no attribute pass-through. CSP +
  X-Frame-Options + nosniff are layered on top as a backstop.
- **Model DoS:** `/chat` is rate-limited (30 req/min per
  session, 30 req/min per IP via slowapi) and `max_tokens`
  is capped at 1024 per turn.

---

## Local development

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) running
- Git

### 1. Clone

```bash
git clone https://github.com/cxk280/agentforge.git
cd agentforge
```

### 2. Start the stack

```bash
cd docker/development-easy-light
docker compose up --detach --wait
```

**First boot takes 10–20 minutes** (image pull + composer install +
gulp build inside the container). Subsequent starts are fast.

The `--wait` flag blocks until the healthcheck passes; you can
watch progress with `docker compose logs -f openemr`.

### 3. Open the app

| Service | URL | Credentials |
|---|---|---|
| OpenEMR | http://localhost:8300/ | `admin` / `pass` |
| OpenEMR (HTTPS) | https://localhost:9300/ | `admin` / `pass` |
| phpMyAdmin | http://localhost:8310/ | root / `root` |

The Railway Dev/QA/Prod environments use the same `admin` / `pass` credentials as local-dev.

### 4. Seed demo data

The seed script is **idempotent** — it sets a marker row in
`globals.copilot_seed_v1` and skips on subsequent runs so UI
edits aren't clobbered. Run once per environment:

```
GET /interface/super/copilot_seed_demo_data.php?confirm=1
GET /interface/super/copilot_seed_demo_patients.php?confirm=1
```

The first call adds: 8 provider/staff users, 4 facilities, 4
pharmacies, 10 drugs, 6 office notes, 7 documents.

The second call imports the demo patient cohort from
`sql/copilot_seeds/demo_patients_v1.sql`: **5 patients** —
pids 1, 4, 5, 8, 17 (Ted Shaw, Eduardo Perez, Farrah Rolle, Nora
Cohen, Jim Moses) — plus their encounters, vitals, problems,
prescriptions, and immunizations. The dump is deduped at source
(regenerated 2026-05-07).

> Earlier deployments seeded a wider 15-patient cohort that
> included unused OpenEMR demo-stock patients (pids 2, 3, 6, 7,
> 9-15). To bring an existing dev/qa/prod env into parity with the
> current dump, apply `sql/copilot_seeds/cleanup_pre_v1_demo_patients.sql`
> once. It's idempotent (no-op on already-clean envs) and only
> deletes from the six tables the dump touches.

### 5. Run the Co-Pilot agent (optional)

```bash
cd copilot/agent
pip install -r requirements.txt
export ANTHROPIC_API_KEY=sk-ant-...
# Hybrid retrieval: BM25 always on. Dense leg activates when VOYAGE_API_KEY
# is set (Voyage-3 embeddings). Optional Cohere rerank when COHERE_API_KEY
# is set. Without either, /search and search_guidelines degrade gracefully
# to sparse-only and report dense_enabled=false in their meta block.
export VOYAGE_API_KEY=...           # optional, enables the dense leg
export COHERE_API_KEY=...           # optional, enables rerank on top
uvicorn main:app --reload
```

Agent listens on http://localhost:8000. The chat demo (`/static/chat.html`)
renders a `Hybrid · sparse + dense` pill inline on every retrieval — the
pill is yellow (`Sparse only`) when `VOYAGE_API_KEY` is unset, green when
the dense leg is live.

---

## Testing

### PHPUnit (isolated, runs in the dev container)

```bash
docker exec -i development-easy-light-openemr-1 sh -c \
  "cd /var/www/localhost/htdocs/openemr && \
   ./vendor/bin/phpunit -c phpunit-isolated.xml --filter Copilot"
```

97 tests total: 32 helper-function unit tests + 65 syntax-lint
tests across every `copilot_*.php` view.

### Pre-push hook

```bash
scripts/install-git-hooks.sh
```

Installs a hook that runs the Copilot suite before every push.
Bypass with `COPILOT_SKIP_PRE_PUSH=1` or `git push --no-verify`
(intentionally awkward — failures should be fixed, not skipped).

### Agent unit tests (Python)

```bash
cd copilot/agent
pip install rank-bm25
python -m unittest discover --pattern 'test_*.py'
```

Two suites: `test_phi_redaction.py` (13 cases pinning the log-scrubber
regex) and `test_retriever_hybrid.py` (6 cases pinning the hybrid
sparse+dense contract — that dense activates when `VOYAGE_API_KEY` is
set, results carry `bm25_score`/`dense_score`/`rrf_score`, the disk
cache invalidates on chunk text mutation, and the sparse-only fallback
keeps `/search` healthy when Voyage isn't configured). Both run on
every push via the CircleCI `agent-unit-test` job, which gates
`deploy-agent-{dev,qa,prod}`.

### Agent evals

See `copilot/agent/evals/README.md`. 53 cases — a mix of `strict`
(deterministic substring grading) and `labeled` (Claude Haiku 4.5
rubric-graded). Results uploaded to Langfuse Datasets
(`copilot-golden-v1`). Runs against the production agent. Includes
three semantic-only cases (`guideline-semantic-*`) that target the
dense retrieval leg by asking paraphrases the corpus doesn't contain
verbatim ("blood sugar target" → A1C, "reduced kidney function" →
eGFR thresholds, "how low should BP be" → <130/80).

**Last full-suite baseline: 24/25 passed, avg score 0.92** on the
prior 25-case set (`copilot/agent/evals/baseline.txt`); a fresh
baseline against the expanded 53-case set will be captured on the
next CI run after the dense-retrieval changes settle.

```bash
cd copilot/agent/evals
pip install -r requirements.txt
export ANTHROPIC_API_KEY=...
export LANGFUSE_PUBLIC_KEY=...
export LANGFUSE_SECRET_KEY=...
export LANGFUSE_HOST=...
python run_evals.py            # full 53-case run
python run_evals.py --smoke    # 5-case smoke for pre-push
```

---

## Repository layout

```
copilot/
├── agent/                    # FastAPI Co-Pilot agent
│   ├── main.py               # /chat + /chat/stream endpoints, session store
│   ├── agent.py              # Anthropic loop + tool dispatch
│   ├── tools.py              # FHIR-backed retrieval tools + W2 RAG/extraction tools
│   ├── graph.py              # W2 LangGraph supervisor + worker nodes
│   ├── observability.py      # Langfuse instrumentation
│   ├── fhir_client.py        # OpenEMR FHIR adapter
│   ├── rag/
│   │   └── retriever.py      # Hybrid BM25 + Voyage-3 + RRF + Cohere
│   ├── guidelines/
│   │   ├── seed_corpus.json
│   │   └── seed_corpus_embeddings.json   # disk-cached Voyage vectors
│   ├── ingest/               # PDF extraction pipeline (W2)
│   ├── static/               # chat.html + chat.js demo UI
│   ├── test_phi_redaction.py # 13 cases — log-scrubber regex
│   ├── test_retriever_hybrid.py  # 6 cases — hybrid sparse+dense contract
│   └── evals/                # Golden + labeled set + harness
│       ├── cases.json
│       ├── run_evals.py
│       └── requirements.txt
interface/
├── ...copilot_*.php          # ~50 redesigned mock views (search the
│                              tree for `copilot_` to enumerate)
├── main/copilot_helpers.php  # Shared formatting helpers
├── main/copilot_mock_index.php   # Walk-every-view nav page
└── super/copilot_seed_demo_data.php  # Idempotent seeder
public/
└── copilot-archetype.css     # Shared design-system CSS
scripts/
├── pre-push.sh               # Git hook
└── install-git-hooks.sh
tests/Tests/Isolated/Copilot/
├── CopilotHelpersTest.php    # 32 unit tests
└── CopilotPagesSyntaxTest.php  # 65 syntax-lint tests
```

The rest of the repository is upstream OpenEMR. See
`CONTRIBUTING.md` for upstream conventions.

---

## Sprint context

This was built as a one-week deliverable for the Gauntlet AI program.
Final review is **Sunday 2026-05-03 at noon CT**. Tradeoffs were made
to fit that window:

- Fork-and-extend rather than greenfield, so the auth layer, RBAC,
  audit log, and FHIR API came for free.
- Mock-faithful screens before deep behavior on each — you can click
  through every view, but only the high-value flows are wired
  end-to-end. The shared archetype CSS lets non-priority screens
  look real without bespoke implementation.
- LLM-authored draft eval cases (with DB-verified ground truth for
  the lookup categories) rather than clinician-authored evals. Plan
  is to evolve toward production-trace-driven evals over time.

---

## Upstream OpenEMR

This repository is a fork of [openemr/openemr](https://github.com/openemr/openemr).
For upstream documentation, contributing guidelines, the OpenEMR
community, and licensing, see:

- [open-emr.org](https://open-emr.org)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`API_README.md`](API_README.md), [`FHIR_README.md`](FHIR_README.md), [`DOCKER_README.md`](DOCKER_README.md)
- [`SECURITY.md`](.github/SECURITY.md) — security disclosure process

OpenEMR is licensed [GPL-3.0](LICENSE). All AgentForge additions
inherit that license.
