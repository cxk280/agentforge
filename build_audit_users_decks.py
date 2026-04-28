"""Generate audit_deck.pptx and users_deck.pptx from AUDIT.md and USERS.md content."""
from pptx import Presentation
from pptx.util import Inches, Pt
from pptx.dml.color import RGBColor
from pptx.enum.text import PP_ALIGN

# ── Palette ───────────────────────────────────────────────────────────────────
NAVY   = RGBColor(0x0D, 0x1B, 0x2A)
TEAL   = RGBColor(0x00, 0x8C, 0x8C)
WHITE  = RGBColor(0xFF, 0xFF, 0xFF)
LIGHT  = RGBColor(0xF0, 0xF4, 0xF8)
GRAY   = RGBColor(0x55, 0x66, 0x77)
AMBER  = RGBColor(0xF5, 0xA6, 0x23)
RED    = RGBColor(0xCC, 0x22, 0x22)
GREEN  = RGBColor(0x1A, 0x7A, 0x4A)
PALE_R = RGBColor(0xFF, 0xF0, 0xF0)
PALE_G = RGBColor(0xEA, 0xF7, 0xEE)
PALE_A = RGBColor(0xFF, 0xF8, 0xE8)

SLIDE_W = Inches(13.33)
SLIDE_H = Inches(7.5)


# ── Primitives ────────────────────────────────────────────────────────────────

def make_prs():
    prs = Presentation()
    prs.slide_width  = SLIDE_W
    prs.slide_height = SLIDE_H
    return prs

def blank(prs):
    return prs.slide_layouts[6]

def fill_bg(slide, color):
    bg = slide.background
    bg.fill.solid()
    bg.fill.fore_color.rgb = color

def rect(slide, l, t, w, h, color):
    s = slide.shapes.add_shape(1, l, t, w, h)
    s.fill.solid()
    s.fill.fore_color.rgb = color
    s.line.fill.background()
    return s

def tb(slide, text, l, t, w, h, size=16, bold=False, color=NAVY,
       align=PP_ALIGN.LEFT, italic=False):
    box = slide.shapes.add_textbox(l, t, w, h)
    box.word_wrap = True
    tf = box.text_frame
    tf.word_wrap = True
    p = tf.paragraphs[0]
    p.alignment = align
    run = p.add_run()
    run.text = text
    run.font.size = Pt(size)
    run.font.bold = bold
    run.font.italic = italic
    run.font.color.rgb = color
    return box

def bullets(slide, items, l, t, w, h, base=16, top_color=NAVY, sub_color=GRAY):
    """items: list of (text, level) tuples."""
    box = slide.shapes.add_textbox(l, t, w, h)
    box.word_wrap = True
    tf = box.text_frame
    tf.word_wrap = True
    for i, (text, level) in enumerate(items):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.space_before = Pt(5 if level == 0 else 2)
        prefix = "•" if level == 0 else ("▸" if level == 1 else "◦")
        indent = "    " * level
        run = p.add_run()
        run.text = f"{indent}{prefix}  {text}"
        run.font.size = Pt(max(base - level * 2, 11))
        run.font.bold = (level == 0)
        run.font.color.rgb = top_color if level == 0 else sub_color

def header(slide, title, sub=""):
    rect(slide, 0, 0, SLIDE_W, Inches(1.1), NAVY)
    tb(slide, title, Inches(0.4), Inches(0.1), Inches(12.5), Inches(0.65),
       size=28, bold=True, color=WHITE)
    if sub:
        tb(slide, sub, Inches(0.4), Inches(0.72), Inches(12.5), Inches(0.34),
           size=13, color=TEAL)

def table(slide, headers, rows, l, t, w, h):
    tbl = slide.shapes.add_table(len(rows) + 1, len(headers), l, t, w, h).table
    for ci, hdr in enumerate(headers):
        cell = tbl.cell(0, ci)
        cell.fill.solid(); cell.fill.fore_color.rgb = TEAL
        run = cell.text_frame.paragraphs[0].add_run()
        run.text = hdr; run.font.bold = True
        run.font.size = Pt(13); run.font.color.rgb = WHITE
    for ri, row in enumerate(rows):
        bg = LIGHT if ri % 2 == 0 else WHITE
        for ci, val in enumerate(row):
            cell = tbl.cell(ri + 1, ci)
            cell.fill.solid(); cell.fill.fore_color.rgb = bg
            run = cell.text_frame.paragraphs[0].add_run()
            run.text = val
            run.font.size = Pt(12); run.font.color.rgb = NAVY

def badge(slide, text, l, t, w, h, bg=TEAL, fg=WHITE, size=13):
    rect(slide, l, t, w, h, bg)
    tb(slide, text, l + Inches(0.1), t + Inches(0.05),
       w - Inches(0.2), h - Inches(0.1),
       size=size, bold=True, color=fg, align=PP_ALIGN.CENTER)

def panel(slide, title, items, l, t, w, h, bg=WHITE,
          title_color=TEAL, base=13):
    rect(slide, l, t, w, h, bg)
    tb(slide, title, l + Inches(0.1), t + Inches(0.1),
       w - Inches(0.2), Inches(0.38),
       size=14, bold=True, color=title_color)
    bullets(slide, items, l + Inches(0.1), t + Inches(0.52),
            w - Inches(0.2), h - Inches(0.6), base=base)


# ══════════════════════════════════════════════════════════════════════════════
# AUDIT DECK
# ══════════════════════════════════════════════════════════════════════════════

