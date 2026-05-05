"""Week 2 MVP smoke demo.

Hits the local agent's /extract and /search routes end-to-end. Prints the
extracted JSON and the top guideline snippets side-by-side so a reviewer
can see the multimodal + retrieval surfaces working in one place.

Usage:
    # Prereqs: agent running on localhost:8400, sample lab PDF on disk
    python copilot/agent/scripts/mvp_demo.py \
        --pdf path/to/sample_lab.pdf \
        --patient-id 1 \
        --query "what is the target A1c for adults with type 2 diabetes?"

If --pdf points to a path that doesn't exist, the script can generate a
synthetic 1-page lab PDF on the fly when reportlab is installed:

    python copilot/agent/scripts/mvp_demo.py --generate-sample sample.pdf
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import httpx


def generate_sample_lab_pdf(out_path: Path) -> None:
    """Write a small synthetic lab PDF for demo + smoke testing.

    Uses reportlab if available; otherwise tells the user how to install
    it. The sample is intentionally low-fi (typed text, no scan noise) so
    the extraction surface area focuses on schema fidelity, not OCR.
    """
    try:
        from reportlab.lib.pagesizes import letter
        from reportlab.pdfgen import canvas
    except ImportError:
        print(
            "reportlab is not installed. Run:\n"
            "  pip install reportlab\n"
            "and re-run this script — or drop your own PDF at the target path.",
            file=sys.stderr,
        )
        sys.exit(2)

    c = canvas.Canvas(str(out_path), pagesize=letter)
    width, height = letter
    y = height - 60
    c.setFont("Helvetica-Bold", 16)
    c.drawString(60, y, "ACME Reference Lab — Comprehensive Metabolic Panel")
    y -= 26
    c.setFont("Helvetica", 11)
    c.drawString(60, y, "Patient: Ted Shaw    DOB: 1947-03-11    Sex: M")
    y -= 16
    c.drawString(60, y, "Ordering Provider: Maria Jensen, MD")
    y -= 16
    c.drawString(60, y, "Collection Date: 2026-04-28    Report Date: 2026-04-30")
    y -= 30
    c.setFont("Helvetica-Bold", 11)
    c.drawString(60, y, "Test")
    c.drawString(220, y, "Result")
    c.drawString(310, y, "Unit")
    c.drawString(370, y, "Reference Range")
    c.drawString(500, y, "Flag")
    y -= 6
    c.line(60, y, 550, y)
    y -= 18
    c.setFont("Helvetica", 11)
    rows = [
        ("Glucose, Fasting",     "138",       "mg/dL",       "70-99",      "H"),
        ("Hemoglobin A1c",       "8.2",       "%",            "<5.7",       "H"),
        ("Creatinine",           "1.6",       "mg/dL",       "0.7-1.3",    "H"),
        ("eGFR",                 "48",        "mL/min/1.73", ">=60",       "L"),
        ("Sodium",               "139",       "mmol/L",      "135-145",    ""),
        ("Potassium",            "4.7",       "mmol/L",      "3.5-5.1",    ""),
        ("LDL Cholesterol",      "118",       "mg/dL",       "<100",       "H"),
        ("HDL Cholesterol",      "42",        "mg/dL",       ">=40",       ""),
    ]
    for test_name, value, unit, ref, flag in rows:
        c.drawString(60, y, test_name)
        c.drawString(220, y, value)
        c.drawString(310, y, unit)
        c.drawString(370, y, ref)
        c.drawString(500, y, flag)
        y -= 16
    y -= 18
    c.setFont("Helvetica-Oblique", 9)
    c.drawString(60, y, "ACME Reference Lab  ·  CLIA #00D1234567  ·  Director: J. Patel, MD")
    c.showPage()
    c.save()


def post(url: str, body: dict, timeout: float = 90.0) -> dict:
    with httpx.Client(timeout=timeout) as http:
        resp = http.post(url, json=body)
    if resp.status_code >= 400:
        print(f"\n[!] {url} → HTTP {resp.status_code}", file=sys.stderr)
        try:
            print(json.dumps(resp.json(), indent=2), file=sys.stderr)
        except Exception:
            print(resp.text, file=sys.stderr)
        sys.exit(1)
    return resp.json()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--agent", default="http://localhost:8400",
                    help="Base URL of the running Co-Pilot agent.")
    ap.add_argument("--pdf",
                    help="Path to a lab PDF to extract.")
    ap.add_argument("--patient-id", type=int, default=1,
                    help="OpenEMR patient pid (default 1, Ted Shaw on the seed).")
    ap.add_argument("--doc-type", default="lab_pdf",
                    choices=["lab_pdf", "intake_form", "medication_list"])
    ap.add_argument("--query", default="metformin contraindicated CKD",
                    help="Hand-typed clinical query for the evidence retriever.")
    ap.add_argument("--generate-sample", metavar="PATH",
                    help="Write a synthetic lab PDF to PATH and exit.")
    args = ap.parse_args()

    if args.generate_sample:
        out = Path(args.generate_sample)
        generate_sample_lab_pdf(out)
        print(f"Wrote synthetic lab PDF → {out}")
        return 0

    if not args.pdf:
        print("Need --pdf <path> or --generate-sample <path>.", file=sys.stderr)
        return 2
    pdf_path = Path(args.pdf).resolve()
    if not pdf_path.exists():
        print(f"PDF not found: {pdf_path}", file=sys.stderr)
        return 2

    print(f"━━━ /extract ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
    extract_resp = post(f"{args.agent}/extract", {
        "patient_id": args.patient_id,
        "doc_type": args.doc_type,
        "file_path": str(pdf_path),
    })
    print(f"  schema_valid={extract_resp.get('schema_valid')}  "
          f"facts={extract_resp.get('fact_count')}  "
          f"citations={extract_resp.get('citation_count')}  "
          f"latency_ms={extract_resp.get('latency_ms')}")
    print(f"  tokens: in={extract_resp.get('input_tokens')} out={extract_resp.get('output_tokens')}")
    payload = extract_resp.get("payload") or {}
    if extract_resp["doc_type"] == "lab_pdf":
        for r in (payload.get("results") or [])[:8]:
            cit = r.get("source_citation", {}) or {}
            bbox = cit.get("bbox", {}) or {}
            print(f"  · {r.get('test_name')}: {r.get('value')} {r.get('unit') or ''}  "
                  f"[ref {r.get('reference_range') or '-'}]  flag={r.get('abnormal_flag')}  "
                  f"conf={r.get('confidence')}")
            print(f"      cite p{cit.get('page')} bbox=({bbox.get('x')},{bbox.get('y')},"
                  f"{bbox.get('w')},{bbox.get('h')})  quote={cit.get('quote')!r}")
    else:
        print(json.dumps(payload, indent=2)[:2000])

    print(f"\n━━━ /search  q='{args.query}' ━━━━━━━━━━━━━━━━━━━━━")
    search_resp = post(f"{args.agent}/search", {
        "query": args.query,
        "top_k": 5,
    })
    for i, r in enumerate(search_resp.get("results", []), 1):
        print(f"  {i}. [{r['source_id']} p{r['page']}, {r['section']}, score={r['score']}]")
        print(f"     {r['quote']}")
        print(f"     {r['source_url']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
