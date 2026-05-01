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
FROM openemr/openemr:flex@sha256:e4562b0c7d3f222ec8f72122ce00d10ffa93f559c38c00ab12c1355394c35d1c

COPY --chown=root:root . /var/www/localhost/htdocs/openemr/
