-- Clinical Co-Pilot — Pending Review queue seed
--
-- Seeds cross-patient procedure_order / procedure_report / procedure_result
-- rows that back the Pending Review queue (Screen 35,
-- copilot_pending_review.php). All rows live in the dedicated 80000–89999 ID
-- range so they never collide with the per-patient timeline seed
-- (`seed_procedure_results.sql`, 70000–79999) or with anything inserted via
-- the application.
--
-- Idempotent: each row is keyed on a stable PK and re-running this script is
-- a no-op for unchanged rows. We use INSERT … ON DUPLICATE KEY UPDATE so
-- review_status that has been flipped via the UI ("reviewed") is preserved
-- on subsequent runs (only fields the seed cares about are reasserted).
--
-- Patients referenced (must exist in patient_data):
--   pid=1  Ted Shaw         (HbA1c critical)
--   pid=4  Eduardo Perez    (CBC + CMP routine)
--   pid=5  Farrah Rolle     (Lipid panel abnormal)
--   pid=8  Nora Cohen       (TSH critical)
--   pid=17 Jim Moses        (UA abnormal)
--   pid=18 Richard Jones    (Glucose routine)
--   pid=22 Ilias Jenane     (MRI Lumbar routine)
--   pid=25 John Dockerty    (CT Chest routine)
--   pid=26 James Janssen    (ED summary doc routine)
--   pid=30 Jason Binder     (Sleep study routine)
--   pid=34 Robert Dickey    (Patient message)
--
-- Providers referenced:
--   id=5  Dr. Rivera (MD)   — primary signer
--   id=6  Dr. Park   (DO)
--   id=7  Dr. Patel  (MD)

SET FOREIGN_KEY_CHECKS=0;

-- ============================================================
-- procedure_order — one order per pending result.
-- date_collected is anchored relative to NOW() so the "h ago"
-- column in the UI looks lively no matter when this is run.
-- ============================================================

INSERT INTO procedure_order
  (procedure_order_id, provider_id, patient_id, encounter_id,
   date_collected, date_ordered, order_priority, order_status,
   activity, control_id, lab_id, specimen_type, specimen_location, specimen_volume,
   clinical_hx, procedure_order_type, order_intent, order_abn)
VALUES
  (80001, 5, 1,  0, DATE_SUB(NOW(), INTERVAL 2 HOUR),  DATE_SUB(NOW(), INTERVAL 2 HOUR),  'high',   'complete', 1, '', 0, '', '', '', 'A1c follow-up',           'laboratory_test', 'order', 'not_required'),
  (80002, 5, 4,  0, DATE_SUB(NOW(), INTERVAL 3 HOUR),  DATE_SUB(NOW(), INTERVAL 3 HOUR),  'normal', 'complete', 1, '', 0, '', '', '', 'Annual labs',             'laboratory_test', 'order', 'not_required'),
  (80003, 6, 22, 0, DATE_SUB(NOW(), INTERVAL 4 HOUR),  DATE_SUB(NOW(), INTERVAL 4 HOUR),  'normal', 'complete', 1, '', 0, '', '', '', 'Low back pain',           'imaging',         'order', 'not_required'),
  (80004, 5, 5,  0, DATE_SUB(NOW(), INTERVAL 5 HOUR),  DATE_SUB(NOW(), INTERVAL 5 HOUR),  'normal', 'complete', 1, '', 0, '', '', '', 'Lipid screening',         'laboratory_test', 'order', 'not_required'),
  (80005, 7, 30, 0, DATE_SUB(NOW(), INTERVAL 6 HOUR),  DATE_SUB(NOW(), INTERVAL 6 HOUR),  'normal', 'complete', 1, '', 0, '', '', '', 'Snoring + daytime fatigue','procedure',      'order', 'not_required'),
  (80006, 5, 8,  0, DATE_SUB(NOW(), INTERVAL 7 HOUR),  DATE_SUB(NOW(), INTERVAL 7 HOUR),  'high',   'complete', 1, '', 0, '', '', '', 'Hypothyroid recheck',     'laboratory_test', 'order', 'not_required'),
  (80007, 5, 34, 0, DATE_SUB(NOW(), INTERVAL 8 HOUR),  DATE_SUB(NOW(), INTERVAL 8 HOUR),  'normal', 'complete', 1, '', 0, '', '', '', 'Patient message',         'document',        'order', 'not_required'),
  (80008, 6, 17, 0, DATE_SUB(NOW(), INTERVAL 26 HOUR), DATE_SUB(NOW(), INTERVAL 26 HOUR), 'normal', 'complete', 1, '', 0, '', '', '', 'Dysuria',                 'laboratory_test', 'order', 'not_required'),
  (80009, 6, 25, 0, DATE_SUB(NOW(), INTERVAL 28 HOUR), DATE_SUB(NOW(), INTERVAL 28 HOUR), 'normal', 'complete', 1, '', 0, '', '', '', 'Chest pain — r/o PE',     'imaging',         'order', 'not_required'),
  (80010, 7, 26, 0, DATE_SUB(NOW(), INTERVAL 30 HOUR), DATE_SUB(NOW(), INTERVAL 30 HOUR), 'normal', 'complete', 1, '', 0, '', '', '', 'ED visit summary',        'document',        'order', 'not_required'),
  (80011, 5, 18, 0, DATE_SUB(NOW(), INTERVAL 50 HOUR), DATE_SUB(NOW(), INTERVAL 50 HOUR), 'normal', 'complete', 1, '', 0, '', '', '', 'Glucose recheck',         'laboratory_test', 'order', 'not_required')
