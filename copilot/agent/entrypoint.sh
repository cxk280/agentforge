#!/bin/sh
# Container entrypoint for the Co-Pilot FastAPI agent.
#
# Starts the New Relic Infrastructure agent as a side-process so the
# Railway container shows up in NR's Infrastructure UI alongside the
# Python APM data, then execs the main application.
#
# When NEW_RELIC_LICENSE_KEY is unset (local dev, CI), the infra agent
# is skipped and the main app starts unmodified.

set -e

if [ -n "${NEW_RELIC_LICENSE_KEY}" ]; then
    export NRIA_LICENSE_KEY="${NEW_RELIC_LICENSE_KEY}"
    export NRIA_DISPLAY_NAME="${NRIA_DISPLAY_NAME:-${NEW_RELIC_APP_NAME:-agentforge-copilot-agent}}"
    # Tag the host so it's easy to filter by app + env in NR.
    export NRIA_CUSTOM_ATTRIBUTES="${NRIA_CUSTOM_ATTRIBUTES:-{\"app\":\"copilot-agent\",\"env\":\"${NEW_RELIC_ENVIRONMENT:-production}\"}}"
    newrelic-infra > /tmp/newrelic-infra.log 2>&1 &
fi

exec "$@"
