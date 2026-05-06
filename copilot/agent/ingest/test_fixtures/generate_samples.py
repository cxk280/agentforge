"""Generate the AgentForge sample-PDF fixture set.

Each function emits one PDF that follows the layout/typography conventions
of a real-world clinical document type. The variety is intentional — the
ingestion pipeline should hit each of these and produce schema-valid JSON
without any layout-specific code paths.

Patient values are pinned to the seeded **Nora Cohen** chart (pid=8) so
cross-checks between extracted facts and the chart can be exercised by the
eval suite. Her profile (anchored to copilot_seed_demo_data.php):

    Demographics:  Nora Cohen, DOB 1967-06-04, Female
                   155 First Avenue, San Luis CA 92101
                   Phone (213) 555-5555.
    Active problems:
                   Hypothyroidism (ICD10 E03.9) since 2015
                   Major Depressive Disorder, recurrent (F33.1) since 2023
                   Osteoporosis (M81.0) since 2024
    Active medications:
                   Levothyroxine 112 mcg PO daily, empty stomach
                   Alendronate 70 mg PO once weekly
                   Sertraline 100 mg PO daily AM
                   Calcium Carbonate 1200 mg + Vitamin D3 2000 IU PO daily
    Allergies:     Codeine (nausea/vomiting), Shellfish (anaphylaxis)

If the seed changes, update PATIENT_PROFILE below and re-run this module.

Usage:
    python -m copilot.agent.ingest.test_fixtures.generate_samples

Outputs (under copilot/agent/ingest/test_fixtures/samples/):
    lab_quest_style.pdf            single-panel CMP, Quest-Diagnostics-shaped
    lab_labcorp_multipanel.pdf     CMP + Lipid + Thyroid + Bone, LabCorp two-column
    lab_hospital_telex.pdf         monospace ASCII-style hospital printout
    intake_modern_clinic.pdf       multi-page clean intake with boxed sections
    intake_specialty_cardiology.pdf  endocrinology pre-visit, different organization
    medication_list_pharmacy.pdf   retail pharmacy med list
    medication_list_discharge.pdf  hospital discharge medication reconciliation

(The "specialty_cardiology" filename is preserved for backwards
compatibility with anything that references it by name; the content is now
endocrinology, which fits Nora's chart better.)
"""

from __future__ import annotations

from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import inch
from reportlab.pdfgen import canvas
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    PageTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
)


SAMPLES_DIR = Path(__file__).parent / "samples"


# ---------------------------------------------------------------------------
# Patient profile — single source of truth for every PDF below.
# ---------------------------------------------------------------------------

PATIENT_PROFILE = {
    "first": "Nora",
    "last": "Cohen",
    "dob": "06/04/1967",
    "dob_iso": "1967-06-04",
    "age": 58,
    "sex_full": "Female",
    "sex_letter": "F",
    "mrn": "AGF-000008",
    "phone": "(213) 555-5555",
    "email": "nora.cohen@example.com",
    "street": "155 First Avenue",
    "city": "San Luis",
    "state": "CA",
    "zip": "92101",
    "primary_provider": "Maria Jensen, MD",
    "primary_clinic": "San Luis Family Medicine",
    "primary_clinic_addr": "200 Main St, San Luis CA 92101",
    "endocrinologist": "Daniel Park, MD",
    "endocrinology_clinic": "Pacific Endocrinology Associates",
    "discharging_hospitalist": "Jorge Reyes, MD",
}


# ---------------------------------------------------------------------------
# Lab #1 — Quest Diagnostics-style single-panel CMP
# ---------------------------------------------------------------------------

