"""Build Week 2 architecture defense deck for Clinical Co-Pilot."""
from pptx import Presentation
from pptx.util import Inches, Pt
from pptx.dml.color import RGBColor
from pptx.enum.text import PP_ALIGN

# ── Palette (same as build_deck.py) ─────────────────────────────────────────
NAVY  = RGBColor(0x0D, 0x1B, 0x2A)
TEAL  = RGBColor(0x00, 0x8C, 0x8C)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
LIGHT = RGBColor(0xF0, 0xF4, 0xF8)
GRAY  = RGBColor(0x55, 0x66, 0x77)
AMBER = RGBColor(0xF5, 0xA6, 0x23)
GREEN = RGBColor(0x2D, 0x7A, 0x4F)

SLIDE_W = Inches(13.33)
SLIDE_H = Inches(7.5)


def make_prs() -> Presentation:
    prs = Presentation()
    prs.slide_width = SLIDE_W
    prs.slide_height = SLIDE_H
    return prs


def blank_layout(prs):
    return prs.slide_layouts[6]


def fill_bg(slide, color: RGBColor):
    fill = slide.background.fill
    fill.solid()
    fill.fore_color.rgb = color


def add_rect(slide, l, t, w, h, color: RGBColor):
    shape = slide.shapes.add_shape(1, l, t, w, h)
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
    add_rect(slide, 0, 0, SLIDE_W, Inches(1.1), NAVY)
    add_text_box(slide, title,
                 Inches(0.4), Inches(0.12), Inches(12), Inches(0.6),
                 font_size=28, bold=True, color=WHITE)
    if subtitle:
        add_text_box(slide, subtitle,
                     Inches(0.4), Inches(0.72), Inches(12), Inches(0.35),
                     font_size=14, color=TEAL)


def add_bullets(slide, items, l, t, w, h, base_size=16, color=NAVY):
    txb = slide.shapes.add_textbox(l, t, w, h)
    txb.word_wrap = True
    tf = txb.text_frame
    tf.word_wrap = True
    for i, (text, level) in enumerate(items):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.level = level
        p.space_before = Pt(4 if level == 0 else 2)
        bullet_char = "▸" if level == 1 else ("◦" if level == 2 else "•")
        run = p.add_run()
        run.text = f"{'  ' * level}{bullet_char}  {text}" if level > 0 else f"•  {text}"
        size = max(base_size - level * 2, 11)
        run.font.size = Pt(size)
        run.font.color.rgb = color if level == 0 else GRAY
        run.font.bold = (level == 0)


def add_table(slide, headers, rows, l, t, w, h,
              header_size=12, body_size=11, col_widths=None):
    cols = len(headers)
    row_count = len(rows) + 1
    tbl = slide.shapes.add_table(row_count, cols, l, t, w, h).table

    if col_widths:
        for ci, cw in enumerate(col_widths):
            tbl.columns[ci].width = cw

    for ci, hdr in enumerate(headers):
        cell = tbl.cell(0, ci)
        cell.fill.solid()
        cell.fill.fore_color.rgb = TEAL
        p = cell.text_frame.paragraphs[0]
        run = p.add_run()
        run.text = hdr
        run.font.bold = True
        run.font.size = Pt(header_size)
        run.font.color.rgb = WHITE

    for ri, row in enumerate(rows):
        bg = LIGHT if ri % 2 == 0 else WHITE
        for ci, val in enumerate(row):
            cell = tbl.cell(ri + 1, ci)
            cell.fill.solid()
            cell.fill.fore_color.rgb = bg
            p = cell.text_frame.paragraphs[0]
            run = p.add_run()
            run.text = val
            run.font.size = Pt(body_size)
            run.font.color.rgb = NAVY
    return tbl


# ── Slides ──────────────────────────────────────────────────────────────────

def slide_title(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, NAVY)

    add_rect(slide, Inches(1.5), Inches(1.8), Inches(10.3), Inches(4.0), TEAL)
    add_rect(slide, Inches(1.6), Inches(1.9), Inches(10.1), Inches(3.8), NAVY)

    add_text_box(slide, "Clinical Co-Pilot",
                 Inches(1.8), Inches(2.05), Inches(9.7), Inches(0.9),
                 font_size=44, bold=True, color=WHITE, align=PP_ALIGN.CENTER)
    add_text_box(slide, "Week 2 — Multimodal Evidence Agent",
                 Inches(1.8), Inches(2.95), Inches(9.7), Inches(0.6),
                 font_size=24, color=TEAL, align=PP_ALIGN.CENTER)
    add_text_box(slide, "Architecture Defense",
                 Inches(1.8), Inches(3.6), Inches(9.7), Inches(0.5),
                 font_size=18, color=WHITE, align=PP_ALIGN.CENTER)
    add_text_box(slide, "AgentForge  ·  Gauntlet AI Austin  ·  May 2026",
                 Inches(1.8), Inches(4.4), Inches(9.7), Inches(0.5),
                 font_size=13, color=GRAY, align=PP_ALIGN.CENTER)


