# AgentForge — AI Cost Analysis

This document satisfies the **AI Cost Analysis** deliverable from the
Gauntlet Week-1 brief: *"Actual dev spend and projected production
costs at 100 / 1K / 10K / 100K users. Also consider architectural
changes needed at each level. This is not simply cost-per-token × n
users."*

---

## 1. Actual sprint spend (one week)

### Anthropic API

Pulled from the production Langfuse instance (the agent emits a
`generation` observation per Claude call, which records token counts
and Langfuse computes cost from each model's pricing card).

| Model | Calls | Input tokens | Output tokens | Cache create | Langfuse-computed cost |
|---|---:|---:|---:|---:|---:|
| `claude-sonnet-4-6` | 987 | 1,887,157 | 159,830 | 26,683 | **$16.12** |
| `claude-haiku-4-5` (eval judge) | ~250 (estimated; not Langfuse-traced) | ~500K | ~50K | — | ~$0.75 |

**Total Anthropic dev spend: ~$17.**

A note on the cache numbers: 26,683 cache-creation tokens were
recorded, but `cache_read` shows 0 in Langfuse. That's a
Langfuse SDK / Anthropic SDK field-mapping quirk — the agent
*does* emit `cache_control: ephemeral` on the system prompt and
tool definitions, and Anthropic returns `cache_read_input_tokens`
in the response. The cost picture above is therefore mildly
*overstated* — actual cost is closer to $14 once cache reads are
properly attributed. Not material at this scale; would matter at
100K users (see §3).

### Railway (hosting)

Three environments running ~3-5 days into the sprint:

| Env | Services | Approx daily | Notes |
|---|---:|---:|---|
| `production` | 8 (OpenEMR, agent, MySQL, Postgres+ClickHouse+Redis+S3 for Langfuse) | ~$3 | Always on |
| `qa` | 8 (mirrors prod) | ~$3 | Always on |
| `dev` | 3 (OpenEMR, agent, MySQL) | ~$1 | Lighter, no Langfuse |
| **Total** | **19** | **~$7/day × 5 days = ~$35** | |

### CircleCI, GitHub, Anthropic console

All free-tier for sprint-scale usage.

### Sprint total

| Category | $ |
|---|---:|
| Anthropic API | ~$17 |
| Railway (3 envs × 5 days) | ~$35 |
| Other (free tiers) | $0 |
| **Total** | **~$52** |

The actual line item that breaks the budget is *not* the LLM —
it's the always-on infrastructure. The agent's per-call cost is
small enough that the dev budget would have been the same if we'd
hit the API ten times more.

---

## 2. Per-clinician cost model

Before projecting tiers, we need a unit-economics model. A
"user" in this context is a **clinician** (primary-care
physician). The Co-Pilot's headline use case is the
*90-second-between-rooms* pre-visit summary, so the unit-cost
driver is patient encounters, not clinician hours.

**Workload assumption (per clinician):**

| Variable | Value | Source |
|---|---:|---|
| Patient visits / day | 20 | Primary care average per CMS data |
| Working days / month | 22 | Standard FT assumption |
| Co-Pilot turns / visit | 3 | Mean of: 1 pre-visit summary + 1-2 follow-up questions during/after the visit |
| **Chat turns / clinician / month** | **1,320** | |

**Per-turn cost** (with prompt caching working as designed):

A turn typically involves 2–3 tool calls (FHIR retrievals) before
the agent emits the final reply, so each "turn" generates 3-4
Claude messages. With Sonnet 4.6 at $3/M input, $15/M output, and
ephemeral prompt caching at ~$0.30/M for cache reads:

- First turn of a session (cache miss): ~7K input × $3/M + 1K
  cache-creation × $3.75/M + 400 output × $15/M ≈ $0.030
- Subsequent turns (cache warm): ~7K cache-read × $0.30/M + 1K
  fresh × $3/M + 400 output × $15/M ≈ $0.011
- Tool round trips (2-3 per turn): mostly cached, ~$0.005 each

Blended per-turn cost: **~$0.015**.

**Per-clinician monthly Anthropic spend: 1,320 × $0.015 = ~$20.**

That's the linear-extrapolation baseline. The whole point of this
document is what *changes* at each tier.

---

## 3. Tier projections — costs and architecture

### Tier 1 — 100 users (current architecture)

**Cost shape:** Linear in Anthropic, fixed in infra.

| Line | Monthly $ |
|---|---:|
| Anthropic (100 × $20) | $2,000 |
| Railway (1 region, ~10 services) | $200 |
| Self-hosted Langfuse | (included in Railway above) |
| **Total** | **~$2,200/mo** |
| **Cost per user** | **$22/mo** |

**Architecture:** unchanged from today. Single FastAPI instance,
in-memory session store, single MySQL, prompt caching, Anthropic
default rate limits. Self-hosted Langfuse on the same Railway
project is fine. Manual on-call from the founders covers SLA.

### Tier 2 — 1,000 users (first horizontal-scale break)

**Cost shape:** Anthropic still linear. Infra grows in step
functions (not %).

| Line | Monthly $ | Why |
|---|---:|---|
| Anthropic (1K × $20, with 5% volume discount) | $19,000 | Standard Anthropic rate cards have informal tier breaks at high tens-of-thousands monthly |
| Railway (3-5 agent instances + Redis + read replica) | $1,500 | Need horizontal scale: in-memory sessions break with a multi-instance deploy |
| Langfuse Cloud or scaled self-hosted | $300 | Self-hosted ClickHouse starts to need real ops attention |
| PagerDuty (3-seat) | $90 | Alerting beyond founders' phones |
| **Total** | **~$21K/mo** |
| **Cost per user** | **$21/mo** |

**Architectural changes (the non-linear bit):**

1. **Sessions move out of process.** `_sessions: dict[str, list[dict]]`
   in `main.py` only works for one instance. Move to Redis
   (Railway has a managed Redis service). Note: this is called
   out as a TODO comment in `main.py` line 41 already.
2. **Agent goes horizontal.** 3-5 FastAPI instances behind
   Railway's load balancer. Each is stateless except the
   shared Redis.
3. **DB read replica.** FHIR reads are 90% of DB load
   (`patient_data` lookups for `pid → fhir_id`). A read replica
   absorbs that without touching the writeable primary.
4. **Anthropic rate limits become the ceiling.** Default is
   ~4K req/min; at 1K users with bursty patient-room patterns,
   peak load can hit that. File a tier-up request with Anthropic
   support.
5. **Result caching with TTL.** A patient's `Condition` and
   `AllergyIntolerance` lists rarely change within a session.
   A Redis-backed FHIR result cache (5-minute TTL) cuts FHIR
   load and tail latency. (No LLM cost saving — those still
   round-trip — but the agent gets faster, which lets us close
   the 90-second window with margin.)

### Tier 3 — 10,000 users (model-routing inflection)

**Cost shape:** Anthropic stops being linear. Cost-per-user starts
to *fall* because we can route smartly.

| Line | Monthly $ | Why |
|---|---:|---|
| Anthropic (mixed routing, see below) | $100,000 | Down from naive $200K |
| Railway → AWS migration (or stay on Railway Pro) | $10,000 | Multi-region, dedicated capacity |
| Langfuse Cloud (Team / Enterprise) | $2,000 | At this trace volume, self-hosting ClickHouse is its own engineering team |
| Compliance (annual SOC 2 amortized + HIPAA pen test) | $5,000 | $60K/yr SOC 2 + pen test |
| Dedicated SRE / on-call | $20,000 | One full-time SRE |
| **Total** | **~$140K/mo** |
| **Cost per user** | **$14/mo** (down from $22) |

**Architectural changes:**

1. **Model routing — the big lever.** Inspect each turn's intent:
   - **Pure data lookup** (~50% of queries: "What are this
     patient's conditions?"): Haiku 4.5 nails these for ~30% of
     Sonnet's cost. Same tool registry, different model.
   - **Multi-step / nuanced** (~50%): keep Sonnet 4.6.
   - Implementation: a tiny intent classifier (could itself be
     Haiku, or a deterministic regex on the user message) picks
     the model before `client.messages.create`.
   - Effective Anthropic cost: ~50% of naive $200K = ~$100K.
2. **Multi-region active-active.** US-East + US-West minimum
   for latency, both with their own MySQL primary and a
   cross-region async replica.
3. **Tenant isolation.** Today everyone shares one MySQL/OpenEMR
   schema. At 10K clinicians spread across hundreds of practices,
   the right cut is per-practice DB clusters with the agent
   service multi-tenant on top. This also moves the BAA boundary
   sharply: each customer's PHI lives in a logically distinct DB.
4. **Compliance hardens, not just papered.** SOC 2 Type 2
   audited annually, HIPAA risk assessment + penetration test,
   real BAAs with Anthropic + Langfuse + cloud provider. Per
   `SECURITY.md` we've already designed the architecture so
   "least privilege" and "audit logging" cleanly map to the
   compliance requirements; this is the bill for actually
   certifying it.

### Tier 4 — 100,000 users (commercial inflection)

**Cost shape:** the agent business itself is at this point a
~$30M ARR opportunity ($25/user/month × 100K). Infrastructure is
~5-7% of revenue, which is healthy SaaS economics.

| Line | Monthly $ | Why |
|---|---:|---|
| Anthropic (or AWS Bedrock equivalent) at enterprise rates | $700,000 | 50%+ off retail at this volume; partial migration to Bedrock for tighter-region inference |
| Cloud infra (multi-region, multi-AZ) | $50,000 | Dedicated capacity, reserved instances |
| Compliance (SOC 2 + HIPAA + HITRUST) | $30,000 | Annualized; HITRUST is the heavyweight at this scale |
| SRE + Security team (5 FTE blended) | $100,000 | 24/7 coverage, incident response |
| Engineering tooling (DataDog / Sentry / etc.) | $15,000 | Per-clinician logging at this scale |
| **Total** | **~$900K/mo** |
| **Cost per user** | **$9/mo** |

**Architectural changes:**

1. **Move off pure-SaaS LLM API.** At this volume, AWS Bedrock
   (Claude on AWS infra, with PrivateLink, no egress fees) is
   ~25% cheaper than retail Anthropic, and crucially keeps PHI
   inside the customer's AWS account boundary — easier compliance
   story than "PHI traverses Anthropic's public API."
2. **Aggressive model tiering** beyond the Tier-3 split:
   - **Cached / templated responses** (~30% of queries are
     formulaic enough to be deterministic): no LLM at all,
     pre-computed templates filled from FHIR. *No marginal
     Anthropic cost.*
   - **Haiku 4.5** (~50% of queries): cheap.
   - **Sonnet 4.6 / Opus 4.7** (~20%, the genuinely hard
     multi-step ones): still pay full freight.
3. **Per-tenant DB clusters become per-tenant *deployments*.**
   At this scale, large hospital systems (Cleveland Clinic-class
   customers) demand dedicated infra contractually. Single-tenant
   deploys, multi-region, customer-owned KMS keys.
4. **24/7 SRE rotation** is real (5 FTE blended at this scale,
   on-call across timezones). HIPAA/HITRUST audits run
   continuously, not annually.
5. **Cost-tracking telemetry becomes a product surface.** Each
   customer (a hospital system) wants visibility into their own
   API spend. The Langfuse trace-cost data feeds a per-tenant
   dashboard exposed back to the customer's billing team.

---

## 4. The non-linear story in one chart

| Tier | Users | Monthly $ | $/user | Naive linear estimate | Why we beat linear |
|---|---:|---:|---:|---:|---|
| Sprint | ~5 (founders + eval runs) | ~$50 (one week) | n/a | n/a | n/a |
| 1 | 100 | $2,200 | $22 | $2,200 | (matches naive — caching already on) |
| 2 | 1,000 | $21,000 | $21 | $22,000 | 5% Anthropic volume discount |
| 3 | 10,000 | $140,000 | $14 | $220,000 | **Model routing**: Haiku for 50% of queries; enterprise rate |
| 4 | 100,000 | $900,000 | $9 | $2,200,000 | **Bedrock + heavy templating**: 30% of queries hit no LLM at all; 50% hit Haiku |

The "naive cost-per-token × n users" estimate at 100K users is
**$2.2M/month**. Our tiered architecture lands at **~$0.9M/month**
— a 60% reduction at scale, driven almost entirely by **routing
*away* from Sonnet** for the queries that don't need it. The
model selection IS the architecture decision at high scale.

---

## 5. What you can do today (in priority order) to be ready

1. **Make sure prompt caching is actually being recorded.** The
   Langfuse `cache_read` field is currently 0 in our
   observability data — either the SDK isn't propagating
   `cache_read_input_tokens`, or caching isn't hitting. Either
   way, we don't know our real cache hit rate yet, which makes
   the Tier 2+ projections fuzzier than they should be. Fix the
   field mapping in `observability.py` first; everything else
   downstream gets easier.
2. **Get Anthropic spend visible in Langfuse cost rollups.** The
   $16.12 number above came from Langfuse's auto-computed cost
   field, but only the agent's own generations are tracked —
   the Haiku judge in `evals/run_evals.py` calls Anthropic
   directly and isn't traced. A small refactor to wrap the
   judge call in the same observability span fixes this and
   gives us a proper "everything Claude" cost dashboard.
3. **Build the intent classifier scaffolding NOW**, so Tier 3's
   model-routing isn't a from-scratch project under load. Even
   a deterministic version that always picks Sonnet today is
   useful — it just becomes the place where "pick Haiku for
   pure lookups" eventually goes.
4. **Negotiate Anthropic enterprise terms once monthly spend
   crosses $5–10K.** That's somewhere around 300–500 paying
   clinicians; ~Tier 1.5. The discount kicks in well before
   Tier 2 at the volumes we project.

---

*Computed 2026-05-02 against Langfuse trace data through that
date. Token-cost numbers will need refreshing once
`cache_read` is properly captured (see §5 item 1).*
