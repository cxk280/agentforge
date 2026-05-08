-- AgentForge clinical demo data — labs, billing, inventory.
--
-- Idempotent (INSERT IGNORE everywhere with explicit ids in the 90000+
-- range so we never collide with auto-increment growth or upstream
-- demo dumps). Re-running on an already-seeded DB is a no-op.
--
-- Cohort: pids 1 (Ted Shaw), 5 (Farrah Rolle), 8 (Nora Cohen).
-- Encounters referenced are from demo_patients_v1.sql (1001-1005 = Ted,
-- 2001-2005 = Farrah, 3001-3005 = Nora). Provider 5 = Dr. Eduardo Rivera, MD.
--
-- Wires:
--   procedure_order   →  Patient Results, Lab Overview, Pending Review,
--   procedure_report      Lab Documents pages
--   procedure_result
--   billing           →  Aging, Billing Manager
--   ar_session        →  Ledger reconciliations
--   ar_activity
--   drug_inventory    →  Inventory page
--
-- Imported by interface/super/copilot_seed_clinical_data.php (which leaves
-- the file untouched and just runs it via $sqlStatementNoLog).

/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

-- ─────────────────────────────────────────────────────────────────────
-- procedure_order: lab orders attached to existing encounters.
-- ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `procedure_order`
  (procedure_order_id, provider_id, patient_id, encounter_id,
   date_collected, date_ordered, order_priority, order_status,
   activity, lab_id, specimen_type, specimen_location, specimen_volume,
   procedure_order_type)
VALUES
  -- Ted Shaw (pid=1) — recent diabetes panel
  (90001, 5, 1, 1005, '2024-10-30 09:30:00', '2024-10-30 09:15:00', 'normal', 'pending', 1, 0, 'serum', '', '', 'laboratory_test'),
  (90002, 5, 1, 1005, '2024-10-30 09:30:00', '2024-10-30 09:15:00', 'normal', 'pending', 1, 0, 'serum', '', '', 'laboratory_test'),
  (90003, 5, 1, 1005, '2024-10-30 09:30:00', '2024-10-30 09:15:00', 'normal', 'pending', 1, 0, 'serum', '', '', 'laboratory_test'),
  (90004, 5, 1, 1004, '2024-07-17 11:15:00', '2024-07-17 11:00:00', 'normal', 'pending', 1, 0, 'serum', '', '', 'laboratory_test'),
  -- Nora Cohen (pid=8) — annual labs + thyroid
  (90005, 5, 8, 3005, '2024-10-08 11:15:00', '2024-10-08 11:00:00', 'normal', 'pending', 1, 0, 'serum', '', '', 'laboratory_test'),
  (90006, 5, 8, 3005, '2024-10-08 11:15:00', '2024-10-08 11:00:00', 'normal', 'pending', 1, 0, 'serum', '', '', 'laboratory_test'),
  (90007, 5, 8, 3003, '2024-02-14 10:15:00', '2024-02-14 10:00:00', 'normal', 'pending', 1, 0, 'serum', '', '', 'laboratory_test'),
  -- Farrah Rolle (pid=5) — cholesterol + recent labs
  (90008, 5, 5, 2005, '2024-11-14 08:45:00', '2024-11-14 08:30:00', 'normal', 'pending', 1, 0, 'serum', '', '', 'laboratory_test'),
  (90009, 5, 5, 2005, '2024-11-14 08:45:00', '2024-11-14 08:30:00', 'normal', 'pending', 1, 0, 'serum', '', '', 'laboratory_test'),
  -- Ted — imaging order (different bucket)
  (90010, 5, 1, 1005, '2024-10-30 09:30:00', '2024-10-30 09:15:00', 'normal', 'pending', 1, 0, '', '', '', 'imaging');

-- ─────────────────────────────────────────────────────────────────────
-- procedure_report: one report per order (1:1 for the demo).
-- ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `procedure_report`
  (procedure_report_id, procedure_order_id, procedure_order_seq,
   date_collected, date_report, source, specimen_num, report_status, review_status)
