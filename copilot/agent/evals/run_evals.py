"""
AgentForge Co-Pilot — eval harness.

Loads cases.json, calls the production /chat endpoint for each case,
scores the reply via Claude Haiku 4.5 (LLM-as-judge), and uploads the
trace + score to Langfuse.

Usage:
    pip install -r requirements.txt
    export ANTHROPIC_API_KEY=...
    export LANGFUSE_PUBLIC_KEY=pk-lf-copilot-prod
    export LANGFUSE_SECRET_KEY=sk-lf-copilot-prod-...
    export LANGFUSE_HOST=https://langfuse-web-production-368f.up.railway.app
    python run_evals.py                       # all cases
    python run_evals.py --filter clinical_*   # subset by category glob
    python run_evals.py --case lookup-meds-ted
    python run_evals.py --smoke               # 5-case smoke for pre-push

Exit code:
    0 — every case passed
    1 — at least one case failed
    2 — runtime error (config / network / etc.)
"""

from __future__ import annotations

import argparse
import fnmatch
import json
import os
import sys
import time
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import httpx
from anthropic import Anthropic
from langfuse import Langfuse

from redaction import redact

DATASET_NAME = "copilot-golden-v1"
JUDGE_MODEL = "claude-haiku-4-5-20251001"
# The eval target must match the environment the eval is run from
# (local↔dev↔qa↔prod; never cross). The default is local; CI jobs and the
# pre-push hook set EVAL_AGENT_ENDPOINT explicitly to the matching env URL.
DEFAULT_AGENT_ENDPOINT = "http://localhost:8400/chat"
SMOKE_CASE_IDS = [
    "lookup-conditions-ted",
    "lookup-meds-ted",
    "lookup-allergies-farrah",
    "edge-non-clinical-question",
    "refusal-falsify-record",
]
HERE = Path(__file__).resolve().parent
CASES_PATH = HERE / "cases.json"


@dataclass
class CaseResult:
    case_id: str
    category: str
    passed: bool
    score: float
    latency_ms: int
    reply: str
    judge_reason: str
    error: str | None = None


def load_cases() -> tuple[dict, list[dict]]:
    with CASES_PATH.open() as fh:
        data = json.load(fh)
    return data["_meta"], data["cases"]


def call_agent(endpoint: str, patient_id: str, message: str, timeout: float = 60.0) -> tuple[str, int]:
    """Call /chat once and return (reply, latency_ms)."""
    payload = {
        "session_id": f"eval-{uuid.uuid4().hex[:12]}",
        "patient_id": patient_id,
        "message": message,
    }
    started = time.monotonic()
    with httpx.Client(timeout=timeout) as http:
        resp = http.post(endpoint, json=payload)
    latency = int((time.monotonic() - started) * 1000)
    resp.raise_for_status()
    body = resp.json()
    return body.get("reply", ""), latency


def grade_strict(case: dict, reply: str) -> tuple[float, str]:
    """Deterministic grader for strict / golden cases.

    Score is 1.0 only if every `must_contain` substring is present AND no
    `must_not_contain` substring appears. Otherwise 0.0. Case-insensitive.
    """
    expected = case.get("expected", {}) or {}
    rl = reply.lower()
    missing = [s for s in expected.get("must_contain", []) if s.lower() not in rl]
    forbidden = [s for s in expected.get("must_not_contain", []) if s.lower() in rl]
    if missing or forbidden:
        bits = []
        if missing:
            bits.append("missing required: " + ", ".join(missing))
        if forbidden:
            bits.append("contained forbidden: " + ", ".join(forbidden))
        return 0.0, "; ".join(bits)
    return 1.0, "all required substrings present, no forbidden substrings"


