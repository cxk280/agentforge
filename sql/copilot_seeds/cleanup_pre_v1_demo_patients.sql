-- Cleanup script — bring deployed envs (dev/qa/prod) into parity with the
-- regenerated demo_patients_v1.sql cohort.
--
-- Why this exists
-- ---------------
-- demo_patients_v1.sql was regenerated 2026-05-07 to a tighter cohort
-- (pids 1, 4, 5, 8, 17). Deployed envs were seeded from the prior
-- 15-patient dump, which used non-sequential pids — they still carry
-- pids 18, 22, 25, 26, 30, 34, 35, 40, 41, 42 (Richard Jones,
-- Ilias Jenane, John Dockerty, James Janssen, Jason Binder, Robert
-- Dickey, Jillian Mahoney, Wallace Buckley, Brent Perez, QA44893
-- TestPatient). None are referenced by the eval suite or any demo
-- flow, but they show up in the patients list and inflate "real-data"
-- counts in the chat sidebar / demographics search. This script
-- deletes them from the six tables the original dump touched, so
-- dev/qa/prod match the new dump's shape.
--
-- Scope
-- -----
-- Only the six tables that demo_patients_v1.sql canonical inserts into:
--   patient_data, form_encounter, form_vitals, lists, prescriptions,
--   immunizations.
-- We deliberately do NOT touch:
--   - audit_master / log / extended_log → compliance evidence; keep.
--   - forms → if stock OpenEMR demo data populated `forms` rows for
--     these pids, they'll persist as harmless orphans. Cleaning them
--     up is out of scope; the demo doesn't surface them.
--   - any other stock-OpenEMR table not in the seed dump — out of
--     scope, those rows weren't ours.
--
-- Idempotency
-- -----------
-- Pure DELETE statements scoped by `pid IN (...)`. Running this twice
-- is identical to running it once. Running this against an env that
-- never had those pids (e.g. local dev as of 2026-05-07) is a no-op —
-- 0 rows affected on every statement.
--
-- Operational order
-- -----------------
-- 1. Run on local first (verify no-op).
-- 2. Run on dev → verify counts → smoke-test the agent against pids
--    1, 4, 5, 8, 17.
-- 3. Run on qa, then prod, with the same verify-then-smoke loop.
--
-- Rollback
-- --------
-- These rows aren't recoverable from the cleanup itself. To restore
-- them, reseed from the historic 15-patient dump (preserved in git
-- history at sql/copilot_seeds/demo_patients_v1.sql @ HEAD~1 of the
-- regeneration commit). For the purposes of the Gauntlet demo, we
-- accept this as one-way.

-- Order: child rows first, parents last. patient_data is the parent of
-- everything else by pid; deleting it last avoids any FK noise even
-- though OpenEMR's tables aren't FK-constrained.

DELETE FROM form_vitals    WHERE pid        IN (18, 22, 25, 26, 30, 34, 35, 40, 41, 42);
DELETE FROM form_encounter WHERE pid        IN (18, 22, 25, 26, 30, 34, 35, 40, 41, 42);
DELETE FROM lists          WHERE pid        IN (18, 22, 25, 26, 30, 34, 35, 40, 41, 42);
DELETE FROM prescriptions  WHERE patient_id IN (18, 22, 25, 26, 30, 34, 35, 40, 41, 42);
DELETE FROM immunizations  WHERE patient_id IN (18, 22, 25, 26, 30, 34, 35, 40, 41, 42);
DELETE FROM patient_data   WHERE pid        IN (18, 22, 25, 26, 30, 34, 35, 40, 41, 42);

-- Verification query — run after to confirm parity:
--   SELECT 'patient_data',   COUNT(*) FROM patient_data   WHERE pid        IN (2,3,6,7,9,10,11,12,13,14,15)
--   UNION ALL SELECT 'form_encounter', COUNT(*) FROM form_encounter WHERE pid        IN (2,3,6,7,9,10,11,12,13,14,15)
--   UNION ALL SELECT 'form_vitals',    COUNT(*) FROM form_vitals    WHERE pid        IN (2,3,6,7,9,10,11,12,13,14,15)
--   UNION ALL SELECT 'lists',          COUNT(*) FROM lists          WHERE pid        IN (2,3,6,7,9,10,11,12,13,14,15)
--   UNION ALL SELECT 'prescriptions',  COUNT(*) FROM prescriptions  WHERE patient_id IN (2,3,6,7,9,10,11,12,13,14,15)
--   UNION ALL SELECT 'immunizations',  COUNT(*) FROM immunizations  WHERE patient_id IN (2,3,6,7,9,10,11,12,13,14,15);
-- Expected: 0 across the board.
