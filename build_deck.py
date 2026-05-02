"""Build architecture deck for Clinical Co-Pilot."""
from pptx import Presentation
from pptx.util import Inches, Pt, Emu
from pptx.dml.color import RGBColor
from pptx.enum.text import PP_ALIGN
from pptx.util import Inches, Pt
import copy

# ── Palette ──────────────────────────────────────────────────────────────────
NAVY   = RGBColor(0x0D, 0x1B, 0x2A)   # slide backgrounds / title bar
TEAL   = RGBColor(0x00, 0x8C, 0x8C)   # accent / headers
WHITE  = RGBColor(0xFF, 0xFF, 0xFF)
LIGHT  = RGBColor(0xF0, 0xF4, 0xF8)   # content background
GRAY   = RGBColor(0x55, 0x66, 0x77)
AMBER  = RGBColor(0xF5, 0xA6, 0x23)   # highlight / badge

SLIDE_W = Inches(13.33)
SLIDE_H = Inches(7.5)


def make_prs() -> Presentation:
    prs = Presentation()
    prs.slide_width  = SLIDE_W
    prs.slide_height = SLIDE_H
    return prs


def blank_layout(prs):
    return prs.slide_layouts[6]  # completely blank


def fill_bg(slide, color: RGBColor):
    bg = slide.background
    fill = bg.fill
    fill.solid()
    fill.fore_color.rgb = color


def add_rect(slide, l, t, w, h, color: RGBColor, alpha=None):
    shape = slide.shapes.add_shape(1, l, t, w, h)  # MSO_SHAPE_TYPE.RECTANGLE
    shape.fill.solid()
    shape.fill.fore_color.rgb = color
    shape.line.fill.background()
    return shape


def add_text_box(slide, text, l, t, w, h,
                 font_size=18, bold=False, color=WHITE,
                 align=PP_ALIGN.LEFT, wrap=True):
    txb = slide.shapes.add_textbox(l, t, w, h)
    txb.word_wrap = wrap
    tf = txb.text_frame
    tf.word_wrap = wrap
    p = tf.paragraphs[0]
    p.alignment = align
    run = p.add_run()
    run.text = text
    run.font.size = Pt(font_size)
    run.font.bold = bold
    run.font.color.rgb = color
    return txb


def slide_header(slide, title: str, subtitle: str = ""):
    """Dark top bar with title."""
    add_rect(slide, 0, 0, SLIDE_W, Inches(1.1), NAVY)
    add_text_box(slide, title,
                 Inches(0.4), Inches(0.12), Inches(12), Inches(0.6),
                 font_size=28, bold=True, color=WHITE)
    if subtitle:
        add_text_box(slide, subtitle,
                     Inches(0.4), Inches(0.72), Inches(12), Inches(0.35),
                     font_size=14, color=TEAL)


def add_bullets(slide, items: list[tuple[str, int]], l, t, w, h,
                base_size=16, color=NAVY):
    """items = [(text, indent_level), ...]"""
    txb = slide.shapes.add_textbox(l, t, w, h)
    txb.word_wrap = True
    tf = txb.text_frame
    tf.word_wrap = True

    for i, (text, level) in enumerate(items):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.level = level
        p.space_before = Pt(4 if level == 0 else 2)
        indent_px = level * 20
        bullet_char = "▸" if level == 1 else ("◦" if level == 2 else "•")
        run = p.add_run()
        run.text = f"{'  ' * level}{bullet_char}  {text}" if level > 0 else f"•  {text}"
        size = max(base_size - level * 2, 12)
        run.font.size = Pt(size)
        run.font.color.rgb = color if level == 0 else GRAY
        run.font.bold = (level == 0)


