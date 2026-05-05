# W2 Architecture: Multimodal Evidence Agent

> Standalone Week-2 architecture document required by the AgentForge Week 2
> submission spec. The full Week-1 baseline + Week-2 extensions live in
> [`ARCHITECTURE.md`](./ARCHITECTURE.md); this file is the focused W2 view.

## Architectural decisions at a glance

The twelve load-bearing decisions for Week 2, with rationale. Every subsequent section in this document expands on one of these.

| # | Decision | Choice | Why |
|---|---|---|---|
| 1 | Orchestration | **LangGraph** `StateGraph` (supervisor + 3 workers + critic) | Spec names it; inspectable handoffs; native Langfuse callbacks |
| 2 | Vector store | **pgvector on a new Railway Postgres service** (one per env: Dev/QA/Prod) | Persistence; SQL-side hybrid (cosine + ts_rank); clean env story |
| 3 | Persistence | **Hybrid** — legacy `addNewDocument()` writes the source PDF (auto-readable via `GET /fhir/DocumentReference?patient=…`); derived facts in side-tables `cp_extracted_facts` + `cp_extraction_citations` + `cp_extraction_runs`, each row carrying explicit `derivedFrom: DocumentReference/{id}` | OpenEMR's FHIR R4 surface has **no** `POST /fhir/Binary`, **no** `POST /fhir/Observation`, only `POST /fhir/DocumentReference/$docref` (a CCDA operation, not a create). Verified 2026-05-05. Spec explicitly allows "FHIR resources OR OpenEMR records." |
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

## Summary

Week 1 shipped a tool-using Co-Pilot that reads structured FHIR data and answers physician questions in the 90-second-between-rooms window. Week 2 extends that agent in three directions: it can now **see** real clinical documents (scanned lab PDFs, intake forms, external medication lists), **route** work across an inspectable supervisor + worker graph, and **prove** quality with a 50-case eval suite that gates every PR.

**What changed architecturally.** The single-tool Anthropic loop from Week 1 is wrapped in a LangGraph `StateGraph` with a Sonnet-4.6 supervisor and four worker nodes: `intake_extractor`, `evidence_retriever`, `critic`, and `final_answer`. The supervisor's routing decisions (and every handoff) are written into LangGraph state and emitted as Langfuse spans, making them inspectable rather than a black box. Week 1's six FHIR read tools are still available and the legacy single-loop path stays operational as a fallback.

**Document ingestion.** A new `attach_and_extract(patient_id, doc_type, document_id|file_path)` tool accepts a PDF, ships it to Sonnet 4.6 as a native `document` content block, and uses forced tool-use to coerce a Pydantic-validated JSON extraction. The source PDF is persisted via OpenEMR's legacy `addNewDocument()` pipeline (`library/documents.php`) — the same `documents` table that backs `GET /fhir/DocumentReference?patient=:pid`, so the source round-trips through FHIR for free with no new controller code. Each derived fact lives in `cp_extracted_facts` with an explicit `derivedFrom: DocumentReference/{id}` field; bounding-box metadata sits alongside in `cp_extraction_citations`. We pivoted to this hybrid path on 2026-05-05 after a Day-1 grep confirmed OpenEMR has no `POST /fhir/Binary`, no `POST /fhir/Observation`, and only the `$docref` operation for DocumentReference — so a "full FHIR write" path would have required ~300 lines of new REST controller plumbing for no behavioral gain over the hybrid.

**Hybrid RAG.** A new `pg-rag` Railway service (Postgres 16 + pgvector, one per env) holds a small clinical-guideline corpus (~250 chunks across ADA, ACC/AHA HTN, USPSTF, GINA, KDIGO). Retrieval is hybrid: cosine top-30 (Voyage-3 embeddings) ∪ ts_rank top-30 (BM25-style sparse) → Cohere Rerank → top-5 evidence chunks fed to the answer model. Every retrieved chunk carries source_id, page, section, and exact quote to satisfy the citation contract.

**Eval gate.** The Week-1 25-case suite grows to 50 and switches from float scoring to per-rubric booleans (`schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`, `no_phi_in_logs`). A `gate.py` compares each run against a checked-in `baseline.json` and fails CI if any rubric category drops more than 5% or below an absolute threshold. The gate runs as a required GitHub Actions check on every PR; CircleCI keeps doing deploy gating downstream.

**Key tradeoffs:** LangGraph adds a dependency and some streaming friction, but its handoff inspectability is the right answer for the spec's "no black-box supervisor" pitfall. pgvector adds a new Railway service per env, but persistence and SQL-side hybrid search are worth it. Sonnet-4.6 native PDF input means one round-trip and one set of bounding boxes; we accept the 32-page-per-call cap.

---

## Worker graph

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

