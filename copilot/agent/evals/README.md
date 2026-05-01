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
- `requirements.txt` — Python deps for the harness only (anthropic,
  httpx, langfuse). Does not pull the full agent stack.

## Usage

```bash
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

export ANTHROPIC_API_KEY=...
export LANGFUSE_PUBLIC_KEY=pk-lf-copilot-prod
export LANGFUSE_SECRET_KEY=sk-lf-copilot-prod-fa805936e58055
export LANGFUSE_HOST=https://langfuse-web-production-368f.up.railway.app

# Full 25-case run against production
python run_evals.py

# 5-case smoke (used by pre-push hook)
python run_evals.py --smoke

# Filter by category or id
python run_evals.py --filter clinical_lookup
python run_evals.py --filter "tool_*"
python run_evals.py --case lookup-meds-ted

# Skip Langfuse upload (e.g. while iterating offline)
python run_evals.py --no-langfuse
```

## Trust model

These cases were authored by Claude (Opus). Lookup cases queried the
real DB for ground truth and are reliable; summary / multi_step /
refusal cases are subjective rubrics and benefit from clinician review.
Treat this as a v0 baseline. Replace cases over time with traces of
real production failures (see `harvest_failures.py`).

## CI integration

Pre-push: `scripts/pre-push.sh` already runs the PHPUnit Copilot suite.
A future change will append `python copilot/agent/evals/run_evals.py
--smoke` (5 cases, ~30s, $0.05 of API spend) so every push gets a
quick agent sanity check.

CircleCI: full 25-case run on every push to master, gated by exit code.
Configured once `.circleci/config.yml` lands.