def gen_lab_quest_style(out: Path) -> None:
    """Single-page CMP report, light blue accents, Helvetica.

    Mirrors the Quest Diagnostics layout: lab logo block top-left, patient
    block top-right, single tabular section with H/L flag column, and a
    methodology footer.

    Values are clinically consistent with Nora Cohen's chart — no diabetes,
    no CKD; routine well-managed metabolic chemistry on Levothyroxine +
    Alendronate + Calcium/D3.
    """
    p = PATIENT_PROFILE
    c = canvas.Canvas(str(out), pagesize=letter)
    W, H = letter

    # Header band
    c.setFillColorRGB(0.15, 0.30, 0.55)
    c.rect(0, H - 60, W, 60, fill=1, stroke=0)
    c.setFillColor(colors.white)
    c.setFont("Helvetica-Bold", 22)
    c.drawString(40, H - 38, "Quest Diagnostics")
    c.setFont("Helvetica", 10)
    c.drawString(40, H - 52, "  Patient Service Center  ·  500 Plaza Dr, Secaucus NJ 07094")

    # Accession / report metadata block
    c.setFillColor(colors.black)
    c.setFont("Helvetica", 9)
    c.drawRightString(W - 40, H - 28, "Accession #  G2604280214")
    c.drawRightString(W - 40, H - 40, "Report Date  04/29/2026  10:42")
    c.drawRightString(W - 40, H - 52, "Page 1 of 1")

    # Patient + ordering provider blocks
    y = H - 90
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "PATIENT")
    c.drawString(310, y, "ORDERING PROVIDER")
    c.setFont("Helvetica", 10)
    c.drawString(40, y - 14, f"{p['last'].upper()}, {p['first'].upper()}")
    c.drawString(40, y - 26, f"DOB: {p['dob']}     Sex: {p['sex_letter']}     Age: {p['age']}")
    c.drawString(40, y - 38, f"MRN: {p['mrn']}")
    c.drawString(40, y - 50, "Collected: 04/27/2026  09:14")
    c.drawString(310, y - 14, p["primary_provider"])
    c.drawString(310, y - 26, p["primary_clinic"])
    c.drawString(310, y - 38, p["primary_clinic_addr"])
    c.drawString(310, y - 50, "NPI 1356470225")

    # Section title
    y -= 80
    c.setFillColorRGB(0.15, 0.30, 0.55)
    c.rect(40, y, W - 80, 16, fill=1, stroke=0)
    c.setFillColor(colors.white)
    c.setFont("Helvetica-Bold", 10)
    c.drawString(48, y + 4, "COMPREHENSIVE METABOLIC PANEL (CMP-14)")
    c.setFillColor(colors.black)

    # Column headers
    y -= 22
    c.setFont("Helvetica-Bold", 9)
    c.drawString(48,  y, "TEST")
    c.drawString(248, y, "RESULT")
    c.drawString(305, y, "FLAG")
    c.drawString(345, y, "UNITS")
    c.drawString(415, y, "REFERENCE RANGE")
    c.line(40, y - 3, W - 40, y - 3)
    y -= 16

    rows = [
        ("Glucose, Fasting",       "92",   "",   "mg/dL",        "70-99"),
        ("BUN",                    "14",   "",   "mg/dL",        "7-20"),
        ("Creatinine",             "0.8",  "",   "mg/dL",        "0.6-1.1"),
        ("eGFR (CKD-EPI 2021)",    ">90",  "",   "mL/min/1.73",  ">=60"),
        ("BUN/Creatinine Ratio",   "17.5", "",   "",             "10.0-20.0"),
        ("Sodium",                 "140",  "",   "mmol/L",       "135-145"),
        ("Potassium",              "4.2",  "",   "mmol/L",       "3.5-5.1"),
        ("Chloride",               "103",  "",   "mmol/L",       "98-107"),
        ("Carbon Dioxide",         "26",   "",   "mmol/L",       "21-32"),
        ("Calcium",                "9.6",  "",   "mg/dL",        "8.6-10.2"),
        ("Total Protein",          "7.0",  "",   "g/dL",         "6.4-8.3"),
        ("Albumin",                "4.2",  "",   "g/dL",         "3.5-5.0"),
        ("Bilirubin, Total",       "0.6",  "",   "mg/dL",        "0.0-1.2"),
        ("Alkaline Phosphatase",   "88",   "",   "U/L",          "44-147"),
        ("AST",                    "24",   "",   "U/L",          "10-35"),
        ("ALT",                    "22",   "",   "U/L",          "7-45"),
    ]
    c.setFont("Helvetica", 9)
    for name, value, flag, unit, ref in rows:
        if flag in ("H", "L"):
            c.setFillColorRGB(0.78, 0.0, 0.0)
            c.setFont("Helvetica-Bold", 9)
        else:
            c.setFillColor(colors.black)
            c.setFont("Helvetica", 9)
        c.drawString(48,  y, name)
        c.drawString(248, y, value)
        c.drawString(308, y, flag)
        c.setFillColor(colors.black)
        c.setFont("Helvetica", 9)
        c.drawString(345, y, unit)
        c.drawString(415, y, ref)
        y -= 14

    # Footer / methodology
    y -= 10
    c.setStrokeColorRGB(0.7, 0.7, 0.7)
    c.line(40, y, W - 40, y)
    y -= 14
    c.setFillColor(colors.black)
    c.setFont("Helvetica-Oblique", 8)
    c.drawString(40, y, "Performed at Quest Diagnostics, San Diego CA 92121   ·   CLIA #05D0641223   ·   Director: Lawrence Patel, MD")
    y -= 12
    c.drawString(40, y, "Methodology: Photometric (Glucose, BUN, Creatinine, electrolytes); Ion-Selective Electrode (Na/K/Cl)")
    y -= 12
    c.drawString(40, y, "eGFR calculated with the 2021 CKD-EPI race-free equation. Reference ranges may vary by methodology.")

    c.showPage()
    c.save()


# ---------------------------------------------------------------------------
# Lab #2 — LabCorp-style multi-panel two-column report
# ---------------------------------------------------------------------------

