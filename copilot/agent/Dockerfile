# Multi-stage: pull the New Relic Infrastructure agent binaries from
# the official NR image so we don't have to maintain an apt repo or
# tarball download. The Go binaries are statically linked and run on
# both Alpine and Debian glibc.
FROM newrelic/infrastructure:latest AS nri

FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy the NR Infrastructure agent. Started as a side-process by
# entrypoint.sh when NEW_RELIC_LICENSE_KEY is set on Railway.
COPY --from=nri /usr/bin/newrelic-infra /usr/local/bin/newrelic-infra
COPY --from=nri /usr/bin/newrelic-infra-ctl /usr/local/bin/newrelic-infra-ctl
COPY --from=nri /usr/bin/newrelic-infra-service /usr/local/bin/newrelic-infra-service
RUN mkdir -p /etc/newrelic-infra /var/db/newrelic-infra/integrations.d

# Cache-bust the COPY layer. Pass a unique value at build time
# (--build-arg BUILD_REV=$(git rev-parse --short HEAD)) to force a
# fresh COPY when source files change. Without this, Railway's
# Buildkit can occasionally reuse a stale COPY layer even when the
# build context differs, leading to "deploy SUCCESS" with old code.
ARG BUILD_REV=dev
ENV BUILD_REV=${BUILD_REV}

COPY . .
RUN chmod +x /app/entrypoint.sh

ENV NEW_RELIC_CONFIG_FILE=/app/newrelic.ini

# entrypoint.sh starts newrelic-infra in the background (when a license
# key is set) then execs the CMD below. Railway injects $PORT; fall
# back to 8400 for local runs. `newrelic-admin run-program` no-ops
# cleanly when NEW_RELIC_LICENSE_KEY is unset, so the same image runs
# locally without NR creds.
ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["sh", "-c", "newrelic-admin run-program uvicorn main:app --host 0.0.0.0 --port ${PORT:-8400}"]