def add_table(slide, headers, rows, l, t, w, h):
    cols = len(headers)
    row_count = len(rows) + 1
    tbl = slide.shapes.add_table(row_count, cols, l, t, w, h).table

    # header row
    for ci, hdr in enumerate(headers):
        cell = tbl.cell(0, ci)
        cell.fill.solid()
        cell.fill.fore_color.rgb = TEAL
        p = cell.text_frame.paragraphs[0]
        run = p.add_run()
        run.text = hdr
        run.font.bold = True
        run.font.size = Pt(13)
        run.font.color.rgb = WHITE

    # data rows
    for ri, row in enumerate(rows):
        bg = LIGHT if ri % 2 == 0 else WHITE
        for ci, val in enumerate(row):
            cell = tbl.cell(ri + 1, ci)
            cell.fill.solid()
            cell.fill.fore_color.rgb = bg
            p = cell.text_frame.paragraphs[0]
            run = p.add_run()
            run.text = val
            run.font.size = Pt(12)
            run.font.color.rgb = NAVY

    return tbl


# ── Slides ────────────────────────────────────────────────────────────────────

def slide_title(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, NAVY)

    # central card
    add_rect(slide, Inches(1.5), Inches(1.8), Inches(10.3), Inches(4.0), TEAL)
    add_rect(slide, Inches(1.6), Inches(1.9), Inches(10.1), Inches(3.8), NAVY)

    add_text_box(slide, "Clinical Co-Pilot",
                 Inches(1.8), Inches(2.1), Inches(9.7), Inches(1.2),
                 font_size=46, bold=True, color=WHITE, align=PP_ALIGN.CENTER)

    add_text_box(slide, "Architecture Defense",
                 Inches(1.8), Inches(3.2), Inches(9.7), Inches(0.6),
                 font_size=22, color=TEAL, align=PP_ALIGN.CENTER)

    add_text_box(slide, "AgentForge  ·  Gauntlet AI Austin  ·  April 2026",
                 Inches(1.8), Inches(3.85), Inches(9.7), Inches(0.5),
                 font_size=14, color=GRAY, align=PP_ALIGN.CENTER)


def slide_overview(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "What We're Building", "One-sentence framing")

    add_bullets(slide, [
        ("A Python FastAPI sidecar service running alongside OpenEMR", 0),
        ("Multi-turn AI agent powered by Claude Sonnet 4.6 with tool use", 1),
        ("Retrieves real patient data from OpenEMR's FHIR R4 API", 1),
        ("Streams responses in <5 seconds — usable in the 90-second window between rooms", 1),
        ("Injected into the OpenEMR patient record view via an iframe panel", 1),

        ("Not a general chatbot — patient-specific, source-attributed, role-gated", 0),
        ("Every factual claim traced back to a FHIR resource ID", 1),
        ("Physician's session identity inherited by the agent — no separate auth", 1),
        ("No PHI ever written to logs or trace storage", 1),
    ], Inches(0.5), Inches(1.25), Inches(12.3), Inches(5.8), base_size=17)


