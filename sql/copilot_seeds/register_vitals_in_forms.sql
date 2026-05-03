-- Register vitals in the `forms` table.
--
-- Why: the original seed (`sql/seed_clinical_data.sql`) inserts vitals
-- into `form_vitals` but never inserts the corresponding rows into the
-- central `forms` registry. OpenEMR's FHIR Observation endpoint reads
-- vital-signs by joining `forms` (formdir='vitals') → `form_vitals`,
-- so the vitals were invisible to the Co-Pilot agent: every "show me
-- the latest vitals" / "BP trend" question came back empty even
-- though the underlying rows existed. Surfaced by the eval suite on
-- 2026-05-02 (lookup-vitals-ted, multi-step-bp-trend).
--
-- Strategy: INSERT-SELECT join from form_vitals to the matching
-- form_encounter (same pid + same date), guarded by a NOT EXISTS so
-- it's idempotent — safe to re-run on local, dev, qa, prod.
--
-- The canonical seed file (sql/seed_clinical_data.sql) has also been
-- patched to include this registration block inline, so a fresh seed
-- will no longer have the bug. This standalone script exists to fix
-- already-seeded environments without re-running the full seed.

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