def slide_what_changed(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "What Changed in Week 2",
                 "See real documents · Route work across a graph · Prove quality with a CI gate")

    # three pillars across the top
    pillars = [
        ("SEE", "Multimodal extraction",
         "Sonnet 4.6 reads scanned lab PDFs, intake forms, and external medication lists. "
         "Forced tool-use guarantees schema-valid JSON. Bounding-box overlays power click-to-source."),
        ("ROUTE", "Multi-agent graph",
         "LangGraph supervisor decides between intake-extractor, evidence-retriever, and critic workers. "
         "Every handoff is logged; no black-box decisions."),
        ("PROVE", "Eval-gated CI",
         "50-case suite scored with per-rubric booleans (schema_valid, citation_present, factually_consistent, "
         "safe_refusal, no_phi_in_logs). PR cannot merge if any rubric regresses > 5%."),
    ]
    for i, (tag, title, body) in enumerate(pillars):
        x = Inches(0.4) + i * Inches(4.3)
        add_rect(slide, x, Inches(1.4), Inches(4.1), Inches(0.5), TEAL)
        add_text_box(slide, tag, x + Inches(0.15), Inches(1.45),
                     Inches(2), Inches(0.4),
                     font_size=14, bold=True, color=WHITE)
        add_text_box(slide, title, x + Inches(1.0), Inches(1.48),
                     Inches(3), Inches(0.4),
                     font_size=14, bold=True, color=WHITE)
        add_rect(slide, x, Inches(1.9), Inches(4.1), Inches(2.6), WHITE)
        add_text_box(slide, body, x + Inches(0.15), Inches(2.0),
                     Inches(3.85), Inches(2.45),
                     font_size=12, color=NAVY)

    # bottom: scenario callout
    add_rect(slide, Inches(0.4), Inches(4.85), Inches(12.5), Inches(2.2), WHITE)
    add_text_box(slide, "The scenario",
                 Inches(0.55), Inches(4.95), Inches(12.0), Inches(0.4),
                 font_size=15, bold=True, color=TEAL)
    add_text_box(slide,
                 "A primary care physician prepping for a follow-up visit asks: "
                 "“What changed, what should I pay attention to, and what evidence supports the recommendation?”\n\n"
                 "The chart has structured OpenEMR data, but the important recent information is buried in a scanned lab PDF "
                 "and a patient intake form uploaded by the front desk. Week 2 makes that information visible to the agent, "
                 "with citations separating patient-record facts from guideline evidence.",
                 Inches(0.55), Inches(5.4), Inches(12.2), Inches(1.6),
                 font_size=13, color=NAVY)


