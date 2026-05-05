#!/usr/bin/env bash
#
# Pre-push hook for AgentForge.
#
# Runs the isolated PHPUnit suite (host has no PHP, so we shell into the
# OpenEMR docker container). Push is blocked if any test fails.
#
# Install:  scripts/install-git-hooks.sh
# Skip selectively (recommended):
#   COPILOT_SKIP_PRE_PUSH=1   git push   # skip the PHPUnit suite
#   COPILOT_SKIP_EVAL_SMOKE=1 git push   # skip the eval smoke
# Sledgehammer:  git push --no-verify  (skips both; don't make a habit of it)

set -euo pipefail

# Use git's idea of the repo root, not BASH_SOURCE-relative — when git
# invokes this script via .git/hooks/pre-push (an installed copy),
# BASH_SOURCE/.. resolves to the .git directory, not the repo root.
REPO_ROOT="$(git rev-parse --show-toplevel)"
CONTAINER="${COPILOT_TEST_CONTAINER:-development-easy-light-openemr-1}"

# Honor COPILOT_SKIP_PRE_PUSH=1 unconditionally. Earlier versions only
# checked it on the docker-missing / container-down failure paths, which
# meant a successful dev stack always ran the full PHPUnit suite even
# when the user explicitly asked to skip (e.g. when pushing a pure
# revert / docs / non-PHP change). Keep `git push --no-verify` as the
# sledgehammer; this env var is the supported, traceable opt-out.
if [[ "${COPILOT_SKIP_PRE_PUSH:-0}" = "1" ]]; then
    echo "⏭  pre-push: COPILOT_SKIP_PRE_PUSH=1 — skipping PHPUnit suite."
else
    if ! command -v docker >/dev/null 2>&1; then
        echo "✗ pre-push: docker not found on PATH; cannot run tests." >&2
        echo "  Install Docker Desktop or set COPILOT_SKIP_PRE_PUSH=1 to skip." >&2
        exit 1
    fi

    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
        echo "✗ pre-push: docker container '${CONTAINER}' is not running." >&2
        echo "  Start the dev stack:" >&2
        echo "    cd docker/development-easy && docker compose up --detach --wait" >&2
        echo "  Or set COPILOT_SKIP_PRE_PUSH=1 to skip (only when you have a good reason)." >&2
        exit 1
    fi

    echo "▶ pre-push: running isolated PHPUnit suite in ${CONTAINER}..."

    # Run the AgentForge Co-Pilot tests + the project's standard isolated suite.
    docker exec -i "${CONTAINER}" sh -c \
        "cd /var/www/localhost/htdocs/openemr && \
         ./vendor/bin/phpunit -c phpunit-isolated.xml --filter 'Copilot|copilot' --no-coverage" \
        || {
            echo "" >&2
            echo "✗ pre-push: AgentForge Co-Pilot tests failed. Push blocked." >&2
            echo "  To bypass intentionally: git push --no-verify" >&2
            exit 1
        }

    echo "✓ pre-push: tests passed."
fi

# ─── Eval smoke (5 cases vs. LOCAL Co-Pilot) ─────────────────────────────
#
# Catches agent regressions from the *previous* push before another change
# lands on top. Costs ~$0.05 / ~30s per push.
#
# Pinned to the local agent at http://localhost:8400/chat — pre-push runs
# on a developer workstation, so it must hit the local environment, not
# any deployed env. CI runs the same suite against dev/qa/prod from the
# corresponding CircleCI jobs.
#
# Skipped automatically when ANTHROPIC_API_KEY is unset (e.g. on a fresh
# clone), when COPILOT_SKIP_EVAL_SMOKE=1 is exported, or when the local
# agent isn't running on :8400.

if [[ -z "${ANTHROPIC_API_KEY:-}" ]] && [[ -f "${REPO_ROOT}/copilot/agent/.env" ]]; then
    # eval the matching line directly. Avoids xargs (mangles `=` and
    # special chars) and process substitution (unreliable here under
    # `set -e` for reasons that aren't worth chasing).
    eval "$(grep -E '^ANTHROPIC_API_KEY=' "${REPO_ROOT}/copilot/agent/.env")"
    export ANTHROPIC_API_KEY
fi

LOCAL_AGENT_ENDPOINT="http://localhost:8400/chat"

if [[ "${COPILOT_SKIP_EVAL_SMOKE:-0}" = "1" ]]; then
    echo "⏭  pre-push: COPILOT_SKIP_EVAL_SMOKE=1 — skipping eval smoke."
elif [[ -z "${ANTHROPIC_API_KEY:-}" ]]; then
    echo "⏭  pre-push: ANTHROPIC_API_KEY not set — skipping eval smoke."
elif ! command -v python3 >/dev/null 2>&1; then
    echo "⏭  pre-push: python3 not on PATH — skipping eval smoke."
elif ! curl -fsS -m 3 -o /dev/null "${LOCAL_AGENT_ENDPOINT%/chat}/" 2>/dev/null; then
    echo "⏭  pre-push: local agent at ${LOCAL_AGENT_ENDPOINT} not reachable — skipping eval smoke."
    echo "   Start it with: cd copilot/agent && uvicorn main:app --port 8400"
    echo "   Do NOT redirect this hook at a deployed env — local↔dev↔qa↔prod must stay isolated."
else
    echo "▶ pre-push: running 5-case eval smoke against local Co-Pilot (${LOCAL_AGENT_ENDPOINT})..."
    EVAL_DIR="${REPO_ROOT}/copilot/agent/evals"
    EVAL_VENV="${EVAL_DIR}/.venv"

    if [[ ! -x "${EVAL_VENV}/bin/python" ]]; then
        python3 -m venv "${EVAL_VENV}" >/dev/null
        "${EVAL_VENV}/bin/pip" install -q -r "${EVAL_DIR}/requirements.txt"
    fi

    # Capture output so we can gate on pass count, not just exit code.
    # run_evals.py exits 1 on any single failure, but Haiku-as-judge
    # introduces ~10% per-case variance — a single failed case isn't
    # signal of a regression. CI's eval-smoke-dev job uses min_pass=4,
    # we match that here.
    SMOKE_LOG=$(mktemp)
    trap 'rm -f "$SMOKE_LOG"' EXIT
    EVAL_AGENT_ENDPOINT="${LOCAL_AGENT_ENDPOINT}" \
        "${EVAL_VENV}/bin/python" "${EVAL_DIR}/run_evals.py" --smoke --no-langfuse \
        | tee "$SMOKE_LOG" || true
    PASSED=$(grep -E "^\s*TOTAL\s+[0-9]+/[0-9]+ passed" "$SMOKE_LOG" \
             | awk '{print $2}' | cut -d/ -f1)
    REQUIRED=4
    if [[ -z "${PASSED:-}" ]]; then
        echo "" >&2
        echo "✗ pre-push: eval smoke could not parse a TOTAL line." >&2
        echo "  Likely the agent endpoint is unreachable. Investigate or skip:" >&2
        echo "  COPILOT_SKIP_EVAL_SMOKE=1 git push" >&2
        exit 1
    fi
    if (( PASSED < REQUIRED )); then
        echo "" >&2
        echo "✗ pre-push: eval smoke passed ${PASSED}/5 (require ${REQUIRED}/5)." >&2
        echo "  More than one failure suggests a real regression — investigate" >&2
        echo "  before pushing more on top." >&2
        echo "  To bypass intentionally: COPILOT_SKIP_EVAL_SMOKE=1 git push" >&2
        exit 1
    fi
    echo "✓ pre-push: eval smoke passed (${PASSED}/5, threshold ${REQUIRED}/5)."
fi