VALUES
  (90001, 90001, 1, '2024-10-30 09:30:00', '2024-10-31 14:00:00', 5, 'SP-T1-2410', 'final', 'reviewed'),
  (90002, 90002, 1, '2024-10-30 09:30:00', '2024-10-31 14:00:00', 5, 'SP-T1-2410', 'final', 'reviewed'),
  (90003, 90003, 1, '2024-10-30 09:30:00', '2024-10-31 14:00:00', 5, 'SP-T1-2410', 'final', 'reviewed'),
  (90004, 90004, 1, '2024-07-17 11:15:00', '2024-07-18 09:00:00', 5, 'SP-T1-2407', 'final', 'reviewed'),
  (90005, 90005, 1, '2024-10-08 11:15:00', '2024-10-09 13:00:00', 5, 'SP-N8-2410', 'final', 'reviewed'),
  (90006, 90006, 1, '2024-10-08 11:15:00', '2024-10-09 13:00:00', 5, 'SP-N8-2410', 'final', 'reviewed'),
  (90007, 90007, 1, '2024-02-14 10:15:00', '2024-02-15 11:00:00', 5, 'SP-N8-2402', 'final', 'reviewed'),
  (90008, 90008, 1, '2024-11-14 08:45:00', '2024-11-15 10:00:00', 5, 'SP-F5-2411', 'final', 'reviewed'),
  (90009, 90009, 1, '2024-11-14 08:45:00', '2024-11-15 10:00:00', 5, 'SP-F5-2411', 'final', 'reviewed'),
  (90010, 90010, 1, '2024-10-30 09:30:00', '2024-11-01 16:00:00', 5, 'IMG-T1-2410', 'final', 'reviewed');

-- ─────────────────────────────────────────────────────────────────────
-- procedure_result: the actual values clinicians read on the lab pages.
-- ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `procedure_result`
  (procedure_result_id, procedure_report_id, result_data_type, result_code,
   result_text, date, facility, units, result, `range`, abnormal, result_status)
VALUES
  -- Ted Shaw (pid=1) — diabetes panel, Oct 2024 (abnormal A1C)
  (90001, 90001, 'N', '4548-4', 'Hemoglobin A1c',           '2024-10-31 14:00:00', 'LabCorp', '%',     '7.9', '4.0-5.6',     'high',     'final'),
  (90002, 90002, 'N', '2345-7', 'Glucose, fasting',         '2024-10-31 14:00:00', 'LabCorp', 'mg/dL', '142', '70-99',       'high',     'final'),
  (90003, 90003, 'N', '13457-7','LDL cholesterol',          '2024-10-31 14:00:00', 'LabCorp', 'mg/dL', '142', '<100',        'high',     'final'),
  (90004, 90004, 'N', '4548-4', 'Hemoglobin A1c',           '2024-07-18 09:00:00', 'LabCorp', '%',     '7.4', '4.0-5.6',     'high',     'final'),
  -- Nora Cohen (pid=8) — TSH abnormal + lipids in range, Oct 2024
  (90005, 90005, 'N', '3016-3', 'TSH',                      '2024-10-09 13:00:00', 'Quest',   'mIU/L', '6.8', '0.4-4.5',     'high',     'final'),
  (90006, 90006, 'N', '2093-3', 'Total cholesterol',        '2024-10-09 13:00:00', 'Quest',   'mg/dL', '198', '<200',        'normal',   'final'),
  (90007, 90007, 'N', '3016-3', 'TSH',                      '2024-02-15 11:00:00', 'Quest',   'mIU/L', '5.2', '0.4-4.5',     'high',     'final'),
  -- Farrah Rolle (pid=5) — lipid + glucose, Nov 2024
  (90008, 90008, 'N', '13457-7','LDL cholesterol',          '2024-11-15 10:00:00', 'LabCorp', 'mg/dL', '128', '<100',        'high',     'final'),
  (90009, 90009, 'N', '2345-7', 'Glucose, fasting',         '2024-11-15 10:00:00', 'LabCorp', 'mg/dL', '94',  '70-99',       'normal',   'final'),
  -- Ted — chest x-ray imaging result (different bucket)
  (90010, 90010, 'S', 'CXR-PA', 'Chest X-ray, PA + lateral','2024-11-01 16:00:00', 'RFM Imaging', '', 'Normal cardiomediastinal silhouette. No acute findings.', '', 'normal', 'final');

