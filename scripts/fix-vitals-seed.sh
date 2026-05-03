#!/usr/bin/env bash
#
# Make seeded vitals visible to the FHIR Observation endpoint.
#
# Two-step backfill (see sql/copilot_seeds/register_vitals_in_forms.sql
# for the full explanation):
#   1. SQL: register each form_vitals row in the `forms` table by
#      joining on (pid, date) → encounter.
#   2. PHP: call autoPopulateAllMissingUuids() so the
#      FHIR Observation Vitals service can resolve each vital sign to a
#      stable FHIR UUID per LOINC code (uuid_mapping table).
#
# This script targets the local docker-compose stack
# (development-easy-light-*). For deployed envs, run the equivalent
# steps via Railway shell or an admin endpoint — DO NOT shell into
# prod from a workstation. See sql file header for manual commands.
#
# Idempotent — safe to re-run.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_CONTAINER="${DB_CONTAINER:-development-easy-light-mysql-1}"
PHP_CONTAINER="${PHP_CONTAINER:-development-easy-light-openemr-1}"
SQL_PATH="${REPO_ROOT}/sql/copilot_seeds/register_vitals_in_forms.sql"

if ! command -v docker >/dev/null 2>&1; then
    echo "✗ docker not on PATH; cannot fix the local seed." >&2
    exit 1
fi
if ! docker ps --format '{{.Names}}' | grep -q "^${DB_CONTAINER}$"; then
    echo "✗ DB container '${DB_CONTAINER}' is not running." >&2
    echo "  Start it: cd docker/development-easy && docker compose up --detach --wait" >&2
    exit 1
fi
if ! docker ps --format '{{.Names}}' | grep -q "^${PHP_CONTAINER}$"; then
    echo "✗ OpenEMR container '${PHP_CONTAINER}' is not running." >&2
    exit 1
fi

echo "▶ Step 1/2: registering form_vitals rows in the forms table…"
docker exec -i "${DB_CONTAINER}" mysql -uroot -proot openemr 2>&1 \
    < "${SQL_PATH}" \
    | grep -v "Using a password on the command line" || true

VITALS_FORMS=$(docker exec -i "${DB_CONTAINER}" mysql -uroot -proot openemr -N -e \
    "SELECT COUNT(*) FROM forms WHERE formdir='vitals' AND deleted=0;" 2>&1 \
    | grep -v "Using a password" | tail -n1)
echo "  forms registry now has ${VITALS_FORMS} vitals rows."

echo "▶ Step 2/2: calling autoPopulateAllMissingUuids() to backfill uuid_mapping…"
docker exec "${PHP_CONTAINER}" sh -lc \
    'cd /var/www/localhost/htdocs/openemr && php -r "
        \$_GET[\"site\"] = \"default\";
        \$ignoreAuth = true;
        require_once \"interface/globals.php\";
        require_once \"library/uuid.php\";
        autoPopulateAllMissingUuids();
        \$n = (int)\\OpenEMR\\Common\\Database\\QueryUtils::fetchSingleValue(
            \"SELECT COUNT(*) c FROM uuid_mapping\", \"c\", []);
        echo \"  uuid_mapping rows now: \$n\n\";
    "' 2>&1 | grep -v "OpenEMR.WARNING" || true

echo "✓ Vitals are now visible to the FHIR Observation endpoint."
echo "  Verify: curl /chat with 'show me the latest vitals' for a seeded patient."