def gen_lab_labcorp_multipanel(out: Path) -> None:
    """Two-column dense report, four grouped panels (CMP, Lipid, Thyroid, Bone).

    Different visual rhythm than the Quest one: panels stacked, each with
    its own mini-header + bordered table, no flag column (uses bold red on
    the value itself).

    Panels were chosen to match Nora's clinical picture — there's no
    diabetes, so the A1c panel from the older fixture has been replaced
    with a full thyroid + bone-health workup that's much more relevant
    to a postmenopausal woman on Levothyroxine + Alendronate.
    """
    p = PATIENT_PROFILE
    c = canvas.Canvas(str(out), pagesize=letter)
    W, H = letter

    # LabCorp-ish header
    c.setFillColor(colors.black)
    c.setFont("Helvetica-Bold", 24)
    c.drawString(40, H - 40, "Labcorp")
    c.setFont("Helvetica", 8)
    c.drawString(40, H - 52, "Burlington Patient Service Center  ·  PO Box 2270, Burlington NC 27216")
    c.setFont("Helvetica", 9)
    c.drawRightString(W - 40, H - 28, "Specimen ID: 026-543-9970-1")
    c.drawRightString(W - 40, H - 40, "Control:  AGF-NC-2026-04-27")
    c.drawRightString(W - 40, H - 52, "Account: 12345678")
    c.line(40, H - 60, W - 40, H - 60)

    y = H - 80
    c.setFont("Helvetica-Bold", 9)
    c.drawString(40, y, "Patient")
    c.drawString(220, y, "Specimen")
    c.drawString(420, y, "Physician")
    c.setFont("Helvetica", 9)
    c.drawString(40, y - 12, f"{p['last'].upper()}, {p['first'].upper()}")
    c.drawString(40, y - 24, f"DOB {p['dob']}  {p['sex_letter']}  Age {p['age']}")
    c.drawString(40, y - 36, f"Patient ID: {p['mrn']}")
    c.drawString(220, y - 12, "Collected: 04/27/2026  09:14")
    c.drawString(220, y - 24, "Received:  04/27/2026  17:36")
    c.drawString(220, y - 36, "Reported:  04/29/2026  10:42")
    c.drawString(420, y - 12, p["primary_provider"])
    c.drawString(420, y - 24, "200 Main St")
    c.drawString(420, y - 36, "San Luis CA 92101")
    y -= 56

    def draw_panel(title: str, rows, x0: float, y0: float, panel_w: float = 240) -> float:
        """Draw a single panel and return the new y position."""
        c.setFillColorRGB(0.92, 0.92, 0.96)
        c.rect(x0, y0 - 14, panel_w, 14, fill=1, stroke=0)
        c.setFillColor(colors.black)
        c.setFont("Helvetica-Bold", 9)
        c.drawString(x0 + 4, y0 - 11, title)
        ny = y0 - 14
        c.setFont("Helvetica-Bold", 8)
        c.drawString(x0 + 4,   ny - 11, "Test")
        c.drawString(x0 + 110, ny - 11, "Result")
        c.drawString(x0 + 158, ny - 11, "Units")
        c.drawString(x0 + 192, ny - 11, "Range")
        c.line(x0, ny - 13, x0 + panel_w, ny - 13)
        ny -= 24
        for name, value, unit, ref, abnormal in rows:
            c.setFont("Helvetica", 8)
            c.setFillColor(colors.black)
            c.drawString(x0 + 4, ny, name)
            if abnormal:
                c.setFillColorRGB(0.78, 0.0, 0.0)
                c.setFont("Helvetica-Bold", 8)
            c.drawString(x0 + 110, ny, value)
            c.setFillColor(colors.black)
            c.setFont("Helvetica", 8)
            c.drawString(x0 + 158, ny, unit)
            c.drawString(x0 + 192, ny, ref)
            ny -= 11
        c.setStrokeColorRGB(0.85, 0.85, 0.85)
        c.rect(x0, ny - 2, panel_w, (y0 - ny) + 2, fill=0, stroke=1)
        return ny

    # Left column — CMP + Lipid
    cmp_rows = [
        ("Glucose, Fasting",  "92",   "mg/dL",        "70-99",     False),
        ("BUN",               "14",   "mg/dL",        "7-20",      False),
        ("Creatinine",        "0.8",  "mg/dL",        "0.6-1.1",   False),
        ("eGFR",              ">90",  "mL/min/1.73",  ">=60",      False),
        ("Sodium",            "140",  "mmol/L",       "135-145",   False),
        ("Potassium",         "4.2",  "mmol/L",       "3.5-5.1",   False),
        ("Chloride",          "103",  "mmol/L",       "98-107",    False),
        ("CO2",               "26",   "mmol/L",       "21-32",     False),
    ]
    next_y = draw_panel("CMP — Comprehensive Metabolic Panel", cmp_rows, 40, y)

    lipid_rows = [
        ("Cholesterol, Total", "218",  "mg/dL", "<200",   True),
        ("Triglycerides",      "112",  "mg/dL", "<150",   False),
        ("HDL Cholesterol",    "64",   "mg/dL", ">=50",   False),
        ("LDL, calculated",    "132",  "mg/dL", "<130",   True),
        ("VLDL, calculated",   "22",   "mg/dL", "5-40",   False),
        ("Chol/HDL Ratio",     "3.4",  "",      "<5.0",   False),
    ]
    draw_panel("LIPID PANEL", lipid_rows, 40, next_y - 16)

    # Right column — Thyroid + Bone Health
    thyroid_rows = [
        ("TSH, 3rd generation", "2.45",  "uIU/mL", "0.45-4.50", False),
        ("Free T4",             "1.2",   "ng/dL",  "0.82-1.77", False),
        ("Free T3",             "3.1",   "pg/mL",  "2.0-4.4",   False),
        ("Thyroglobulin Ab",    "<1.0",  "IU/mL",  "<4.0",      False),
    ]
    right_y = draw_panel("THYROID FUNCTION (on Levothyroxine)", thyroid_rows, 310, y)

    bone_rows = [
        ("25-OH Vitamin D",        "36",   "ng/mL",  "30-100",   False),
        ("Ionized Calcium",        "5.2",  "mg/dL",  "4.6-5.3",  False),
        ("Phosphorus",             "3.6",  "mg/dL",  "2.5-4.5",  False),
        ("Magnesium",              "2.0",  "mg/dL",  "1.6-2.6",  False),
        ("Alk Phosphatase, bone",  "12",   "ug/L",   "<22",      False),
        ("PTH, intact",            "44",   "pg/mL",  "15-65",    False),
    ]
    draw_panel("BONE HEALTH", bone_rows, 310, right_y - 16)

    # Methodology footer
    c.setFillColor(colors.black)
    c.setFont("Helvetica-Oblique", 7)
    c.drawString(40, 60, "Performed by Labcorp, Burlington NC 27216  ·  CLIA #34D0654001  ·  Lab Director: J. Patel, MD")
    c.drawString(40, 50, "TSH/Free T4/Free T3 by chemiluminescent immunoassay. 25-OH Vit D by LC-MS/MS. Lipid by Beckman Coulter AU680. eGFR via 2021 CKD-EPI (race-free).")
    c.drawString(40, 40, "* (H) high, (L) low — see reference range columns. Values flagged with red bold.")

    c.showPage()
    c.save()


# ---------------------------------------------------------------------------
# Lab #3 — Hospital monospace "telex" printout
# ---------------------------------------------------------------------------

