#!/bin/sh
# Container entrypoint for the OpenEMR PHP app on Railway.
#
# Starts the New Relic Infrastructure agent as a side-process so the
# Railway container shows up in NR's Infrastructure UI alongside the
# PHP APM data, then defers to the OpenEMR base image's normal
# startup (./openemr.sh, supplied via CMD).
#
# When NEW_RELIC_LICENSE_KEY is unset (local dev, CI), the infra agent
# is skipped and the OpenEMR start script runs unmodified.

set -e

if [ -n "${NEW_RELIC_LICENSE_KEY}" ]; then
    export NRIA_LICENSE_KEY="${NEW_RELIC_LICENSE_KEY}"
    export NRIA_DISPLAY_NAME="${NRIA_DISPLAY_NAME:-${NEW_RELIC_APP_NAME:-agentforge-openemr}}"
    export NRIA_CUSTOM_ATTRIBUTES="${NRIA_CUSTOM_ATTRIBUTES:-{\"app\":\"openemr\",\"env\":\"${NEW_RELIC_ENVIRONMENT:-production}\"}}"
    newrelic-infra > /tmp/newrelic-infra.log 2>&1 &
fi

exec "$@"