def slide_decisions_matrix(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Architectural Decisions at a Glance",
                 "Twelve load-bearing decisions · The rest of this deck expands on each row")

    headers = ["#", "Decision", "Choice", "Why"]
    rows = [
        ("1",  "Orchestration",     "LangGraph StateGraph (supervisor + 3 workers + critic)",
         "Spec names it; inspectable handoffs; native Langfuse callbacks"),
        ("2",  "Vector store",      "pgvector on a new Railway Postgres (per env)",
         "Persistence; SQL-side hybrid (cosine + ts_rank); clean env story"),
        ("3",  "Persistence",       "Hybrid — legacy addNewDocument for source PDF (auto-readable via FHIR DocumentReference); cp_* side-tables for derived facts",
         "Pivoted Day-1 (2026-05-05): no POST Binary / Observation / DocumentReference-create exists in OpenEMR. Spec allows “FHIR resources OR OpenEMR records.”"),
        ("4",  "Vision pipeline",   "Sonnet 4.6 native PDF input + tool-use forced extraction",
         "Single API call sees rendered + text; tool_choice guarantees JSON; bbox via grounding"),
        ("5",  "Eval rubric",       "Per-rubric booleans (schema_valid, citation_present, factually_consistent, safe_refusal, no_phi_in_logs)",
         "Spec calls out exact category names; boolean → failures actionable"),
        ("6",  "CI gate",           "GitHub Actions on PR (agent-evals.yml), required check via branch protection",
         "Cleanest “PR-blocking” story; CircleCI keeps deploy gating downstream"),
        ("7",  "Citation UI",       "Documents tab + chat side-panel — PDF.js viewer with bbox overlay",
         "Reuses existing copilot_documents.php mock; click-to-source from chat"),
        ("8",  "Scope",             "Core + all 3 extensions — critic, lab trend chart, third doc type",
         "Ambitious; explicit Thursday stop-loss to drop extensions if Early Submission isn't green"),
        ("9",  "Embeddings",        "Voyage-3 (1024-dim)",
         "Anthropic-recommended; biomedical-strong; HIPAA-eligible under BAA"),
        ("10", "3rd doc type",      "External medication list",
         "Common workflow; reuses MedicationStatement shape; lower extraction risk than fax"),
        ("11", "Guideline corpus",  "ADA + ACC/AHA HTN + USPSTF + GINA + KDIGO (~10 PDFs, ~250 chunks)",
         "Direct hit on every seeded patient's conditions; public-domain; citation-stable"),
        ("12", "Memory",            "Session-scoped LangGraph state with extracted-fact cache; 30-min TTL",
         "Avoids re-extraction on follow-ups; preserves the 90-second goal"),
    ]
    col_widths = [Inches(0.4), Inches(2.0), Inches(4.4), Inches(5.7)]
    add_table(slide, headers, rows,
              Inches(0.4), Inches(1.25), Inches(12.5), Inches(5.9),
              header_size=12, body_size=10, col_widths=col_widths)

    add_text_box(slide,
                 "Out of scope (stay narrow): ColQwen2/multi-vector, contextual-retrieval rewrites, fax doc type, imaging report.",
                 Inches(0.4), Inches(7.18), Inches(12.5), Inches(0.3),
                 font_size=11, color=GRAY)


def _tech_block(slide, l, t, w, h, header, bullets):
    """One TEAL-banded tech-choice block with bullets beneath.

    `bullets` is a list of (text, level) tuples passed straight to add_bullets.
    """
    add_rect(slide, l, t, w, Inches(0.32), TEAL)
    add_text_box(slide, header, l + Inches(0.1), t + Inches(0.02),
                 w - Inches(0.2), Inches(0.28),
                 font_size=12, bold=True, color=WHITE)
    add_rect(slide, l, t + Inches(0.32), w, h - Inches(0.32), WHITE)
    add_bullets(slide, bullets,
                l + Inches(0.15), t + Inches(0.36),
                w - Inches(0.3), h - Inches(0.38),
                base_size=10)


def slide_tech_choices_1(prs):
    """Why these tech, not alternatives — orchestration + RAG stack."""
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Why These, Not Alternatives — Orchestration & RAG",
                 "Each block: the choice, then the rejected alternatives + why")

    block_h = Inches(1.45)
    y0 = Inches(1.25)

    _tech_block(slide, Inches(0.4), y0, Inches(12.5), block_h,
                "LangGraph StateGraph   (vs Anthropic SDK direct, OpenAI Agents SDK, LlamaIndex/CrewAI)",
                [
                    ("Anthropic SDK direct (Week 1's path): handoffs emerge from prompts, not declared state — exactly the “black-box supervisor” pitfall the spec calls out.", 0),
                    ("OpenAI Agents SDK: forces an OpenAI dependency into an Anthropic-monoculture stack — adds a model boundary and another BAA we'd otherwise avoid.", 0),
                    ("LlamaIndex / CrewAI: weaker Langfuse integration; LangGraph has first-class LangChain callbacks so every node becomes a labeled span automatically.", 0),
                ])

    _tech_block(slide, Inches(0.4), y0 + block_h + Inches(0.05), Inches(12.5), block_h,
                "pgvector on a new Railway Postgres (per env)   (vs Qdrant Cloud, in-memory FAISS, Pinecone/Chroma, pgvector on Langfuse Postgres)",
                [
                    ("Qdrant Cloud / Pinecone / Chroma: SaaS adds a third-party data processor — new BAA, expanded trust boundary, no upside vs self-hosted Postgres.", 0),
                    ("In-memory FAISS + rank_bm25: doesn't survive container restart; no native SQL hybrid (cosine + ts_rank in one query); harder to keep deterministic across Dev/QA/Prod.", 0),
                    ("pgvector on the existing Langfuse Postgres: coupling — corpus refreshes would touch trace storage; clean separation per env is worth one extra Railway service.", 0),
                ])

    _tech_block(slide, Inches(0.4), y0 + 2 * (block_h + Inches(0.05)), Inches(12.5), block_h,
                "Voyage-3 embeddings (1024-dim)   (vs OpenAI text-embedding-3-large, Cohere embed-v3, local sentence-transformers)",
                [
                    ("OpenAI text-embedding-3-large (3072 dim): 3× the pgvector index size, plus a fourth LLM-vendor BAA we'd otherwise avoid.", 0),
                    ("Cohere embed-v3: vendor concentration — we already use Cohere Rerank on the retrieval path; one Cohere outage shouldn't take both stages down.", 0),
                    ("Local sentence-transformers (BGE-large): self-hosted but adds GPU/CPU pressure to the agent container, 10–20s cold-start, weaker on biomedical text.", 0),
                ])

    _tech_block(slide, Inches(0.4), y0 + 3 * (block_h + Inches(0.05)), Inches(12.5), block_h,
                "Cohere Rerank v3.5   (vs Voyage Rerank, local cross-encoder, no rerank)",
                [
                    ("Voyage Rerank: less mature evaluation surface on clinical text; pricing similar; sticking with the spec's named option (“Cohere Rerank or equivalent”).", 0),
                    ("Local cross-encoder (e.g. ms-marco-MiniLM): CPU inference is too slow for the 90-second-between-rooms goal; GPU container would inflate ops cost.", 0),
                    ("No rerank: spec calls out rerank as a core requirement; without it, BM25∪dense ordering produces visibly worse top-5 evidence on the eval set.", 0),
                ])


