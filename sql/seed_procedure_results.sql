-- Clinical Co-Pilot — Procedure Results seed
--
-- Seeds the procedure_order / procedure_report / procedure_result tables that
-- back the patient Results timeline (Screen 36, copilot_results.php).
--
-- Idempotent: each row is keyed on a stable PK (procedure_*_id ∈ 70000..79999)
-- so re-running this script is a no-op except for the targeted upsert.
--
-- Rows are scoped to Ted Shaw (pid=1) since that is the demo patient pinned to
-- session pid=1 in dev. Provider 1 (admin / Site Administrator) is referenced
-- so cp_format_provider_name() yields a clean display string.

SET FOREIGN_KEY_CHECKS=0;

-- ============================================================
-- procedure_order — one order per result-bearing encounter.
-- ============================================================

DELETE FROM procedure_order WHERE procedure_order_id BETWEEN 70000 AND 79999;
INSERT INTO procedure_order
  (procedure_order_id, provider_id, patient_id, encounter_id,
   date_collected, date_ordered, order_priority, order_status,
   activity, control_id, lab_id, specimen_type, specimen_location, specimen_volume,
   clinical_hx, procedure_order_type, order_intent, order_abn)
VALUES
  (70001, 1, 1, 1005, '2024-10-30 09:15:00', '2024-10-30 09:15:00', 'normal', 'complete',
   1, '', 0, '', '', '',
   'Annual exam', 'laboratory_test', 'order', 'not_required'),
  (70002, 1, 1, 1004, '2024-07-17 11:00:00', '2024-07-17 11:00:00', 'normal', 'complete',
   1, '', 0, '', '', '',
   'Routine follow-up', 'laboratory_test', 'order', 'not_required'),
  (70003, 1, 1, 1003, '2024-04-08 08:45:00', '2024-04-08 08:45:00', 'normal', 'complete',
   1, '', 0, '', '', '',
   'Diabetes management', 'laboratory_test', 'order', 'not_required'),
  (70004, 1, 1, 1002, '2024-01-22 10:30:00', '2024-01-22 10:30:00', 'normal', 'complete',
   1, '', 0, '', '', '',
   'Elevated creatinine follow-up', 'laboratory_test', 'order', 'not_required'),
  -- Imaging order: chest x-ray during the 04/2024 visit
  (70005, 1, 1, 1003, '2024-04-08 09:00:00', '2024-04-08 09:00:00', 'normal', 'complete',
   1, '', 0, '', '', '',
   'Imaging — CXR for chronic cough', 'imaging', 'order', 'not_required'),
  -- Older annual labs (Oct 2023)
  (70006, 1, 1, 1001, '2023-10-15 09:00:00', '2023-10-15 09:00:00', 'normal', 'complete',
   1, '', 0, '', '', '',
   'Annual wellness labs', 'laboratory_test', 'order', 'not_required');

-- ============================================================
-- procedure_report — one report per order.
-- ============================================================

DELETE FROM procedure_report WHERE procedure_report_id BETWEEN 70000 AND 79999;
INSERT INTO procedure_report
  (procedure_report_id, procedure_order_id, procedure_order_seq,
   date_collected, date_report, source, specimen_num,
   report_status, review_status, report_notes)
VALUES
  (70101, 70001, 1, '2024-10-30 09:15:00', '2024-10-30 14:00:00', 0, '', 'final', 'reviewed', ''),
  (70102, 70002, 1, '2024-07-17 11:00:00', '2024-07-17 16:00:00', 0, '', 'final', 'reviewed', ''),
  (70103, 70003, 1, '2024-04-08 08:45:00', '2024-04-08 13:30:00', 0, '', 'final', 'reviewed', ''),
  (70104, 70004, 1, '2024-01-22 10:30:00', '2024-01-22 15:30:00', 0, '', 'final', 'reviewed', ''),
  (70105, 70005, 1, '2024-04-08 09:00:00', '2024-04-08 11:00:00', 0, '', 'final', 'reviewed', ''),
  (70106, 70006, 1, '2023-10-15 09:00:00', '2023-10-15 14:30:00', 0, '', 'final', 'reviewed', '');

-- ============================================================
-- procedure_result — multiple result codes per report.
-- ============================================================

