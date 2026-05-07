# OpenEMR with AgentForge Co-Pilot customizations.
#
# Bases on the official openemr/openemr:flex image (Apache + PHP +
# vendor/ pre-built). We layer our forked source on top so all
# interface/ UI overlays, copilot_*.php pages, theme overrides, and
# template tweaks land on top of stock OpenEMR.
#
# Avoids the Nixpacks composer-install path that was failing on the
# project's autoloader classmap scan ("library/classes" not found),
# because the base image already ships a generated autoloader.
#
# Note: the base image declares VOLUMEs for public/themes,
# public/assets, sites, node_modules, and vendor — content COPY'd into
# those paths at build time gets masked at runtime. Customisations that
# need to survive (e.g. copilot-overlay.css) must live OUTSIDE those
# volumed paths; copilot-overlay.css is intentionally placed at
# public/copilot-overlay.css rather than under public/themes.
# Multi-stage: pull NR Infrastructure agent binaries from the official
# NR image. Started as a side-process by docker/start-with-nri.sh
# whenever NEW_RELIC_LICENSE_KEY is set on the Railway service.
FROM newrelic/infrastructure:latest AS nri

# --- AgentForge React UI builder ---------------------------------------
# Compiles the /frontend Vite project to /app/public/build/. Output is
# COPY'd into the runtime stage below. /public/build is NOT in the
# openemr/openemr:flex VOLUME list (only public/themes and public/assets
# are), so artifacts COPY'd there survive at runtime.
FROM node:24-alpine AS frontend
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./frontend/
RUN cd frontend && npm ci
COPY frontend/ ./frontend/
RUN cd frontend && npm run build
# vite outDir is ../public/build relative to /app/frontend, i.e. /app/public/build

FROM openemr/openemr:flex@sha256:e4562b0c7d3f222ec8f72122ce00d10ffa93f559c38c00ab12c1355394c35d1c

# --- New Relic Infrastructure agent (Go binary, statically linked) ---
COPY --from=nri /usr/bin/newrelic-infra /usr/local/bin/newrelic-infra
COPY --from=nri /usr/bin/newrelic-infra-ctl /usr/local/bin/newrelic-infra-ctl
COPY --from=nri /usr/bin/newrelic-infra-service /usr/local/bin/newrelic-infra-service
RUN mkdir -p /etc/newrelic-infra /var/db/newrelic-infra/integrations.d

# --- New Relic PHP agent ---
# Runtime config (license key, app name, HIPAA flags) is sourced from
# NEW_RELIC_* env vars set on the Railway openemr service so the same
# image runs across dev/qa/prod with no rebuild. The PLACEHOLDER key
# below only satisfies the silent installer at build time and is
# overridden at runtime by NEW_RELIC_LICENSE_KEY.
#
# The flex base is Alpine-based, so we use the linux-musl tarball.
ARG NEWRELIC_VERSION=12.6.0.34
RUN apk add --no-cache --virtual .nr-deps curl bash \
 && curl -fsSL "https://download.newrelic.com/php_agent/release/newrelic-php5-${NEWRELIC_VERSION}-linux-musl.tar.gz" -o /tmp/nr.tar.gz \
 && mkdir -p /tmp/nr && tar xzf /tmp/nr.tar.gz -C /tmp/nr --strip-components=1 \
 && cd /tmp/nr \
 && NR_INSTALL_USE_CP_NOT_LN=1 NR_INSTALL_SILENT=1 \
    NR_INSTALL_KEY=0000000000000000000000000000000000000000 \
    ./newrelic-install install \
 && rm -rf /tmp/nr /tmp/nr.tar.gz \
 && apk del .nr-deps

COPY --chown=root:root . /var/www/localhost/htdocs/openemr/

# Bundle React build output. Layered separately so we drop fresh React
# bundles on top of the source COPY. This must run after the source COPY
# so the manifest.json reaches the right path even if the local
# /public/build was empty at docker-build time.
COPY --from=frontend --chown=root:root /app/public/build /var/www/localhost/htdocs/openemr/public/build

# Wrap the base image's CMD (./openemr.sh) so the NR Infrastructure
# agent starts as a side-process before OpenEMR's normal startup runs.
COPY --chmod=0755 docker/start-with-nri.sh /usr/local/bin/start-with-nri.sh
ENTRYPOINT ["/usr/local/bin/start-with-nri.sh"]
CMD ["./openemr.sh"]