def slide_tech_choices_2(prs):
    """Why these tech, not alternatives — vision + persistence + eval."""
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Why These, Not Alternatives — Vision, Persistence, Eval",
                 "Each block: the choice, then the rejected alternatives + why")

    block_h = Inches(1.45)
    y0 = Inches(1.25)

    _tech_block(slide, Inches(0.4), y0, Inches(12.5), block_h,
                "Sonnet 4.6 native PDF input + tool_choice extraction   (vs OCR-then-LLM, GPT-4o vision, two-stage layout-then-text)",
                [
                    ("OCR-then-LLM (PyMuPDF or Tesseract + Sonnet text): handwritten intake forms break deterministic OCR; forces two extraction code paths; bounding boxes have to be reconstructed separately.", 0),
                    ("GPT-4o vision: foreign vendor on the hot path — another BAA, breaks the Anthropic-monoculture story, and our existing Langfuse + cost-tracking instrumentation is Anthropic-shaped.", 0),
                    ("Two-stage (layout-vision then text-extract): doubles API calls and latency; tool_choice forcing a Pydantic-shaped JSON gives equivalent reliability in one round-trip.", 0),
                ])

    _tech_block(slide, Inches(0.4), y0 + block_h + Inches(0.05), Inches(12.5), block_h,
                "Hybrid persistence — addNewDocument + cp_* side-tables   (vs full FHIR write, vs side-table-only)",
                [
                    ("Full FHIR write (original Decision #3): grep'd OpenEMR Day-1 — no POST /fhir/Binary, no POST /fhir/Observation, no FhirBinary* service; only POST /fhir/DocumentReference/$docref (CCDA op, not create). Would need ~300 lines of new REST controller + new OAuth scopes for zero behavioral gain.", 0),
                    ("Side-table-only (would skip FHIR entirely): violates the spec's round-trip requirement — source PDF wouldn't be queryable via FHIR DocumentReference at all.", 0),
                    ("Hybrid wins: source lands in OpenEMR's `documents` table (the same table FHIR DocumentReference reads from — round-trip is automatic), derived facts in cp_extracted_facts with explicit derivedFrom: DocumentReference/{id}. Spec explicitly allows “FHIR resources OR OpenEMR records.”", 0),
                ])

    _tech_block(slide, Inches(0.4), y0 + 2 * (block_h + Inches(0.05)), Inches(12.5), block_h,
                "Per-rubric boolean eval scoring   (vs Week 1's 0–1 float, vs LLM 1–10 rating, vs pure case-level pass/fail)",
                [
                    ("Week 1's 0–1 float with a 0.7 threshold: partial credit absorbs regressions — the “> 5 % drop” gate the spec mandates can't target individual rubric categories.", 0),
                    ("LLM-as-judge 1–10 rating: spec calls this out as a pitfall — rubrics become non-actionable and judge variance dominates the signal.", 0),
                    ("Single pass/fail per case: doesn't tell us which rubric (schema_valid vs citation_present vs factually_consistent) regressed; debugging a failed gate becomes guesswork.", 0),
                ])

    _tech_block(slide, Inches(0.4), y0 + 3 * (block_h + Inches(0.05)), Inches(12.5), block_h,
                "GitHub Actions on PR + branch protection   (vs CircleCI deploy gate only, vs pre-push hook only, vs Buildkite/GitLab CI)",
                [
                    ("Pre-push hook only: bypassable with --no-verify; graders typically discount; the spec's hard-gate test (introduce a regression, watch CI fail) is unconvincing without a server-side check.", 0),
                    ("CircleCI deploy gate only (Week 1's posture): gates merging to deploy, not opening a PR — the spec's wording is “PR-blocking,” not “deploy-blocking.”", 0),
                    ("Buildkite / GitLab CI: no existing infra in this repo; standing up a parallel CI system mid-sprint is wasted scope. GHA fits naturally next to the existing CircleCI deploy pipeline.", 0),
                ])


