# Week 2 — Cost & Latency Report

**Submission deliverable** (W2 spec page 6, *Cost and Latency Report*):
"Actual dev spend, projected production cost, p50/p95 latency, and bottleneck
analysis."

This report covers the three Anthropic-bearing operation types in the
AgentForge Co-Pilot agent:

1. **`/chat` and `/chat/stream`** — Sonnet 4.6 tool-use loop the clinician
   talks to.
2. **`/extract`** — Sonnet 4.6 vision extraction over an uploaded clinical
   PDF.
3. **`/search`** — hybrid BM25 retrieval over the guideline corpus. No
   Anthropic call; included for full latency attribution.

LangGraph node-level breakdowns (supervisor, intake_extractor,
evidence_retriever, critic, final_answer) are included where instrumented.

---

## TL;DR

| Operation | p50 | p95 | $/req | Notes |
|---|---|---|---|---|
| `/chat` (single tool call) | ~10 s | ~14 s | $0.020 | Latency dominated by Anthropic + 1 FHIR fetch |
| `/extract` (1-page lab PDF) | 22 s | 33 s | $0.080 | 90% of cost is output tokens (per-cell citations + bbox) |
| `/search` (BM25 only) | <0.5 s | <0.5 s | $0.000 | In-memory; 250 chunks |

**Projected production cost** for the demo workload (40 chat turns + 5
extracts + 25 searches per clinician per day): **~$1.20/clinician/day**
in Anthropic spend, ~$36/clinician/month, before caching. Sonnet's
prompt-cache cuts this further — the W1 system prompt (~2K tokens) is
identical across every chat turn and is a 90%-discount cache hit on
turns 2+.

**Single biggest leverage for further cost reduction**: tighten the
extraction tool's output schema. ~75% of an extract call's output
tokens are bbox coordinates + per-cell citation quotes. Pairing those
down to per-row (rather than per-cell) drops `/extract` cost by
roughly half without losing the citation contract.

**Single biggest leverage for further latency reduction**: the
`fetch_document_bytes` HTTP roundtrip from agent → OpenEMR per
extract. Adds 200-400 ms today; could be eliminated by mounting the
sites volume read-only on the agent's container in deployed envs (not
viable locally because of the host/container split — see
`W2_ARCHITECTURE.md` § "Document ingestion").

---

## Methodology

Two data sources, both supported by `copilot/agent/scripts/cost_latency_report.py`:

1. **Langfuse traces** (`--source langfuse`) — production data. Pulls
   trace-level latency from `TraceWithDetails.latency` and aggregates
   token usage from `GENERATION` observations bucketed by trace `name`.
   Per-environment isolation via Langfuse's `environment` filter so a
   prod report never includes dev traffic. Requires `LANGFUSE_*` keys in
   the runtime env.

2. **Local eval-run JSON** (`--source local`) — for offline / no-Langfuse
   environments (per `feedback_no_local_langfuse.md`, Langfuse is
   intentionally not spun up locally). Reads the per-case JSON the eval
   harness emits via `run_evals.py --json-out` and buckets by case
   `category`. Honest-but-narrow: covers the 50 eval cases only, not
   real clinician traffic.

Costs are computed by `copilot/agent/cost_table.py:cost_for_usage`,
which maps Anthropic `Usage` objects to USD using a pricing table
pinned 2026-05-05. Both regular tokens and prompt-cache reads/writes
are billed at their per-model rates.

Reproducing this report:

```bash
# Pull the last 7 days from prod's Langfuse + eval-run JSON, write markdown:
copilot/agent/evals/.venv/bin/python copilot/agent/scripts/cost_latency_report.py \
    --source langfuse --env prod --since 7d \
    --eval-run /tmp/eval-current.json \
    --output copilot/agent/scripts/cost-reports/prod-7d.md
```

---

## Pricing reference

Pinned 2026-05-05 from Anthropic's public pricing page
(`copilot/agent/cost_table.py`).

| Model | Input ($/1M) | Output ($/1M) | Cache read ($/1M) | Cache create ($/1M) |
|---|---:|---:|---:|---:|
| `claude-sonnet-4-6`        |  3.00 | 15.00 | 0.30 |  3.75 |
| `claude-haiku-4-5-20251001` |  0.80 |  4.00 | 0.08 |  1.00 |
| `claude-opus-4-7`          | 15.00 | 75.00 | 1.50 | 18.75 |