def audit_title(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, NAVY)
    rect(slide, Inches(1.4), Inches(1.7), Inches(10.5), Inches(4.2), TEAL)
    rect(slide, Inches(1.5), Inches(1.8), Inches(10.3), Inches(4.0), NAVY)
    tb(slide, "OpenEMR Security &\nArchitecture Audit",
       Inches(1.7), Inches(2.0), Inches(9.9), Inches(1.8),
       size=42, bold=True, color=WHITE, align=PP_ALIGN.CENTER)
    tb(slide, "Clinical Co-Pilot  ·  AgentForge  ·  April 2026",
       Inches(1.7), Inches(3.85), Inches(9.9), Inches(0.5),
       size=16, color=TEAL, align=PP_ALIGN.CENTER)
    tb(slide, "5 areas reviewed: Security · Performance · Architecture · Data Quality · Compliance",
       Inches(1.7), Inches(4.4), Inches(9.9), Inches(0.5),
       size=13, color=GRAY, align=PP_ALIGN.CENTER)


def audit_summary(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Audit Summary — 5 Key Findings")

    findings = [
        ("1", "PHI Exposure",         "SSN, DOB, insurance data stored plaintext in patient_data — no column encryption",          RED),
        ("2", "Dual Auth Surface",     "Legacy PHP session + OAuth 2.0. Agent must use OAuth — not session piggyback",               AMBER),
        ("3", "ACL Bypassable",        "phpGACL is real but raw DB queries skip it. Agent tool layer must enforce explicitly",       AMBER),
        ("4", "FHIR is the Right Path","FHIR R4 endpoints are scope-controlled, structured, and normalise messy underlying data",    GREEN),
        ("5", "Audit Log Gaps",        "EventAuditLogger exists but agent API calls won't trigger it automatically — must be wired in", AMBER),
    ]

    for i, (num, title, desc, color) in enumerate(findings):
        y = Inches(1.3) + i * Inches(1.14)
        rect(slide, Inches(0.4), y, Inches(0.55), Inches(0.9), color)
        tb(slide, num, Inches(0.4), y + Inches(0.2), Inches(0.55), Inches(0.5),
           size=22, bold=True, color=WHITE, align=PP_ALIGN.CENTER)
        rect(slide, Inches(0.97), y, Inches(11.93), Inches(0.9), WHITE)
        tb(slide, title, Inches(1.1), y + Inches(0.05), Inches(3.0), Inches(0.38),
           size=15, bold=True, color=color)
        tb(slide, desc, Inches(1.1), y + Inches(0.45), Inches(11.7), Inches(0.42),
           size=13, color=GRAY)


def audit_phi(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Finding 1: PHI Exposure at the Persistence Layer",
           "Most critical finding — shapes every design decision for the agent")

    rect(slide, Inches(0.4), Inches(1.2), Inches(12.5), Inches(2.15), PALE_R)
    tb(slide, "What the audit found",
       Inches(0.55), Inches(1.25), Inches(12.0), Inches(0.38),
       size=14, bold=True, color=RED)
    bullets(slide, [
        ("patient_data table stores SSN, DOB, driver's licence, full address, and insurance data in plaintext", 0),
        ("No column-level encryption on the database — any query path touches unencrypted PHI", 0),
        ("FHIR bulk export (GET /fhir/$export) can dump the entire dataset — must be scope-restricted", 0),
    ], Inches(0.55), Inches(1.65), Inches(12.0), Inches(1.6), base=14,
       top_color=RED, sub_color=GRAY)

    rect(slide, Inches(0.4), Inches(3.45), Inches(12.5), Inches(2.4), PALE_G)
    tb(slide, "Agent design constraints that follow",
       Inches(0.55), Inches(3.5), Inches(12.0), Inches(0.38),
       size=14, bold=True, color=GREEN)
    bullets(slide, [
        ("No PHI in logs — tool call arguments and responses are never written to log files or trace payloads", 0),
        ("No PHI in LLM trace payloads — Langfuse spans log FHIR resource type and ID, not content", 0),
        ("No PHI persisted outside the OpenEMR database boundary — conversation content has a 30-minute in-memory TTL only", 0),
        ("Minimise PHI in prompts — use patient IDs rather than names/DOB where the agent can reason without them", 0),
    ], Inches(0.55), Inches(3.9), Inches(12.0), Inches(1.85), base=14,
       top_color=GREEN, sub_color=GRAY)

    tb(slide, "BAA status: per project guidelines all LLM providers are treated as having a signed BAA with training use disabled. "
       "In production, a real signed BAA with Anthropic must be in place before live PHI is processed.",
       Inches(0.4), Inches(5.95), Inches(12.5), Inches(0.55),
       size=12, color=GRAY, italic=True)


def audit_auth_surface(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Finding 2 & 3: Authentication Surface & ACL Enforcement")

    panel(slide, "Finding 2 — Dual Authentication Surface",
          [
              ("OpenEMR supports both legacy PHP session cookies and OAuth 2.0 + SMART-on-FHIR", 0),
              ("The agent must use the OAuth path — not piggyback the PHP session", 0),
              ("Session piggyback inherits the full session with no scope limiting — a trust boundary problem", 1),
              ("OAuth enforces explicit scopes, supports token revocation, creates a clean authorization boundary", 1),
              ("Agent scopes (read-only): user/Patient.rs, user/Observation.rs, user/MedicationRequest.rs,", 1),
              ("user/Condition.rs, user/AllergyIntolerance.rs, user/Encounter.rs", 1),
          ],
          Inches(0.4), Inches(1.2), Inches(12.5), Inches(2.55), base=13)

    panel(slide, "Finding 3 — ACL System is Real but Bypassable",
          [
              ("phpGACL (src/Gacl/) is a full ACL system — AclMain::aclCheck() runs throughout the UI layer", 0),
              ("Risk: raw database queries and direct API calls can bypass aclCheck() entirely if implemented carelessly", 0),
              ("Bigger risk: phpGACL grants role-level access, not per-patient access", 0),
              ("A physician role can query any patient unless the UI enforces the provider-patient relationship — the agent must replicate this", 1),
              ("Resolution: agent tool layer checks care relationship (provider_id on patient_data + Encounter history) before every tool call", 1),
              ("Failed auth returns a structured error — never silently returns empty results", 1),
          ],
          Inches(0.4), Inches(3.85), Inches(12.5), Inches(2.75), base=13,
          bg=PALE_A, title_color=AMBER)


def audit_security_detail(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Security Deep-Dive — Authentication & Data Exposure")

    panel(slide, "Authentication — What's in Place",
          [
              ("argon2id password hashing via AuthHash — current best practice", 0),
              ("Timing-attack-resistant login: preventTimingAttack() runs dummy hash for invalid users — prevents username enumeration", 0),
              ("MFA support (TOTP/U2F) via src/Common/Auth/MfaUtils.php — optional, not enforced by default", 0),
              ("Session management: validated per request via AuthUtils::authCheckSession(), expiry checks, forced destruction on logout", 0),
              ("Login failure counter (users_secure) — auto-lock after configurable threshold", 0),
          ],
          Inches(0.4), Inches(1.2), Inches(6.1), Inches(3.0), base=12)

    panel(slide, "Remaining Risks",
          [
              ("MFA is optional — a compromised credential gives full access", 0),
              ("No explicit SameSite=Strict enforcement visible in session config", 0),
              ("Brute force threshold is configurable and may be set loosely in demo installs", 0),
              ("Default dev credentials (admin / pass) trivially guessable — unacceptable in any shared deployment", 0),
              ("HTTP port (8300) active in dev — no automatic redirect to HTTPS", 0),
              ("GITHUB_COMPOSER_TOKEN hardcoded in docker-compose.yml — secrets management red flag", 0),
          ],
          Inches(6.6), Inches(1.2), Inches(6.3), Inches(3.0), base=12,
          bg=PALE_R, title_color=RED)

    panel(slide, "HIPAA Audit Logging",
          [
              ("EventAuditLogger (src/Common/Logger/EventAuditLogger.php) tracks logins, logouts, record access", 0),
              ("Gap: direct agent API calls will NOT automatically trigger audit log entries", 0),
              ("Agent backend must explicitly call the audit logger after each PHI retrieval — same as the UI does", 0),
              ("Required HIPAA audit fields: who accessed, what record, when, from where (IP)", 0),
          ],
          Inches(0.4), Inches(4.3), Inches(12.5), Inches(2.1), base=13,
          bg=PALE_A, title_color=AMBER)


def audit_performance(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Performance Audit — Bottlenecks & Agent Latency Impact")

    panel(slide, "Application Architecture Bottlenecks",
          [
              ("PHP monolith: every request is a full PHP bootstrap — no persistent process like Node or Gunicorn", 0),
              ("No built-in caching layer: no Redis/Memcached in the default stack — repeated queries hit DB every time", 0),
              ("Complex patient summaries require multiple sequential queries (labs + meds + notes)", 0),
              ("N+1 risk: encounter list queries that individually fetch notes per encounter is a known codebase pattern", 0),
          ],
          Inches(0.4), Inches(1.2), Inches(6.1), Inches(2.9), base=13)

    panel(slide, "First-Boot Overhead",
          [
              ("composer install (~200 packages including PHPStan dev deps)", 0),
              ("npm install (large frontend dependency tree)", 0),
              ("SCSS compilation (bootstrap-rtl + custom themes)", 0),
              ("Total first-boot: 10–15 minutes — one-time cost, subsequent starts are fast", 0),
          ],
          Inches(6.6), Inches(1.2), Inches(6.3), Inches(2.9), base=13)

    panel(slide, "Agent Response Latency — Implications & Mitigation",
          [
              ("Serial API calls: 100–500ms per call depending on data volume", 0),
              ("Full patient brief (demographics + meds + last 3 labs + encounter notes) = 5–8 API calls = 500ms–4s if serial", 0),
              ("Mitigation: fetch all context at conversation start in parallel via asyncio.gather — do not call per-turn", 0),
              ("Subsequent turns reason over cached context; fresh queries only when physician asks for something not in cache", 0),
              ("Key tables indexed on pid — point lookups are fast; the bottleneck is number of round-trips, not query complexity", 0),
          ],
          Inches(0.4), Inches(4.2), Inches(12.5), Inches(2.55), base=13,
          bg=PALE_G, title_color=GREEN)


def audit_architecture(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Architecture Audit — System Organization & Integration Points")

    panel(slide, "System Layers",
          [
              ("UI layer (/interface/) → Service layer (/src/Services/)", 0),
              ("REST/FHIR API (/apis/ + /src/RestControllers/) → Service layer → MariaDB", 0),
              ("No domain model / ORM — thin services over raw SQL via QueryUtils / sqlQuery()", 0),
              ("Agent path: OAuth token → FHIR API → RestController → Service → MariaDB", 0),
          ],
          Inches(0.4), Inches(1.2), Inches(5.8), Inches(2.7), base=13)

    panel(slide, "Integration Points for Agent",
          [
              ("FHIR API + OAuth 2.0 sidecar (recommended path)", 0),
              ("Chat UI injected via PatientMenuEvent — new tab iframing the agent frontend", 0),
              ("Session passthrough: UI passes OpenEMR user identity → agent maps to OAuth token", 0),
              ("Audit logging: agent calls EventAuditLogger after each PHI access", 0),
          ],
          Inches(6.3), Inches(1.2), Inches(6.6), Inches(2.7), base=13)

    tb(slide, "Key Service Files the Agent Uses",
       Inches(0.4), Inches(4.05), Inches(12.5), Inches(0.38),
       size=14, bold=True, color=TEAL)

    headers = ["Service File", "Data Returned"]
    rows = [
        ("src/Services/PatientService.php",             "Demographics"),
        ("src/Services/PrescriptionService.php",        "Medications"),
        ("src/Services/ObservationLabService.php",      "Lab results"),
        ("src/Services/ObservationService.php",         "Vitals"),
        ("src/Services/ConditionService.php",           "Problem list"),
        ("src/Services/AllergyIntoleranceService.php",  "Allergies"),
        ("src/Services/EncounterService.php",           "Encounters"),
    ]
    table(slide, headers, rows,
          Inches(0.4), Inches(4.5), Inches(12.5), Inches(2.8))


def audit_data_quality(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Data Quality Audit — Completeness, Consistency & Agent Failure Modes")

    panel(slide, "Completeness Gaps",
          [
              ("patient_data.providerID often missing — blocks patient-provider relationship check for ACL", 0),
              ("form_observation.ob_unit missing in many real-world installs — units needed for safe lab display", 0),
              ("prescriptions.end_date often absent — no end date = indefinitely active in UI", 0),
              ("lists_medication and prescriptions are separate — some patients have data in one but not both", 0),
          ],
          Inches(0.4), Inches(1.2), Inches(6.1), Inches(2.55), base=13)

    panel(slide, "Consistency & Format Issues",
          [
              ("Medication dosage is free-text varchar: '10mg' vs '10 mg' vs '10MG' are all valid", 0),
              ("Lab values (ob_value) similarly free-text — no enforced type", 0),
              ("Date fields use mixed formats across legacy tables (YYYY-MM-DD vs Unix timestamps)", 0),
              ("FHIR layer normalises much of this — another reason to use FHIR over raw DB queries", 0),
              ("Duplicate patients can exist — no unique constraint on name + DOB", 0),
          ],
          Inches(6.6), Inches(1.2), Inches(6.3), Inches(2.55), base=13)

    tb(slide, "Agent Failure Modes from Data Quality — Required Behaviours",
       Inches(0.4), Inches(3.85), Inches(12.5), Inches(0.38),
       size=14, bold=True, color=TEAL)

    headers = ["Scenario", "Required Agent Behaviour"]
    rows = [
        ("No labs on file",                 "Return 'No lab results found' — do not infer"),
        ("Medication with no end_date",      "Treat as active, note uncertainty to physician"),
        ("Duplicate patient records",        "Surface the ambiguity — do not merge silently"),
        ("Missing provider assignment",      "Cannot verify patient-provider relationship for ACL — block the query"),
        ("Free-text dosage field",           "Quote source text exactly — do not parse or normalise"),
    ]
    table(slide, headers, rows,
          Inches(0.4), Inches(4.3), Inches(12.5), Inches(2.9))


def audit_compliance(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Compliance & Regulatory Audit — HIPAA, BAA, Data Retention")

    panel(slide, "HIPAA Audit Logging",
          [
              ("EventAuditLogger exists — covers logins, logouts, record access via UI", 0),
              ("Gap: direct agent API calls do NOT auto-trigger audit entries", 0),
              ("Agent backend must explicitly log after each PHI retrieval: who / what record / when / from where (IP)", 0),
          ],
          Inches(0.4), Inches(1.2), Inches(6.1), Inches(2.1), base=13)

    panel(slide, "BAA (Business Associate Agreement)",
          [
              ("Anthropic is a Business Associate when the agent sends patient data to the Claude API", 0),
              ("Project guideline: all LLM providers treated as having signed BAA with training use disabled", 0),
              ("Production requirement: real signed BAA with Anthropic before live PHI is processed — blocking", 0),
              ("Langfuse (if cloud-hosted) is also a Business Associate — self-host to avoid this, or get a BAA", 0),
          ],
          Inches(6.6), Inches(1.2), Inches(6.3), Inches(2.1), base=13,
          bg=PALE_R, title_color=RED)

    panel(slide, "Breach Notification & Data Retention",
          [
              ("HIPAA: breach of unsecured PHI requires notification within 60 days — LLM API calls are a new vector", 0),
              ("Mitigation: minimise PHI in prompts; use patient IDs not names/DOB where possible", 0),
              ("HIPAA retention: medical records minimum 6 years from creation or last use", 0),
              ("Agent conversation logs: never persist raw PHI content; only anonymised metadata (token counts, latency, tool sequence)", 0),
          ],
          Inches(0.4), Inches(3.4), Inches(12.5), Inches(2.1), base=13,
          bg=PALE_A, title_color=AMBER)

    tb(slide, "Compliance Gaps Summary",
       Inches(0.4), Inches(5.6), Inches(12.5), Inches(0.35),
       size=14, bold=True, color=TEAL)

    headers = ["Gap", "Priority"]
    rows = [
        ("PHI access not logged via EventAuditLogger for agent calls",        "High"),
        ("Agent conversation content persistence (must be zero PHI)",          "High"),
        ("Langfuse trace payloads must strip / hash PHI",                      "High"),
        ("No signed BAA with Anthropic for production",                        "Blocking"),
        ("phpGACL patient-level access not enforced at DB — agent must do it", "High"),
        ("HTTP port active in dev — enforce HTTPS in any shared deployment",   "Medium"),
    ]
    table(slide, headers, rows,
          Inches(0.4), Inches(6.0), Inches(12.5), Inches(1.35))


def audit_closing(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, NAVY)
    tb(slide, "Audit Conclusions",
       Inches(1.0), Inches(1.3), Inches(11.3), Inches(0.7),
       size=34, bold=True, color=WHITE, align=PP_ALIGN.CENTER)

    points = [
        (RED,   "PHI exposure",        "Plaintext patient data means zero tolerance for PHI in logs, traces, or LLM prompts"),
        (AMBER, "OAuth, not sessions", "Use the OAuth path — it enforces scopes and creates a clean trust boundary"),
        (AMBER, "Enforce ACL yourself","phpGACL can be bypassed; the agent tool layer must check patient-provider relationship"),
        (TEAL,  "FHIR is the answer",  "Structured, scope-controlled, normalised — use FHIR R4 for all data access"),
        (AMBER, "Wire the audit log",  "EventAuditLogger must be called explicitly for every agent PHI retrieval"),
        (RED,   "BAA before go-live",  "Signed BAA with Anthropic is a hard blocker before any real patient data is processed"),
    ]
    for i, (color, label, desc) in enumerate(points):
        y = Inches(2.2) + i * Inches(0.82)
        rect(slide, Inches(0.7), y, Inches(3.2), Inches(0.62), color)
        tb(slide, label, Inches(0.8), y + Inches(0.1), Inches(3.0), Inches(0.42),
           size=13, bold=True, color=WHITE)
        tb(slide, desc, Inches(4.1), y + Inches(0.12), Inches(9.0), Inches(0.42),
           size=13, color=LIGHT)


# ══════════════════════════════════════════════════════════════════════════════
# USERS DECK
# ══════════════════════════════════════════════════════════════════════════════

def users_title(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, NAVY)
    rect(slide, Inches(1.4), Inches(1.7), Inches(10.5), Inches(4.2), TEAL)
    rect(slide, Inches(1.5), Inches(1.8), Inches(10.3), Inches(4.0), NAVY)
    tb(slide, "Target Users &\nUse Cases",
       Inches(1.7), Inches(2.05), Inches(9.9), Inches(1.8),
       size=46, bold=True, color=WHITE, align=PP_ALIGN.CENTER)
    tb(slide, "Clinical Co-Pilot  ·  AgentForge  ·  April 2026",
       Inches(1.7), Inches(3.85), Inches(9.9), Inches(0.5),
       size=16, color=TEAL, align=PP_ALIGN.CENTER)
    tb(slide, "4 use cases  ·  1 target user  ·  explicit refusal boundaries",
       Inches(1.7), Inches(4.4), Inches(9.9), Inches(0.5),
       size=13, color=GRAY, align=PP_ALIGN.CENTER)


def users_target_user(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Target User — Dr. Sarah Chen",
           "Board-certified internist · Primary care · 18–22 patients/day")

    # left: profile card
    rect(slide, Inches(0.4), Inches(1.2), Inches(4.5), Inches(5.9), NAVY)
    tb(slide, "Dr. Sarah Chen",
       Inches(0.55), Inches(1.35), Inches(4.2), Inches(0.5),
       size=18, bold=True, color=WHITE)
    tb(slide, "Primary Care Physician\nInternal Medicine",
       Inches(0.55), Inches(1.85), Inches(4.2), Inches(0.6),
       size=13, color=TEAL)
    profile = [
        ("18–22 patients per day", 0),
        ("15-minute appointment slots", 0),
        ("90 seconds between rooms", 0),
        ("Day: 8:45 AM – 5 PM back-to-back", 0),
        ("30-minute lunch break", 0),
        ("Uses OpenEMR as practice EHR", 0),
    ]
    bullets(slide, profile,
            Inches(0.55), Inches(2.6), Inches(4.2), Inches(3.0),
            base=13, top_color=WHITE, sub_color=LIGHT)

    # right: what she needs vs doesn't
    panel(slide, "What she NEEDS",
          [
              ("Fast, accurate, patient-specific context pulled from the record in front of her", 0),
              ("Information she can absorb in under 30 seconds", 0),
              ("Synthesis — not a raw data dump she has to read herself", 0),
              ("Reliability — she will stop using the tool the first time it makes her look uninformed", 0),
              ("Source attribution — every fact must be traceable to the record", 0),
          ],
          Inches(5.1), Inches(1.2), Inches(7.8), Inches(2.75),
          bg=PALE_G, title_color=GREEN, base=13)

    panel(slide, "What she does NOT need",
          [
              ("Medical literature summaries — she is an expert physician", 0),
              ("ICD code explanations — she knows these", 0),
              ("Help writing notes — out of scope", 0),
              ("Tools that require training or slow her down", 0),
              ("Data she can't trust — she has been burned by tools that hallucinate drug interactions", 0),
          ],
          Inches(5.1), Inches(4.05), Inches(7.8), Inches(2.75),
          bg=PALE_R, title_color=RED, base=13)


def users_workflow(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "The 90-Second Window — Why This Constraint Matters")

    tb(slide,
       "The entire product is designed around a single time constraint: "
       "Dr. Chen has 60–90 seconds between finishing with one patient and knocking on the next room's door.",
       Inches(0.4), Inches(1.2), Inches(12.5), Inches(0.7),
       size=16, color=NAVY)

    # timeline
    steps = [
        (TEAL,  "8:45 AM",    "Day starts", "Quick schedule scan"),
        (GRAY,  "9:00 AM",    "Patient 1",  "15-min appointment slot"),
        (AMBER, "9:15 AM",    "⟵ 90 sec ⟶", "Mental context switch + Co-Pilot query"),
        (GRAY,  "9:16 AM",    "Patient 2",  "15-min appointment slot"),
        (AMBER, "9:31 AM",    "⟵ 90 sec ⟶", "Mental context switch + Co-Pilot query"),
        (GRAY,  "...",        "Repeat",     "18–22 times across the day"),
    ]
    for i, (color, time, label, detail) in enumerate(steps):
        x = Inches(0.4) + i * Inches(2.15)
        rect(slide, x, Inches(2.1), Inches(2.0), Inches(0.55), color)
        tb(slide, time, x + Inches(0.05), Inches(2.15), Inches(1.9), Inches(0.28),
           size=11, bold=True, color=WHITE, align=PP_ALIGN.CENTER)
        tb(slide, label, x + Inches(0.05), Inches(2.43), Inches(1.9), Inches(0.2),
           size=10, color=WHITE, align=PP_ALIGN.CENTER)
        tb(slide, detail, x, Inches(2.75), Inches(2.05), Inches(0.5),
           size=11, color=GRAY, align=PP_ALIGN.CENTER)

    bullets(slide, [
        ("This is a harder constraint than 'during rounds' or 'whenever I have a minute'", 0),
        ("It forces us to optimise for speed and terseness above all else — response must be scannable in 20 seconds", 1),
        ("It defines the format: 4–6 bullets, not paragraphs. Synthesis, not data", 1),
        ("Predictable workflow: scheduled appointments with a known patient panel — we can optimise for depth on known patients", 0),
        ("Primary care: chronic condition management, long medication lists — maximises the value of the medication and lab use cases", 0),
        ("High trust bar but achievable: she will use the tool consistently if it is reliable", 0),
    ], Inches(0.4), Inches(3.45), Inches(12.5), Inches(3.7), base=15)


def users_uc1(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "UC-1: Pre-Room Briefing",
           "Moment: finishes with one patient, walks to the next room's door — 60–90 seconds")

    badge(slide, "UC-1", Inches(0.4), Inches(1.25), Inches(0.9), Inches(0.5), TEAL)
    tb(slide, '"Brief me on this patient before I walk in."',
       Inches(1.4), Inches(1.25), Inches(11.5), Inches(0.5),
       size=18, bold=True, color=NAVY, italic=True)

    panel(slide, "What the agent must do",
          [
              ("Retrieve: last visit date + reason, active conditions, current medications, flagged alerts, today's appointment reason", 0),
              ("Synthesise: surface what's changed or new since the last visit — not a full chart dump", 0),
              ("Format: 4–6 bullet points, scannable in 20 seconds", 0),
          ],
          Inches(0.4), Inches(1.85), Inches(6.1), Inches(2.2), base=13)

    panel(slide, "Why an agent, not a dashboard",
          [
              ("A dashboard shows data — the physician still has to mentally synthesise it", 0),
              ('The agent answers: "Since her last visit 6 weeks ago, her HbA1c came back elevated at 7.9, '
               'her lisinopril was refilled, and she\'s coming in today for a cough — likely unrelated to the diabetes. '
               'Two overdue items: mammogram and flu shot."', 0),
              ("That is a different cognitive load than a screen of tabbed data", 0),
          ],
          Inches(6.6), Inches(1.85), Inches(6.3), Inches(2.2), base=13)

    panel(slide, "Source attribution requirement",
          [
              ("Every fact (HbA1c value, medication name, appointment reason) must cite the source FHIR resource", 0),
              ('"Likely unrelated" must be flagged as agent inference — not source data', 0),
              ("The physician must be able to verify every claim without opening the full chart", 0),
          ],
          Inches(0.4), Inches(4.15), Inches(12.5), Inches(1.7), base=13,
          bg=PALE_A, title_color=AMBER)

    tb(slide, "FHIR resources used: Patient · Encounter · Condition · MedicationRequest · Observation · AllergyIntolerance",
       Inches(0.4), Inches(5.95), Inches(12.5), Inches(0.45),
       size=12, color=GRAY)


def users_uc2(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "UC-2: Medication Safety Check",
           "Moment: during the visit — physician wants to prescribe a new medication")

    badge(slide, "UC-2", Inches(0.4), Inches(1.25), Inches(0.9), Inches(0.5), TEAL)
    tb(slide, '"What\'s she currently on? Any interactions I should know about before I add metformin?"',
       Inches(1.4), Inches(1.25), Inches(11.5), Inches(0.5),
       size=18, bold=True, color=NAVY, italic=True)

    panel(slide, "What the agent must do",
          [
              ("Retrieve: active prescriptions via FHIR MedicationRequest endpoint", 0),
              ("Check: flag known interaction risks against the current medication list", 0),
              ("Hedge appropriately: if interaction data is limited, say so explicitly — do not confidently clear the medication", 0),
          ],
          Inches(0.4), Inches(1.85), Inches(6.1), Inches(2.2), base=13)

    panel(slide, "Why an agent, not a dashboard",
          [
              ("Dr. Chen can see the medication list in the chart", 0),
              ("What she cannot do in 90 seconds is mentally cross-reference a new drug against 7 active medications", 0),
              ("She wants to ask in natural language and get a targeted response for the specific drug she is considering", 0),
          ],
          Inches(6.6), Inches(1.85), Inches(6.3), Inches(2.2), base=13)

    panel(slide, "Critical boundary — the agent must not make prescribing decisions",
          [
              ('It surfaces information: "lisinopril + NSAIDs has a known interaction risk"', 0),
              ('It does NOT say: "do not prescribe ibuprofen"', 0),
              ("The physician decides — the agent provides the information to make the decision", 0),
              ("If confidence in interaction data is low: explicitly state uncertainty rather than false confidence", 0),
          ],
          Inches(0.4), Inches(4.15), Inches(12.5), Inches(1.85), base=13,
          bg=PALE_R, title_color=RED)

    tb(slide, "FHIR resources used: MedicationRequest (active status)",
       Inches(0.4), Inches(6.1), Inches(12.5), Inches(0.4),
       size=12, color=GRAY)


def users_uc3(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "UC-3: Lab Trend Follow-up",
           "Moment: patient's labs came back — is she moving in the right direction?")

    badge(slide, "UC-3", Inches(0.4), Inches(1.25), Inches(0.9), Inches(0.5), TEAL)
    tb(slide, '"How are her kidney labs trending? Are the creatinine levels getting worse?"',
       Inches(1.4), Inches(1.25), Inches(11.5), Inches(0.5),
       size=18, bold=True, color=NAVY, italic=True)

    panel(slide, "What the agent must do",
          [
              ("Retrieve: last 3–5 Observation records for creatinine and BUN via FHIR, filtered to category=laboratory", 0),
              ("Trend: compare values over time and state direction (improving / stable / worsening)", 0),
              ("Include reference ranges: contextualise without requiring Dr. Chen to look them up", 0),
              ("Date every value: never give a number without its collection date", 0),
          ],
          Inches(0.4), Inches(1.85), Inches(6.1), Inches(2.5), base=13)

    panel(slide, "Why an agent, not a dashboard",
          [
              ("The lab results tab in OpenEMR shows individual rows — understanding a trend means clicking multiple rows", 0),
              ('The agent answers directly: "Creatinine was 1.4 on Mar 3, 1.6 on Mar 28, 1.7 on Apr 15 — trending up, '
               'above normal range of 0.6–1.2. BUN is stable."', 0),
              ("That is a complete clinical answer in one response", 0),
          ],
          Inches(6.6), Inches(1.85), Inches(6.3), Inches(2.5), base=13)

    panel(slide, "Source attribution requirement",
          [
              ("Every value must include its collection date and the specific Observation record ID it came from", 0),
              ("The trend direction (improving/worsening) is agent inference — must be labelled as such", 0),
              ("Reference ranges from the Observation resource where available; otherwise state source of the range", 0),
          ],
          Inches(0.4), Inches(4.45), Inches(12.5), Inches(1.75), base=13,
          bg=PALE_A, title_color=AMBER)

    tb(slide, "FHIR resources used: Observation (category=laboratory, sorted by date descending, count=5)",
       Inches(0.4), Inches(6.3), Inches(12.5), Inches(0.4),
       size=12, color=GRAY)


def users_uc4(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "UC-4: Visit History Query",
           "Moment: patient presents with a complaint that may have a prior history")

    badge(slide, "UC-4", Inches(0.4), Inches(1.25), Inches(0.9), Inches(0.5), TEAL)
    tb(slide, '"Has she been in for chest pain before? How many times in the last two years?"',
       Inches(1.4), Inches(1.25), Inches(11.5), Inches(0.5),
       size=18, bold=True, color=NAVY, italic=True)

    panel(slide, "What the agent must do",
          [
              ("Retrieve: encounter records via FHIR Encounter endpoint, sorted by date descending", 0),
              ("Search: look for encounters with chief complaint or SOAP notes mentioning the relevant symptom", 0),
              ("Return: count, dates, and one-line summary of each relevant visit", 0),
          ],
          Inches(0.4), Inches(1.85), Inches(6.1), Inches(2.2), base=13)

    panel(slide, "Why an agent, not a dashboard",
          [
              ("Encounter history in OpenEMR is a reverse-chronological list — finding all prior chest pain visits means clicking into each note", 0),
              ("The agent answers in one response: count, dates, and a one-line summary per relevant visit", 0),
              ("Natural language query ('chest pain') maps to free-text search across SOAP notes", 0),
          ],
          Inches(6.6), Inches(1.85), Inches(6.3), Inches(2.2), base=13)

    panel(slide, "Limitation the agent must surface",
          [
              ("SOAP note search is text-matching over free-text fields — the agent must acknowledge incompleteness", 0),
              ('"I found 2 encounters with \'chest pain\' in the notes; there may be additional encounters where it was coded differently"', 0),
              ("Honest uncertainty is required — false completeness is worse than stated uncertainty", 0),
          ],
          Inches(0.4), Inches(4.15), Inches(12.5), Inches(1.75), base=13,
          bg=PALE_A, title_color=AMBER)

    tb(slide, "FHIR resources used: Encounter (sorted by date descending) + free-text search over SOAP notes",
       Inches(0.4), Inches(6.0), Inches(12.5), Inches(0.4),
       size=12, color=GRAY)


def users_refusals(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "What the Agent Must Refuse",
           "Explicit boundaries — not edge cases")

    refusals = [
        ("Patients outside Dr. Chen's panel",
         "Queries about patients she is not assigned to or does not have ACL access to → blocked at tool layer"),
        ("Any write operation",
         "The agent is read-only. No record modifications, note creation, or prescription writes — ever"),
        ("Prescribing decisions",
         '"Should I prescribe X?" → "I can show what she\'s currently taking and flag interactions, but the decision is yours"'),
        ("General medical knowledge",
         '"What is the treatment for Type 2 diabetes?" → redirect to clinical resources — not grounded in this patient\'s record'),
        ("Unanswerable from the record",
         '"What do you think is causing her fatigue?" → if not diagnosable from the record, say so explicitly'),
    ]

    for i, (title, desc) in enumerate(refusals):
        y = Inches(1.25) + i * Inches(1.18)
        rect(slide, Inches(0.4), y, Inches(0.08), Inches(0.9), RED)
        rect(slide, Inches(0.5), y, Inches(12.4), Inches(0.9), WHITE)
        tb(slide, title, Inches(0.65), y + Inches(0.08), Inches(4.5), Inches(0.38),
           size=14, bold=True, color=RED)
        tb(slide, desc, Inches(0.65), y + Inches(0.46), Inches(12.1), Inches(0.38),
           size=13, color=GRAY)


def users_why_this_user(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, LIGHT)
    header(slide, "Why This User — Not an ED Resident, Hospitalist, or Nurse")

    panel(slide, "Why Dr. Chen (PCP), not others",
          [
              ("Defined time constraint: 90 seconds between rooms is harder than 'during rounds' or 'whenever' — forces speed + terseness", 0),
              ("Predictable workflow: scheduled appointments, known patient panel — optimise depth on known patients, not breadth on unknowns", 0),
              ("Medication context: primary care manages chronic conditions and long medication lists — maximises UC-2 and UC-3 value", 0),
              ("Trust bar is high but achievable: she will use the tool consistently if reliable — ED residents operate in too much uncertainty", 0),
          ],
          Inches(0.4), Inches(1.2), Inches(12.5), Inches(2.55), base=14)

    headers = ["User", "Why Out of Scope (Week 1)"]
    rows = [
        ("ED Resident",  "High-volume intake of unknown patients — breadth-optimised workflow requires different tool design"),
        ("Hospitalist",  "Rounding workflow is different; real-time data needs differ from scheduled appointment context"),
        ("Nurse",        "Different permission scope (no prescribing) — valid future user but requires separate use case design"),
    ]
    tb(slide, "Future users (out of scope for Week 1)",
       Inches(0.4), Inches(3.85), Inches(12.5), Inches(0.38),
       size=14, bold=True, color=TEAL)
    table(slide, headers, rows,
          Inches(0.4), Inches(4.3), Inches(12.5), Inches(1.85))

    panel(slide, "Use case to target user traceability",
          [
              ("UC-1 Pre-room briefing → 90-second window, predictable daily schedule", 0),
              ("UC-2 Medication safety → chronic condition management, long medication lists", 0),
              ("UC-3 Lab trends → follow-up appointments, ongoing chronic condition monitoring", 0),
              ("UC-4 Visit history → scheduled appointments where prior context shapes today's visit", 0),
          ],
          Inches(0.4), Inches(6.25), Inches(12.5), Inches(1.0), base=13,
          bg=PALE_G, title_color=GREEN)


def users_closing(prs):
    slide = prs.slides.add_slide(blank(prs))
    fill_bg(slide, NAVY)
    tb(slide, "Use Case Summary",
       Inches(1.0), Inches(1.2), Inches(11.3), Inches(0.65),
       size=34, bold=True, color=WHITE, align=PP_ALIGN.CENTER)

    ucs = [
        (TEAL,  "UC-1  Pre-room briefing",      "4–6 bullets, synthesised changes since last visit, scannable in 20 seconds"),
        (TEAL,  "UC-2  Medication safety",       "Active medication list + interaction flags for a specific candidate drug"),
        (TEAL,  "UC-3  Lab trend follow-up",     "Dated values, direction, reference ranges — trend stated as inference"),
        (TEAL,  "UC-4  Visit history",           "Count + date + one-line summary; acknowledge free-text search limitations"),
        (RED,   "Hard boundaries",               "Read-only · Patient panel only · No prescribing decisions · Source-attributed"),
    ]
    for i, (color, label, desc) in enumerate(ucs):
        y = Inches(2.1) + i * Inches(0.98)
        rect(slide, Inches(0.7), y, Inches(3.8), Inches(0.72), color)
        tb(slide, label, Inches(0.8), y + Inches(0.12), Inches(3.6), Inches(0.48),
           size=13, bold=True, color=WHITE)
        tb(slide, desc, Inches(4.7), y + Inches(0.15), Inches(8.4), Inches(0.48),
           size=13, color=LIGHT)

    tb(slide, "github.com/cxk280/agentforge  ·  https://openemr-production-971e.up.railway.app/",
       Inches(1.0), Inches(7.05), Inches(11.3), Inches(0.38),
       size=12, color=GRAY, align=PP_ALIGN.CENTER)


# ── Build both decks ──────────────────────────────────────────────────────────

# Audit deck
prs_audit = make_prs()
audit_title(prs_audit)
audit_summary(prs_audit)
audit_phi(prs_audit)
audit_auth_surface(prs_audit)
audit_security_detail(prs_audit)
audit_performance(prs_audit)
audit_architecture(prs_audit)
audit_data_quality(prs_audit)
audit_compliance(prs_audit)
audit_closing(prs_audit)
prs_audit.save("/Users/christopherking/code/gauntlet/agentforge/audit_deck.pptx")
print(f"audit_deck.pptx — {len(prs_audit.slides)} slides")

# Users deck
prs_users = make_prs()
users_title(prs_users)
users_target_user(prs_users)
users_workflow(prs_users)
users_uc1(prs_users)
users_uc2(prs_users)
users_uc3(prs_users)
users_uc4(prs_users)
users_refusals(prs_users)
users_why_this_user(prs_users)
users_closing(prs_users)
prs_users.save("/Users/christopherking/code/gauntlet/agentforge/users_deck.pptx")
print(f"users_deck.pptx  — {len(prs_users.slides)} slides")