ON DUPLICATE KEY UPDATE
  patient_id = VALUES(patient_id),
  provider_id = VALUES(provider_id),
  procedure_order_type = VALUES(procedure_order_type),
  clinical_hx = VALUES(clinical_hx),
  order_priority = VALUES(order_priority),
  order_status = VALUES(order_status);

-- ============================================================
-- procedure_report — one report per order. review_status='not reviewed'
-- so they show up in the Pending queue. The first run inserts the row;
-- subsequent runs leave review_status alone if it has been flipped via
-- the UI to 'reviewed'.
-- ============================================================

INSERT INTO procedure_report
  (procedure_report_id, procedure_order_id, procedure_order_seq,
   date_collected, date_report, source, specimen_num,
   report_status, review_status, report_notes)
VALUES
  (80101, 80001, 1, DATE_SUB(NOW(), INTERVAL 2 HOUR),  DATE_SUB(NOW(), INTERVAL 2 HOUR),  0, 'Q-29101', 'final', 'not reviewed', 'Quest Diagnostics'),
  (80102, 80002, 1, DATE_SUB(NOW(), INTERVAL 3 HOUR),  DATE_SUB(NOW(), INTERVAL 3 HOUR),  0, 'Q-29102', 'final', 'not reviewed', 'Quest Diagnostics'),
  (80103, 80003, 1, DATE_SUB(NOW(), INTERVAL 4 HOUR),  DATE_SUB(NOW(), INTERVAL 4 HOUR),  0, 'IM-3401', 'final', 'not reviewed', 'Radiology Associates'),
  (80104, 80004, 1, DATE_SUB(NOW(), INTERVAL 5 HOUR),  DATE_SUB(NOW(), INTERVAL 5 HOUR),  0, 'Q-29103', 'final', 'not reviewed', 'Quest Diagnostics'),
  (80105, 80005, 1, DATE_SUB(NOW(), INTERVAL 6 HOUR),  DATE_SUB(NOW(), INTERVAL 6 HOUR),  0, 'SS-118',  'final', 'not reviewed', 'Sleep Lab'),
  (80106, 80006, 1, DATE_SUB(NOW(), INTERVAL 7 HOUR),  DATE_SUB(NOW(), INTERVAL 7 HOUR),  0, 'Q-29104', 'final', 'not reviewed', 'Quest Diagnostics'),
  (80107, 80007, 1, DATE_SUB(NOW(), INTERVAL 8 HOUR),  DATE_SUB(NOW(), INTERVAL 8 HOUR),  0, '',        'final', 'not reviewed', 'Patient portal'),
  (80108, 80008, 1, DATE_SUB(NOW(), INTERVAL 26 HOUR), DATE_SUB(NOW(), INTERVAL 26 HOUR), 0, 'Q-29105', 'final', 'not reviewed', 'Quest Diagnostics'),
  (80109, 80009, 1, DATE_SUB(NOW(), INTERVAL 28 HOUR), DATE_SUB(NOW(), INTERVAL 28 HOUR), 0, 'IM-3402', 'final', 'not reviewed', 'Radiology Associates'),
  (80110, 80010, 1, DATE_SUB(NOW(), INTERVAL 30 HOUR), DATE_SUB(NOW(), INTERVAL 30 HOUR), 0, '',        'final', 'not reviewed', 'St. Mary ED'),
  (80111, 80011, 1, DATE_SUB(NOW(), INTERVAL 50 HOUR), DATE_SUB(NOW(), INTERVAL 50 HOUR), 0, 'Q-29106', 'final', 'not reviewed', 'Quest Diagnostics')
ON DUPLICATE KEY UPDATE
  procedure_order_id = VALUES(procedure_order_id),
  date_collected = VALUES(date_collected),
  date_report = VALUES(date_report),
  specimen_num = VALUES(specimen_num),
  report_notes = VALUES(report_notes);
  -- review_status intentionally NOT updated — UI flips to 'reviewed' must persist.

-- ============================================================
-- procedure_result — one or more result codes per report. The "primary"
-- result-of-interest for the queue row is the FIRST result in each
-- report (lowest procedure_result_id).
-- ============================================================

INSERT INTO procedure_result
  (procedure_result_id, procedure_report_id, result_data_type,
   result_code, result_text, date, facility, units, result, `range`,
   abnormal, comments, document_id, result_status)
