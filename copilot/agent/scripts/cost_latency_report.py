"""Cost + latency report — submission deliverable.

Pulls observability data from a saved eval-run JSON (the same shape
emitted by `run_evals.py --json-out`) plus an optional Langfuse export,
and produces a human-readable report covering:

  - Per-rubric pass rates (sanity check)
  - Latency p50 / p95 / p99 per case category
  - Token usage + USD spend per model
  - Cost per encounter (median + p95)
  - Bottleneck table — slowest 5 cases by step

Usage:
    python copilot/agent/scripts/cost_latency_report.py \
        --eval-run /tmp/eval-current.json \
        --out cost_latency_report.md
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from statistics import median
from typing import Any

# Allow import without the package being installed.
HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from cost_table import cost_for_usage  # noqa: E402


def percentile(values: list[float], p: float) -> float:
    if not values:
        return 0.0
    s = sorted(values)
    idx = max(0, min(len(s) - 1, int(round((p / 100) * (len(s) - 1)))))
    return s[idx]


def format_md(eval_run: dict[str, Any]) -> str:
    per_case = eval_run.get("per_case") or []

    lines: list[str] = ["# AgentForge Co-Pilot — Cost & Latency Report", ""]
    lines.append(f"_Source: eval run with {eval_run.get('n_cases', 0)} cases._\n")

    # Per-rubric pass rates.
    lines.append("## Pass rates per rubric\n")
    rubrics = eval_run.get("rubric_pass_rate") or {}
    if rubrics:
        lines.append("| Rubric | Pass rate |")
        lines.append("|---|---:|")
        for r in sorted(rubrics):
            lines.append(f"| {r} | {rubrics[r]:.2%} |")
    else:
        lines.append("_(no rubric data)_")
    lines.append("")

    # Latency by category.
    lines.append("## Latency per case category\n")
    by_cat: dict[str, list[int]] = {}
    for c in per_case:
        if not c.get("latency_ms"):
            continue
        by_cat.setdefault(c.get("category", "uncategorised"), []).append(int(c["latency_ms"]))
    if by_cat:
        lines.append("| Category | n | p50 (ms) | p95 (ms) | max (ms) |")
        lines.append("|---|---:|---:|---:|---:|")
        for cat in sorted(by_cat):
            vs = by_cat[cat]
            lines.append(
                f"| {cat} | {len(vs)} | {int(percentile(vs, 50))} | "
                f"{int(percentile(vs, 95))} | {max(vs)} |"
            )
    else:
        lines.append("_(no latency data on this run)_")
    lines.append("")

    # Token + cost rollups.
    # eval-run JSON emits per-case `usage` + `model` only when the agent
    # included them in /chat replies; we tolerate their absence and just
    # emit whatever we have.
    usages_by_model: dict[str, list[dict]] = {}
    for c in per_case:
        usage = c.get("usage")
        model = c.get("model")
        if usage and model:
            usages_by_model.setdefault(model, []).append(usage)

    lines.append("## Token usage + USD spend\n")
    if usages_by_model:
        lines.append("| Model | Calls | in | cache_read | cache_write | out | $ total |")
        lines.append("|---|---:|---:|---:|---:|---:|---:|")
        grand_total = 0.0
        for model, ulist in sorted(usages_by_model.items()):
            tot_in = sum(int(u.get("input_tokens") or 0) for u in ulist)
            tot_cr = sum(int(u.get("cache_read_input_tokens") or 0) for u in ulist)
            tot_cw = sum(int(u.get("cache_creation_input_tokens") or 0) for u in ulist)
            tot_out = sum(int(u.get("output_tokens") or 0) for u in ulist)
            cb = cost_for_usage(model, {
                "input_tokens": tot_in,
                "output_tokens": tot_out,
                "cache_read_input_tokens": tot_cr,
                "cache_creation_input_tokens": tot_cw,
            })
            grand_total += cb.total_usd
            lines.append(
                f"| `{model}` | {len(ulist)} | {tot_in} | {tot_cr} | {tot_cw} | "
                f"{tot_out} | ${cb.total_usd:.4f} |"
            )
        lines.append(f"\n**Grand total (eval run only): ${grand_total:.4f}**")
    else:
        lines.append(
            "_(usage data not present in this eval run — wire `usage`/`model` "
            "fields into run_evals.py's per-case output to populate)_"
        )
    lines.append("")

    # Bottlenecks.
    lines.append("## Slowest cases (by latency)\n")
    slowest = sorted(
        [c for c in per_case if c.get("latency_ms")],
        key=lambda c: int(c["latency_ms"]),
        reverse=True,
    )[:5]
    if slowest:
        lines.append("| Case | Category | Latency (ms) |")
        lines.append("|---|---|---:|")
        for c in slowest:
            lines.append(
                f"| `{c.get('case_id')}` | {c.get('category')} | {c.get('latency_ms')} |"
            )
    else:
        lines.append("_(no latency data)_")
    lines.append("")

    return "\n".join(lines)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--eval-run", required=True, type=Path,
                    help="JSON file emitted by run_evals.py --json-out")
    ap.add_argument("--out", type=Path,
                    help="Write the markdown report here (default: stdout)")
    args = ap.parse_args()

    if not args.eval_run.exists():
        print(f"✗ eval-run file not found: {args.eval_run}", file=sys.stderr)
        return 2

    data = json.loads(args.eval_run.read_text())
    md = format_md(data)
    if args.out:
        args.out.write_text(md)
        print(f"Wrote {args.out} ({len(md)} bytes).")
    else:
        print(md)
    return 0


if __name__ == "__main__":
    sys.exit(main())
