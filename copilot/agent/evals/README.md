# AgentForge Co-Pilot — Evals

This directory holds the eval suite that runs the production Co-Pilot
agent against a fixed set of cases and grades the replies via Claude
Haiku 4.5 (LLM-as-judge). Results are uploaded to Langfuse Datasets
(`copilot-golden-v1`) so you get history + diffs in the dashboard.

## Files

- `cases.json` — the 25-case golden + labeled set. Each case has an
  `input` (patient_id + message) and an `expected` block with optional
  `must_contain`, `must_not_contain`, and free-text `rubric` fields.
- `run_evals.py` — the harness. Loads cases, calls `/chat`, judges,
  uploads scores to Langfuse, prints a summary, exits 0/1/2.
- `harvest_failures.py` — pulls failed `eval-case` traces from
  Langfuse and writes a candidate cases JSON for human review.
- `requirements.txt` — Python deps for the harness only (anthropic,
  httpx, langfuse). Does not pull the full agent stack.

## Usage

The eval target must match the environment you're running from
(local↔dev↔qa↔prod; never cross). The harness defaults to
`http://localhost:8400/chat`. CI jobs and the pre-push hook set
`EVAL_AGENT_ENDPOINT` explicitly to the matching env URL — do not
re-point a local run at a deployed env.

```bash
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

export ANTHROPIC_API_KEY=...
# Skip Langfuse for local runs (we don't run Langfuse locally — see
# feedback_no_local_langfuse). For CI runs against dev/qa/prod, the
# CircleCI project envs supply LANGFUSE_PUBLIC_KEY/SECRET_KEY/HOST.

# Make sure the LOCAL agent is up first:
#   cd copilot/agent && uvicorn main:app --port 8400

# Full 25-case run against the local agent (default endpoint)
python run_evals.py --no-langfuse

# 5-case smoke (used by pre-push hook, also against local)
python run_evals.py --smoke --no-langfuse

# Filter by category or id
python run_evals.py --no-langfuse --filter clinical_lookup
python run_evals.py --no-langfuse --filter "tool_*"
python run_evals.py --no-langfuse --case lookup-meds-ted

# Run against a non-local env (only when you ARE that env, e.g. inside CI)
EVAL_AGENT_ENDPOINT=https://copilot-agent-dev.up.railway.app/chat \
    python run_evals.py
```

## Trust model

These cases were authored by Claude (Opus). Lookup cases queried the
real DB for ground truth and are reliable; summary / multi_step /
refusal cases are subjective rubrics and benefit from clinician review.
Treat this as a v0 baseline. Replace cases over time with traces of
real production failures (see *Harvesting failures* below).

## Harvesting failures from Langfuse

`harvest_failures.py` mines the self-hosted Langfuse instance for
`eval-case` spans whose `passed` score is 0, deduplicates by case id
(keeping the most recent failure), and writes a candidate cases JSON
the engineer reviews and selectively merges into `cases.json`.

```bash
python harvest_failures.py                     # last 7 days, default output
python harvest_failures.py --since 2026-04-25  # explicit cutoff
python harvest_failures.py --out /tmp/foo.json # custom output
```

Output goes to `harvested/candidates.json` by default. That directory
is gitignored so harvest output never accidentally lands in the test
suite. Each candidate carries `_source.failure_count_in_window` (so
flaky cases bubble up first), the deep-link `trace_url`, the judge's
rubric reason, and the agent's actual reply. Promote a candidate by
editing `expected.rubric`, flipping `category` from `harvested` to a
real category, and copy-pasting the entry into `cases.json`.

## CI integration

Pre-push: `scripts/pre-push.sh` already runs the PHPUnit Copilot suite.
A future change will append `python copilot/agent/evals/run_evals.py
--smoke` (5 cases, ~30s, $0.05 of API spend) so every push gets a
quick agent sanity check.

CircleCI: full 25-case run on every push to master, gated by exit code.
Configured once `.circleci/config.yml` lands.
