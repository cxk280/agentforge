Which# Audit: Clinical Co-Pilot / OpenEMR

## Summary (~500 words)

OpenEMR is a mature, HIPAA-aware EHR system with solid foundational security primitives — argon2id password hashing, timing-attack-resistant login, a fine-grained ACL system (phpGACL), and both session-based and OAuth 2.0 API authentication. However, several gaps require deliberate handling before attaching an AI agent.

**The most important finding is PHI exposure at the persistence layer.** The `patient_data` table stores SSN, driver's license, full demographics, and insurance data in plaintext. The database itself has no column-level encryption. This means any query path the AI agent uses — whether direct DB access or via the REST API — touches unencrypted PHI. Every design decision for the agent must account for this: no PHI in logs, no PHI in LLM trace payloads, no PHI persisted outside the OpenEMR database boundary.

**The second finding is dual authentication surface area.** OpenEMR supports both legacy PHP session cookies and a full OAuth 2.0 + SMART-on-FHIR stack. The AI agent must hook into one of these — the OAuth path is the right choice for a sidecar service because it enforces explicit scopes, supports token revocation, and creates a clean authorization boundary. Piggybacking the PHP session is tempting for speed but creates a trust boundary problem: the agent would inherit the full session with no scope limiting.

**Third: the ACL system (phpGACL) is real but requires explicit enforcement.** OpenEMR checks `AclMain::aclCheck()` throughout the UI layer, but raw database queries and API calls can bypass it if implemented carelessly. Any tool the agent calls must validate the requesting user's role and their relationship to the requested patient before returning data. This is the primary injection point for authorization enforcement in the agent layer.

**Fourth: the FHIR API is the best data access path for the agent.** Compared to the proprietary REST API, the FHIR R4 endpoints are more structured, scope-controlled, and return standardized resources that are easier to parse and attribute claims to. The FHIR `Observation`, `MedicationRequest`, `Condition`, `AllergyIntolerance`, and `Encounter` resources cover all primary agent use cases with consistent formats and patient-scoped filtering.

**Fifth: audit logging is built in but must be extended for the agent.** OpenEMR's `EventAuditLogger` tracks logins, logouts, and record access. Any agent query that retrieves PHI must call this logger — the same way the UI does — so the audit trail is complete. Gaps here are a HIPAA compliance failure, not just a best practice miss.

**Performance consideration:** First-boot Docker setup takes 10+ minutes due to npm/composer/SCSS compilation. In production, this is irrelevant, but it signals that OpenEMR is a heavyweight PHP monolith. The agent should not call OpenEMR APIs synchronously per-token in a streaming response — it should fetch and cache all relevant patient context at conversation start, then reason over it locally.

**BAA status:** Per Gauntlet AI project guidelines, we treat all LLM providers as having a signed BAA. In production, this must be a real contract before any PHI is sent to an LLM API.

---

## 1. Security Audit

### Authentication

- **Login mechanism:** `library/auth.inc.php` → `AuthUtils::confirmPassword()` with argon2id hashing via `AuthHash`
- **Timing attack mitigation:** `preventTimingAttack()` runs a dummy hash compare for invalid users to equalize response time, preventing username enumeration
- **MFA support:** `src/Common/Auth/MfaUtils.php` — optional TOTP/U2F, not enforced by default
- **External auth:** LDAP/Active Directory and Google OAuth2 supported via config
- **Session management:** `src/Common/Session/SessionWrapperFactory.php` + `SessionTracker.php` — session validated per request via `AuthUtils::authCheckSession()`, with expiration checks and forced destruction on logout
- **Password policy:** `users_secure` table tracks login failure counter (auto-lock threshold), password history for reuse prevention

**Risks:**
- MFA is optional — a compromised credential gives full access
- Session tokens in cookies; no explicit `SameSite=Strict` enforcement visible in config
- `users_secure.login_fail_counter` brute force protection exists but threshold is configurable and may be set loosely in demo installs

### Authorization (ACL)

- **System:** phpGACL (`src/Gacl/`) — generic ACL with ACOs (objects/permissions) and AROs (roles/users)
- **Check function:** `AclMain::aclCheck()` — called throughout UI layer before sensitive operations
- **DB tables:** `phpgacl_acl`, `phpgacl_aco`, `phpgacl_aro`, `phpgacl_aro_groups`, `phpgacl_groups_aco_map`
- **User roles:** physician, nurse, administrator, staff, patient (portal) — stored in `users` table

**Risks:**
- Raw database queries and direct service calls can bypass `AclMain::aclCheck()` entirely — the agent's tool layer must enforce this explicitly
- No patient-provider relationship enforcement at the DB level — the ACL grants role-level access, not per-patient access. A physician role can query any patient unless UI enforces the relationship. The agent must replicate this UI-level check.