VALUES
  -- 80101 — Ted Shaw, HbA1c critical
  (80201, 80101, 'N', '4548-4',  'HbA1c',                   DATE_SUB(NOW(), INTERVAL 2 HOUR), 'Quest', '%',     '7.9',     '<7.0',     'critical', 'Up from 7.2% (11/15/2025)',     0, 'final'),
  -- 80102 — Eduardo Perez, CBC + CMP routine
  (80202, 80102, 'N', '57021-8', 'CBC w/ Differential',     DATE_SUB(NOW(), INTERVAL 3 HOUR), 'Quest', '',      'Normal',  '',         'no',       'All counts within reference',   0, 'final'),
  (80203, 80102, 'N', '24323-8', 'Comprehensive Metabolic', DATE_SUB(NOW(), INTERVAL 3 HOUR), 'Quest', '',      'Normal',  '',         'no',       'All values within reference',   0, 'final'),
  -- 80103 — Ilias Jenane, MRI Lumbar
  (80204, 80103, 'L', '24531-6', 'MRI Lumbar Spine',        DATE_SUB(NOW(), INTERVAL 4 HOUR), 'Radiology', '', 'Mild bulge L4-L5', '', 'no',       'No central canal stenosis',     0, 'final'),
  -- 80104 — Farrah Rolle, Lipid panel abnormal
  (80205, 80104, 'N', '57698-3', 'Lipid Panel',             DATE_SUB(NOW(), INTERVAL 5 HOUR), 'Quest', 'mg/dL', '142',     '<100',     'high',     'LDL 142 — recommend statin',    0, 'final'),
  -- 80105 — Jason Binder, Sleep study
  (80206, 80105, 'L', '34082-1', 'Polysomnography',         DATE_SUB(NOW(), INTERVAL 6 HOUR), 'Sleep Lab', '', 'Mild OSA, AHI 12', '', 'no',       'CPAP titration recommended',    0, 'final'),
  -- 80106 — Nora Cohen, TSH critical
  (80207, 80106, 'N', '3016-3',  'TSH',                     DATE_SUB(NOW(), INTERVAL 7 HOUR), 'Quest', 'uIU/mL','12.4',    '0.4-4.0',  'critical', 'TSH 12.4 — increase levo',      0, 'final'),
  -- 80107 — Robert Dickey, patient message (no result body, just doc)
  (80208, 80107, 'L', 'MSG-RFL', 'Patient message',         DATE_SUB(NOW(), INTERVAL 8 HOUR), 'Portal',    '', 'RE: refill request', '', '',     'Patient asking for Lisinopril refill', 0, 'final'),
  -- 80108 — Jim Moses, UA leuk esterase abnormal
  (80209, 80108, 'N', '5799-2',  'Urinalysis — Leuk Est',   DATE_SUB(NOW(), INTERVAL 26 HOUR),'Quest', '',      '+ leuk esterase','Negative','high', 'Possible UTI — consider abx',  0, 'final'),
  -- 80109 — John Dockerty, CT chest routine
  (80210, 80109, 'L', '24627-2', 'CT Chest w/o contrast',   DATE_SUB(NOW(), INTERVAL 28 HOUR),'Radiology','', 'No acute findings', '', 'no',     'No PE, no infiltrate',          0, 'final'),
  -- 80110 — James Janssen, ED summary doc routine
  (80211, 80110, 'L', '11526-1', 'ED discharge summary',    DATE_SUB(NOW(), INTERVAL 30 HOUR),'St. Mary ED','','URI, discharged',   '', 'no',     'Sx self-limited; rest, fluids', 0, 'final'),
  -- 80111 — Richard Jones, glucose high-normal routine
  (80212, 80111, 'N', '2345-7',  'Glucose',                 DATE_SUB(NOW(), INTERVAL 50 HOUR),'Quest', 'mg/dL', '108',     '70-99',    'high',     'Slightly elevated fasting glu', 0, 'final')
ON DUPLICATE KEY UPDATE
  procedure_report_id = VALUES(procedure_report_id),
  result_code = VALUES(result_code),
  result_text = VALUES(result_text),
  result = VALUES(result),
  units = VALUES(units),
  `range` = VALUES(`range`),
  abnormal = VALUES(abnormal),
  comments = VALUES(comments);

-- ============================================================
-- HbA1c trend rows for Ted Shaw (pid=1) — synthetic last-4 trend
-- so the right-pane sparkline / "Last 4: 7.2 → 7.4 → 7.6 → 7.9"
-- shows real history. These are added to the existing patient
-- timeline (which already has 7.2, 7.4, 7.6 across 70101..70106).
-- The current pending row (80201) is the 4th, latest point, so
-- the screen's existing seed already gives us the prior three.
-- This block is intentionally a no-op — reserved for future use.
-- ============================================================

SET FOREIGN_KEY_CHECKS=1;

-- Marker: track that this seed has run.
INSERT INTO globals (gl_name, gl_index, gl_value)
VALUES ('copilot_pending_review_seed_v1', 0, NOW())
ON DUPLICATE KEY UPDATE gl_value = VALUES(gl_value);
