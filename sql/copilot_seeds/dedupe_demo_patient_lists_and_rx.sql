-- Dedupe demo-patient lists + prescriptions.
--
-- DEPRECATED 2026-05-07: demo_patients_v1.sql was regenerated from the
-- deduped local dev DB on this date, so this script is a no-op against
-- any environment seeded from the current dump. Kept for one cycle in
-- case dev/qa/prod are running on the pre-regeneration dump and need
-- to be reconciled before their next reseed. Safe to delete after
-- dev/qa/prod have been reseeded from demo_patients_v1.sql.
--
-- Why (historic): copilot_seed_demo_patients.php was re-run after the
-- idempotency marker (globals.copilot_demo_patients_v1) was cleared.
-- Because the dump regenerated UUIDs and let MySQL auto-increment ids,
-- INSERT IGNORE did not protect against re-insertion, leaving 3x copies
-- of every (lists / prescriptions) row for pids 1, 5, 8. patient_data,
-- form_encounter, form_vitals, immunizations were unaffected.
--
-- Strategy: keep MIN(id) per logical key, delete the rest. Idempotent —
-- safe to re-run on any environment (local, dev, qa, prod).
--
-- Logical key
--   lists:         (pid, type, title, begdate, COALESCE(diagnosis,''))
--   prescriptions: (patient_id, drug, dosage, start_date)
--
-- Rows added by the agent / UI after the original seed (cnt = 1) are
-- left untouched.

DELETE l1 FROM lists l1
JOIN lists l2
  ON l1.pid = l2.pid
 AND l1.type = l2.type
 AND l1.title = l2.title
 AND (l1.begdate <=> l2.begdate)
 AND (COALESCE(l1.diagnosis, '') = COALESCE(l2.diagnosis, ''))
 AND l1.id > l2.id;

DELETE p1 FROM prescriptions p1
JOIN prescriptions p2
  ON p1.patient_id = p2.patient_id
 AND p1.drug = p2.drug
 AND (p1.dosage <=> p2.dosage)
 AND (p1.start_date <=> p2.start_date)
 AND p1.id > p2.id;