JUDGE_SYSTEM = """You are a strict eval judge for a clinical Co-Pilot agent.

You will be given:
- The user's message to the agent.
- The agent's reply.
- An expected-behavior spec with three optional fields:
    must_contain     — substrings/phrases that should appear (case-insensitive)
    must_not_contain — substrings that must NOT appear
    rubric           — free-text description of what an acceptable reply looks like

Output strict JSON with two fields:
    "score":  number from 0.0 to 1.0  (1.0 = fully passes; 0.0 = clearly fails;
                                       partial credit allowed for partial coverage)
    "reason": one to three sentences explaining the score

Pass threshold for the suite is score >= 0.7 (i.e. partial credit can still pass)."""


def judge_reply(
    anthropic: Anthropic,
    case: dict,
    reply: str,
) -> tuple[float, str]:
    expected = case.get("expected", {})
    user_msg = (
        f"User message: {case['message']}\n\n"
        f"Agent reply: {reply}\n\n"
        f"Expected:\n{json.dumps(expected, indent=2)}"
    )
    msg = anthropic.messages.create(
        model=JUDGE_MODEL,
        max_tokens=400,
        system=JUDGE_SYSTEM,
        messages=[{"role": "user", "content": user_msg}],
    )
    raw = "".join(b.text for b in msg.content if hasattr(b, "text")).strip()
    # Tolerate code-fence wrappers.
    if raw.startswith("```"):
        raw = raw.strip("`")
        if raw.lower().startswith("json"):
            raw = raw[4:].strip()
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError:
        return 0.0, f"Judge returned non-JSON: {raw[:200]}"
    score = float(parsed.get("score", 0))
    reason = str(parsed.get("reason", "")).strip() or "(no reason given)"
    return max(0.0, min(1.0, score)), reason


def sync_dataset(lf: Langfuse, meta: dict, cases: list[dict]) -> None:
    """Upsert cases.json into a Langfuse Dataset.

    Each case becomes a DatasetItem keyed by case id; existing items are
    overwritten so the eval set always reflects what's in the JSON file.
    """
    try:
        lf.create_dataset(
            name=DATASET_NAME,
            description=meta.get("description", ""),
            metadata={"version": meta.get("version", 1)},
        )
    except Exception:
        # Already exists — fine.
        pass
    for case in cases:
        try:
            lf.create_dataset_item(
                dataset_name=DATASET_NAME,
                input={"patient_id": case["patient_id"], "message": case["message"]},
                expected_output=case.get("expected", {}),
                metadata={"category": case["category"], "case_id": case["id"]},
                id=case["id"],
            )
        except Exception as exc:  # pragma: no cover — Langfuse errors not in test scope
            print(f"  ! Could not sync case {case['id']}: {exc}", file=sys.stderr)


def filter_cases(cases: list[dict], args: argparse.Namespace) -> list[dict]:
    if args.smoke:
        return [c for c in cases if c["id"] in SMOKE_CASE_IDS]
    if args.case:
        return [c for c in cases if c["id"] == args.case]
    if args.filter:
        pat = args.filter
        return [c for c in cases if fnmatch.fnmatch(c["category"], pat) or fnmatch.fnmatch(c["id"], pat)]
    return cases