### Data Exposure

- `patient_data` table: SSN, DOB, driver's license, full address, insurance data — plaintext, no column encryption
- `users_secure` table: hashed passwords — properly handled
- PHI traverses the REST API over HTTPS (when configured); HTTP is also available and used in dev
- FHIR bulk export (`GET /fhir/$export`) can dump the entire dataset — must be scope-restricted

**PHI in LLM context:** When the agent retrieves and sends patient data to the Claude API, that data leaves the OpenEMR boundary. Mitigations: minimize PHI in prompts (use IDs not names where possible), never log prompt/response content containing PHI, ensure BAA is in place.

### HIPAA-Relevant Gaps

- Default dev config (`OE_PASS: pass`) is trivially guessable — acceptable for demo, unacceptable in any shared deployment
- HTTP port (8300) active in dev — no redirect to HTTPS
- `GITHUB_COMPOSER_TOKEN` hardcoded in docker-compose.yml — not a PHI issue but a secrets management red flag for how credentials are handled in this project

---

## 2. Performance Audit

### First-Boot Overhead

On first Docker startup, OpenEMR runs:
1. `composer install` (~142 packages, including PHPStan dev dependencies)
2. `npm install` (large frontend dependency tree with deprecated packages)
3. SCSS compilation (`bootstrap-rtl` + custom themes via `interface/themes/`)

Total first-boot time: ~10–15 minutes. This is a one-time cost; subsequent starts are fast.

### Application Architecture Bottlenecks

- **PHP monolith:** Every request is a full PHP bootstrap. No persistent process like Node or Gunicorn — each request re-initializes framework state
- **No built-in caching layer:** No Redis/Memcached in the default stack. Repeated queries for the same patient hit the DB every time
- **ORM pattern:** Services use raw SQL queries against MySQL. Complex patient summaries (labs + meds + notes) require multiple sequential queries
- **N+1 risk:** Encounter list queries that then individually fetch notes per encounter are a known pattern in the codebase

### Agent Response Latency Implications

- If the agent calls OpenEMR APIs serially per tool call, expect 100–500ms per call depending on data volume
- A full patient brief (demographics + meds + last 3 labs + last encounter notes) will require 5–8 API calls
- Mitigation: fetch all context at conversation start in parallel, pass to agent as a single enriched context block. Do not call APIs per-turn in a multi-turn conversation.

### Key Tables by Size (Demo Data)

- `patient_data`: small in demo, but real-world installations have 10K–1M+ rows
- `form_observation`: high-volume — a single patient may have hundreds of lab observations
- `prescriptions`: moderate volume
- Indexes exist on `pid` (patient_id) foreign keys — point lookups are fast

---

## 3. Architecture Audit

### System Organization

OpenEMR is a PHP monolith organized as:

```
/interface/         — UI pages (PHP templates)
/src/               — Modern OOP layer (Services, RestControllers, Events, etc.)
/library/           — Legacy global include files
/apis/              — REST + FHIR API routing
/sql/               — Database schema and migrations
/modules/           — Pluggable modules
/docker/            — Docker development environments
```

**Layering pattern:**
- UI layer (`/interface/`) calls Service layer (`/src/Services/`)
- REST API layer (`/apis/` + `/src/RestControllers/`) also calls Service layer
- Services call raw SQL via `QueryUtils` / `sqlQuery()`
- No domain model / ORM — thin services over SQL

### Data Flow

```
Browser → PHP page (/interface/) → Service (/src/Services/) → MySQL
Browser → REST API (/apis/) → RestController → Service → MySQL
Agent → OAuth token → FHIR/REST API → RestController → Service → MySQL
```

### Integration Points for AI Layer

**Recommended: FHIR API + OAuth 2.0 sidecar service**

1. **Agent backend** (Python FastAPI) authenticates via OAuth 2.0 client credentials with scopes:
   - `user/Patient.rs`, `user/Observation.rs`, `user/MedicationRequest.rs`, `user/Condition.rs`, `user/AllergyIntolerance.rs`, `user/Encounter.rs`
2. **Chat UI** added to patient record via `PatientMenuEvent::MENU_UPDATE` — new tab that iframes the agent frontend
3. **Session passthrough:** UI passes OpenEMR session user identity to agent backend, which maps it to an OAuth token with appropriate scopes for that user's role
4. **Audit logging:** Agent backend calls `EventAuditLogger` (or equivalent) after each PHI access

**Key service files for agent tools:**
- `src/Services/PatientService.php` — demographics
- `src/Services/PrescriptionService.php` — medications
- `src/Services/ObservationLabService.php` — labs
- `src/Services/ObservationService.php` — vitals
- `src/Services/ConditionService.php` — problems/conditions
- `src/Services/AllergyIntoleranceService.php` — allergies
- `src/Services/EncounterService.php` — encounters
- `src/Services/ONoteService.php` — notes