def slide_system_diagram(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "System Architecture", "Docker Compose stack")

    # diagram boxes
    def box(label, sublabel, l, t, w, h, col=NAVY):
        add_rect(slide, l, t, w, h, col)
        add_text_box(slide, label, l + Inches(0.1), t + Inches(0.1),
                     w - Inches(0.2), Inches(0.35),
                     font_size=14, bold=True, color=WHITE)
        if sublabel:
            add_text_box(slide, sublabel, l + Inches(0.1), t + Inches(0.42),
                         w - Inches(0.2), Inches(0.35),
                         font_size=11, color=RGBColor(0xAA, 0xCC, 0xDD))

    box("OpenEMR",   "PHP + Apache  :8300/:9300",  Inches(0.6),  Inches(1.4), Inches(3.2), Inches(1.0))
    box("Copilot",   "Python FastAPI  :8400",        Inches(4.6),  Inches(1.4), Inches(3.2), Inches(1.0), TEAL)
    box("MySQL",   ":8320",                        Inches(0.6),  Inches(3.2), Inches(3.2), Inches(0.9))
    box("Langfuse",  "Traces  :3000",                Inches(4.6),  Inches(3.2), Inches(3.2), Inches(0.9))
    box("Claude API","External / Anthropic",         Inches(8.6),  Inches(1.4), Inches(4.2), Inches(0.9), AMBER)

    # labels / arrows (text stand-ins)
    arrow_style = dict(font_size=11, color=GRAY)
    add_text_box(slide, "FHIR REST API  ◄────────────────",
                 Inches(2.1), Inches(2.05), Inches(2.6), Inches(0.3), **arrow_style)
    add_text_box(slide, "────────►  Claude API (httpx + Anthropic SDK)",
                 Inches(5.0), Inches(2.05), Inches(7.5), Inches(0.3), **arrow_style)
    add_text_box(slide, "SQL queries",
                 Inches(0.9), Inches(2.5), Inches(2.0), Inches(0.3), **arrow_style)
    add_text_box(slide, "│", Inches(2.15), Inches(2.5), Inches(0.3), Inches(0.6),
                 font_size=18, color=GRAY)
    add_text_box(slide, "Trace spans (no PHI)",
                 Inches(4.9), Inches(2.5), Inches(2.5), Inches(0.3), **arrow_style)
    add_text_box(slide, "│", Inches(6.15), Inches(2.5), Inches(0.3), Inches(0.6),
                 font_size=18, color=GRAY)

    # legend
    add_rect(slide, Inches(0.5), Inches(4.5), Inches(12.3), Inches(0.04), TEAL)
    add_bullets(slide, [
        ("Physician browser → copilot.php (OpenEMR) → Agent backend → FHIR API → MySQL", 0),
        ("Agent never touches MySQL directly — all data access via FHIR R4 REST", 1),
    ], Inches(0.5), Inches(4.6), Inches(12.3), Inches(2.5), base_size=14)