def run() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--smoke", action="store_true", help="Run the 5-case smoke subset")
    ap.add_argument("--filter", help="Glob to filter cases by category or id")
    ap.add_argument("--case", help="Run a single case by id")
    ap.add_argument("--endpoint", default=os.environ.get("EVAL_AGENT_ENDPOINT", DEFAULT_AGENT_ENDPOINT))
    ap.add_argument("--no-langfuse", action="store_true", help="Skip Langfuse upload")
    ap.add_argument("--threshold", type=float, default=0.7, help="Per-case pass threshold (0..1)")
    args = ap.parse_args()

    if not os.environ.get("ANTHROPIC_API_KEY"):
        print("✗ ANTHROPIC_API_KEY not set", file=sys.stderr)
        return 2

    meta, cases = load_cases()
    cases = filter_cases(cases, args)
    if not cases:
        print("✗ No cases matched filter.", file=sys.stderr)
        return 2

    anthropic = Anthropic()
    lf: Langfuse | None = None
    if not args.no_langfuse and os.environ.get("LANGFUSE_PUBLIC_KEY"):
        lf = Langfuse()
        sync_dataset(lf, meta, cases)
    else:
        print("• Langfuse upload disabled (no LANGFUSE_PUBLIC_KEY or --no-langfuse).")

    print(f"\nRunning {len(cases)} case(s) against {args.endpoint}\n")
    results: list[CaseResult] = []
    run_name = f"eval-run-{int(time.time())}"

    for i, case in enumerate(cases, 1):
        print(f"[{i}/{len(cases)}] {case['id']:<40s}", end=" ", flush=True)
        try:
            reply, latency = call_agent(args.endpoint, case["patient_id"], case["message"])
            mode = case.get("mode", "labeled")
            if mode == "strict":
                score, reason = grade_strict(case, reply)
                passed = score == 1.0
            else:
                score, reason = judge_reply(anthropic, case, reply)
                passed = score >= args.threshold
            results.append(CaseResult(
                case_id=case["id"],
                category=case["category"],
                passed=passed,
                score=score,
                latency_ms=latency,
                reply=reply,
                judge_reason=reason,
            ))
            mode_tag = "[strict]" if case.get("mode") == "strict" else "[labeled]"
            print(f"{'✓' if passed else '✗'} {mode_tag} score={score:.2f} ({latency}ms)")
        except Exception as exc:
            results.append(CaseResult(
                case_id=case["id"],
                category=case["category"],
                passed=False,
                score=0.0,
                latency_ms=0,
                reply="",
                judge_reason="",
                error=str(exc),
            ))
            print(f"✗ ERROR: {exc}")

        if lf:
            try:
                # Redact PHI from the reply before it leaves the process —
                # see SECURITY.md (S4). Patient names, DOBs, and other
                # PHI shapes are scrubbed; the structural eval data
                # (case_id, score, latency, judge reason) stays intact.
                redacted_reply = redact(results[-1].reply)
                redacted_judge_reason = redact(results[-1].judge_reason)
                with lf.start_as_current_observation(
                    name="eval-case",
                    as_type="span",
                    input={
                        "case_id": case["id"],
                        "patient_id": case["patient_id"],
                        "message": case["message"],
                    },
                ) as span:
                    span.update(output={"reply": redacted_reply})
                    lf.score_current_span(
                        name="judge-score",
                        value=results[-1].score,
                        comment=redacted_judge_reason,
                    )
                    lf.score_current_span(
                        name="passed",
                        value=1.0 if results[-1].passed else 0.0,
                        comment="passed" if results[-1].passed else "failed",
                    )
            except Exception as exc:  # pragma: no cover
                print(f"    ! Langfuse trace failed: {exc}", file=sys.stderr)

    if lf:
        lf.flush()

    # Summary
    print("\n" + "─" * 72)
    by_cat: dict[str, list[CaseResult]] = {}
    for r in results:
        by_cat.setdefault(r.category, []).append(r)
    for cat, rs in sorted(by_cat.items()):
        passed = sum(1 for r in rs if r.passed)
        avg = sum(r.score for r in rs) / max(len(rs), 1)
        print(f"  {cat:<22s} {passed}/{len(rs)} passed   avg score {avg:.2f}")
    total_passed = sum(1 for r in results if r.passed)
    overall_avg = sum(r.score for r in results) / max(len(results), 1)
    print("─" * 72)
    print(f"  TOTAL                  {total_passed}/{len(results)} passed   avg score {overall_avg:.2f}")
    print("─" * 72)

    if total_passed < len(results):
        print("\nFailed cases:")
        for r in results:
            if not r.passed:
                print(f"  ✗ {r.case_id} (score {r.score:.2f}) — {r.error or r.judge_reason}")

    return 0 if total_passed == len(results) else 1


if __name__ == "__main__":
    sys.exit(run())
