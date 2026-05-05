"""Generate the AgentForge sample-PDF fixture set.

Each function emits one PDF that follows the layout/typography conventions
of a real-world clinical document type. The variety is intentional — the
ingestion pipeline should hit each of these and produce schema-valid JSON
without any layout-specific code paths.

Patient values are pinned to the seeded Ted Shaw chart so cross-checks
between extracted facts and the chart can be exercised by the eval suite.

Usage:
    python -m copilot.agent.ingest.test_fixtures.generate_samples

Outputs (under copilot/agent/ingest/test_fixtures/samples/):
    lab_quest_style.pdf            single-panel CMP, Quest-Diagnostics-shaped
    lab_labcorp_multipanel.pdf     CMP + Lipid + A1c + TSH, LabCorp two-column
    lab_hospital_telex.pdf         monospace ASCII-style hospital printout
    intake_modern_clinic.pdf       multi-page clean intake with boxed sections
    intake_specialty_cardiology.pdf  cardiology pre-visit, different organization
    medication_list_pharmacy.pdf   retail pharmacy med list
    medication_list_discharge.pdf  hospital discharge medication reconciliation
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
# Lab #1 — Quest Diagnostics-style single-panel CMP
# ---------------------------------------------------------------------------

def gen_lab_quest_style(out: Path) -> None:
    """Single-page CMP report, light blue accents, Helvetica.

    Mirrors the Quest Diagnostics layout: lab logo block top-left, patient
    block top-right, single tabular section with H/L flag column, and a
    methodology footer.
    """
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
    c.drawRightString(W - 40, H - 28, "Accession #  G2604280171")
    c.drawRightString(W - 40, H - 40, "Report Date  04/30/2026  09:14")
    c.drawRightString(W - 40, H - 52, "Page 1 of 1")

    # Patient + ordering provider blocks
    y = H - 90
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "PATIENT")
    c.drawString(310, y, "ORDERING PROVIDER")
    c.setFont("Helvetica", 10)
    c.drawString(40, y - 14, "SHAW, TED W")
    c.drawString(40, y - 26, "DOB: 03/11/1947     Sex: M     Age: 79")
    c.drawString(40, y - 38, "MRN: AGF-000001")
    c.drawString(40, y - 50, "Collected: 04/28/2026  08:32")
    c.drawString(310, y - 14, "Maria Jensen, MD")
    c.drawString(310, y - 26, "Internal Medicine Associates")
    c.drawString(310, y - 38, "1450 Lavaca St, Austin TX 78701")
    c.drawString(310, y - 50, "NPI 1234567890")

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
        ("Glucose, Fasting",       "138",  "H",  "mg/dL",        "70-99"),
        ("BUN",                    "24",   "H",  "mg/dL",        "7-20"),
        ("Creatinine",             "1.6",  "H",  "mg/dL",        "0.7-1.3"),
        ("eGFR (CKD-EPI 2021)",    "48",   "L",  "mL/min/1.73",  ">=60"),
        ("BUN/Creatinine Ratio",   "15.0", "",   "",             "10.0-20.0"),
        ("Sodium",                 "139",  "",   "mmol/L",       "135-145"),
        ("Potassium",              "4.7",  "",   "mmol/L",       "3.5-5.1"),
        ("Chloride",               "104",  "",   "mmol/L",       "98-107"),
        ("Carbon Dioxide",         "26",   "",   "mmol/L",       "21-32"),
        ("Calcium",                "9.4",  "",   "mg/dL",        "8.6-10.2"),
        ("Total Protein",          "7.2",  "",   "g/dL",         "6.4-8.3"),
        ("Albumin",                "4.1",  "",   "g/dL",         "3.5-5.0"),
        ("Bilirubin, Total",       "0.6",  "",   "mg/dL",        "0.0-1.2"),
        ("Alkaline Phosphatase",   "87",   "",   "U/L",          "44-147"),
        ("AST",                    "32",   "",   "U/L",          "10-40"),
        ("ALT",                    "29",   "",   "U/L",          "7-56"),
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
    c.drawString(40, y, "Performed at Quest Diagnostics, Lewisville TX 75067   ·   CLIA #45D0703518   ·   Director: Lawrence Patel, MD")
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
    """Two-column dense report, four grouped panels (CMP, Lipid, A1c, TSH).

    Different visual rhythm than the Quest one: panels stacked, each with
    its own mini-header + bordered table, no flag column (uses bold red
    on the value itself).
    """
    c = canvas.Canvas(str(out), pagesize=letter)
    W, H = letter

    # LabCorp-ish header
    c.setFillColor(colors.black)
    c.setFont("Helvetica-Bold", 24)
    c.drawString(40, H - 40, "Labcorp")
    c.setFont("Helvetica", 8)
    c.drawString(40, H - 52, "Burlington Patient Service Center  ·  PO Box 2270, Burlington NC 27216")
    c.setFont("Helvetica", 9)
    c.drawRightString(W - 40, H - 28, "Specimen ID: 026-543-9921-0")
    c.drawRightString(W - 40, H - 40, "Control:  AGF-TS-2026-04-28")
    c.drawRightString(W - 40, H - 52, "Account: 12345678")
    c.line(40, H - 60, W - 40, H - 60)

    y = H - 80
    c.setFont("Helvetica-Bold", 9)
    c.drawString(40, y, "Patient")
    c.drawString(220, y, "Specimen")
    c.drawString(420, y, "Physician")
    c.setFont("Helvetica", 9)
    c.drawString(40, y - 12, "SHAW, TED W")
    c.drawString(40, y - 24, "DOB 03/11/1947  M  Age 79")
    c.drawString(40, y - 36, "Patient ID: AGF-000001")
    c.drawString(220, y - 12, "Collected: 04/28/2026  08:32")
    c.drawString(220, y - 24, "Received:  04/28/2026  17:08")
    c.drawString(220, y - 36, "Reported:  04/30/2026  09:14")
    c.drawString(420, y - 12, "Maria Jensen, MD")
    c.drawString(420, y - 24, "1450 Lavaca St")
    c.drawString(420, y - 36, "Austin TX 78701")
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
        ("Glucose, Fasting",  "138",  "mg/dL",        "70-99",     True),
        ("BUN",               "24",   "mg/dL",        "7-20",      True),
        ("Creatinine",        "1.6",  "mg/dL",        "0.7-1.3",   True),
        ("eGFR",              "48",   "mL/min/1.73",  ">=60",      True),
        ("Sodium",            "139",  "mmol/L",       "135-145",   False),
        ("Potassium",         "4.7",  "mmol/L",       "3.5-5.1",   False),
        ("Chloride",          "104",  "mmol/L",       "98-107",    False),
        ("CO2",               "26",   "mmol/L",       "21-32",     False),
    ]
    next_y = draw_panel("CMP — Comprehensive Metabolic Panel", cmp_rows, 40, y)

    lipid_rows = [
        ("Cholesterol, Total", "212",  "mg/dL", "<200",   True),
        ("Triglycerides",      "172",  "mg/dL", "<150",   True),
        ("HDL Cholesterol",    "42",   "mg/dL", ">=40",   False),
        ("LDL, calculated",    "136",  "mg/dL", "<100",   True),
        ("VLDL, calculated",   "34",   "mg/dL", "5-40",   False),
        ("Chol/HDL Ratio",     "5.0",  "",      "<5.0",   False),
    ]
    draw_panel("LIPID PANEL", lipid_rows, 40, next_y - 16)

    # Right column — A1c + TSH
    a1c_rows = [
        ("Hemoglobin A1c",     "8.2",  "%",        "<5.7",      True),
        ("Estimated avg glucose", "189", "mg/dL", "<117",       True),
    ]
    right_y = draw_panel("DIABETES — HbA1c", a1c_rows, 310, y)

    tsh_rows = [
        ("TSH, 3rd generation", "2.14", "uIU/mL", "0.45-4.50", False),
    ]
    right_y = draw_panel("THYROID FUNCTION", tsh_rows, 310, right_y - 16)

    # Methodology footer
    c.setFillColor(colors.black)
    c.setFont("Helvetica-Oblique", 7)
    c.drawString(40, 60, "Performed by Labcorp, Burlington NC 27216  ·  CLIA #34D0654001  ·  Lab Director: J. Patel, MD")
    c.drawString(40, 50, "A1c by HPLC. Lipid by Beckman Coulter AU680. eGFR via 2021 CKD-EPI (race-free). Critical values called per laboratory policy.")
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
    """
    c = canvas.Canvas(str(out), pagesize=letter)
    W, H = letter
    c.setFont("Courier", 10)

    lines = [
        "============================================================================",
        "  ST. DAVID'S MEDICAL CENTER -- CENTRAL LABORATORY                          ",
        "  919 East 32nd Street, Austin TX 78705        Tel: (512) 476-7111          ",
        "============================================================================",
        "",
        "  PATIENT.....: SHAW, TED W                  ACCT NO....: ASD2604280171     ",
        "  MRN........: 0000123456                    DOB........: 11-MAR-1947       ",
        "  AGE/SEX....: 79  M                         ROOM.......: OUTPATIENT        ",
        "  ATTENDING..: JENSEN, MARIA       MD        ORDERED....: 28-APR-2026 08:32 ",
        "  COLLECTED..: 28-APR-2026  08:32            REPORTED...: 30-APR-2026 09:14 ",
        "============================================================================",
        "                                                                            ",
        "  HEMATOLOGY -- COMPLETE BLOOD COUNT W/AUTO DIFF                            ",
        "  ----------------------------------------------------------------          ",
        "  TEST                       RESULT     UNITS         REFERENCE             ",
        "                                                                            ",
        "  WBC                         6.8       k/uL          4.0  - 11.0           ",
        "  RBC                         4.21  L   M/uL          4.7  -  6.1           ",
        "  HEMOGLOBIN                 12.7   L   g/dL          13.5 - 17.5           ",
        "  HEMATOCRIT                 38.4   L   %             41.0 - 53.0           ",
        "  MCV                        91.2       fL            80.0 -100.0           ",
        "  MCH                        30.2       pg            27.0 - 33.0           ",
        "  MCHC                       33.1       g/dL          32.0 - 36.0           ",
        "  RDW                        14.1       %             11.5 - 14.5           ",
        "  PLATELETS                  238        k/uL         150   -450             ",
        "                                                                            ",
        "  HEMATOLOGY -- PROTHROMBIN TIME / INR                                      ",
        "  ----------------------------------------------------------------          ",
        "  PT                         11.4       sec           9.4  - 12.5           ",
        "  INR                         1.0                     0.8  -  1.2           ",
        "                                                                            ",
        "  CHEMISTRY -- BASIC METABOLIC PANEL                                        ",
        "  ----------------------------------------------------------------          ",
        "  GLUCOSE, FASTING          138    H   mg/dL         70   - 99              ",
        "  CREATININE                  1.6  H   mg/dL          0.7 -  1.3            ",
        "  EGFR (CKD-EPI 2021)        48    L   mL/min/1.73   >= 60                  ",
        "  BUN                        24    H   mg/dL          7   - 20              ",
        "  SODIUM                    139        mmol/L        135  -145              ",
        "  POTASSIUM                   4.7      mmol/L          3.5-  5.1            ",
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
    story.append(Paragraph("Lavaca Family Medicine", title_style))
    story.append(Paragraph("New Patient Intake Form  ·  Please complete in full", note))
    story.append(Spacer(1, 6))

    # Demographics block
    story.append(Paragraph("Patient Demographics", h2))
    demo_data = [
        ["First Name",  "Ted",                "Last Name",   "Shaw"],
        ["Date of Birth", "03/11/1947",       "Sex",         "Male"],
        ["Phone",       "(512) 555-0118",     "Email",       "ted.shaw@example.com"],
        ["Preferred Pharmacy", "CVS #4421, 1100 Lavaca St, Austin TX 78701", "", ""],
        ["Emergency Contact",  "Linda Shaw — spouse — (512) 555-0119",       "", ""],
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
        "Annual diabetes follow-up. Concerned about morning fasting glucose creeping back up over "
        "the past two months despite metformin, also some new ankle swelling in the evenings."
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
        ["Metformin",          "1000 mg",  "Twice daily",  "Dr. Jensen"],
        ["Lisinopril",         "20 mg",    "Once daily",   "Dr. Jensen"],
        ["Atorvastatin",       "20 mg",    "Once nightly", "Dr. Jensen"],
        ["Empagliflozin",      "10 mg",    "Once daily",   "Dr. Jensen"],
        ["Aspirin (low dose)", "81 mg",    "Once daily",   "self / OTC"],
    ]
    t = Table(med_rows, colWidths=[2.0 * inch, 1.0 * inch, 1.6 * inch, 1.7 * inch])
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
        ["Penicillin", "Rash, hives — last reaction 1972"],
        ["Sulfa drugs", "Stevens-Johnson per ER record (2014)"],
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
        ["Father",  "Type 2 diabetes",       "55"],
        ["Father",  "Myocardial infarction", "68 (deceased 71)"],
        ["Mother",  "Hypertension",          "60"],
        ["Brother", "Colorectal cancer",     "62"],
        ["Sister",  "Breast cancer",         "57 (in remission)"],
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
        ["Signature: ___________________________", "Date: 04/28/2026"],
    ], colWidths=[3.8 * inch, 2.5 * inch])
    sig.setStyle(TableStyle([("FONTSIZE", (0, 0), (-1, -1), 10)]))
    story.append(sig)

    doc.build(story)