def gen_lab_hospital_telex(out: Path) -> None:
    """Old-school hospital lab printout: monospace, fixed-column, no graphics.

    The kind of output an HL7-feeding hospital lab system spits onto a
    line printer that the front desk then scans into a PDF. Tests the
    extractor's ability to handle column-aligned text with no visual cues.

    Panel mix: CBC + Chemistry (BMP) + Thyroid + Bone — the labs you'd
    realistically run on Nora at a 6-month follow-up.
    """
    p = PATIENT_PROFILE
    c = canvas.Canvas(str(out), pagesize=letter)
    W, H = letter
    c.setFont("Courier", 10)

    name_line = f"  PATIENT.....: {p['last'].upper()}, {p['first'].upper()}                      ACCT NO....: AGF2604280214        "
    mrn_line = f"  MRN........: 0000123488                    DOB........: {p['dob_iso'].upper().replace('-', '-')}       "
    # Convert DOB to "DD-MON-YYYY" telex style.
    dob_yyyy, dob_mm, dob_dd = p["dob_iso"].split("-")
    months = ["JAN", "FEB", "MAR", "APR", "MAY", "JUN", "JUL", "AUG", "SEP", "OCT", "NOV", "DEC"]
    dob_telex = f"{dob_dd}-{months[int(dob_mm) - 1]}-{dob_yyyy}"
    mrn_line = f"  MRN........: 0000123488                    DOB........: {dob_telex}       "
    age_sex = f"  AGE/SEX....: {p['age']}  {p['sex_letter']}                         ROOM.......: OUTPATIENT        "

    lines = [
        "============================================================================",
        "  ST. DAVID'S MEDICAL CENTER -- CENTRAL LABORATORY                          ",
        "  919 East 32nd Street, Austin TX 78705        Tel: (512) 476-7111          ",
        "============================================================================",
        "",
        name_line,
        mrn_line,
        age_sex,
        "  ATTENDING..: JENSEN, MARIA       MD        ORDERED....: 27-APR-2026 09:14 ",
        "  COLLECTED..: 27-APR-2026  09:14            REPORTED...: 29-APR-2026 10:42 ",
        "============================================================================",
        "                                                                            ",
        "  HEMATOLOGY -- COMPLETE BLOOD COUNT W/AUTO DIFF                            ",
        "  ----------------------------------------------------------------          ",
        "  TEST                       RESULT     UNITS         REFERENCE             ",
        "                                                                            ",
        "  WBC                         6.2       k/uL          4.0  - 11.0           ",
        "  RBC                         4.46       M/uL         4.0  -  5.2           ",
        "  HEMOGLOBIN                 13.1       g/dL          12.0 - 15.5           ",
        "  HEMATOCRIT                 39.6       %             36.0 - 46.0           ",
        "  MCV                        88.7       fL            80.0 -100.0           ",
        "  MCH                        29.4       pg            27.0 - 33.0           ",
        "  MCHC                       33.1       g/dL          32.0 - 36.0           ",
        "  RDW                        13.4       %             11.5 - 14.5           ",
        "  PLATELETS                  245        k/uL         150   -450             ",
        "                                                                            ",
        "  CHEMISTRY -- BASIC METABOLIC PANEL                                        ",
        "  ----------------------------------------------------------------          ",
        "  GLUCOSE, FASTING           92         mg/dL         70   - 99             ",
        "  CREATININE                  0.8       mg/dL         0.6 -  1.1            ",
        "  EGFR (CKD-EPI 2021)        >90        mL/min/1.73   >= 60                 ",
        "  BUN                        14         mg/dL          7   - 20             ",
        "  SODIUM                    140         mmol/L        135  -145             ",
        "  POTASSIUM                   4.2       mmol/L          3.5-  5.1           ",
        "  MAGNESIUM                   2.0       mg/dL          1.6-  2.6            ",
        "  PHOSPHORUS                  3.6       mg/dL          2.5-  4.5            ",
        "                                                                            ",
        "  ENDOCRINE -- THYROID FUNCTION (PATIENT ON LEVOTHYROXINE 112MCG)           ",
        "  ----------------------------------------------------------------          ",
        "  TSH, 3rd GEN                2.45      uIU/mL        0.45-  4.50           ",
        "  FREE T4                     1.2       ng/dL         0.82-  1.77           ",
        "  FREE T3                     3.1       pg/mL         2.0 -  4.4            ",
        "                                                                            ",
        "  BONE HEALTH                                                               ",
        "  ----------------------------------------------------------------          ",
        "  25-OH VITAMIN D            36         ng/mL         30  -100              ",
        "  ALKALINE PHOSPHATASE       88         U/L           44  - 147             ",
        "  CALCIUM                     9.6       mg/dL          8.6-  10.2           ",
        "                                                                            ",
        "  ----------------------------------------------------------------          ",
        "  Performed at St. David's Central Lab. CLIA #45D0987654.                   ",
        "  Director: Maria Garcia, MD, FCAP.                                         ",
        "  *L/H flags compare against age- and sex-adjusted reference ranges.        ",
        "============================================================================",
        "  END OF REPORT  -- PAGE 1 OF 1                                              ",
        "============================================================================",
    ]
    y = H - 36
    for line in lines:
        c.drawString(36, y, line)
        y -= 12
    c.showPage()
    c.save()


# ---------------------------------------------------------------------------
# Intake #1 — modern clinic intake (multi-page, boxed sections)
# ---------------------------------------------------------------------------

