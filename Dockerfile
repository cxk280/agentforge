# OpenEMR with AgentForge Co-Pilot customizations.
#
# Bases on the official openemr/openemr:flex image (Apache + PHP + vendor/
# pre-built). We copy our forked source over the image so all interface/
# UI overlays, copilot_*.php pages, theme overrides, and template tweaks
# land on top of stock OpenEMR.
#
# Avoids the Nixpacks composer-install path that was failing on the
# project's autoloader classmap scan ("library/classes" not found),
# because the base image already ships a generated autoloader.
FROM openemr/openemr:flex@sha256:e4562b0c7d3f222ec8f72122ce00d10ffa93f559c38c00ab12c1355394c35d1c

# Copy fork source over the baked-in OpenEMR install. Preserve existing
# vendor/, sites/, and node_modules/ from the base image to avoid a
# rebuild — those are gitignored on our side anyway.
COPY --chown=root:root . /var/www/localhost/htdocs/openemr/

# The base image's entrypoint runs MySQL bootstrap, autoload regen, and
# Apache. We don't override it.