def slide_why_sidecar(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Why a Sidecar, Not a PHP Module")

    add_bullets(slide, [
        ("OpenEMR is a synchronous PHP monolith", 0),
        ("Streaming LLM responses in PHP requires significant complexity", 1),
        ("No native async I/O — every Claude token would block the process", 1),

        ("Python sidecar gives us what we need", 0),
        ("FastAPI + httpx: async-native streaming out of the box", 1),
        ("Anthropic SDK: clean tool use patterns, type-safe responses", 1),
        ("Independent deployment — iterate on the agent without touching OpenEMR's PHP build", 1),

        ("Minimal PHP footprint in OpenEMR", 0),
        ("One new page: interface/patient_file/summary/copilot.php", 1),
        ("Renders the iframe, passes patient ID and session identity to the agent", 1),
        ("One event hook: CopilotMenuEvent registers the tab in patient navigation", 1),
    ], Inches(0.5), Inches(1.25), Inches(12.3), Inches(5.8), base_size=17)


def slide_what_is_fhir(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "What is FHIR?", "Fast Healthcare Interoperability Resources")

    # left column: definition
    add_rect(slide, Inches(0.4), Inches(1.25), Inches(5.9), Inches(5.8), WHITE)
    add_text_box(slide, "The standard",
                 Inches(0.5), Inches(1.3), Inches(5.7), Inches(0.4),
                 font_size=15, bold=True, color=TEAL)
    add_bullets(slide, [
        ("Published by HL7 — defines how healthcare data is structured and exchanged between systems", 0),
        ("Solves the EHR interoperability problem: every vendor historically had its own proprietary data format and API", 0),
        ("FHIR standardises both the data model and the API, so the same code works against any compliant EHR", 0),
        ("Mandated by the 21st Century Cures Act — every major US EHR must expose it", 0),
        ("R4 (released 2019) is the current stable version — what OpenEMR implements", 0),
    ], Inches(0.5), Inches(1.75), Inches(5.7), Inches(3.0), base_size=13)

    add_text_box(slide, "Resources (the data model)",
                 Inches(0.5), Inches(4.85), Inches(5.7), Inches(0.4),
                 font_size=15, bold=True, color=TEAL)
    add_bullets(slide, [
        ("Clinical concepts map to typed JSON objects called resources", 0),
        ("Patient, MedicationRequest, Observation, Condition, AllergyIntolerance, Encounter, ...", 1),
        ("Each resource type has a predictable schema regardless of which EHR produced it", 1),
        ("Lab result (Observation) always has: code (LOINC), value, status, patient reference", 1),
    ], Inches(0.5), Inches(5.3), Inches(5.7), Inches(1.7), base_size=13)

    # right column: what R4 endpoints look like concretely
    add_rect(slide, Inches(6.8), Inches(1.25), Inches(6.1), Inches(5.8), NAVY)
    add_text_box(slide, "FHIR R4 endpoints — concretely",
                 Inches(6.9), Inches(1.3), Inches(5.9), Inches(0.4),
                 font_size=15, bold=True, color=TEAL)
    add_text_box(slide,
                 "REST API URLs that follow the R4 spec:\n\n"
                 "GET /fhir/Patient/123\n\n"
                 "GET /fhir/MedicationRequest\n"
                 "    ?patient=123&status=active\n\n"
                 "GET /fhir/Observation\n"
                 "    ?patient=123\n"
                 "    &category=laboratory\n"
                 "    &_sort=-date\n"
                 "    &_count=10",
                 Inches(6.9), Inches(1.8), Inches(5.9), Inches(3.0),
                 font_size=13, color=WHITE)
    add_text_box(slide,
                 "Same query pattern works against any\ncompliant EHR — no vendor-specific code.\n\n"
                 "In OpenEMR: /apis/default/fhir/\nProtected by OAuth 2.0 bearer tokens.\n\n"
                 "OAuth = how the agent inherits the\nlogged-in physician's identity and scope.",
                 Inches(6.9), Inches(4.9), Inches(5.9), Inches(2.0),
                 font_size=13, color=LIGHT)


def slide_fhir_over_db(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Why FHIR Over Direct DB Access")

    # two columns
    add_rect(slide, Inches(0.4), Inches(1.2), Inches(5.9), Inches(5.9), WHITE)
    add_rect(slide, Inches(6.9), Inches(1.2), Inches(5.9), Inches(5.9), WHITE)

    add_text_box(slide, "FHIR R4 API  ✓",
                 Inches(0.5), Inches(1.25), Inches(5.7), Inches(0.4),
                 font_size=16, bold=True, color=TEAL)
    add_text_box(slide, "Direct DB Access  ✗",
                 Inches(7.0), Inches(1.25), Inches(5.7), Inches(0.4),
                 font_size=16, bold=True, color=AMBER)

    add_bullets(slide, [
        ("Normalizes messy underlying data", 0),
        ("Free-text dosages → structured MedicationRequest", 1),
        ("Mixed date formats → ISO 8601", 1),
        ("Inconsistent nulls → typed FHIR fields", 1),
        ("OAuth scopes enforce access at the API layer", 0),
        ("Agent can only read what its token permits", 1),
        ("Portable across FHIR-compliant EHRs", 0),
        ("Future deployment = URL change only", 1),
    ], Inches(0.5), Inches(1.7), Inches(5.7), Inches(5.0), base_size=14)

    add_bullets(slide, [
        ("Tied to MySQL schema internals", 0),
        ("Schema changes break the agent", 1),
        ("Bypasses OpenEMR's ACL system", 0),
        ("phpGACL not enforced on raw SQL", 1),
        ("Agent would need its own auth layer", 1),
        ("50–100ms latency cost of FHIR", 0),
        ("Accepted: scope enforcement + normalization worth the cost", 1),
    ], Inches(7.0), Inches(1.7), Inches(5.7), Inches(5.0), base_size=14, color=GRAY)


def slide_data_access(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Data Access: Tool → FHIR Mapping")

    headers = ["Tool", "FHIR Endpoint", "Returns"]
    rows = [
        ("get_patient_summary",  "GET /fhir/Patient/:id",                                           "Demographics, active problems"),
        ("get_medications",      "GET /fhir/MedicationRequest?patient=:pid&status=active",           "Active prescriptions"),
        ("get_recent_labs",      "GET /fhir/Observation?category=laboratory&_sort=-date&_count=10",  "Lab results with dates + units"),
        ("get_vitals",           "GET /fhir/Observation?category=vital-signs&_sort=-date&_count=5",  "Recent vitals"),
        ("get_conditions",       "GET /fhir/Condition?clinical-status=active",                       "Active problem list"),
        ("get_allergies",        "GET /fhir/AllergyIntolerance?patient=:pid",                        "Allergy list"),
        ("get_encounters",       "GET /fhir/Encounter?_sort=-date&_count=10",                        "Recent visits"),
    ]
    add_table(slide, headers, rows,
              Inches(0.4), Inches(1.25), Inches(12.5), Inches(4.5))

    add_text_box(slide,
                 "Context pre-fetch: all tools called in parallel via asyncio.gather at conversation start — "
                 "subsequent turns reason over cached context.",
                 Inches(0.4), Inches(5.9), Inches(12.5), Inches(0.6),
                 font_size=13, color=GRAY)


def slide_auth(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Authorization Design", "Three layers — no write access anywhere")

    add_bullets(slide, [
        ("Layer 1 — OpenEMR OAuth Scopes", 0),
        ("Agent authenticates with minimum read-only scopes for the user's role", 1),
        ("Physician: user/Patient.rs  user/Observation.rs  user/MedicationRequest.rs  user/Condition.rs  ...", 1),
        ("No write scopes ever requested", 1),

        ("Layer 2 — Patient Access Validation", 0),
        ("Before any tool call: confirm requesting physician has a care relationship with the patient", 1),
        ("Checks provider_id on patient_data OR Encounter history in past 12 months", 1),

        ("Layer 3 — Tool-Level Guard", 0),
        ("Each tool validates inputs + authorization before calling FHIR", 1),
        ("Failed auth → structured error response, never silent empty result", 1),
    ], Inches(0.5), Inches(1.25), Inches(8.5), Inches(5.5), base_size=15)

    # trust chain
    add_rect(slide, Inches(9.2), Inches(1.25), Inches(3.7), Inches(5.5), WHITE)
    add_text_box(slide, "Trust Chain",
                 Inches(9.3), Inches(1.3), Inches(3.5), Inches(0.4),
                 font_size=14, bold=True, color=TEAL)
    chain = [
        "Physician browser",
        "     ↓",
        "copilot.php",
        "(session validated)",
        "     ↓",
        "Agent backend",
        "(role resolved)",
        "     ↓",
        "FHIR API",
        "(OAuth scopes)",
        "     ↓",
        "MySQL",
        "(ACL enforced)",
    ]
    add_text_box(slide, "\n".join(chain),
                 Inches(9.3), Inches(1.8), Inches(3.5), Inches(4.8),
                 font_size=13, color=NAVY)


def slide_verification(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Verification Layer", "Every response checked before delivery")

    # Step 1
    add_rect(slide, Inches(0.4), Inches(1.25), Inches(5.9), Inches(2.7), WHITE)
    add_text_box(slide, "Step 1 — Source Attribution",
                 Inches(0.5), Inches(1.3), Inches(5.7), Inches(0.4),
                 font_size=15, bold=True, color=TEAL)
    add_bullets(slide, [
        ("Claude Haiku 4.5 receives tool results + draft response", 0),
        ("Tags each factual claim with FHIR resourceType/id/field", 1),
        ("Claims without a traceable source → flagged [UNVERIFIED]", 1),
        ("Agent removes or disclaims unverified claims before delivery", 1),
        ("Adds ~1 second to response time", 0),
    ], Inches(0.5), Inches(1.75), Inches(5.7), Inches(2.1), base_size=13)

    # Step 2
    add_rect(slide, Inches(6.8), Inches(1.25), Inches(6.1), Inches(2.7), WHITE)
    add_text_box(slide, "Step 2 — Domain Constraint Check",
                 Inches(6.9), Inches(1.3), Inches(5.9), Inches(0.4),
                 font_size=15, bold=True, color=TEAL)
    add_bullets(slide, [
        ("Rules-based (no LLM call — no added latency)", 0),
        ("Medication names must match active MedicationRequest records exactly", 1),
        ("Lab values must match Observation values exactly — no rounding", 1),
        ("Prescriptive language flagged: 'should', 'must', 'recommend'", 1),
    ], Inches(6.9), Inches(1.75), Inches(5.9), Inches(2.1), base_size=13)

    # Known limitations
    add_rect(slide, Inches(0.4), Inches(4.15), Inches(12.5), Inches(2.9), RGBColor(0xFF, 0xF3, 0xCD))
    add_text_box(slide, "Known Limitations",
                 Inches(0.5), Inches(4.2), Inches(12.0), Inches(0.4),
                 font_size=14, bold=True, color=AMBER)
    add_bullets(slide, [
        ("Source attribution catches hallucinated facts — not reasoning errors (correct quote, wrong conclusion)", 0),
        ("Domain constraint check is pattern-based — misses violations it can't pattern-match", 0),
        ("Combined verification adds 1–2 seconds — accepted given clinical safety requirement", 0),
    ], Inches(0.5), Inches(4.65), Inches(12.0), Inches(2.2), base_size=13, color=RGBColor(0x5C, 0x40, 0x00))


def slide_tech_justification(prs):
    """Why MySQL, FastAPI, and httpx vs alternatives."""
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Technology Choices — Why These, Not Others")

    # MySQL
    add_rect(slide, Inches(0.4), Inches(1.25), Inches(12.5), Inches(0.35), TEAL)
    add_text_box(slide, "MySQL",
                 Inches(0.5), Inches(1.27), Inches(12.0), Inches(0.3),
                 font_size=14, bold=True, color=WHITE)
    add_bullets(slide, [
        ("OpenEMR requires it — the codebase was built and tested against MySQL/MySQL semantics; PostgreSQL is not supported", 0),
        ("Strict SQL mode enforces data integrity at the database layer — bad values are rejected, not silently coerced", 0),
        ("MySQL-compatible wire protocol: Railway production runs MySQL 9.4, zero code changes required", 0),
    ], Inches(0.5), Inches(1.65), Inches(12.0), Inches(1.1), base_size=13)

    # FastAPI
    add_rect(slide, Inches(0.4), Inches(2.85), Inches(12.5), Inches(0.35), TEAL)
    add_text_box(slide, "FastAPI  (vs Flask, Django, Node/Express)",
                 Inches(0.5), Inches(2.87), Inches(12.0), Inches(0.3),
                 font_size=14, bold=True, color=WHITE)
    add_bullets(slide, [
        ("Python is mandatory — Anthropic SDK and AI/ML tooling ecosystem are Python-first", 0),
        ("Async-first: streaming Claude responses while making concurrent FHIR tool calls requires non-blocking I/O; Flask blocks the event loop on every DB round-trip", 0),
        ("Pydantic models give typed, validated structures at the boundary between raw FHIR data and the agent — parse, don't validate", 0),
        ("Django is overkill: its ORM and admin are irrelevant; we do not manage our own database", 0),
        ("Automatic OpenAPI docs: agent tool endpoints are self-documenting, enabling the eval framework to generate test clients directly from the schema", 0),
    ], Inches(0.5), Inches(3.25), Inches(12.0), Inches(1.6), base_size=13)

    # httpx
    add_rect(slide, Inches(0.4), Inches(4.95), Inches(12.5), Inches(0.35), TEAL)
    add_text_box(slide, "httpx  (vs requests, aiohttp, urllib3)",
                 Inches(0.5), Inches(4.97), Inches(12.0), Inches(0.3),
                 font_size=14, bold=True, color=WHITE)
    add_bullets(slide, [
        ("requests is synchronous-only — blocks the FastAPI event loop on every FHIR API call; not usable here", 0),
        ("Anthropic SDK uses httpx internally — one fewer dependency, consistent connection pooling across the whole stack", 0),
        ("Cleaner API than aiohttp with sync/async parity in the same library, useful in test scripts without a running event loop", 0),
    ], Inches(0.5), Inches(5.35), Inches(12.0), Inches(1.1), base_size=13)


def slide_llm(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "LLM & Framework Selection")

    headers = ["Component", "Choice", "Why"]
    rows = [
        ("Primary model",       "Claude Sonnet 4.6",               "Speed + structured tool use + 200K context window + streaming"),
        ("Verification model",  "Claude Haiku 4.5",                "Fast + cheap for narrow attribution task — adds <1s"),
        ("Agent framework",     "Anthropic SDK (direct, no LangChain)", "No framework overhead; tool use pattern clean; stream=True built in"),
        ("HTTP client",         "httpx",                           "Async-native; used by Anthropic SDK internally; one dependency, consistent pooling"),
        ("Conversation state",  "Server-side memory (30 min TTL)", "PHI constraint — no conversation content written to DB"),
        ("Streaming",           "stream=True + SSE",               "Progressive display; first token visible in <1s"),
    ]
    add_table(slide, headers, rows,
              Inches(0.4), Inches(1.25), Inches(12.5), Inches(4.6))

    add_text_box(slide,
                 "200K token context window: full patient history (labs, meds, notes, vitals) fits in a single context "
                 "without truncation — no chunking strategy needed.",
                 Inches(0.4), Inches(6.0), Inches(12.5), Inches(0.6),
                 font_size=13, color=GRAY)


def slide_observability(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Observability — Langfuse", "Why Langfuse · Self-hosted · No PHI in traces")

    # why langfuse (top band)
    add_rect(slide, Inches(0.4), Inches(1.25), Inches(12.5), Inches(2.0), WHITE)
    add_text_box(slide, "Why Langfuse  (vs LangSmith, Datadog/CloudWatch, roll-your-own)",
                 Inches(0.5), Inches(1.3), Inches(12.0), Inches(0.4),
                 font_size=15, bold=True, color=TEAL)
    add_bullets(slide, [
        ("The agent makes a chain of decisions per request — without trace reconstruction, debugging a bad response means guessing which step failed", 0),
        ("LangSmith is tightly coupled to LangChain; we use the Anthropic SDK directly — LangSmith requires wrapping code in LangChain abstractions we don't otherwise need", 0),
        ("Rolling our own (Datadog/CloudWatch) gives raw logs but no trace reconstruction — you'd manually correlate log lines across a multi-step agent run", 0),
        ("Langfuse is framework-agnostic, wraps the Anthropic client with one line of config, and can be self-hosted — self-hosting is a HIPAA requirement, not a preference", 0),
    ], Inches(0.5), Inches(1.75), Inches(12.0), Inches(1.4), base_size=13)

    # left: what IS traced
    add_rect(slide, Inches(0.4), Inches(3.35), Inches(6.0), Inches(3.75), RGBColor(0xE8, 0xF5, 0xF5))
    add_text_box(slide, "What is traced",
                 Inches(0.5), Inches(3.4), Inches(5.8), Inches(0.35),
                 font_size=14, bold=True, color=TEAL)
    add_bullets(slide, [
        ("Session validation span", 0),
        ("Tool call spans: name, resource type, duration, success/fail", 0),
        ("Claude API spans: model, token counts, latency, time-to-first-token", 0),
        ("Verification span: claims attributed vs flagged, duration", 0),
        ("Derivable: P50/P95 latency, tool failure rates, token cost/day, verification flag rate", 0),
    ], Inches(0.5), Inches(3.8), Inches(5.8), Inches(2.9), base_size=12)

    # right: what is NOT traced
    add_rect(slide, Inches(6.9), Inches(3.35), Inches(6.0), Inches(3.75), RGBColor(0xFF, 0xF0, 0xF0))
    add_text_box(slide, "What is NOT traced  (PHI constraint)",
                 Inches(7.0), Inches(3.4), Inches(5.8), Inches(0.35),
                 font_size=14, bold=True, color=RGBColor(0xCC, 0x00, 0x00))
    add_bullets(slide, [
        ("Tool call arguments/responses — contain patient data", 0),
        ("Raw Claude prompt and response content", 0),
        ("Patient IDs in plain form — hashed in traces", 0),
        ("Self-hosted on Railway: trace data never leaves our infrastructure boundary", 0),
        ("Cloud Langfuse would require a BAA — self-hosting avoids the issue entirely", 0),
    ], Inches(7.0), Inches(3.8), Inches(5.8), Inches(2.9), base_size=12, color=RGBColor(0x66, 0x00, 0x00))


def slide_tradeoffs(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Tradeoffs & Risks")

    headers = ["Decision", "Cost", "Rationale"]
    rows = [
        ("FHIR API (not direct DB)",       "+50–100ms per tool call",    "Scope enforcement + data normalization + portability"),
        ("Verification pass",              "+1–2s per response",         "Clinical hallucination risk too high to skip"),
        ("Iframe injection",               "Less elegant than native UI", "Agent codebase stays decoupled, independently deployable"),
        ("No persistent conversation DB",  "Context resets after 30 min","PHI constraint — no conversation content written to storage"),
        ("Self-hosted Langfuse",           "Ops overhead to maintain",   "Avoids PHI in third-party trace storage; no BAA required"),
    ]
    add_table(slide, headers, rows,
              Inches(0.4), Inches(1.25), Inches(12.5), Inches(3.5))

    add_text_box(slide, "Highest Risk Items",
                 Inches(0.4), Inches(4.9), Inches(12.5), Inches(0.4),
                 font_size=16, bold=True, color=AMBER)
    add_bullets(slide, [
        ("OAuth flow complexity: OpenEMR's OAuth server is the most failure-prone integration step — must validate early", 0),
        ("Patient access authorization: care relationship check requires combining Encounter history with patient_data.provider_id — test edge cases", 0),
        ("FHIR data quality: OpenEMR's FHIR layer can return empty or malformed resources for incomplete records — every tool must handle gracefully", 0),
    ], Inches(0.4), Inches(5.35), Inches(12.5), Inches(2.0), base_size=14)


def slide_closing(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, NAVY)

    add_text_box(slide, "Architecture Summary",
                 Inches(1.0), Inches(1.5), Inches(11.3), Inches(0.8),
                 font_size=32, bold=True, color=WHITE, align=PP_ALIGN.CENTER)

    points = [
        ("Python FastAPI sidecar", "keeps AI logic independent of the PHP monolith"),
        ("FHIR R4 exclusively",    "scope enforcement, normalization, portability"),
        ("Three-layer auth",       "OAuth scopes → patient access check → tool guard"),
        ("Two-step verification",  "source attribution (Haiku) + domain constraints (rules)"),
        ("Self-hosted Langfuse",   "full trace visibility, zero PHI outside our boundary"),
    ]

    for i, (label, desc) in enumerate(points):
        y = Inches(2.5) + i * Inches(0.82)
        add_rect(slide, Inches(0.8), y, Inches(3.5), Inches(0.6), TEAL)
        add_text_box(slide, label,
                     Inches(0.85), y + Inches(0.1), Inches(3.4), Inches(0.4),
                     font_size=14, bold=True, color=WHITE)
        add_text_box(slide, desc,
                     Inches(4.5), y + Inches(0.1), Inches(8.5), Inches(0.4),
                     font_size=14, color=LIGHT)

    add_text_box(slide, "github.com/cxk280/agentforge  ·  https://openemr-production-971e.up.railway.app/",
                 Inches(1.0), Inches(6.9), Inches(11.3), Inches(0.4),
                 font_size=12, color=GRAY, align=PP_ALIGN.CENTER)


# ── Main ──────────────────────────────────────────────────────────────────────

prs = make_prs()
slide_title(prs)
slide_overview(prs)
slide_system_diagram(prs)
slide_why_sidecar(prs)
slide_tech_justification(prs)
slide_what_is_fhir(prs)
slide_fhir_over_db(prs)
slide_data_access(prs)
slide_auth(prs)
slide_verification(prs)
slide_llm(prs)
slide_observability(prs)
slide_tradeoffs(prs)
slide_closing(prs)

out = "/Users/christopherking/code/gauntlet/agentforge/architecture_deck.pptx"
prs.save(out)
print(f"Saved: {out}  ({len(prs.slides)} slides)")