-- ─────────────────────────────────────────────────────────────────────
-- billing: charges from existing encounters.
-- ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `billing`
  (id, date, code_type, code, pid, provider_id, user, groupname, authorized,
   encounter, code_text, billed, activity, payer_id, fee, units, justify)
VALUES
  -- Ted (pid=1)
  (90001, '2024-10-30 09:15:00', 'CPT4',  '99214', 1, 5, 1, 'Default', 1, 1005, 'Office visit, established, level 4 (25 min)', 1, 1, 0, 220.00, 1, 'E11.9'),
  (90002, '2024-10-30 09:15:00', 'CPT4',  '85025', 1, 5, 1, 'Default', 1, 1005, 'Complete blood count w/ auto diff',           1, 1, 0,  35.00, 1, 'E11.9'),
  (90003, '2024-10-30 09:15:00', 'CPT4',  '83036', 1, 5, 1, 'Default', 1, 1005, 'Hemoglobin A1c',                              1, 1, 0,  45.00, 1, 'E11.9'),
  (90004, '2024-07-17 11:00:00', 'CPT4',  '99213', 1, 5, 1, 'Default', 1, 1004, 'Office visit, established, level 3 (15 min)', 1, 1, 0, 145.00, 1, 'E11.9'),
  -- Nora (pid=8)
  (90005, '2024-10-08 11:00:00', 'CPT4',  '99396', 8, 5, 1, 'Default', 1, 3005, 'Periodic comprehensive preventive medicine, 40-64 yrs', 1, 1, 0, 320.00, 1, 'Z00.00'),
  (90006, '2024-10-08 11:00:00', 'CPT4',  '84443', 8, 5, 1, 'Default', 1, 3005, 'Thyroid stimulating hormone (TSH)',           1, 1, 0,  68.00, 1, 'E03.9'),
  (90007, '2024-02-14 10:00:00', 'CPT4',  '99213', 8, 5, 1, 'Default', 1, 3003, 'Office visit, established, level 3 (15 min)', 1, 1, 0, 145.00, 1, 'F33.1'),
  -- Farrah (pid=5)
  (90008, '2024-11-14 08:30:00', 'CPT4',  '99214', 5, 5, 1, 'Default', 1, 2005, 'Office visit, established, level 4 (25 min)', 1, 1, 0, 220.00, 1, 'J45.40'),
  (90009, '2024-11-14 08:30:00', 'CPT4',  '80061', 5, 5, 1, 'Default', 1, 2005, 'Lipid panel',                                 1, 1, 0,  90.00, 1, 'E78.5'),
  (90010, '2024-07-02 11:30:00', 'CPT4',  '99213', 5, 5, 1, 'Default', 1, 2004, 'Office visit, established, level 3 (15 min)', 1, 1, 0, 145.00, 1, 'J45.40');

-- ─────────────────────────────────────────────────────────────────────
-- ar_session: insurance + patient payments deposited against the
-- charges above. Two ar_session rows: one BCBS payer batch, one
-- patient self-pay.
-- ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `ar_session`
  (session_id, payer_id, user_id, closed, reference, check_date, deposit_date,
   pay_total, modified_time, global_amount, payment_type, description,
   adjustment_code, post_to_date, patient_id, payment_method)
VALUES
  (90001, 0, 1, 1, 'BCBS-EFT-241115-1', '2024-11-12', '2024-11-15',  640.00, '2024-11-15 10:00:00', 0.00, 'insurance', 'BCBS PPO bulk EFT', '', '2024-11-15', 0, 'eft'),
  (90002, 0, 1, 1, 'PAT-COPAY-241015',  '2024-10-15', '2024-10-15',   80.00, '2024-10-15 10:30:00', 0.00, 'patient',   'Patient copay',     '', '2024-10-15', 0, 'card');