# ---------------------------------------------------------------------------
# Intake #2 — cardiology specialty pre-visit
# ---------------------------------------------------------------------------

def gen_intake_specialty_cardiology(out: Path) -> None:
    """Specialty-clinic style intake — different organization than primary care.
    Cardiac history is the centerpiece; demographics block compressed at top."""
    c = canvas.Canvas(str(out), pagesize=letter)
    W, H = letter

    # Title bar
    c.setFillColorRGB(0.10, 0.20, 0.40)
    c.rect(0, H - 50, W, 50, fill=1, stroke=0)
    c.setFillColor(colors.white)
    c.setFont("Helvetica-Bold", 16)
    c.drawString(40, H - 30, "Texas Heart Specialists — Pre-Visit Questionnaire")
    c.setFont("Helvetica", 9)
    c.drawString(40, H - 44, "Please complete prior to your cardiology appointment")
    c.setFillColor(colors.black)

    # Compact demographics strip
    y = H - 76
    c.setFont("Helvetica-Bold", 9)
    c.drawString(40, y, "Name:")
    c.setFont("Helvetica", 10)
    c.drawString(80, y, "Ted Shaw")
    c.setFont("Helvetica-Bold", 9)
    c.drawString(220, y, "DOB:")
    c.setFont("Helvetica", 10)
    c.drawString(255, y, "03/11/1947")
    c.setFont("Helvetica-Bold", 9)
    c.drawString(340, y, "Sex:")
    c.setFont("Helvetica", 10)
    c.drawString(370, y, "M")
    c.setFont("Helvetica-Bold", 9)
    c.drawString(420, y, "Phone:")
    c.setFont("Helvetica", 10)
    c.drawString(465, y, "(512) 555-0118")
    c.line(40, y - 4, W - 40, y - 4)

    # Reason for referral
    y -= 20
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "1.  Reason for cardiology referral")
    c.setFont("Helvetica", 10)
    y -= 14
    c.drawString(50, y, "Newly elevated BP (avg 144/90 home cuff x 4 weeks) on top of long-standing T2DM and CKD3a.")
    y -= 12
    c.drawString(50, y, "Primary requesting risk-stratification + statin intensity guidance.")

    # Cardiac symptom checklist
    y -= 22
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "2.  Cardiac symptoms (check all that apply)")
    y -= 16
    c.setFont("Helvetica", 10)
    items = [
        ("[X]",  "Chest discomfort with exertion"),
        ("[ ]",  "Chest pain at rest"),
        ("[X]",  "Shortness of breath when climbing stairs"),
        ("[ ]",  "Palpitations / fluttering"),
        ("[X]",  "Lower-extremity swelling"),
        ("[ ]",  "Lightheadedness / pre-syncope"),
        ("[ ]",  "Syncope"),
        ("[ ]",  "Orthopnea (shortness of breath lying flat)"),
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
    c.drawString(50, y, "Metformin 1000 mg twice daily, Lisinopril 20 mg daily, Atorvastatin 20 mg nightly,")
    y -= 12
    c.drawString(50, y, "Empagliflozin 10 mg daily, low-dose ASA 81 mg daily.")

    # Allergies
    y -= 22
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "4.  Drug allergies")
    y -= 14
    c.setFont("Helvetica", 10)
    c.drawString(50, y, "Penicillin (rash, hives 1972).  Sulfa drugs (Stevens-Johnson per 2014 ER record).")

    # Family history (cardiology-focused)
    y -= 22
    c.setFont("Helvetica-Bold", 11)
    c.drawString(40, y, "5.  Family history of heart disease")
    y -= 16
    c.setFont("Helvetica-Bold", 9)
    c.drawString(50, y, "Relation")
    c.drawString(180, y, "Condition")
    c.drawString(420, y, "Age at event")
    c.line(40, y - 3, W - 40, y - 3)
    c.setFont("Helvetica", 10)
    fh = [
        ("Father",  "Myocardial infarction (deceased)",  "68 (death 71)"),
        ("Father",  "Type 2 diabetes",                   "55"),
        ("Mother",  "Hypertension",                      "60"),
        ("Brother", "Coronary artery disease, stent",    "63"),
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
    c.drawString(50, y, "Tobacco: never.   Alcohol: 1-2 drinks/wk.   Exercise: walks 20 min, 3-4 days/wk.")

    # Signature
    y -= 30
    c.line(40, y, 280, y)
    c.line(330, y, 540, y)
    y -= 12
    c.setFont("Helvetica", 9)
    c.drawString(40, y, "Patient signature")
    c.drawString(330, y, "Date")
    c.drawString(330, y - 14, "04/28/2026")

    c.showPage()
    c.save()


# ---------------------------------------------------------------------------
# Medication list #1 — retail pharmacy printout
# ---------------------------------------------------------------------------

def gen_medication_list_pharmacy(out: Path) -> None:
    """Walgreens/CVS-style printout: header with pharmacy info, simple
    rectangular table, refill history mini-section."""
    c = canvas.Canvas(str(out), pagesize=letter)
    W, H = letter

    c.setFont("Helvetica-Bold", 18)
    c.drawString(40, H - 40, "CVS Pharmacy — Patient Medication Profile")
    c.setFont("Helvetica", 10)
    c.drawString(40, H - 56, "Store #4421  ·  1100 Lavaca St, Austin TX 78701  ·  (512) 555-2200")
    c.line(40, H - 64, W - 40, H - 64)

    y = H - 84
    c.setFont("Helvetica-Bold", 10)
    c.drawString(40, y, "Patient: Ted W. Shaw")
    c.drawString(220, y, "DOB: 03/11/1947")
    c.drawString(330, y, "Sex: M")
    c.drawString(390, y, "Profile printed: 04/29/2026")

    y -= 24
    c.setFont("Helvetica-Bold", 9)
    c.drawString(40,  y, "Medication")
    c.drawString(190, y, "Strength")
    c.drawString(245, y, "Sig (Frequency)")
    c.drawString(380, y, "Route")
    c.drawString(430, y, "Prescriber")
    c.drawString(540, y, "Last fill")
    c.line(40, y - 3, W - 30, y - 3)

    rows = [
        ("Metformin HCl",            "1000 mg",  "Take 1 tablet twice daily with meals",  "PO",  "Jensen, M.",   "04/15/26"),
        ("Lisinopril",               "20 mg",    "Take 1 tablet by mouth once daily",     "PO",  "Jensen, M.",   "04/15/26"),
        ("Atorvastatin Calcium",     "20 mg",    "Take 1 tablet by mouth at bedtime",     "PO",  "Jensen, M.",   "04/15/26"),
        ("Empagliflozin (Jardiance)","10 mg",    "Take 1 tablet by mouth daily",          "PO",  "Jensen, M.",   "04/15/26"),
        ("Aspirin Low Dose",         "81 mg",    "Take 1 tablet by mouth once daily",     "PO",  "OTC",          "—"),
        ("Glucagon Emergency Kit",   "1 mg/mL",  "Inject IM/SC if unconscious",           "IM",  "Jensen, M.",   "01/02/26"),
    ]
    y -= 14
    c.setFont("Helvetica", 9)
    for name, strength, sig, route, presc, fill in rows:
        c.drawString(40,  y, name)
        c.drawString(190, y, strength)
        c.drawString(245, y, sig)
        c.drawString(380, y, route)
        c.drawString(430, y, presc)
        c.drawString(540, y, fill)
        y -= 14

    y -= 12
    c.setFont("Helvetica-Oblique", 8)
    c.drawString(40, y, "Refills remaining shown on label. Profile reflects medications dispensed at this CVS location only;")
    y -= 11
    c.drawString(40, y, "the patient may have additional medications filled elsewhere. Verified with patient at counseling 04/15/2026.")

    c.showPage()
    c.save()


# ---------------------------------------------------------------------------
# Medication list #2 — hospital discharge medication reconciliation
# ---------------------------------------------------------------------------

def gen_medication_list_discharge(out: Path) -> None:
    """Hospital-style discharge med rec: NEW vs CONTINUED vs CHANGED vs STOPPED.
    Different organization than the pharmacy printout — categorized rather
    than tabular. Tests fact_type assignment and indication-grouping.
    """
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
    story.append(Paragraph("St. David's Medical Center — Discharge Medication Reconciliation", h1))
    story.append(Paragraph(
        "Patient: <b>Ted Shaw</b>  ·  MRN 0000123456  ·  DOB 03/11/1947  ·  "
        "Discharged 04/27/2026 1430 from 5W ·  Discharging hospitalist: J. Reyes, MD",
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
        ["Metformin HCl",       "1000 mg", "PO",  "BID with meals",   "Type 2 diabetes",   "Jensen, M."],
        ["Atorvastatin",        "20 mg",   "PO",  "QHS",              "Hyperlipidemia",    "Jensen, M."],
        ["Aspirin (low dose)",  "81 mg",   "PO",  "Daily",            "CV prevention",     "OTC"],
    ])

    med_block("CHANGED — dose or frequency adjusted", "#a37020", [
        ["Lisinopril", "40 mg", "PO", "Daily (was 20 mg daily)", "Hypertension / CKD",  "Reyes, J."],
    ])

    med_block("NEW — started during this admission", "#2d7a4f", [
        ["Empagliflozin (Jardiance)", "10 mg", "PO", "Daily",            "T2DM + cardio-renal", "Reyes, J."],
        ["Furosemide",                "20 mg", "PO", "Daily",            "Lower-extremity edema", "Reyes, J."],
        ["Cholecalciferol (Vit D3)",  "2000 IU", "PO", "Daily",          "Vitamin D deficiency",  "Reyes, J."],
    ])

    med_block("STOPPED — do not continue at home", "#7a1f1f", [
        ["Metoprolol tartrate", "25 mg", "PO", "BID (held during admit)", "Replaced by Lisinopril", "Reyes, J."],
    ])

    story.append(Spacer(1, 10))
    story.append(Paragraph(
        "Patient verbalized understanding of all medication changes and was provided a written copy. "
        "Follow-up with primary care (Dr. Jensen) within 7 days to recheck renal panel and BP.",
        body,
    ))
    story.append(Spacer(1, 8))
    story.append(Paragraph(
        "Reconciled by: Marisol Ortega, RN  ·  Reviewed by: Jorge Reyes, MD  ·  04/27/2026 1352",
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