def slide_multi_agent_graph(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Multi-Agent Graph",
                 "LangGraph StateGraph · Supervisor decides routing; handoffs logged to Langfuse")

    # supervisor at top
    add_rect(slide, Inches(5.1), Inches(1.4), Inches(3.1), Inches(0.95), NAVY)
    add_text_box(slide, "Supervisor", Inches(5.1), Inches(1.45),
                 Inches(3.1), Inches(0.4),
                 font_size=15, bold=True, color=WHITE, align=PP_ALIGN.CENTER)
    add_text_box(slide, "Sonnet 4.6 · routes between workers",
                 Inches(5.1), Inches(1.85), Inches(3.1), Inches(0.4),
                 font_size=11, color=TEAL, align=PP_ALIGN.CENTER)

    # 4 workers
    workers = [
        ("Intake\nExtractor",  "Sonnet 4.6 PDF\n+ tool_choice\n→ JSON\n+ FHIR write", TEAL),
        ("Evidence\nRetriever", "BM25 + Voyage-3\n+ Cohere Rerank\n→ top-5\nguideline chunks", TEAL),
        ("Critic",             "(extension)\nRejects uncited\nor unsafe claims;\nloops back ≤1×", AMBER),
        ("Final\nAnswer",      "Streams reply\nto chat UI\nwith citation\nchips", GREEN),
    ]
    for i, (name, body, col) in enumerate(workers):
        x = Inches(0.5) + i * Inches(3.1)
        add_rect(slide, x, Inches(3.1), Inches(2.85), Inches(0.7), col)
        add_text_box(slide, name, x + Inches(0.05), Inches(3.15),
                     Inches(2.75), Inches(0.6),
                     font_size=13, bold=True, color=WHITE, align=PP_ALIGN.CENTER)
        add_rect(slide, x, Inches(3.8), Inches(2.85), Inches(1.5), WHITE)
        add_text_box(slide, body, x + Inches(0.1), Inches(3.85),
                     Inches(2.7), Inches(1.5),
                     font_size=11, color=NAVY, align=PP_ALIGN.CENTER)

    # arrows between supervisor and workers
    for i in range(4):
        x = Inches(0.5) + i * Inches(3.1) + Inches(1.4)
        add_text_box(slide, "│", x, Inches(2.4),
                     Inches(0.3), Inches(0.6),
                     font_size=20, color=GRAY, align=PP_ALIGN.CENTER)

    # state shape
    add_rect(slide, Inches(0.4), Inches(5.55), Inches(12.5), Inches(1.6), WHITE)
    add_text_box(slide, "AgentState (carried across nodes)",
                 Inches(0.55), Inches(5.6), Inches(12.0), Inches(0.4),
                 font_size=14, bold=True, color=TEAL)
    add_text_box(slide,
                 "session_id  ·  patient_id (server-enforced)  ·  messages  ·  pending_doc_uploads"
                 "\nextracted_facts_cache {doc_id: ExtractedFacts}   ·   evidence_cache {query_hash: chunks}"
                 "\ncitations[]   ·   handoff_log[]  (every supervisor decision → Langfuse span)",
                 Inches(0.55), Inches(6.0), Inches(12.2), Inches(1.1),
                 font_size=12, color=NAVY)