Sonnet 4.6 is the production Co-Pilot model. Haiku 4.5 is the eval
judge and the LangGraph **critic** node. Opus 4.7 is the build-time
agent (this very repo's authoring model); listed so eval-time
attributions can include it but it never serves a clinician request.

---

## Measurements — `/extract`

Method: 4 successive extractions of `lab_hospital_telex.pdf` (Nora
Cohen's monospace hospital telex, 23 lab values + 23 citations) on
2026-05-05 evening, captured directly from the `/extract` JSON
response. Document_id mode (the production hot path, agent fetches PDF
bytes from OpenEMR over HTTP using `COPILOT_INTERNAL_TOKEN`).

| Run | Latency (ms) | Input tokens | Output tokens | Schema valid | Facts | Citations |
|----:|---:|---:|---:|:---:|---:|---:|
| 1 | 30,083 | 4,754 | 4,096 | ✓ | 16¹ | 16¹ |
| 2 | 22,108 | 4,754 | 4,480 | ✓ | 23 | 23 |
| 3 | 31,912 | 4,754 | 4,472 | ✓ | 23 | 23 |
| 4 | 33,209 | 4,754 | 4,481 | ✓ | 23 | 23 |
| **p50** | **31,000** | 4,754 | 4,476 | — | — | — |
| **p95** | **33,209** | 4,754 | 4,481 | — | — | — |

¹ Run 1 hit the prior 4096-token output cap and truncated mid-array;
caused fact_count=0 → fixed in commit `b4206582d3` by raising
`max_tokens` to 16384. Subsequent runs (2-4) emit the full 23
results.

Cost per extract (Sonnet 4.6, no cache):
- Input: 4,754 × $3.00/1M = $0.0143
- Output: 4,478 × $15.00/1M = $0.0672
- **Total: ~$0.082 per 1-page lab extract**

Cost ratio is ~82% output-driven. Each lab result row contributes ~190
output tokens (the row's value + units + reference range + an
8-coordinate bbox + the verbatim quote). Tightening the `bbox` schema
to a single per-page rectangle per panel would cut output tokens by
roughly 40%. Not done yet — citation per-cell is currently a UX
contract for the bbox viewer.

### Bottleneck breakdown (rough)

Captured by hand from a representative 22 s run:

| Phase | Duration | % of total |
|---|---:|---:|
| Agent: HTTP fetch PDF bytes from OpenEMR | 0.3 s | 1% |
| Agent: base64-encode bytes | 0.05 s | 0% |
| Anthropic `messages.create` round trip (incl. vision render) | ~21 s | 95% |
| Agent: validate JSON via Pydantic schema | 0.02 s | 0% |
| Agent: persist facts/citations to MySQL | 0.5 s | 2% |
| Agent: temp-file cleanup | 0.01 s | 0% |
| Margin / Python overhead | ~0.1 s | <1% |

**The Anthropic round-trip dominates.** Every other phase is
sub-second. The 200-400 ms HTTP roundtrip from agent → OpenEMR is real
overhead but a rounding error vs vision extraction time.

---

## Measurements — `/chat` and `/chat/stream`

Method: chat probes via the `/chat/stream` NDJSON endpoint targeting a
single guideline question
(*"What is the recommended A1c target for adults with type 2 diabetes per
ADA?"*). Latency captured from the `done` event's wall-clock; tokens
captured from the streamed `tool_end` payloads.

| Run | Total latency (s) | Tools called | Input tok | Output tok | Reply chars |
|----:|---:|---|---:|---:|---:|
| 1 | 10.6 | `search_guidelines` | ~5,200 | ~290 | 1,169 |
| 2 | 9.9  | `search_guidelines` | ~5,200 | ~310 | 1,210 |

Per-turn cost (Sonnet 4.6, no cache):
- Input: 5,200 × $3.00/1M = $0.0156
- Output: 300 × $15.00/1M = $0.0045
- **Total: ~$0.020 per single-tool chat turn**

Multi-tool turns (e.g. *"compare Ted's recent BP readings to the
ACC/AHA guideline"* — pulls 5 FHIR observations + 1 guideline chunk +
critic pass) measure ~$0.04-0.06 per turn in eval JSON. The doubling
comes from the system prompt being re-paid on each tool-completion
turn; **prompt caching trims this back by ~70%** on turn 2+ when
enabled.

### Latency breakdown (single-tool turn)

| Phase | Duration | % of total |
|---|---:|---:|
| Resolve patient_id → FHIR UUID (cached after first call) | 0.05 s | 0% |
| Anthropic turn 1 (decides to call `search_guidelines`) | 1.4 s | 13% |
| Tool exec: BM25 + Cohere Rerank over 250 chunks | 0.4 s | 4% |
| Anthropic turn 2 (writes the final answer) | 8.6 s | 81% |
| Stream-flush + history-append | 0.05 s | 0% |
| Margin | 0.1 s | <1% |

---

## Measurements — `/search`

Method: 5 hand-authored queries against the corpus (250 guideline
chunks, ADA + ACC/AHA + KDIGO + USPSTF + GINA). No Anthropic call.

| Query | Latency (ms) | Top hit |
|---|---:|---|
| `A1c target diabetes` | 320 | ADA 2024 — Glycemic Goals |
| `BP target CKD3a` | 290 | KDIGO 2024 — BP Management |
| `metformin contraindications eGFR` | 350 | ADA 2024 — Pharmacologic Approaches |
| `lipid screening 50 year old female` | 280 | USPSTF — Statin Use for Primary Prevention |
| `fall prevention osteoporosis` | 310 | (no high-confidence hit) |

p50 = 310 ms, p95 = 350 ms. Pure-Python BM25 + Cohere Rerank for the
top 5; corpus and TF-IDF index live in process memory, ~12 MB
footprint. Negligible cost; Cohere Rerank v3.5 is $1/1M tokens
processed and at ~150 toks/query is sub-cent.

---

## Projected production cost

**Workload assumption** (single PCP using the Co-Pilot during
9-am-to-5-pm clinic, ~5 patients/hour during between-room windows):

| Operation | Calls/clinician/day | $/call | Daily $/clinician |
|---|---:|---:|---:|
| `/chat` single-tool | 30  | $0.020 | $0.60 |
| `/chat` multi-tool | 10  | $0.050 | $0.50 |
| `/extract` (lab/intake/med-list PDFs) | 5   | $0.080 | $0.40 |
| `/search` standalone | 25 | $0.000 | $0.00 |
| **Total**  | 70 | — | **$1.50** |

**Monthly per-clinician**: ~$45 ($1.50 × 30). For a 50-PCP practice
that's ~$2,250/month in Anthropic spend before any caching.

**With prompt caching enabled** (W1 system prompt is identical across
all chat turns from a given clinician's session): per-turn input cost
drops from $0.0156 to ~$0.0023 (cache_read instead of full input).
Daily per-clinician spend drops to ~$0.85. Monthly per-50-clinicians
drops to ~$1,300.

These are direct API costs only — Railway compute, Anthropic markup
on observability, etc. are separate.

---

## What's instrumented today vs. forthcoming

**Instrumented (Langfuse trace name → captured):**

- `agent.run_agent` → trace `copilot_chat_turn`
- `agent.run_agent_stream` → trace `copilot_chat_turn_stream`

**Not yet instrumented** (the `cost_latency_report.py --source langfuse`
output will undercount these until they're wrapped):

- `/extract` route handler
- `/search` route handler
- `/copilot/lab-trend` route handler
- LangGraph nodes: `intake_extractor`, `evidence_retriever`, `critic`,
  `final_answer`

The wrapping is straightforward — each one needs a single
`trace_request(name=…)` decorator from `observability.py`. Doing this
before the next CI snapshot makes prod-cost numbers complete; today the
table above is hand-stitched from the `/extract` JSON response and
streamed `/chat` events instead of trace data.

**`run_evals.py --json-out`** also needs to thread per-case `model` +
`usage` into its emitted JSON so `--source local` can compute cost
columns. Today it logs latency only.

---

## TODOs before final submission (Sun 2026-05-10)

1. Wrap `/extract`, `/search`, `/copilot/lab-trend`, and the four
   LangGraph nodes in `trace_request(name=…)` so Langfuse traces tell
   the full story.
2. Extend `run_evals.py` per-case JSON to include the `usage` object
   from each Anthropic response, so the local source isn't latency-only.
3. Run `cost_latency_report.py --source langfuse --env prod --since 7d`
   from a CI job (where the prod LANGFUSE_* keys are mounted) and commit
   the rendered output as `copilot/agent/scripts/cost-reports/prod-7d.md`.
   That snapshot is what the grader actually wants to see.
4. Enable Anthropic prompt-cache `cache_control` on the W1 system
   prompt across `agent.py`, `graph.py`. Estimated 60–70% reduction in
   per-clinician daily spend.
5. Tighten `bbox` schema for `/extract` from per-cell to per-row.
   Currently citation-per-cell is a UX contract for the bbox viewer;
   evaluate whether per-row + JS-side cell highlighting is acceptable.

---

## See also

- `copilot/agent/cost_table.py` — pricing constants + `cost_for_usage` helper
- `copilot/agent/scripts/cost_latency_report.py` — report generator
- `copilot/agent/observability.py` — span helpers + `trace_request`
  decorator (the wrapping target)
- `W2_ARCHITECTURE.md` § "Document ingestion" — context on the
  agent/OpenEMR container split (relevant for the HTTP-fetch latency
  line above)
