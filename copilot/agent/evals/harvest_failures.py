"""
AgentForge Co-Pilot — harvest failed eval cases from Langfuse.

Pulls `eval-case` observations from the self-hosted Langfuse instance,
filters to those whose `passed` score is 0, joins them with the
matching `judge-score` (which carries the rubric reason), and emits a
candidate eval-cases JSON the engineer can review and merge into
`cases.json`.

The flow is intentionally human-in-the-loop:
  1. Each candidate has `_source` metadata (trace URL, timestamp, judge
     reason) so the reviewer can pull up the original failure.
  2. `expected.rubric` is left as a placeholder — the reviewer writes
     the new pass criterion based on what the failure revealed.
  3. The output file lives in evals/harvested/ so it never accidentally
     joins the test suite without explicit promotion.

Usage:
    export LANGFUSE_PUBLIC_KEY=...
    export LANGFUSE_SECRET_KEY=...
    export LANGFUSE_HOST=https://langfuse-web-production-368f.up.railway.app
    python harvest_failures.py                     # last 7 days, all envs
    python harvest_failures.py --since 2026-04-25  # explicit cutoff
    python harvest_failures.py --limit 500         # paginate further
    python harvest_failures.py --out /tmp/foo.json # custom output

Exit code:
    0 — script ran (regardless of how many failures were found)
    2 — config / network error
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import urlencode

import httpx

HERE = Path(__file__).resolve().parent
DEFAULT_OUT = HERE / "harvested" / "candidates.json"
PAGE_SIZE = 100


def env(name: str) -> str:
    val = os.environ.get(name)
    if not val:
        print(f"✗ {name} not set", file=sys.stderr)
        sys.exit(2)
    return val


def lf_get(client: httpx.Client, host: str, path: str, params: dict) -> dict:
    """One paginated GET against the Langfuse public API."""
    url = f"{host.rstrip('/')}/api/public/{path.lstrip('/')}?{urlencode(params)}"
    resp = client.get(url)
    resp.raise_for_status()
    return resp.json()


def fetch_failed_passed_scores(
    client: httpx.Client,
    host: str,
    since: datetime,
    page_limit: int,
) -> list[dict]:
    """Page through /api/public/scores, return only those with name=passed value=0.

    The Langfuse API's `value` filter is unreliable for numeric scores,
    so we paginate and filter client-side. `since` limits the scan window.
    """
    found: list[dict] = []
    page = 1
    while True:
        body = lf_get(
            client,
            host,
            "scores",
            {"name": "passed", "limit": PAGE_SIZE, "page": page},
        )
        data = body.get("data") or []
        if not data:
            break
        for s in data:
            ts = parse_ts(s.get("timestamp"))
            if ts is not None and ts < since:
                # Past the cutoff — earlier pages are older still
                return found
            if s.get("value") == 0:
                found.append(s)
        if len(found) >= page_limit:
            break
        meta = body.get("meta") or {}
        if page >= (meta.get("totalPages") or 1):
            break
        page += 1
    return found


def fetch_observation(client: httpx.Client, host: str, observation_id: str) -> dict | None:
    """Pull a single observation by ID. Returns None on 404."""
    url = f"{host.rstrip('/')}/api/public/observations/{observation_id}"
    resp = client.get(url)
    if resp.status_code == 404:
        return None
    resp.raise_for_status()
    return resp.json()


def fetch_judge_reason(
    client: httpx.Client, host: str, observation_id: str
) -> str | None:
    """Find the `judge-score` comment attached to this observation, if any."""
    body = lf_get(
        client,
        host,
        "scores",
        {"name": "judge-score", "limit": 50},
    )
    for s in body.get("data") or []:
        if s.get("observationId") == observation_id:
            return s.get("comment")
    return None


def parse_ts(s: str | None) -> datetime | None:
    if not s:
        return None
    try:
        # Langfuse returns 2026-05-02T13:56:14.123Z
        return datetime.fromisoformat(s.replace("Z", "+00:00"))
    except ValueError:
        return None


def trace_url(host: str, trace_id: str) -> str:
    return f"{host.rstrip('/')}/trace/{trace_id}"


def to_candidate(obs: dict, score: dict, judge_reason: str | None, host: str) -> dict:
    """Render an observation+score into a cases.json-shaped candidate."""
    inp = obs.get("input") or {}
    out = obs.get("output") or {}
    case_id = inp.get("case_id") or "unknown"
    return {
        # Prefix harvested IDs so they never collide with hand-authored ones
        "id": f"harvested-{case_id}",
        "category": "harvested",
        "patient_id": str(inp.get("patient_id") or ""),
        "message": inp.get("message") or "",
        "expected": {
            "rubric": "TODO: human reviewer fills this in based on the failure below.",
            "must_contain": [],
            "must_not_contain": [],
        },
        "_source": {
            "harvested_from_case_id": case_id,
            "trace_id": obs.get("traceId"),
            "trace_url": trace_url(host, obs.get("traceId") or ""),
            "observed_at": score.get("timestamp"),
            "agent_reply": (out.get("reply") or "")[:1500],
            "judge_reason": judge_reason or "",
        },
    }


def run() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "--since",
        help="Only harvest failures after this date (YYYY-MM-DD). Default: 7 days ago.",
    )
    ap.add_argument(
        "--limit",
        type=int,
        default=200,
        help="Max failed scores to scan (paginate up to this many). Default 200.",
    )
    ap.add_argument(
        "--out",
        default=str(DEFAULT_OUT),
        help=f"Where to write candidate cases JSON. Default {DEFAULT_OUT}.",
    )
    args = ap.parse_args()

    host = env("LANGFUSE_HOST")
    auth = (env("LANGFUSE_PUBLIC_KEY"), env("LANGFUSE_SECRET_KEY"))

    if args.since:
        since = datetime.fromisoformat(args.since).replace(tzinfo=timezone.utc)
    else:
        since = datetime.now(timezone.utc) - timedelta(days=7)

    print(f"• Scanning Langfuse failures since {since.isoformat()} (host: {host})")

    with httpx.Client(auth=auth, timeout=30.0) as client:
        failed_scores = fetch_failed_passed_scores(client, host, since, args.limit)
        print(f"• Found {len(failed_scores)} failed `passed` scores in window.")

        # Dedup by case_id — keep only the most recent failure per case.
        # Repeated failures of the same case are a signal worth surfacing
        # in the summary count, but only the latest run yields a useful
        # candidate (the agent's latest behavior is what we'd test against).
        candidates_by_case: dict[str, dict] = {}
        failure_counts: dict[str, int] = {}
        seen_obs: set[str] = set()
        for score in failed_scores:
            obs_id = score.get("observationId")
            if not obs_id or obs_id in seen_obs:
                continue
            seen_obs.add(obs_id)
            obs = fetch_observation(client, host, obs_id)
            if not obs or obs.get("name") != "eval-case":
                continue
            case_id = (obs.get("input") or {}).get("case_id") or "unknown"
            failure_counts[case_id] = failure_counts.get(case_id, 0) + 1
            # Scores come back newest-first so the first one we see for a
            # given case_id is the most recent — keep it, drop later (older) ones.
            if case_id in candidates_by_case:
                continue
            judge_reason = fetch_judge_reason(client, host, obs_id)
            cand = to_candidate(obs, score, judge_reason, host)
            cand["_source"]["failure_count_in_window"] = 0  # set below
            candidates_by_case[case_id] = cand

        # Backfill the failure count now that we've seen everything
        for case_id, cand in candidates_by_case.items():
            cand["_source"]["failure_count_in_window"] = failure_counts[case_id]
        candidates = sorted(
            candidates_by_case.values(),
            key=lambda c: c["_source"]["failure_count_in_window"],
            reverse=True,
        )

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "_meta": {
            "harvested_at": datetime.now(timezone.utc).isoformat(),
            "since": since.isoformat(),
            "host": host,
            "candidate_count": len(candidates),
            "review_steps": [
                "1. Open each trace_url to see the full failed run.",
                "2. Decide if the failure represents a real regression worth covering.",
                "3. If yes: rewrite `expected.rubric`, optionally add must_contain / must_not_contain, change `category` from 'harvested' to a real category, then move into cases.json.",
                "4. If no (judge nitpick / one-off): drop the entry.",
            ],
        },
        "candidates": candidates,
    }
    with out_path.open("w") as fh:
        json.dump(payload, fh, indent=2)

    print(f"• Wrote {len(candidates)} unique candidate(s) to {out_path}")
    if candidates:
        print("\nMost-failing-first:")
        for c in candidates:
            src = c["_source"]
            print(
                f"  ✗ {c['id']:<45s} "
                f"(failed {src['failure_count_in_window']}x) {src['trace_url']}"
            )
    return 0


if __name__ == "__main__":
    sys.exit(run())