def slide_ingestion_flow(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Document Ingestion Flow",
                 "Upload → vision extraction → FHIR write → citations side-table")

    steps = [
        ("1. Upload",
         "Clinician uploads PDF via OpenEMR's existing Documents tab.\n"
         "library/ajax/upload.php → addNewDocument() → row in `documents` table."),
        ("2. Trigger",
         "POST /extract on the agent  { patient_id, document_id, doc_type }\n"
         "attach_and_extract resolves document_id → on-disk path,\n"
         "cross-checks foreign_id == patient_id."),
        ("3. Vision",
         "Sonnet 4.6 receives PDF as a `document` content block.\n"
         "tool_choice forces extract_lab_report / extract_intake_form / extract_medication_list.\n"
         "Pydantic re-validates the model's tool_use input."),
        ("4. Persist facts",
         "INSERT cp_extraction_runs (run_id, document_id, …)\n"
         "INSERT cp_extracted_facts × N  (each row carries derivedFrom: DocumentReference/{id})\n"
         "INSERT cp_extraction_citations × N  (page, bbox, field_path, quote)"),
        ("5. Return",
         "{ run_id, schema_valid, fact_count, citation_count, latency_ms,\n"
         "  input_tokens, output_tokens, payload }\n"
         "Cached in AgentState.extracted_facts_cache for follow-up turns."),
    ]
    for i, (title, body) in enumerate(steps):
        y = Inches(1.4) + i * Inches(1.12)
        add_rect(slide, Inches(0.4), y, Inches(2.4), Inches(1.0), TEAL)
        add_text_box(slide, title, Inches(0.5), y + Inches(0.3),
                     Inches(2.2), Inches(0.5),
                     font_size=14, bold=True, color=WHITE)
        add_rect(slide, Inches(2.85), y, Inches(10.1), Inches(1.0), WHITE)
        add_text_box(slide, body, Inches(2.95), y + Inches(0.08),
                     Inches(9.95), Inches(0.95),
                     font_size=12, color=NAVY)

    add_text_box(slide,
                 "Round-trip guarantee: derivedFrom is the integrity contract — every Observation can pivot back to its source PDF in one FHIR query.",
                 Inches(0.4), Inches(7.05), Inches(12.5), Inches(0.35),
                 font_size=11, color=GRAY)


def slide_hybrid_rag(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Hybrid RAG — Guideline Evidence",
                 "BM25 + Voyage-3 + Cohere Rerank → top-5 chunks; pgvector on a new Railway service")

    # Retrieval flow (left)
    add_rect(slide, Inches(0.4), Inches(1.25), Inches(6.0), Inches(3.5), WHITE)
    add_text_box(slide, "Retrieval algorithm",
                 Inches(0.55), Inches(1.3), Inches(5.7), Inches(0.4),
                 font_size=14, bold=True, color=TEAL)
    add_bullets(slide, [
        ("Sparse: ts_rank_cd(tsv, plainto_tsquery)  → top-30", 0),
        ("Dense:  1 - (embedding <=> voyage_3)      → top-30", 0),
        ("Union, dedupe, keep up to 60 candidates", 0),
        ("Cohere Rerank v3.5  → top-5", 0),
        ("Each chunk: source_id, source_url, page, section, quote, score", 0),
    ], Inches(0.55), Inches(1.75), Inches(5.7), Inches(2.85), base_size=12)

    # Schema (right)
    add_rect(slide, Inches(6.9), Inches(1.25), Inches(6.0), Inches(3.5), NAVY)
    add_text_box(slide, "pgvector schema",
                 Inches(7.05), Inches(1.3), Inches(5.7), Inches(0.4),
                 font_size=14, bold=True, color=TEAL)
    add_text_box(slide,
                 "CREATE TABLE corpus_chunks (\n"
                 "  id          SERIAL PRIMARY KEY,\n"
                 "  source_id   TEXT NOT NULL,\n"
                 "  source_url  TEXT,\n"
                 "  page        INT,\n"
                 "  section     TEXT,\n"
                 "  text        TEXT NOT NULL,\n"
                 "  embedding   VECTOR(1024) NOT NULL,\n"
                 "  tsv         TSVECTOR GENERATED ALWAYS\n"
                 "              AS (to_tsvector('english', text))\n"
                 "              STORED\n"
                 ");",
                 Inches(7.05), Inches(1.75), Inches(5.7), Inches(2.85),
                 font_size=11, color=WHITE)

    # Corpus inventory (bottom)
    add_rect(slide, Inches(0.4), Inches(4.9), Inches(12.5), Inches(2.25), WHITE)
    add_text_box(slide, "Corpus inventory",
                 Inches(0.55), Inches(4.95), Inches(12.0), Inches(0.4),
                 font_size=14, bold=True, color=TEAL)
    add_table(slide,
              ["Source", "Coverage", "Chunks (≈)"],
              [
                  ("ADA Standards of Care 2024",   "T2DM",            "80"),
                  ("ACC/AHA Hypertension 2017",    "HTN",             "40"),
                  ("USPSTF: statins for CVD",      "Hyperlipidemia",  "15"),
                  ("GINA Asthma 2024",             "Asthma",          "60"),
                  ("KDIGO CKD 2024",               "CKD",             "40"),
                  ("(room for ~3 more)",           "",                "~15"),
                  ("Total",                        "",                "~250"),
              ],
              Inches(0.55), Inches(5.4), Inches(12.2), Inches(1.7),
              header_size=11, body_size=10)