-- ─────────────────────────────────────────────────────────────────────
-- ar_activity: line-level postings against the billing rows above.
-- (encounter, code) pair links back to the billing row.
-- ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `ar_activity`
  (pid, encounter, sequence_no, code_type, code, modifier, payer_type,
   post_time, post_user, session_id, post_date, pay_amount, adj_amount,
   memo, modified_time, follow_up, follow_up_note, account_code, reason_code)
VALUES
  -- Ted: BCBS paid most of $300 on 99214/85025/83036
  (1, 1005, 90001, 'CPT4', '99214', '', 1, '2024-11-15 10:00:00', 1, 90001, '2024-11-15', 175.00, 45.00, 'BCBS contract',         '2024-11-15 10:00:00', '', '', 'PP', ''),
  (1, 1005, 90002, 'CPT4', '85025', '', 1, '2024-11-15 10:00:00', 1, 90001, '2024-11-15',  28.00,  7.00, 'BCBS contract',         '2024-11-15 10:00:00', '', '', 'PP', ''),
  (1, 1005, 90003, 'CPT4', '83036', '', 1, '2024-11-15 10:00:00', 1, 90001, '2024-11-15',  37.00,  8.00, 'BCBS contract',         '2024-11-15 10:00:00', '', '', 'PP', ''),
  -- Nora: BCBS paid 99396 fully
  (8, 3005, 90005, 'CPT4', '99396', '', 1, '2024-11-15 10:00:00', 1, 90001, '2024-11-15', 280.00, 40.00, 'BCBS preventive 100%',  '2024-11-15 10:00:00', '', '', 'PP', ''),
  (8, 3005, 90006, 'CPT4', '84443', '', 1, '2024-11-15 10:00:00', 1, 90001, '2024-11-15',  54.00, 14.00, 'BCBS contract',         '2024-11-15 10:00:00', '', '', 'PP', ''),
  -- Patient copays
  (1, 1005, 90004, 'CPT4', '99214', '', 0, '2024-10-15 10:30:00', 1, 90002, '2024-10-15',  40.00,  0.00, 'Copay collected (visa)','2024-10-15 10:30:00', '', '', 'PP', ''),
  (8, 3005, 90007, 'CPT4', '99396', '', 0, '2024-10-15 10:30:00', 1, 90002, '2024-10-15',  40.00,  0.00, 'Copay collected (visa)','2024-10-15 10:30:00', '', '', 'PP', '');

-- ─────────────────────────────────────────────────────────────────────
-- drug_inventory: on-hand stock for the existing demo drugs.
-- ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `drug_inventory`
  (inventory_id, drug_id, lot_number, expiration, manufacturer, on_hand,
   warehouse_id, vendor_id)
VALUES
  (90001,  1, 'LSN-T010-J2024',   '2026-12-31', 'Lupin Pharmaceuticals', 240, 'main', 0),
  (90002,  2, 'LSN-T020-K2024',   '2026-12-31', 'Lupin Pharmaceuticals', 180, 'main', 0),
  (90003,  3, 'MTF-1000-M2025',   '2027-03-31', 'Teva Pharmaceuticals',  320, 'main', 0),
  (90004,  4, 'LVT-050-N2024',    '2026-09-30', 'Mylan',                  95, 'main', 0),
  (90005,  5, 'ATV-040-P2025',    '2027-06-30', 'Pfizer',                160, 'main', 0),
  (90006,  6, 'EMP-010-Q2025',    '2027-09-30', 'Boehringer Ingelheim',   72, 'main', 0),
  (90007,  7, 'AMX-500-R2024',    '2026-06-30', 'Sandoz',                  8, 'main', 0),  -- low stock for visual
  (90008,  8, 'IBU-400-S2025',    '2027-12-31', 'Generic',               420, 'main', 0),
  (90009,  9, 'PRV-040-T2025',    '2027-04-30', 'AstraZeneca',           120, 'main', 0),
  (90010, 10, 'GBP-300-U2024',    '2026-08-31', 'Apotex',                  0, 'main', 0); -- out of stock for visual

/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
