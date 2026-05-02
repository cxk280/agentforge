# AgentForge — Clinical Co-Pilot

> An AI agent embedded directly inside an EHR, designed to give clinicians
> patient-specific clinical context in the **90-second window between
> exam-room visits**. Built as a one-week sprint deliverable for
> Gauntlet AI on top of [OpenEMR](https://open-emr.org).

## Live demo

| Service | URL | Credentials |
|---|---|---|
| OpenEMR + Co-Pilot UI | https://openemr-production-971e.up.railway.app | `admin` / `admin` |
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
3. **Eval suite** (`copilot/agent/evals/`) — 25 golden + labeled
   cases scored by Claude Haiku 4.5 as judge, results uploaded to
   Langfuse Datasets. Runs against the production agent.

The OpenEMR skeleton underneath provides the database schema
(MySQL/MariaDB-compatible), the auth/session layer, and the surrounding clinical
workflow primitives. The fork is intentionally light-touch on the
core OpenEMR code — almost all new files live in their own
namespaces (`copilot_*.php`, `copilot/agent/`, `tests/.../Copilot/`)
so the fork stays mergeable with upstream.

---

## Tech stack

| Layer | Technology |
|---|---|
| EHR shell | OpenEMR 7.x (PHP 8.2+) — MySQL 9.4 in Railway envs, MariaDB 11.8 in local dev |
| Agent runtime | Python 3.13, FastAPI, Anthropic SDK |
| Agent model | Claude Sonnet 4.6 (production), Haiku 4.5 (eval judge) |
| Patient data API | OpenEMR FHIR R4 endpoints |
| Observability | Self-hosted [Langfuse](https://langfuse.com) (Postgres + ClickHouse + Redis + S3) |
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

**Real (queries live data, persists user actions):**
- Patient demographics, encounter history, vitals, prescriptions,
  conditions, allergies, immunizations — all read from the OpenEMR
  schema.
- CRUD flows that write to the DB and persist across reload:
  - Create patient (Screen 22 → `patient_data` insert)
  - Post office note (Screen 49 → `onotes`)
  - Take payment (Screen 58 → `ar_session`)
  - Send e-Rx (Screen 25 → `prescriptions`)
  - Sign & lock encounter (Screen 23 → `form_encounter` update)
  - Invite / deactivate user (Screen 52 → `users`)
  - Add facility / add drug to inventory (Screens 54 / 60)
- Audit log (Screen 55) reads ~80k real entries from the OpenEMR
  `log` table.
- The Co-Pilot agent answers from real FHIR data for the active
  patient.

**Mocked (visually faithful but synthetic / static):**
- Some KPI tiles on the dashboard archetypes (Recalls, Aging,
  Pending Review) display plausible synthetic data because the
  underlying schema doesn't exist in the OpenEMR demo dataset.
- Lab trends / quality measures pages show illustrative numbers.
- The "Co-Pilot suggestion" card on encounter views is static text
  in the mock; the agent itself answers in the chat surface.

A complete view-by-view inventory is in
`/interface/main/copilot_mock_index.php` on the live demo.

---

## Compliance & HIPAA

This is a **demo deployment**. Real-world deployment would require
several compliance steps that are intentionally simplified here:

- **Langfuse + Anthropic + New Relic (planned)** — each is a third-party
  data processor. Sending PHI to any of them in production requires
  a signed Business Associate Agreement (BAA) with that vendor.
  For the purposes of this demo, we proceed under the **premise
  that BAAs have been executed**. None have actually been signed
  for this deployment.
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
  the production database is currently running on the official
  `mysql:9.4` Community image. MySQL Community's keyring
  components do not include native HashiCorp Vault, AWS KMS,
  or KMIP integration — those plugins are MySQL Enterprise
  features. Adding a real KMS-backed customer-managed key
  therefore requires (1) switching the image to MariaDB 11.x
  (which ships `hashicorp_key_management`, `aws_key_management`,
  and `kmip_key_management` in Community), (2) deploying
  HashiCorp Vault as an additional Railway service to hold the
  master key, (3) building a custom MariaDB Dockerfile to load
  the plugin and configure `innodb_encrypt_tables`, (4)
  migrating the existing data via `mysqldump` and re-running
  `ALTER TABLE ... ENCRYPTION='Y'` on each app table, and
  (5) repeating the rollout across Dev → QA → Prod with a
  rollback path. End-to-end estimate: 7–10 hours of careful
  work plus a maintenance window per environment.

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

Note the local-dev password is `pass`; on Railway production it's `admin`.

### 4. Seed demo data

The seed script is **idempotent** — it sets a marker row in
`globals.copilot_seed_v1` and skips on subsequent runs so UI
edits aren't clobbered. Run once per environment:

```
GET /interface/super/copilot_seed_demo_data.php?confirm=1
```

This adds: 8 provider/staff users, 4 facilities, 4 pharmacies,
10 drugs, 6 office notes, 7 documents, 5 immunizations. The
underlying OpenEMR demo dataset already has 14 patients, 32
prescriptions, ~80k audit log entries, etc. — those are
preserved.

### 5. Run the Co-Pilot agent (optional)

```bash
cd copilot/agent
pip install -r requirements.txt
export ANTHROPIC_API_KEY=sk-ant-...
uvicorn main:app --reload
```

Agent listens on http://localhost:8000.

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

### Agent evals

See `copilot/agent/evals/README.md`. 25 cases — a mix of `strict`
(deterministic substring grading) and `labeled` (Claude Haiku 4.5
rubric-graded). Results uploaded to Langfuse Datasets
(`copilot-golden-v1`). Runs against the production agent.

**Current baseline: 24/25 passed, avg score 0.92** (run captured in
`copilot/agent/evals/baseline.txt`).

```bash
cd copilot/agent/evals
pip install -r requirements.txt
export ANTHROPIC_API_KEY=...
export LANGFUSE_PUBLIC_KEY=...
export LANGFUSE_SECRET_KEY=...
export LANGFUSE_HOST=...
python run_evals.py            # full 25-case run
python run_evals.py --smoke    # 5-case smoke for pre-push
```

---

## Repository layout

```
copilot/
├── agent/                    # FastAPI Co-Pilot agent
│   ├── main.py               # /chat + /chat/stream endpoints, session store
│   ├── agent.py              # Anthropic loop + tool dispatch
│   ├── tools.py              # FHIR-backed retrieval tools
│   ├── observability.py      # Langfuse instrumentation
│   ├── fhir_client.py        # OpenEMR FHIR adapter
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