def slide_eval_gate(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Eval Gate — deterministic-first + adversarial + replay",
                 "Reframed after final-submission grader feedback (2026-05-03): eval quality, not quantity")

    # case mix (left)
    add_rect(slide, Inches(0.4), Inches(1.25), Inches(6.4), Inches(3.6), WHITE)
    add_text_box(slide, "Case mix  (50 total)",
                 Inches(0.55), Inches(1.3), Inches(6.1), Inches(0.4),
                 font_size=14, bold=True, color=TEAL)
    add_table(slide,
              ["Category", "Count", "Notes"],
              [
                  ("Goldens",          "~15", "Hand-authored, locked to seed snapshot"),
                  ("Labeled",          "~10", "Open-ended; LLM-judge"),
                  ("Adversarial",      "~10", "Corrupt PDFs, malformed FHIR, fuzzed inputs, prompt-inject in extracted text"),
                  ("Replay",           "~10", "PHI-redacted production traces, grows weekly"),
                  ("System metrics",   "n/a", "tool_call_accuracy + latency p50/p95 vs baseline"),
              ],
              Inches(0.55), Inches(1.75), Inches(6.1), Inches(3.0),
              header_size=11, body_size=10)

    # rubrics — deterministic-first (right)
    add_rect(slide, Inches(7.0), Inches(1.25), Inches(5.9), Inches(3.6), NAVY)
    add_text_box(slide, "Rubrics — deterministic-first",
                 Inches(7.15), Inches(1.3), Inches(5.6), Inches(0.4),
                 font_size=14, bold=True, color=TEAL)
    add_text_box(slide,
                 "schema_valid       — Pydantic re-validate\n"
                 "                       (no judge)\n\n"
                 "citation_present   — state-based regex match\n"
                 "                       on emitted citation_ids\n"
                 "                       (no judge)\n\n"
                 "factually_consistent — exact-match against\n"
                 "                       extracted facts /\n"
                 "                       guideline chunk;\n"
                 "                       judge only on residual\n\n"
                 "safe_refusal       — refusal classifier first;\n"
                 "                       judge only when uncertain\n\n"
                 "no_phi_in_logs     — redact() over every trace\n"
                 "                       (no judge)",
                 Inches(7.15), Inches(1.75), Inches(5.6), Inches(3.05),
                 font_size=10, color=WHITE)

    # gate logic (bottom)
    add_rect(slide, Inches(0.4), Inches(5.0), Inches(12.5), Inches(2.15), WHITE)
    add_text_box(slide, "Regression gate — fails CI if any of:",
                 Inches(0.55), Inches(5.05), Inches(12.0), Inches(0.4),
                 font_size=14, bold=True, color=TEAL)
    add_bullets(slide, [
        ("Any rubric drops > 5 pp vs checked-in baseline.json", 0),
        ("Any rubric falls below its floor: schema_valid ≥ 0.95, citation_present ≥ 0.95, factually_consistent ≥ 0.85, safe_refusal = 1.00, no_phi_in_logs = 1.00", 0),
        ("tool_call_accuracy drops > 5 pp OR falls below 0.90", 0),
        ("latency_p95_ms increases > 25 % vs baseline", 0),
        ("Runs in .github/workflows/agent-evals.yml as a required check via branch protection — non-bypassable", 0),
    ], Inches(0.55), Inches(5.45), Inches(12.0), Inches(1.7), base_size=11)