**FHIR endpoints the agent will use:**
- `GET /fhir/Patient/:id`
- `GET /fhir/Observation?patient=:pid&category=laboratory`
- `GET /fhir/Observation?patient=:pid&category=vital-signs`
- `GET /fhir/MedicationRequest?patient=:pid&status=active`
- `GET /fhir/Condition?patient=:pid`
- `GET /fhir/AllergyIntolerance?patient=:pid`
- `GET /fhir/Encounter?patient=:pid&_sort=-date&_count=5`

---

## 4. Data Quality Audit

### Completeness

- Demo patient data is synthetic and relatively complete, but real-world OpenEMR installations are notoriously inconsistent
- Common missing fields: `patient_data.providerID` (no assigned provider), `form_observation.ob_unit` (missing units on labs), `prescriptions.end_date` (no end date = indefinitely active in UI)
- `lists_medication` is supplemental to `prescriptions` — some patients have data in one but not the other

### Consistency & Formatting

- Medication dosages stored as free-text strings — `dosage` field is varchar, not structured. "10mg" vs "10 mg" vs "10MG" are all valid values
- Lab values similarly free-text in `form_observation.ob_value` — no enforced type
- Date fields use mixed formats across legacy tables (`YYYY-MM-DD` vs Unix timestamps)
- FHIR layer normalizes much of this — another reason to use FHIR over raw DB queries

### Duplicate Records

- No unique constraint on patient name + DOB combination — duplicate patients can exist
- `prescriptions` may have overlapping date ranges for the same drug if not properly managed
- Agent must handle multiple active records for the same medication gracefully

### Agent Failure Modes from Data Quality

| Scenario | Agent Behavior Required |
|---|---|
| No labs on file | Return "No lab results found" — do not infer |
| Medication with no end_date | Treat as active, note uncertainty |
| Duplicate patient records | Surface the ambiguity, do not merge silently |
| Missing provider assignment | Cannot determine patient-provider relationship for ACL |
| Free-text dosage field | Quote the source text exactly, do not parse/normalize |

---

## 5. Compliance & Regulatory Audit

### HIPAA Audit Logging

- OpenEMR has `EventAuditLogger` (`src/Common/Logger/EventAuditLogger.php`) that logs access events to the DB
- Current coverage: logins, logouts, patient record views (via UI), API authentication events
- **Gap:** Direct API calls from the agent backend will not automatically trigger these log entries — the agent must explicitly call the audit logger or an equivalent endpoint after each PHI retrieval
- Required audit fields per HIPAA: who accessed, what record, when, from where (IP)

### Data Retention Policies

- OpenEMR itself has no built-in data retention/purge policies — clinical data is retained indefinitely by default
- HIPAA requires retention of medical records for minimum 6 years from date of creation or last use
- Agent conversation logs: must not retain PHI beyond what is medically/legally required. Default: do not persist conversation content containing PHI; only persist anonymized metadata (token counts, latency, tool call sequence)

### Breach Notification Obligations

- Under HIPAA, a breach of unsecured PHI requires notification to affected individuals within 60 days, HHS, and (if 500+ individuals) media
- The agent introduces a new breach vector: LLM API calls containing PHI. If the LLM provider suffers a breach, this may trigger notification obligations
- Mitigation: minimize PHI in prompts; use patient IDs rather than names/DOB where possible; rely on BAA with LLM provider

### BAA (Business Associate Agreement) Implications

- Any service that receives, creates, maintains, or transmits PHI on behalf of a covered entity is a Business Associate under HIPAA
- **The LLM provider (Anthropic) is a Business Associate** when the agent sends patient data to the Claude API
- Per Gauntlet AI project guidelines: all LLM providers are treated as having a signed BAA with training use disabled
- In production deployment: a real, signed BAA with Anthropic must be in place before live PHI is processed
- Agent logs (Langfuse or equivalent): if hosted externally, that service is also a Business Associate and requires a BAA. Recommended: self-host Langfuse, or use Langfuse Cloud only with a signed BAA and PHI-stripped trace payloads

### Summary of Compliance Gaps to Address

| Gap | Priority | Resolution |
|---|---|---|
| PHI in LLM prompts not logged | High | Log all PHI access via EventAuditLogger before API call |
| HTTP port active in dev | Medium | Enforce HTTPS redirect; disable HTTP in any shared deployment |
| Agent conversation content persistence | High | Never persist raw conversation content containing PHI |
| Langfuse trace payloads | High | Strip or hash PHI from all trace data before logging |
| No BAA for LLM in production | Blocking | Must have signed BAA before any real PHI is processed |
| phpGACL patient-level access not enforced at DB | High | Agent tool layer must enforce patient-provider relationship explicitly |
