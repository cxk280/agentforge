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

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
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