DELETE FROM procedure_result WHERE procedure_result_id BETWEEN 70000 AND 79999;
INSERT INTO procedure_result
  (procedure_result_id, procedure_report_id, result_data_type,
   result_code, result_text, date, facility, units, result, `range`,
   abnormal, comments, document_id, result_status)
VALUES
  -- 10/2024 panel — HbA1c, creatinine, eGFR (Ted is trending worse)
  (70201, 70101, 'N', '4548-4',  'HbA1c',         '2024-10-30 14:00:00', 'Quest', '%',         '7.9',  '<7.0',     'high',   '', 0, 'final'),
  (70202, 70101, 'N', '2160-0',  'Creatinine',    '2024-10-30 14:00:00', 'Quest', 'mg/dL',     '1.7',  '0.7-1.3',  'high',   '', 0, 'final'),
  (70203, 70101, 'N', '33914-3', 'eGFR',          '2024-10-30 14:00:00', 'Quest', 'mL/min',    '54',   '>60',      'low',    '', 0, 'final'),
  (70204, 70101, 'N', '2093-3',  'Total Cholesterol', '2024-10-30 14:00:00', 'Quest', 'mg/dL', '182', '<200',     'no',     '', 0, 'final'),
  (70205, 70101, 'N', '18262-6', 'LDL Cholesterol', '2024-10-30 14:00:00', 'Quest', 'mg/dL',   '98',   '<100',     'no',     '', 0, 'final'),

  -- 07/2024 panel — Lipids
  (70210, 70102, 'N', '2093-3',  'Total Cholesterol', '2024-07-17 16:00:00', 'Quest', 'mg/dL', '198', '<200',     'no',     '', 0, 'final'),
  (70211, 70102, 'N', '18262-6', 'LDL Cholesterol', '2024-07-17 16:00:00', 'Quest', 'mg/dL',   '118',  '<100',     'high',   '', 0, 'final'),
  (70212, 70102, 'N', '2085-9',  'HDL Cholesterol', '2024-07-17 16:00:00', 'Quest', 'mg/dL',   '42',   '>40',      'no',     '', 0, 'final'),
  (70213, 70102, 'N', '4548-4',  'HbA1c',         '2024-07-17 16:00:00', 'Quest', '%',         '7.2',  '<7.0',     'high',   '', 0, 'final'),

  -- 04/2024 panel — HbA1c + glucose
  (70220, 70103, 'N', '4548-4',  'HbA1c',         '2024-04-08 13:30:00', 'Quest', '%',         '7.4',  '<7.0',     'high',   '', 0, 'final'),
  (70221, 70103, 'N', '2345-7',  'Glucose',       '2024-04-08 13:30:00', 'Quest', 'mg/dL',     '152',  '70-99',    'high',   '', 0, 'final'),

  -- 04/2024 imaging — chest x-ray
  (70230, 70105, 'S', '36643-5', 'Chest X-ray',   '2024-04-08 11:00:00', 'RFM Imaging', '', 'No acute cardiopulmonary findings',
   '', 'no', 'BIRADS not applicable; mild bibasilar atelectasis', 0, 'final'),

  -- 01/2024 — kidney follow-up
  (70240, 70104, 'N', '2160-0',  'Creatinine',    '2024-01-22 15:30:00', 'Quest', 'mg/dL',     '1.5',  '0.7-1.3',  'high',   '', 0, 'final'),
  (70241, 70104, 'N', '33914-3', 'eGFR',          '2024-01-22 15:30:00', 'Quest', 'mL/min',    '61',   '>60',      'no',     '', 0, 'final'),
  (70242, 70104, 'N', '4548-4',  'HbA1c',         '2024-01-22 15:30:00', 'Quest', '%',         '7.6',  '<7.0',     'high',   '', 0, 'final'),

  -- 10/2023 baseline
  (70250, 70106, 'N', '4548-4',  'HbA1c',         '2023-10-15 14:30:00', 'Quest', '%',         '8.4',  '<7.0',     'critical', 'New T2DM diagnosis', 0, 'final'),
  (70251, 70106, 'N', '2160-0',  'Creatinine',    '2023-10-15 14:30:00', 'Quest', 'mg/dL',     '1.3',  '0.7-1.3',  'no',     '', 0, 'final'),
  (70252, 70106, 'N', '2345-7',  'Glucose',       '2023-10-15 14:30:00', 'Quest', 'mg/dL',     '184',  '70-99',    'high',   '', 0, 'final');

SET FOREIGN_KEY_CHECKS=1;
