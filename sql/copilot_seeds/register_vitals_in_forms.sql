-- Make seeded vitals visible to the FHIR Observation endpoint.
--
-- Why: the original seed (`sql/seed_clinical_data.sql`) inserts vitals
-- into `form_vitals` but never (a) registers them in the central
-- `forms` table or (b) creates the per-LOINC-code rows in
-- `uuid_mapping` that `FhirObservationVitalsService` needs to expose
-- each measurement as a separate FHIR Observation. Without both
-- backfills, vitals are invisible to FHIR consumers including the
-- Co-Pilot agent: every "show me the latest vitals" / "BP trend"
-- question came back empty even though the rows existed. Surfaced
-- by the eval suite on 2026-05-02 (lookup-vitals-ted,
-- multi-step-bp-trend).
--
-- This script does part (a) — registers each form_vitals row in the
-- `forms` table by joining on (pid, date) to find the matching
-- encounter. Idempotent (NOT EXISTS guard) — safe to re-run.
--
-- Part (b) — backfilling `uuid_mapping` — requires PHP because the
-- LOINC code paths and binary UUIDs are computed by
-- `OpenEMR\Common\Uuid\UuidMapping::createAllMissingResourceUuids()`.
-- Run it after this SQL via:
--
--     scripts/fix-vitals-seed.sh           # local docker stack, both steps
--
-- or, manually inside any OpenEMR shell (local/dev/qa/prod):
--
--     php -r '$_GET["site"]="default"; $ignoreAuth=true;
--             require "interface/globals.php";
--             require "library/uuid.php";
--             autoPopulateAllMissingUuids();'
--
-- The canonical seed file (sql/seed_clinical_data.sql) has been
-- patched to include the part-(a) registration block inline; the
-- part-(b) PHP step must still be invoked after seeding because SQL
-- can't do it. A fresh seed without the PHP step still has invisible
-- vitals.

INSERT INTO forms
    (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir)
SELECT
    v.date,
    e.encounter,
    'Vitals',
    v.id,
    v.pid,
    'admin',
    'Default',
    1,
    0,
    'vitals'
FROM form_vitals v
JOIN form_encounter e
  ON e.pid = v.pid
 AND DATE(e.date) = DATE(v.date)
WHERE NOT EXISTS (
    SELECT 1 FROM forms f
    WHERE f.formdir = 'vitals'
      AND f.form_id = v.id
);
