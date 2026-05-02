#!/usr/bin/env bash
#
# Pre-push hook for AgentForge.
#
# Runs the isolated PHPUnit suite (host has no PHP, so we shell into the
# OpenEMR docker container). Push is blocked if any test fails.
#
# Install:  scripts/install-git-hooks.sh
# Bypass:   git push --no-verify  (don't make a habit of it)

set -euo pipefail

# Use git's idea of the repo root, not BASH_SOURCE-relative — when git
# invokes this script via .git/hooks/pre-push (an installed copy),
# BASH_SOURCE/.. resolves to the .git directory, not the repo root.
REPO_ROOT="$(git rev-parse --show-toplevel)"
CONTAINER="${COPILOT_TEST_CONTAINER:-development-easy-light-openemr-1}"

if ! command -v docker >/dev/null 2>&1; then
    echo "✗ pre-push: docker not found on PATH; cannot run tests." >&2
    echo "  Install Docker Desktop or set COPILOT_SKIP_PRE_PUSH=1 to skip." >&2
    [[ "${COPILOT_SKIP_PRE_PUSH:-0}" = "1" ]] && exit 0
    exit 1
fi

if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
    echo "✗ pre-push: docker container '${CONTAINER}' is not running." >&2
    echo "  Start the dev stack:" >&2
    echo "    cd docker/development-easy && docker compose up --detach --wait" >&2
    echo "  Or set COPILOT_SKIP_PRE_PUSH=1 to skip (only when you have a good reason)." >&2
    [[ "${COPILOT_SKIP_PRE_PUSH:-0}" = "1" ]] && exit 0
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

# ─── Eval smoke (5 cases vs. production Co-Pilot) ────────────────────────
#
# Catches agent regressions from the *previous* push before another change
# lands on top. Costs ~$0.05 / ~30s per push.
#
# Skipped automatically when ANTHROPIC_API_KEY is unset (e.g. on a fresh
# clone), or when COPILOT_SKIP_EVAL_SMOKE=1 is exported (e.g. when prod
# is intentionally down).

if [[ -z "${ANTHROPIC_API_KEY:-}" ]] && [[ -f "${REPO_ROOT}/copilot/agent/.env" ]]; then
    # eval the matching line directly. Avoids xargs (mangles `=` and
    # special chars) and process substitution (unreliable here under
    # `set -e` for reasons that aren't worth chasing).
    eval "$(grep -E '^ANTHROPIC_API_KEY=' "${REPO_ROOT}/copilot/agent/.env")"
    export ANTHROPIC_API_KEY
fi

if [[ "${COPILOT_SKIP_EVAL_SMOKE:-0}" = "1" ]]; then
    echo "⏭  pre-push: COPILOT_SKIP_EVAL_SMOKE=1 — skipping eval smoke."
elif [[ -z "${ANTHROPIC_API_KEY:-}" ]]; then
    echo "⏭  pre-push: ANTHROPIC_API_KEY not set — skipping eval smoke."
elif ! command -v python3 >/dev/null 2>&1; then
    echo "⏭  pre-push: python3 not on PATH — skipping eval smoke."
else
    echo "▶ pre-push: running 5-case eval smoke against production Co-Pilot..."
    EVAL_DIR="${REPO_ROOT}/copilot/agent/evals"
    EVAL_VENV="${EVAL_DIR}/.venv"

    if [[ ! -x "${EVAL_VENV}/bin/python" ]]; then
        python3 -m venv "${EVAL_VENV}" >/dev/null
        "${EVAL_VENV}/bin/pip" install -q -r "${EVAL_DIR}/requirements.txt"
    fi

    "${EVAL_VENV}/bin/python" "${EVAL_DIR}/run_evals.py" --smoke --no-langfuse \
        || {
            echo "" >&2
            echo "✗ pre-push: eval smoke failed against production Co-Pilot." >&2
            echo "  This usually means the LAST push broke the agent — investigate" >&2
            echo "  before pushing more on top." >&2
            echo "  To bypass intentionally: COPILOT_SKIP_EVAL_SMOKE=1 git push" >&2
            exit 1
        }
    echo "✓ pre-push: eval smoke passed."
fi