def gen_intake_modern_clinic(out: Path) -> None:
    """Multi-page intake with bordered sections. Tests multi-page extraction
    and the demographic + chief-concern + meds + allergies + family-hx layout.
    """
    p = PATIENT_PROFILE
    styles = getSampleStyleSheet()
    title_style = ParagraphStyle(
        "TitleBig", parent=styles["Title"], fontSize=18, spaceAfter=4,
        textColor=colors.HexColor("#1f3a68"),
    )
    h2 = ParagraphStyle(
        "H2", parent=styles["Heading2"], fontSize=11, spaceBefore=10, spaceAfter=4,
        textColor=colors.HexColor("#1f3a68"),
    )
    body = ParagraphStyle("Body", parent=styles["BodyText"], fontSize=10, leading=13)
    note = ParagraphStyle("Note", parent=body, fontSize=8, textColor=colors.grey)

    doc = BaseDocTemplate(str(out), pagesize=letter,
                          leftMargin=40, rightMargin=40,
                          topMargin=40, bottomMargin=40)
    frame = Frame(doc.leftMargin, doc.bottomMargin,
                  doc.width, doc.height, id="main")
    doc.addPageTemplates([PageTemplate(id="all", frames=[frame])])

    story = []
    story.append(Paragraph(p["primary_clinic"], title_style))
    story.append(Paragraph("Established Patient Intake — 6-Month Follow-Up", note))
    story.append(Spacer(1, 6))

    # Demographics block
    story.append(Paragraph("Patient Demographics", h2))
    demo_data = [
        ["First Name",  p["first"],         "Last Name",   p["last"]],
        ["Date of Birth", p["dob"],         "Sex",         p["sex_full"]],
        ["Phone",       p["phone"],         "Email",       p["email"]],
        ["Address",     f"{p['street']}, {p['city']} {p['state']} {p['zip']}", "", ""],
        ["Preferred Pharmacy", "Walgreens #6712, 980 Front St, San Luis CA 92101", "", ""],
        ["Emergency Contact",  "Daniel Cohen — spouse — (213) 555-7090",          "", ""],
    ]
    t = Table(demo_data, colWidths=[1.3 * inch, 2.0 * inch, 1.0 * inch, 2.0 * inch])
    t.setStyle(TableStyle([
        ("BOX",        (0, 0), (-1, -1), 0.6, colors.HexColor("#1f3a68")),
        ("INNERGRID",  (0, 0), (-1, -1), 0.25, colors.lightgrey),
        ("FONTNAME",   (0, 0), (0, -1), "Helvetica-Bold"),
        ("FONTNAME",   (2, 0), (2, -1), "Helvetica-Bold"),
        ("FONTSIZE",   (0, 0), (-1, -1), 9),
        ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#eef1f7")),
        ("BACKGROUND", (2, 0), (2, -1), colors.HexColor("#eef1f7")),
        ("VALIGN",     (0, 0), (-1, -1), "MIDDLE"),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING",  (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    story.append(t)
    story.append(Spacer(1, 8))

    # Chief concern
    story.append(Paragraph("Reason for Today's Visit", h2))
    cc_data = [[
        "Routine 6-month follow-up for hypothyroidism management on the increased "
        "Levothyroxine dose. Also wants to discuss recent mild back pain after a small "
        "fall last month and timing of next bone-density scan, plus a brief "
        "mood/medication check given Sertraline has been at 100 mg since early 2024."
    ]]
    t = Table(cc_data, colWidths=[6.3 * inch])
    t.setStyle(TableStyle([
        ("BOX", (0, 0), (-1, -1), 0.6, colors.HexColor("#1f3a68")),
        ("FONTSIZE", (0, 0), (-1, -1), 10),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING",  (0, 0), (-1, -1), 8),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 8),
    ]))
    story.append(t)
    story.append(Spacer(1, 8))

    # Current meds
    story.append(Paragraph("Current Medications", h2))
    med_rows = [
        ["Medication", "Dose", "Frequency", "Prescriber"],
        ["Levothyroxine",                       "112 mcg",      "Once daily, empty stomach", "Dr. Park"],
        ["Alendronate (Fosamax)",               "70 mg",        "Once weekly, with water",   "Dr. Park"],
        ["Sertraline (Zoloft)",                 "100 mg",       "Once daily, AM",            "Dr. Jensen"],
        ["Calcium Carbonate + Vitamin D3",      "1200 mg/2000 IU", "Once daily with food",   "self / OTC"],
    ]
    t = Table(med_rows, colWidths=[2.0 * inch, 1.4 * inch, 1.6 * inch, 1.3 * inch])
    t.setStyle(TableStyle([
        ("BOX",        (0, 0), (-1, -1), 0.6, colors.HexColor("#1f3a68")),
        ("INNERGRID",  (0, 0), (-1, -1), 0.25, colors.lightgrey),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1f3a68")),
        ("TEXTCOLOR",  (0, 0), (-1, 0), colors.white),
        ("FONTNAME",   (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE",   (0, 0), (-1, -1), 9),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING",  (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    story.append(t)
    story.append(Spacer(1, 8))

    # Allergies
    story.append(Paragraph("Allergies", h2))
    al_rows = [
        ["Substance", "Reaction"],
        ["Codeine",    "Severe nausea/vomiting"],
        ["Shellfish",  "Anaphylaxis — last reaction 2009 (ER, EpiPen)"],
    ]
    t = Table(al_rows, colWidths=[2.0 * inch, 4.3 * inch])
    t.setStyle(TableStyle([
        ("BOX",        (0, 0), (-1, -1), 0.6, colors.HexColor("#1f3a68")),
        ("INNERGRID",  (0, 0), (-1, -1), 0.25, colors.lightgrey),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1f3a68")),
        ("TEXTCOLOR",  (0, 0), (-1, 0), colors.white),
        ("FONTNAME",   (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE",   (0, 0), (-1, -1), 9),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING",  (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    story.append(t)
    story.append(Spacer(1, 8))

    # Family history (page break likely)
    story.append(Paragraph("Family History", h2))
    fh_rows = [
        ["Relation", "Condition", "Age at diagnosis"],
        ["Mother",  "Osteoporosis, hip fracture",   "72 (deceased 78)"],
        ["Mother",  "Hashimoto's thyroiditis",      "60"],
        ["Father",  "Coronary artery disease",      "65"],
        ["Sister",  "Breast cancer (DCIS)",         "61 (in remission)"],
        ["Sister",  "Major depression",             "30"],
    ]
    t = Table(fh_rows, colWidths=[1.4 * inch, 3.0 * inch, 1.9 * inch])
    t.setStyle(TableStyle([
        ("BOX",        (0, 0), (-1, -1), 0.6, colors.HexColor("#1f3a68")),
        ("INNERGRID",  (0, 0), (-1, -1), 0.25, colors.lightgrey),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1f3a68")),
        ("TEXTCOLOR",  (0, 0), (-1, 0), colors.white),
        ("FONTNAME",   (0, 0), (-1, 0), "Helvetica-Bold"),
        ("FONTSIZE",   (0, 0), (-1, -1), 9),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING",  (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    story.append(t)
    story.append(Spacer(1, 14))

    # Signature
    story.append(Paragraph(
        "I certify the above information is accurate to the best of my knowledge.",
        body,
    ))
    story.append(Spacer(1, 16))
    sig = Table([
        ["Signature: ___________________________", "Date: 04/27/2026"],
    ], colWidths=[3.8 * inch, 2.5 * inch])
    sig.setStyle(TableStyle([("FONTSIZE", (0, 0), (-1, -1), 10)]))
    story.append(sig)

    doc.build(story)


# ---------------------------------------------------------------------------
# Intake #2 — endocrinology specialty pre-visit
#
# (Filename retains the historical "specialty_cardiology" name for any
# tooling that addresses it by path — the content is endocrinology, which
# matches Nora's chart. When the file is regenerated this docstring is
# the source of truth, not the filename.)
# ---------------------------------------------------------------------------

def gen_intake_specialty_cardiology(out: Path) -> None:
    """Specialty-clinic style intake — endocrinology pre-visit.

    Cardiac-specific format from the prior fixture replaced with an
    endocrine symptom checklist and bone-health questionnaire. Different
    organization than primary care; demographics block compressed at top.
    """
    p = PATIENT_PROFILE
    c = canvas.Canvas(str(out), pagesize=letter)
    W, H = letter

    # Title bar
    c.setFillColorRGB(0.10, 0.20, 0.40)
    c.rect(0, H - 50, W, 50, fill=1, stroke=0)
    c.setFillColor(colors.white)
    c.setFont("Helvetica-Bold", 16)
    c.drawString(40, H - 30, f"{p['endocrinology_clinic']} — Pre-Visit Questionnaire")
    c.setFont("Helvetica", 9)
    c.drawString(40, H - 44, "Please complete prior to your endocrinology appointment")
    c.setFillColor(colors.black)

    # Compact demographics strip
    y = H - 76
    c.setFont("Helvetica-Bold", 9)
    c.drawString(40, y, "Name:")
    c.setFont("Helvetica", 10)
    c.drawString(80, y, f"{p['first']} {p['last']}")
    c.setFont("Helvetica-Bold", 9)
    c.drawString(220, y, "DOB:")
    c.setFont("Helvetica", 10)
    c.drawString(255, y, p["dob"])
    c.setFont("Helvetica-Bold", 9)
    c.drawString(340, y, "Sex:")
    c.setFont("Helvetica", 10)
    c.drawString(370, y, p["sex_letter"])
    c.setFont("Helvetica-Bold", 9)
    c.drawString(420, y, "Phone:")
    c.setFont("Helvetica", 10)
    c.drawString(465, y, p["phone"])
    c.line(40, y - 4, W - 40, y - 4)

    # Reason for referral
    y -= 20
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "1.  Reason for endocrinology referral")
    c.setFont("Helvetica", 10)
    y -= 14
    c.drawString(50, y, "Long-standing primary hypothyroidism — recent labs after Levothyroxine dose increase to 112 mcg.")
    y -= 12
    c.drawString(50, y, "Also overdue for bone-density follow-up. Last DEXA Aug-2024 showed lumbar T-score -2.7.")

    # Endocrine symptom checklist
    y -= 22
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "2.  Symptoms in the past 8 weeks (check all that apply)")
    y -= 16
    c.setFont("Helvetica", 10)
    items = [
        ("[X]",  "Fatigue / low energy"),
        ("[ ]",  "Heat intolerance / sweating"),
        ("[X]",  "Cold intolerance"),
        ("[ ]",  "Hair thinning"),
        ("[X]",  "Brain fog / poor concentration"),
        ("[ ]",  "Palpitations"),
        ("[X]",  "Mid-back pain (since fall 03/2026)"),
        ("[ ]",  "Unintended weight loss"),
        ("[ ]",  "Tremor"),
        ("[X]",  "Constipation"),
    ]
    col_x = [50, 320]
    for i, (chk, label) in enumerate(items):
        cx = col_x[i % 2]
        c.drawString(cx, y, f"{chk}  {label}")
        if i % 2 == 1:
            y -= 14

    # Active medications (free-form, comma-separated)
    y -= 20
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "3.  Current medications")
    y -= 14
    c.setFont("Helvetica", 10)
    c.drawString(50, y, "Levothyroxine 112 mcg daily (empty stomach), Alendronate 70 mg once weekly,")
    y -= 12
    c.drawString(50, y, "Sertraline 100 mg daily AM, Calcium Carbonate 1200 mg + Vitamin D3 2000 IU daily.")

    # Allergies
    y -= 22
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "4.  Drug / substance allergies")
    y -= 14
    c.setFont("Helvetica", 10)
    c.drawString(50, y, "Codeine (severe nausea/vomiting).  Shellfish (anaphylaxis 2009, EpiPen used).")

    # Family history (endocrinology + bone focused)
    y -= 22
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "5.  Family history of endocrine / bone disease")
    y -= 16
    c.setFont("Helvetica-Bold", 9)
    c.drawString(50, y, "Relation")
    c.drawString(180, y, "Condition")
    c.drawString(420, y, "Age at event")
    c.line(40, y - 3, W - 40, y - 3)
    c.setFont("Helvetica", 10)
    fh = [
        ("Mother",  "Osteoporosis with hip fracture",     "72 (death 78)"),
        ("Mother",  "Hashimoto's thyroiditis",            "60"),
        ("Sister",  "Hyperthyroidism (Graves')",          "55"),
        ("Maternal aunt", "Vertebral fractures (post-menopausal)", "70"),
    ]
    y -= 16
    for rel, cond, age in fh:
        c.drawString(50, y, rel)
        c.drawString(180, y, cond)
        c.drawString(420, y, age)
        y -= 14

    # Lifestyle
    y -= 12
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "6.  Lifestyle")
    y -= 14
    c.setFont("Helvetica", 10)
    c.drawString(50, y, "Tobacco: never.   Alcohol: 2-3 glasses of wine/wk.   Exercise: walks 25 min, 4-5 days/wk.")

    # Signature
    y -= 30
    c.line(40, y, 280, y)
    c.line(330, y, 540, y)
    y -= 12
    c.setFont("Helvetica", 9)
    c.drawString(40, y, "Patient signature")
    c.drawString(330, y, "Date")
    c.drawString(330, y - 14, "04/27/2026")

    c.showPage()
    c.save()


# ---------------------------------------------------------------------------
# Medication list #1 — retail pharmacy printout
# ---------------------------------------------------------------------------

def gen_medication_list_pharmacy(out: Path) -> None:
    """Walgreens-style printout: header with pharmacy info, simple
    rectangular table, refill history mini-section."""
    p = PATIENT_PROFILE
    c = canvas.Canvas(str(out), pagesize=letter)
    W, H = letter

    c.setFont("Helvetica-Bold", 18)
    c.drawString(40, H - 40, "Walgreens — Patient Medication Profile")
    c.setFont("Helvetica", 10)
    c.drawString(40, H - 56, "Store #6712  ·  980 Front St, San Luis CA 92101  ·  (213) 555-2200")
    c.line(40, H - 64, W - 40, H - 64)

    y = H - 84
    c.setFont("Helvetica-Bold", 10)
    c.drawString(40, y, f"Patient: {p['first']} {p['last']}")
    c.drawString(220, y, f"DOB: {p['dob']}")
    c.drawString(330, y, f"Sex: {p['sex_letter']}")
    c.drawString(390, y, "Profile printed: 04/29/2026")

    y -= 24
    c.setFont("Helvetica-Bold", 9)
    c.drawString(40,  y, "Medication")
    c.drawString(190, y, "Strength")
    c.drawString(260, y, "Sig (Frequency)")
    c.drawString(400, y, "Route")
    c.drawString(450, y, "Prescriber")
    c.drawString(540, y, "Last fill")
    c.line(40, y - 3, W - 30, y - 3)

    rows = [
        ("Levothyroxine Sodium",       "112 mcg",          "Take 1 tablet by mouth once daily, empty stomach 30 min before breakfast",  "PO",  "Park, D.",      "04/15/26"),
        ("Alendronate Sodium",         "70 mg",            "Take 1 tablet by mouth once weekly with full glass of water",                "PO",  "Park, D.",      "04/02/26"),
        ("Sertraline HCl",             "100 mg",           "Take 1 tablet by mouth once daily in the morning",                           "PO",  "Jensen, M.",    "04/15/26"),
        ("Calcium Carbonate / Vit D3", "1200 mg / 2000 IU","Take 1 tablet by mouth once daily with food",                                "PO",  "OTC",           "—"),
        ("Lorazepam",                  "0.5 mg",           "Take 1 tablet under tongue PRN severe anxiety, max 1 per 24h (last fill 2024)","SL", "Jensen, M.",    "08/12/24"),
    ]
    y -= 14
    c.setFont("Helvetica", 8)
    for name, strength, sig, route, presc, fill in rows:
        c.drawString(40,  y, name)
        c.drawString(190, y, strength)
        c.drawString(260, y, sig[:75])
        c.drawString(400, y, route)
        c.drawString(450, y, presc)
        c.drawString(540, y, fill)
        y -= 14

    y -= 12
    c.setFont("Helvetica-Oblique", 8)
    c.drawString(40, y, "Refills remaining shown on label. Profile reflects medications dispensed at this Walgreens location only;")
    y -= 11
    c.drawString(40, y, "the patient may have additional medications filled elsewhere. Verified with patient at counseling 04/15/2026.")

    c.showPage()
    c.save()


# ---------------------------------------------------------------------------
# Medication list #2 — hospital discharge medication reconciliation
# ---------------------------------------------------------------------------

def gen_medication_list_discharge(out: Path) -> None:
    """Hospital-style discharge med rec: NEW vs CONTINUED vs CHANGED vs STOPPED.

    Scenario: Nora was admitted 36h after a fall with imaging-confirmed L2
    vertebral compression fracture. Discharge med rec switches her oral
    bisphosphonate (Alendronate) for an annual IV bisphosphonate
    (Zoledronic Acid), adds short-course pain control, holds anything that
    would impair bone healing, and continues the rest of her home regimen.
    """
    p = PATIENT_PROFILE
    styles = getSampleStyleSheet()
    h1 = ParagraphStyle("H1", parent=styles["Title"], fontSize=15, spaceAfter=2,
                        textColor=colors.HexColor("#7a1f1f"))
    h2 = ParagraphStyle("H2", parent=styles["Heading2"], fontSize=11, spaceBefore=10,
                        spaceAfter=4, textColor=colors.HexColor("#7a1f1f"))
    body = ParagraphStyle("Body", parent=styles["BodyText"], fontSize=9.5, leading=12)

    doc = BaseDocTemplate(str(out), pagesize=letter,
                          leftMargin=40, rightMargin=40,
                          topMargin=40, bottomMargin=40)
    frame = Frame(doc.leftMargin, doc.bottomMargin,
                  doc.width, doc.height, id="main")
    doc.addPageTemplates([PageTemplate(id="all", frames=[frame])])

    story = []
    story.append(Paragraph("Pacific General Hospital — Discharge Medication Reconciliation", h1))
    story.append(Paragraph(
        f"Patient: <b>{p['first']} {p['last']}</b>  ·  MRN 0000123488  ·  DOB {p['dob']}  ·  "
        f"Discharged 04/22/2026 1530 from 4N (Ortho-Med)  ·  Discharging hospitalist: {p['discharging_hospitalist']}",
        body,
    ))
    story.append(Spacer(1, 8))

    def med_block(category: str, color: str, rows: list[list[str]]) -> None:
        story.append(Paragraph(category, h2))
        header = ["Medication", "Dose", "Route", "Frequency", "Indication", "Prescriber"]
        t = Table([header] + rows,
                  colWidths=[1.6 * inch, 0.75 * inch, 0.55 * inch,
                             1.2 * inch, 1.4 * inch, 0.85 * inch])
        t.setStyle(TableStyle([
            ("BOX",        (0, 0), (-1, -1), 0.6, colors.HexColor(color)),
            ("INNERGRID",  (0, 0), (-1, -1), 0.25, colors.lightgrey),
            ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor(color)),
            ("TEXTCOLOR",  (0, 0), (-1, 0), colors.white),
            ("FONTNAME",   (0, 0), (-1, 0), "Helvetica-Bold"),
            ("FONTSIZE",   (0, 0), (-1, -1), 8.5),
            ("LEFTPADDING", (0, 0), (-1, -1), 4),
            ("TOPPADDING",  (0, 0), (-1, -1), 3),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ]))
        story.append(t)

    med_block("CONTINUED — no changes from home regimen", "#365f9c", [
        ["Levothyroxine",                  "112 mcg",   "PO",  "Daily, empty stomach", "Hypothyroidism",      "Park, D."],
        ["Sertraline",                     "100 mg",    "PO",  "Daily AM",             "Recurrent MDD",       "Jensen, M."],
        ["Calcium Carbonate + Vitamin D3", "1200 mg/2000 IU", "PO", "Daily with food", "Bone health",         "OTC"],
    ])

    med_block("CHANGED — switched agent", "#a37020", [
        ["Zoledronic Acid (Reclast)", "5 mg",  "IV",  "Once yearly (next due 04/2027)", "Osteoporosis (replacing oral Alendronate after vertebral fx)", "Park, D."],
    ])

    med_block("NEW — started during this admission", "#2d7a4f", [
        ["Acetaminophen",     "650 mg",  "PO",  "Q6H scheduled x 14 days, then PRN", "Vertebral fx pain",      "Reyes, J."],
        ["Lidocaine 5% Patch","1 patch", "TOP", "Apply to mid-back x 12h on / 12h off", "Localized fx pain",   "Reyes, J."],
        ["Polyethylene Glycol","17 g",   "PO",  "Daily PRN constipation",            "Bowel reg with rest",     "Reyes, J."],
    ])

    med_block("STOPPED — do not continue at home", "#7a1f1f", [
        ["Alendronate (Fosamax)", "70 mg", "PO",  "Was once-weekly", "Switched to IV zoledronic (above)", "Park, D."],
    ])

    story.append(Spacer(1, 10))
    story.append(Paragraph(
        "Patient verbalized understanding of all medication changes and was provided a written copy. "
        "Hold ibuprofen and other NSAIDs for at least 6 weeks while the L2 compression fracture is "
        "healing. Follow-up with primary care (Dr. Jensen) within 7 days; endocrinology (Dr. Park) "
        "within 30 days for repeat DEXA planning and Reclast counseling.",
        body,
    ))
    story.append(Spacer(1, 8))
    story.append(Paragraph(
        f"Reconciled by: Marisol Ortega, RN  ·  Reviewed by: {p['discharging_hospitalist']}  ·  04/22/2026 1452",
        ParagraphStyle("Sig", parent=body, fontSize=8, textColor=colors.grey),
    ))

    doc.build(story)


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

GENERATORS = {
    "lab_quest_style.pdf":              gen_lab_quest_style,
    "lab_labcorp_multipanel.pdf":       gen_lab_labcorp_multipanel,
    "lab_hospital_telex.pdf":           gen_lab_hospital_telex,
    "intake_modern_clinic.pdf":         gen_intake_modern_clinic,
    "intake_specialty_cardiology.pdf":  gen_intake_specialty_cardiology,
    "medication_list_pharmacy.pdf":     gen_medication_list_pharmacy,
    "medication_list_discharge.pdf":    gen_medication_list_discharge,
}


def main() -> None:
    SAMPLES_DIR.mkdir(parents=True, exist_ok=True)
    for name, fn in GENERATORS.items():
        out = SAMPLES_DIR / name
        fn(out)
        size_kb = out.stat().st_size / 1024
        print(f"  ✓ {name:38s}  {size_kb:6.1f} KB")


if __name__ == "__main__":
    main()
