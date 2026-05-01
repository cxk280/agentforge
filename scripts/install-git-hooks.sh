#!/usr/bin/env bash
#
# Install AgentForge git hooks into .git/hooks/.
# Re-running is safe — the script overwrites with the latest version.
#
# Usage: scripts/install-git-hooks.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOOKS_DIR="${REPO_ROOT}/.git/hooks"

if [[ ! -d "${HOOKS_DIR}" ]]; then
    echo "✗ Not in a git repo (no ${HOOKS_DIR})." >&2
    exit 1
fi

install_hook () {
    local hook_name="$1"
    local source="${REPO_ROOT}/scripts/${hook_name}.sh"
    local target="${HOOKS_DIR}/${hook_name}"
    if [[ ! -f "${source}" ]]; then
        echo "✗ Source script not found: ${source}" >&2
        return 1
    fi
    cp "${source}" "${target}"
    chmod +x "${target}"
    echo "✓ Installed ${hook_name} → ${target}"
}

install_hook pre-push

echo ""
echo "Done. Tests will run via docker before each git push."
echo "Container: \${COPILOT_TEST_CONTAINER:-development-easy-light-openemr-1}"
echo "Skip with: COPILOT_SKIP_PRE_PUSH=1 git push  (or git push --no-verify)"