**Framework:** [LangGraph](https://langchain-ai.github.io/langgraph/) `StateGraph`. Chosen because the spec calls it out by name, handoffs and routing decisions live in declared state, and Langfuse has first-class LangChain/LangGraph callbacks.

**State (`AgentState`):**

| Field | Purpose |
|---|---|
| `session_id`, `patient_id` | Server-enforced context |
| `messages` | Anthropic-format conversation |
| `pending_doc_uploads` | Drained by the supervisor |
| `extracted_facts_cache` | `{doc_id: ExtractedFacts}` for follow-up turns |
| `evidence_cache` | `{query_hash: list[EvidenceChunk]}` |
| `citations` | All citations referenced in the in-progress reply |
| `handoff_log` | Append-only routing log emitted to Langfuse |

**Streaming:** LangGraph's `astream_events()` is bridged into the existing FastAPI NDJSON event stream. New event types: `handoff`, `extraction_progress`, `retrieval_hit`. Existing W1 events (`tool_start`, `tool_end`, `delta`, `done`) remain.

**Memory:** Session-scoped, in-process, 30-min TTL. Caching extracted facts and retrieval results avoids re-running expensive workers on follow-ups, which is critical for the 90-second goal.

---

## Document ingestion flow

```
1. Browser uploads PDF via OpenEMR's existing Documents tab
   → multipart POST → library/ajax/upload.php
   → addNewDocument()  (controllers/C_Document::upload_action_process)
   → row in `documents` (id, foreign_id=patient, url=file://…)

2. UI / agent triggers extraction
   POST /extract on the agent  { patient_id, document_id, doc_type }
   → attach_and_extract(...) resolves document_id → on-disk path
   → cross-checks foreign_id == patient_id (PermissionError otherwise)

3. Vision extraction
   Sonnet 4.6 with `document` content block + tool_choice forces
   extract_lab_report / extract_intake_form / extract_medication_list
   Pydantic re-validates the model's tool_use input on our side

4. Persist derived facts
   INSERT cp_extraction_runs (run_id, document_id, patient_id, doc_type, model)
   For each extracted clinical fact:
     INSERT cp_extracted_facts
        doc_id, patient_id, doc_type, fact_type, fact_json,
        confidence, source_quote, extraction_run_id
     INSERT cp_extraction_citations
        fact_id, page, bbox_{x,y,w,h}, field_path, quote
   UPDATE cp_extraction_runs SET status, fact_count, latency_ms,
        input_tokens, output_tokens, schema_valid

5. Return
   { run_id, document_id, patient_id, doc_type, schema_valid,
     fact_count, citation_count, latency_ms, payload }
```

**Round-trip integrity contract.** The source PDF lands in OpenEMR's stock `documents` table — the exact storage `GET /fhir/DocumentReference?patient=…` reads from — so any FHIR client can discover uploaded sources without our agent in the loop. Derived facts sit alongside in `cp_extracted_facts` keyed by `document_id` with an explicit `derivedFrom: DocumentReference/{id}` field, and `cp_extraction_citations` foreign-keys to `cp_extracted_facts.id` with `ON DELETE CASCADE`. A custom `GET /copilot/extractions/{patient_id}` route on the agent surfaces the derived layer.

**Why not full FHIR POST.** Verified 2026-05-05 against `apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php` and `src/Services/FHIR/`: OpenEMR has no `POST /fhir/Binary`, no `POST /fhir/Observation`, no `FhirBinary*` service class at all, and `POST /fhir/DocumentReference/$docref` is only the CCDA-export operation. Full FHIR write would have required ~300 lines of new REST controller plumbing, new OAuth scopes, and a multi-week PR upstream — for no behavioral gain over the hybrid the spec explicitly permits.

---

## Schemas

Pydantic models in `copilot/agent/ingest/schemas.py`. Each exports a JSON schema consumed directly by Anthropic tool-use.

| Schema | Required fields |
|---|---|
| `LabReport.results[]` | `test_name, value, unit, reference_range, collection_date, abnormal_flag, source_citation` |
| `IntakeForm` | `demographics{first_name,last_name,dob,sex}, chief_concern, current_medications[], allergies[], family_history[]`, all with per-field `source_citation` |
| `MedicationList.medications[]` | `medication_name, dose, frequency, route, prescriber, source_citation` |
| `Citation` (shared) | `source_type, source_id, page_or_section, field_or_chunk_id, bbox: [x,y,w,h], quote_or_value` |

Validation runs **inside** the extraction tool — the model is forced into the schema by `tool_choice`, not asked to "please return JSON." Unknown fields are `null` with `confidence < 0.5`; downstream code treats null + low-confidence as "not present."

---

## RAG design

**Service:** `pg-rag` — a new Railway Postgres service (one per Dev/QA/Prod env) running Postgres 16 + pgvector. Separate from the OpenEMR DB and Langfuse Postgres.

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

**Retrieval:**

1. Sparse: `ts_rank_cd(tsv, plainto_tsquery(query))` top-30
2. Dense: `1 - (embedding <=> voyage_3_embed(query))` top-30
3. Union, dedupe, keep up to 60 candidates
4. Cohere Rerank v3.5 → top-5
5. Each result: `{source_id, source_url, page, section, text, score}` — required for citations

**Embedding model:** Voyage-3 (1024 dim). Anthropic-recommended; biomedical-strong; HIPAA-eligible under BAA.

**Corpus inventory:**

| Source | Coverage | Chunks (≈) |
|---|---|---|
| ADA Standards of Care 2024 | T2DM | 80 |
| ACC/AHA Hypertension 2017 | HTN | 40 |
| USPSTF: statins for CVD prevention | Hyperlipidemia | 15 |
| GINA Asthma 2024 | Asthma | 60 |
| KDIGO CKD 2024 | CKD | 40 |
| (room for ~3 more) | | ~15 |
| **Total** | | **~250** |

**Refresh:** `python -m copilot.agent.rag.ingest_corpus` runs locally; output committed and idempotently reapplied on each env's `pg-rag` service via CircleCI.

---

## Citation contract

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

**UI rendering:** patient-record citations render as **blue chips**; guideline-evidence citations render as **green chips**. Both expand on click; PDF-backed citations open `copilot_doc_viewer.php` (PDF.js + bbox overlay) at the highlighted region. GuidelineChunks open the source URL in a new tab.

---

## Persistence & round-trip integrity

Week 1 used only FHIR R4 *read* scopes. Week 2 needs a write surface for uploaded documents and the structured facts the agent extracts. We pivoted on Day-1 (2026-05-05) from a full-FHIR-write design to a **hybrid** persistence model after grep'ing the FHIR layer.

| Artifact | Where it lives | Write path | Read path |
|---|---|---|---|
| Source PDF | `documents` table + `sites/<site>/documents/<pid>/<file>` on disk | `library/documents.php::addNewDocument()` (legacy upload pipe) | `GET /fhir/DocumentReference?patient=:pid` (auto — same table) |
| Derived facts | `cp_extracted_facts` (new) | Agent INSERTs from `copilot/agent/ingest/persistence.py` | `GET /copilot/extractions/{patient_id}` (custom on agent) |
| Bbox citations | `cp_extraction_citations` (new) | Same INSERT transaction; FK + cascade | Joined with facts by the agent's read route |
| Run summary | `cp_extraction_runs` (new) | Agent INSERTs at start, UPDATEs at finish | Powers `schema_valid` rubric + cost/latency report |

Every `cp_extracted_facts` row carries a `document_id` FK to `documents.id` and an explicit `derivedFrom: DocumentReference/{id}` field in its `fact_json`. **No new FHIR scopes** — the agent's existing read-only `user/*.rs` scopes plus direct DB access to the `cp_*` tables are sufficient.

**What FHIR does NOT expose** (verified by grep, 2026-05-05):

- No `POST /fhir/Binary` route. No `FhirBinary*` service class.
- No `POST /fhir/Observation` route. `FhirObservationService` only exposes read paths.
- The only DocumentReference POST is `POST /fhir/DocumentReference/$docref` — a CCDA *operation*, not a resource create.

The hybrid model satisfies the spec's "round-trip through OpenEMR without creating duplicate or untraceable records" requirement: source is a stock OpenEMR row (no parallel storage), derived facts are explicitly attributable via FK + `derivedFrom`. Spec lets us pick "FHIR resources OR OpenEMR records"; we picked FHIR for the source (auto) and OpenEMR records for the derived layer.

---

## Eval gate

**Direction (post final-submission feedback, 2026-05-03).** Grader praised the W1 25/25 pass-rate + Dev/QA/Prod CI separation but pushed for *eval quality, not quantity*: more adversarial cases, replay from real sessions, system-level metrics tracked over time, and **deterministic checks over LLM-judge wherever possible**. We rebuilt the eval design around that pushback.

**Case categories (50 total).** Goldens are no longer the majority of the suite.

| Category | Count target | Source |
|---|---|---|
| Goldens (deterministic ground-truth) | ~15 | Hand-authored, locked to seed snapshot |
| Labeled (open-ended; LLM-judge) | ~10 | Hand-authored, narrative outputs |
| **Adversarial** | ~10 | Corrupt PDFs, malformed FHIR, missing fields, fuzzed inputs, prompt-injection in extracted text, dropped tool results |
| **Replay** | ~10 | PHI-redacted production traces converted to cases via `harvest_failures.py`. Set grows weekly from real traffic. |
| **System-level metrics** (not cases per se — assertions over the whole run) | n/a | `tool_call_accuracy`, `latency_p50_ms`, `latency_p95_ms`, drift-vs-baseline |

**Rubrics — boolean per case, deterministic-first.**

| Rubric | Implementation |
|---|---|
| `schema_valid` | **Deterministic.** Pydantic re-validation of the extraction tool's `tool_use.input` against the relevant model. Already wired in `vision.py`. |
| `citation_present` | **Deterministic.** State-based — every clinical claim in the reply text must regex-match a citation_id surfaced earlier in the same trace. No judge call. |
| `factually_consistent` | **Deterministic-first.** Exact-match the cited value against the source (extracted `cp_extracted_facts.fact_json` or guideline chunk text). LLM-judge only for the residual cases where exact-match doesn't apply (e.g. paraphrased synthesis). |
| `safe_refusal` | **Deterministic-first.** Pattern-match against a refusal classifier (existing `phi_redaction.py` patterns + a small refusal-signature list). LLM-judge only when the classifier is uncertain. |
| `no_phi_in_logs` | **Deterministic.** `redact()` pass over every Langfuse trace payload from the run; any hit fails the rubric. No judge. |

A case passes only if every applicable rubric returns true. Judge model when used: Claude Haiku 4.5.

**Regression gate.** `gate.py` compares against a checked-in `baseline.json`. CI fails if **any rubric drops > 5 pp vs baseline**, **any rubric falls below its absolute floor**, OR **any system-level metric regresses beyond its tolerance**:

| Signal | Floor / tolerance |
|---|---|
| `schema_valid` | ≥ 0.95 |
| `citation_present` | ≥ 0.95 |
| `factually_consistent` | ≥ 0.85 |
| `safe_refusal` | = 1.00 |
| `no_phi_in_logs` | = 1.00 |
| `tool_call_accuracy` | ≥ 0.90 (no >5 pp drop) |
| `latency_p95_ms` | no >25% increase vs baseline |

**Where it runs.** `.github/workflows/agent-evals.yml` — required check on every PR via branch protection. CircleCI's existing dev/qa/prod deploy gates remain.

---

## Observability & cost

`copilot/agent/observability.py` extends W1 with:

- **Per-step latency rollups** as Langfuse span scores: `extraction_ms`, `retrieval_ms`, `supervisor_ms`, `critic_ms`, `total_ms`
- **Token usage → cost.** New `cost_table.py` maps `(model, token_type)` → USD; every generation gets a derived `usd_cost` score
- **Retrieval hits** logged with score, source_id, page (never the full text)
- **Extraction confidence distribution** summarized per encounter
- **PHI-in-logs guard.** Post-hoc pass over every Langfuse trace runs the existing `redaction.py` PHI patterns; any hit fails the `no_phi_in_logs` rubric and surfaces in the eval report

---

## Risks & tradeoffs

| Tradeoff | Decision | Rationale |
|---|---|---|
| LangGraph dependency + streaming friction | Accept | Inspectable handoffs are non-negotiable per spec |
| pgvector adds 3rd Railway service per env | Accept | SQL-side hybrid + persistence is worth env duplication |
| Sonnet 4.6 PDF cap = 32 pages | Accept | Lab/intake docs are 1–3 pages; corpus chunker splits big PDFs |
| Voyage + Cohere added to BAA boundary | Accept | Both HIPAA-eligible; W1 already assumes BAAs signed |
| Per-rubric boolean loses partial-credit nuance | Accept | Spec mandates booleans; partial credit hides regressions |
| Side-table for bbox citations | Accept | FHIR Observation has no native bbox shape |
| Critic + trend chart + 3rd doc type extensions | Stop-loss | Drop extensions if Thursday Early Submission isn't green |

**Highest risk items:**

1. ~~**OpenEMR FHIR write completeness.**~~ **GRADUATED 2026-05-05.** Verified absent; pivoted to the hybrid-persistence path documented above. Risk closed.
2. **LangGraph + Anthropic streaming.** `astream_events()` v1/v2 quirks and partial tool-call streaming are finicky. Build a streaming smoke before migrating chat off the W1 single-loop path.
3. **pgvector parity across envs.** Three Railway services, three sets of credentials. Mitigation: corpus PDFs committed; `seed_corpus.sh` is idempotent and runs on every deploy. (MVP today uses an in-memory BM25 retriever over `seed_corpus.json` to avoid this provisioning blocker.)
4. **Scope sprawl.** All three extensions chosen against the spec's "narrower is better" warning. Hard stop-loss: if Thursday Early Submission is not green, drop critic and trend-chart before the third doc type (medication list reuses ingestion infra).

---

## Week 2 vs Week 1 boundary

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