def slide_risks(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, LIGHT)
    slide_header(slide, "Risks & Stop-Loss",
                 "Where the plan can go wrong, and what we do about it")

    headers = ["Risk", "Mitigation"]
    rows = [
        ("OpenEMR FHIR write completeness  —  no POST Binary / Observation / DocumentReference-create exist",
         "GRADUATED 2026-05-05: verified absent by grep; pivoted to hybrid persistence (legacy addNewDocument + cp_* side-tables). Risk closed."),
        ("LangGraph + Anthropic streaming has known friction  —  astream_events v1/v2 quirks, partial tool-call streaming",
         "Build minimal streaming smoke before migrating chat off the W1 single-loop path. W1 path stays as fallback."),
        ("pgvector parity across Dev/QA/Prod  —  three Railway services, three credential sets",
         "MVP today uses an in-memory BM25 retriever over seed_corpus.json to dodge this provisioning blocker. pgvector lands later this week."),
        ("Sonnet 4.6 PDF cap = 32 pages",
         "Lab + intake docs are 1–3 pages; corpus chunker splits any guideline PDF that exceeds the cap."),
        ("Scope sprawl  —  all 3 extensions chosen against the spec's “narrower is better” warning",
         "Stop-loss: if Thursday Early Submission isn't green, drop critic and trend chart; keep med-list (reuses ingestion infra)."),
        ("Vendor concentration on retrieval  —  Anthropic + Voyage + Cohere",
         "All HIPAA-eligible under BAA; matches the demo posture already documented in README."),
    ]
    add_table(slide, headers, rows,
              Inches(0.4), Inches(1.25), Inches(12.5), Inches(5.0),
              header_size=12, body_size=11,
              col_widths=[Inches(5.5), Inches(7.0)])

    add_rect(slide, Inches(0.4), Inches(6.4), Inches(12.5), Inches(0.8), AMBER)
    add_text_box(slide,
                 "Hard stop-loss order if Thursday isn't green:  trend chart  →  critic  →  third doc type.  "
                 "Core (lab + intake + supervisor + 2 workers + RAG + eval gate) ships no matter what.",
                 Inches(0.55), Inches(6.55), Inches(12.2), Inches(0.55),
                 font_size=13, bold=True, color=NAVY)


def slide_closing(prs):
    slide = prs.slides.add_slide(blank_layout(prs))
    fill_bg(slide, NAVY)

    add_text_box(slide, "Week 2 Architecture — Summary",
                 Inches(1.0), Inches(1.2), Inches(11.3), Inches(0.8),
                 font_size=30, bold=True, color=WHITE, align=PP_ALIGN.CENTER)

    points = [
        ("LangGraph StateGraph",   "supervisor + 3 workers + critic, handoffs logged to Langfuse"),
        ("Sonnet 4.6 native PDF",  "tool_choice forces schema-valid extraction; bbox via grounding"),
        ("Hybrid persistence",     "addNewDocument for source (auto FHIR-readable); cp_* side-tables for derived facts + bboxes"),
        ("Hybrid RAG on pgvector", "BM25 + Voyage-3 + Cohere Rerank, ~250 chunks across 5 guideline sources"),
        ("Per-rubric boolean gate","50 cases, GitHub Actions PR check, regression > 5% blocks merge"),
        ("Citations everywhere",   "patient-record vs guideline chips; PDF.js bbox overlay on click"),
    ]
    for i, (label, desc) in enumerate(points):
        y = Inches(2.2) + i * Inches(0.7)
        add_rect(slide, Inches(0.8), y, Inches(3.8), Inches(0.55), TEAL)
        add_text_box(slide, label, Inches(0.85), y + Inches(0.08),
                     Inches(3.7), Inches(0.4),
                     font_size=13, bold=True, color=WHITE)
        add_text_box(slide, desc, Inches(4.8), y + Inches(0.08),
                     Inches(8.3), Inches(0.4),
                     font_size=13, color=LIGHT)

    add_text_box(slide,
                 "github.com/cxk280/agentforge   ·   ./ARCHITECTURE.md   ·   ./W2_ARCHITECTURE.md",
                 Inches(1.0), Inches(6.85), Inches(11.3), Inches(0.4),
                 font_size=11, color=GRAY, align=PP_ALIGN.CENTER)


# ── Main ────────────────────────────────────────────────────────────────────

prs = make_prs()
slide_title(prs)
slide_what_changed(prs)
slide_decisions_matrix(prs)
slide_tech_choices_1(prs)
slide_tech_choices_2(prs)
slide_multi_agent_graph(prs)
slide_ingestion_flow(prs)
slide_hybrid_rag(prs)
slide_eval_gate(prs)
slide_risks(prs)
slide_closing(prs)

out = "/Users/christopherking/code/gauntlet/agentforge/w2_architecture_deck.pptx"
prs.save(out)
print(f"Saved: {out}  ({len(prs.slides)} slides)")
