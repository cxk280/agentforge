"""Cost + latency report — submission deliverable (W2 day 5–6).

Produces a markdown report covering per-operation latency (p50 / p95 / p99),
token usage, and estimated USD spend across the agent's recent traffic.

Two data sources are supported (pick whichever matches the env's
observability state):

    --source local      — read the per-case JSON emitted by
                          `run_evals.py --json-out`. Ground truth for the
                          eval suite; works offline; the only source local
                          dev has, since LANGFUSE_* are intentionally unset
                          (see feedback_no_local_langfuse.md).

    --source langfuse   — pull traces + generation observations from the
                          Langfuse instance configured for the chosen
                          --env. Honest production data; requires
                          LANGFUSE_PUBLIC_KEY / LANGFUSE_SECRET_KEY /
                          LANGFUSE_HOST in the environment (or the agent's
                          .env file resolved via config.Settings).

Bucketing: by operation type. For Langfuse traces the operation = the
top-level trace `name` (e.g. `copilot_chat_turn_stream`). For the local
eval JSON the operation = the case `category` field (e.g.
`clinical_lookup`, `refusal`, `edge`).

Usage:

    # Local — points at the eval JSON the harness already emits:
    python copilot/agent/scripts/cost_latency_report.py \
        --source local --eval-run /tmp/eval-current.json

    # Langfuse — last 24h against the prod env:
    python copilot/agent/scripts/cost_latency_report.py \
        --source langfuse --env prod --since 24h \
        --output copilot/agent/scripts/cost-reports/prod-24h.md

    # Both (write the merged report into cost-reports/ for committal):
    python copilot/agent/scripts/cost_latency_report.py \
        --source langfuse --env prod --since 7d \
        --eval-run /tmp/eval-current.json \
        --output copilot/agent/scripts/cost-reports/weekly.md

Stdout is the markdown report; --output writes to disk in addition.

This script never calls Anthropic — it is post-hoc analysis of telemetry
the agent already emits.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Iterable

# Allow import without the package being installed.
HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent))

from cost_table import cost_for_usage  # noqa: E402

REPORTS_DIR = HERE / "cost-reports"


# ---------------------------------------------------------------------------
# Stats helpers
# ---------------------------------------------------------------------------

def percentile(values: Iterable[float], p: float) -> float:
    """Nearest-rank percentile. Returns 0.0 for an empty input.

    Nearest-rank (rather than linear-interpolation) keeps the report
    integer-friendly when callers cast back to int(ms), which is what
    every downstream consumer here does.
    """
    s = sorted(values)
    if not s:
        return 0.0
    idx = max(0, min(len(s) - 1, int(round((p / 100) * (len(s) - 1)))))
    return s[idx]


def parse_since(spec: str) -> datetime:
    """Parse a relative ('24h', '7d', '30m') or ISO-8601 timestamp into UTC.

    Relative shorthand keeps the CLI ergonomic for the common "last N
    hours" report; ISO-8601 lets callers reproduce a specific window for
    a given incident.
    """
    spec = spec.strip()
    now = datetime.now(timezone.utc)
    if not spec:
        return now - timedelta(hours=24)

    # Relative — <int><unit> where unit is m/h/d.
    if spec[-1].lower() in {"m", "h", "d"} and spec[:-1].isdigit():
        n = int(spec[:-1])
        unit = spec[-1].lower()
        if unit == "m":
            return now - timedelta(minutes=n)
        if unit == "h":
            return now - timedelta(hours=n)
        if unit == "d":
            return now - timedelta(days=n)

    # ISO-8601 fallback.
    try:
        dt = datetime.fromisoformat(spec.replace("Z", "+00:00"))
    except ValueError as exc:
        raise SystemExit(f"✗ Could not parse --since {spec!r}: {exc}") from None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt


# ---------------------------------------------------------------------------
# Per-operation aggregate
# ---------------------------------------------------------------------------

@dataclass
class OpStats:
    """Rolling aggregate for one operation bucket (chat / extract / etc)."""

    operation: str
    latencies_ms: list[float] = field(default_factory=list)
    input_tokens: int = 0
    output_tokens: int = 0
    cache_read_tokens: int = 0
    cache_creation_tokens: int = 0
    usd_total: float = 0.0
    # Track the dominant model for the bucket — when one bucket spans
    # multiple models we surface "+N" so the reader knows the row is a
    # blend (e.g. supervisor uses Sonnet but the critic uses Haiku).
    models: dict[str, int] = field(default_factory=dict)

    @property
    def n(self) -> int:
        return len(self.latencies_ms)

    def add_latency(self, ms: float, *, model: str | None = None) -> None:
        if ms is None:
            return
        self.latencies_ms.append(float(ms))
        if model:
            self.models[model] = self.models.get(model, 0) + 1

    def add_usage(self, model: str, usage: dict) -> None:
        cb = cost_for_usage(model, usage)
        self.input_tokens += cb.input_tokens
        self.output_tokens += cb.output_tokens
        self.cache_read_tokens += cb.cache_read_tokens
        self.cache_creation_tokens += cb.cache_creation_tokens
        self.usd_total += cb.total_usd

    def model_label(self) -> str:
        if not self.models:
            return "—"
        ranked = sorted(self.models.items(), key=lambda kv: -kv[1])
        head = ranked[0][0]
        return head if len(ranked) == 1 else f"{head} +{len(ranked) - 1}"


def _aggregate_blank(name: str, table: dict[str, OpStats]) -> OpStats:
    return table.setdefault(name, OpStats(operation=name))


# ---------------------------------------------------------------------------
# Source: local eval JSON
# ---------------------------------------------------------------------------

def collect_from_eval_run(payload: dict) -> tuple[dict[str, OpStats], dict]:
    """Bucket per-case eval results by category."""
    per_case = payload.get("per_case") or []
    table: dict[str, OpStats] = {}

    for case in per_case:
        op = case.get("category") or "uncategorised"
        agg = _aggregate_blank(op, table)
        latency = case.get("latency_ms")
        if latency:
            agg.add_latency(int(latency), model=case.get("model"))
        usage = case.get("usage")
        model = case.get("model")
        if usage and model:
            agg.add_usage(model, usage)

    meta = {
        "source": "local-eval-run",
        "n_cases": payload.get("n_cases", len(per_case)),
        "rubric_pass_rate": payload.get("rubric_pass_rate") or {},
        "tool_call_accuracy": payload.get("tool_call_accuracy"),
        "latency_p50_ms": payload.get("latency_p50_ms"),
        "latency_p95_ms": payload.get("latency_p95_ms"),
    }
    return table, meta


# ---------------------------------------------------------------------------
# Source: Langfuse
# ---------------------------------------------------------------------------

def collect_from_langfuse(env: str, since: datetime, *, page_limit: int = 1000) -> tuple[dict[str, OpStats], dict]:
    """Pull traces + generation observations from Langfuse and bucket them.

    Latency comes from the top-level trace (one row per request); tokens +
    cost are summed across child generation observations attached to the
    same trace. This matches how the agent emits telemetry today —
    `trace_request` opens the trace, `span_generation` records every
    Claude call inside it.
    """
    try:
        from langfuse import Langfuse
    except ImportError as exc:  # pragma: no cover — ImportError surfaces cleanly
        raise SystemExit(
            "✗ langfuse SDK not installed. Run: "
            "copilot/agent/evals/.venv/bin/pip install langfuse"
        ) from exc

    if not (os.environ.get("LANGFUSE_PUBLIC_KEY") and os.environ.get("LANGFUSE_SECRET_KEY")):
        # Fall through to the agent's own settings loader so the .env file
        # at copilot/agent/.env is honored without a manual `set -a`.
        try:
            from config import settings  # type: ignore[import-not-found]
            os.environ.setdefault("LANGFUSE_PUBLIC_KEY", settings.langfuse_public_key or "")
            os.environ.setdefault("LANGFUSE_SECRET_KEY", settings.langfuse_secret_key or "")
            os.environ.setdefault("LANGFUSE_HOST", settings.langfuse_host)
        except Exception:
            pass

    if not (os.environ.get("LANGFUSE_PUBLIC_KEY") and os.environ.get("LANGFUSE_SECRET_KEY")):
        raise SystemExit(
            "✗ LANGFUSE_PUBLIC_KEY / LANGFUSE_SECRET_KEY not set. Either "
            "export them or populate copilot/agent/.env. (Local dev does "
            "not run Langfuse — see feedback_no_local_langfuse.md — so "
            "use --source local for the local env.)"
        )

    lf = Langfuse()

    # ── Pull traces in the window ────────────────────────────────────
    traces: list = []
    page = 1
    while True:
        resp = lf.api.trace.list(
            from_timestamp=since,
            environment=env,
            page=page,
            limit=100,
        )
        chunk = list(resp.data or [])
        if not chunk:
            break
        traces.extend(chunk)
        if len(chunk) < 100 or len(traces) >= page_limit:
            break
        page += 1

    # Build trace_id → trace lookup so we can attribute generations.
    by_id = {t.id: t for t in traces}

    table: dict[str, OpStats] = {}
    for t in traces:
        op = (t.name or "unnamed_trace").strip()
        agg = _aggregate_blank(op, table)
        # `latency` is seconds float on TraceWithDetails; convert to ms.
        if t.latency:
            agg.add_latency(t.latency * 1000.0)
        # total_cost is already-computed Langfuse cost. We keep it for the
        # meta footer but use cost_table.py for the per-bucket math so the
        # math is reproducible from token counts (Langfuse doesn't always
        # have prices configured per-env).

    # ── Pull generation observations and roll into the same buckets ──
    obs: list = []
    cursor: str | None = None
    while True:
        resp = lf.api.observations.get_many(
            type="GENERATION",
            from_start_time=since,
            environment=env,
            limit=100,
            cursor=cursor,
        )
        chunk = list(resp.data or [])
        if not chunk:
            break
        obs.extend(chunk)
        meta = getattr(resp, "meta", None)
        cursor = getattr(meta, "next_cursor", None) if meta else None
        if not cursor or len(obs) >= page_limit:
            break

    skipped_no_trace = 0
    for o in obs:
        parent = by_id.get(o.trace_id)
        if parent is None:
            skipped_no_trace += 1
            continue
        op = (parent.name or "unnamed_trace").strip()
        agg = _aggregate_blank(op, table)
        # usage_details is the v3+ shape: {"input": N, "output": N, ...}
        # Map it back to the Anthropic Usage shape cost_table understands.
        usage_details = o.usage_details or {}
        usage_dict = {
            "input_tokens": usage_details.get("input") or usage_details.get("input_tokens") or 0,
            "output_tokens": usage_details.get("output") or usage_details.get("output_tokens") or 0,
            "cache_read_input_tokens": (
                usage_details.get("cache_read")
                or usage_details.get("cache_read_input_tokens")
                or 0
            ),
            "cache_creation_input_tokens": (
                usage_details.get("cache_creation")
                or usage_details.get("cache_creation_input_tokens")
                or 0
            ),
        }
        if any(usage_dict.values()):
            agg.add_usage(o.model or "claude-sonnet-4-6", usage_dict)
            # Track which models contributed even when latency was logged
            # at the trace level (keeps the model column populated when a
            # bucket has 0 trace-level latencies).
            if o.model:
                agg.models[o.model] = agg.models.get(o.model, 0) + 1

    meta = {
        "source": "langfuse",
        "env": env,
        "since": since.isoformat(),
        "trace_count": len(traces),
        "generation_count": len(obs),
        "generations_orphaned": skipped_no_trace,
    }
    return table, meta


# ---------------------------------------------------------------------------
# Markdown rendering
# ---------------------------------------------------------------------------

def render_markdown(
    *,
    title_suffix: str,
    aggregates: dict[str, OpStats],
    meta: dict,
    eval_meta: dict | None = None,
) -> str:
    lines: list[str] = []
    lines.append(f"# AgentForge Co-Pilot — Cost & Latency Report{title_suffix}")
    lines.append("")
    lines.append(
        f"_Generated {datetime.now(timezone.utc).isoformat(timespec='seconds')} _"
    )
    lines.append("")

    # ── Source banner ────────────────────────────────────────────────
    lines.append("## Source")
    lines.append("")
    if meta.get("source") == "langfuse":
        lines.append(f"- **Source:** Langfuse (`{meta.get('env')}` environment)")
        lines.append(f"- **Window:** since `{meta.get('since')}`")
        lines.append(f"- **Traces:** {meta.get('trace_count', 0)}")
        lines.append(f"- **Generation observations:** {meta.get('generation_count', 0)}")
        if meta.get("generations_orphaned"):
            lines.append(
                f"- _{meta['generations_orphaned']} generations skipped — "
                f"trace not in window_"
            )
    else:
        lines.append(f"- **Source:** local eval-run JSON")
        lines.append(f"- **Cases:** {meta.get('n_cases', 0)}")
        if (tca := meta.get("tool_call_accuracy")) is not None:
            lines.append(f"- **Tool-call accuracy:** {tca:.2%}")
    lines.append("")

    # ── Per-operation table ──────────────────────────────────────────
    lines.append("## Per-operation breakdown")
    lines.append("")
    if not aggregates:
        lines.append("_(no data in window)_")
    else:
        lines.append(
            "| Operation | n | Model | p50 (ms) | p95 (ms) | p99 (ms) | "
            "Input tok | Output tok | Cache R/W | $ total | $/req |"
        )
        lines.append(
            "|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|"
        )
        # Sort by total cost descending — biggest spenders first.
        ordered = sorted(
            aggregates.values(),
            key=lambda a: (-a.usd_total, -a.n),
        )
        tot_n = 0
        tot_lat: list[float] = []
        tot_in = tot_out = tot_cr = tot_cw = 0
        tot_usd = 0.0
        for agg in ordered:
            p50 = int(percentile(agg.latencies_ms, 50)) if agg.latencies_ms else 0
            p95 = int(percentile(agg.latencies_ms, 95)) if agg.latencies_ms else 0
            p99 = int(percentile(agg.latencies_ms, 99)) if agg.latencies_ms else 0
            per_req = agg.usd_total / agg.n if agg.n else 0.0
            lines.append(
                f"| `{agg.operation}` | {agg.n} | `{agg.model_label()}` | "
                f"{p50} | {p95} | {p99} | "
                f"{agg.input_tokens} | {agg.output_tokens} | "
                f"{agg.cache_read_tokens}/{agg.cache_creation_tokens} | "
                f"${agg.usd_total:.4f} | ${per_req:.4f} |"
            )
            tot_n += agg.n
            tot_lat.extend(agg.latencies_ms)
            tot_in += agg.input_tokens
            tot_out += agg.output_tokens
            tot_cr += agg.cache_read_tokens
            tot_cw += agg.cache_creation_tokens
            tot_usd += agg.usd_total
        # Totals row.
        if ordered:
            tp50 = int(percentile(tot_lat, 50)) if tot_lat else 0
            tp95 = int(percentile(tot_lat, 95)) if tot_lat else 0
            tp99 = int(percentile(tot_lat, 99)) if tot_lat else 0
            per_req = tot_usd / tot_n if tot_n else 0.0
            lines.append(
                f"| **TOTAL** | **{tot_n}** | — | "
                f"**{tp50}** | **{tp95}** | **{tp99}** | "
                f"**{tot_in}** | **{tot_out}** | "
                f"**{tot_cr}/{tot_cw}** | "
                f"**${tot_usd:.4f}** | **${per_req:.4f}** |"
            )
    lines.append("")

    # ── Optional rubric pass-rates (for eval source) ─────────────────
    if eval_meta and eval_meta.get("rubric_pass_rate"):
        lines.append("## Eval rubric pass rates")
        lines.append("")
        lines.append("| Rubric | Pass rate |")
        lines.append("|---|---:|")
        for r, v in sorted(eval_meta["rubric_pass_rate"].items()):
            lines.append(f"| `{r}` | {v:.2%} |")
        lines.append("")

    # ── Footnotes ────────────────────────────────────────────────────
    lines.append("## Notes")
    lines.append("")
    lines.append(
        "- Costs computed from `cost_table.py` (pinned to Anthropic public "
        "pricing 2026-05-05). Update that file when prices change."
    )
    lines.append(
        "- Latency p50/p95/p99 are nearest-rank over the window; small-N "
        "buckets (n < 20) are noisy and should be read as directional only."
    )
    if meta.get("source") == "langfuse":
        lines.append(
            "- Operation = top-level Langfuse trace name. Buckets that show "
            "up as `unnamed_trace` are emitted by code paths that don't yet "
            "go through `observability.trace_request` — extend that helper "
            "to cover them."
        )
    else:
        lines.append(
            "- Operation = eval case category. To populate the `Input tok` "
            "and `$ total` columns, add `model` + `usage` fields to each "
            "per-case entry in `run_evals.py --json-out`."
        )
    lines.append("")
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument(
        "--source",
        choices=("local", "langfuse"),
        default="local",
        help="Data source. 'local' reads --eval-run JSON; 'langfuse' "
             "queries the Langfuse instance for --env.",
    )
    ap.add_argument("--env", choices=("local", "dev", "qa", "prod"), default="prod",
                    help="Environment label to filter Langfuse traces by.")
    ap.add_argument("--since", default="24h",
                    help="Window for --source langfuse. Either a relative "
                         "shorthand (24h, 7d, 30m) or an ISO-8601 timestamp.")
    ap.add_argument("--eval-run", type=Path,
                    help="JSON file emitted by run_evals.py --json-out. "
                         "Required for --source local; optional alongside "
                         "--source langfuse to splice in eval rubric scores.")
    ap.add_argument("--output", "--out", dest="output", type=Path,
                    help="Write the markdown report here in addition to "
                         "stdout. Path is resolved relative to repo cwd.")
    args = ap.parse_args()

    aggregates: dict[str, OpStats] = {}
    meta: dict = {}
    eval_meta: dict | None = None

    if args.source == "local":
        if not args.eval_run:
            print("✗ --source local requires --eval-run", file=sys.stderr)
            return 2
        if not args.eval_run.exists():
            print(f"✗ eval-run file not found: {args.eval_run}", file=sys.stderr)
            return 2
        payload = json.loads(args.eval_run.read_text())
        aggregates, meta = collect_from_eval_run(payload)
        eval_meta = meta
        title_suffix = f" — local eval ({meta['n_cases']} cases)"
    else:
        since = parse_since(args.since)
        aggregates, meta = collect_from_langfuse(args.env, since)
        title_suffix = f" — {args.env} (since {args.since})"

        # If the caller also provided --eval-run, splice in rubric scores.
        if args.eval_run and args.eval_run.exists():
            payload = json.loads(args.eval_run.read_text())
            _, eval_meta = collect_from_eval_run(payload)

    md = render_markdown(
        title_suffix=title_suffix,
        aggregates=aggregates,
        meta=meta,
        eval_meta=eval_meta,
    )

    print(md)

    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(md)
        print(f"\n• Wrote report → {args.output} ({len(md)} bytes)", file=sys.stderr)

    return 0


if __name__ == "__main__":
    sys.exit(main())
