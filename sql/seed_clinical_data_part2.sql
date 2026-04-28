-- Clinical Co-Pilot: Seed Part 2 — Labs, Conditions, Allergies, Medications

SET FOREIGN_KEY_CHECKS=0;

-- ============================================================
-- LAB RESULTS (form_observation) — form_id required
-- ============================================================

INSERT INTO form_observation (form_id, date, pid, encounter, user, groupname, authorized, activity, code, observation, ob_value, ob_unit, description, code_type, ob_code, ob_type, result_status, category) VALUES
-- Ted Shaw: HbA1c trend
(1001, '2023-10-15', 1, '1001', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '8.4', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),
(1002, '2024-01-22', 1, '1002', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '7.6', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),
(1003, '2024-04-08', 1, '1003', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '7.4', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),
(1004, '2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '7.2', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),
(1005, '2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '7.9', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),
-- Ted Shaw: Creatinine trend
(1001, '2023-10-15', 1, '1001', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.3', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),
(1002, '2024-01-22', 1, '1002', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.5', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),
(1003, '2024-04-08', 1, '1003', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.5', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),
(1004, '2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.6', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),
(1005, '2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.7', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),
-- Ted Shaw: Fasting glucose
(1001, '2023-10-15', 1, '1001', 'admin', 'Default', 1, 1, '2345-7', 'Glucose', '184', 'mg/dL', 'Glucose [Mass/volume] in Serum or Plasma', 'LOINC', '2345-7', 'numeric', 'final', 'laboratory'),
(1003, '2024-04-08', 1, '1003', 'admin', 'Default', 1, 1, '2345-7', 'Glucose', '152', 'mg/dL', 'Glucose [Mass/volume] in Serum or Plasma', 'LOINC', '2345-7', 'numeric', 'final', 'laboratory'),
(1005, '2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '2345-7', 'Glucose', '168', 'mg/dL', 'Glucose [Mass/volume] in Serum or Plasma', 'LOINC', '2345-7', 'numeric', 'final', 'laboratory'),
-- Ted Shaw: Lipids
(1004, '2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '198', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
(1004, '2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '118', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),
(1004, '2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '2085-9', 'HDL Cholesterol', '42', 'mg/dL', 'High density lipoprotein cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2085-9', 'numeric', 'final', 'laboratory'),
(1005, '2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '182', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
(1005, '2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '98', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),
-- Ted Shaw: eGFR trend
(1001, '2023-10-15', 1, '1001', 'admin', 'Default', 1, 1, '33914-3', 'eGFR', '68', 'mL/min/1.73 m2', 'Glomerular filtration rate/1.73 sq M.predicted', 'LOINC', '33914-3', 'numeric', 'final', 'laboratory'),
(1002, '2024-01-22', 1, '1002', 'admin', 'Default', 1, 1, '33914-3', 'eGFR', '61', 'mL/min/1.73 m2', 'Glomerular filtration rate/1.73 sq M.predicted', 'LOINC', '33914-3', 'numeric', 'final', 'laboratory'),
(1004, '2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '33914-3', 'eGFR', '58', 'mL/min/1.73 m2', 'Glomerular filtration rate/1.73 sq M.predicted', 'LOINC', '33914-3', 'numeric', 'final', 'laboratory'),
(1005, '2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '33914-3', 'eGFR', '54', 'mL/min/1.73 m2', 'Glomerular filtration rate/1.73 sq M.predicted', 'LOINC', '33914-3', 'numeric', 'final', 'laboratory'),
-- Farrah Rolle: Lipids
(2002, '2023-12-11', 5, '2002', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '224', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
(2002, '2023-12-11', 5, '2002', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '148', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),
(2002, '2023-12-11', 5, '2002', 'admin', 'Default', 1, 1, '2085-9', 'HDL Cholesterol', '52', 'mg/dL', 'High density lipoprotein cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2085-9', 'numeric', 'final', 'laboratory'),
(2002, '2023-12-11', 5, '2002', 'admin', 'Default', 1, 1, '2571-8', 'Triglycerides', '188', 'mg/dL', 'Triglyceride [Mass/volume] in Serum or Plasma', 'LOINC', '2571-8', 'numeric', 'final', 'laboratory'),
(2003, '2024-03-20', 5, '2003', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '192', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
(2003, '2024-03-20', 5, '2003', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '112', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),
(2005, '2024-11-14', 5, '2005', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '178', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
(2005, '2024-11-14', 5, '2005', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '94', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),
(2005, '2024-11-14', 5, '2005', 'admin', 'Default', 1, 1, '2085-9', 'HDL Cholesterol', '56', 'mg/dL', 'High density lipoprotein cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2085-9', 'numeric', 'final', 'laboratory'),
-- Nora Cohen: TSH trend
(3001, '2023-08-22', 8, '3001', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '7.8', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),
(3002, '2023-11-07', 8, '3002', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '2.1', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),
(3003, '2024-02-14', 8, '3003', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '1.8', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),
(3004, '2024-06-19', 8, '3004', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '2.4', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),
(3005, '2024-10-08', 8, '3005', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '4.8', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),
-- Nora Cohen: CBC + Vitamin D
(3002, '2023-11-07', 8, '3002', 'admin', 'Default', 1, 1, '718-7', 'Hemoglobin', '12.8', 'g/dL', 'Hemoglobin [Mass/volume] in Blood', 'LOINC', '718-7', 'numeric', 'final', 'laboratory'),
(3005, '2024-10-08', 8, '3005', 'admin', 'Default', 1, 1, '718-7', 'Hemoglobin', '12.4', 'g/dL', 'Hemoglobin [Mass/volume] in Blood', 'LOINC', '718-7', 'numeric', 'final', 'laboratory'),
(3002, '2023-11-07', 8, '3002', 'admin', 'Default', 1, 1, '14635-7', 'Vitamin D', '18', 'ng/mL', '25-hydroxyvitamin D3 [Mass/volume] in Serum or Plasma', 'LOINC', '14635-7', 'numeric', 'final', 'laboratory'),
(3004, '2024-06-19', 8, '3004', 'admin', 'Default', 1, 1, '14635-7', 'Vitamin D', '34', 'ng/mL', '25-hydroxyvitamin D3 [Mass/volume] in Serum or Plasma', 'LOINC', '14635-7', 'numeric', 'final', 'laboratory');

-- ============================================================
-- CONDITIONS / PROBLEMS (lists, type='medical_problem')
-- ============================================================

INSERT INTO lists (date, type, subtype, title, begdate, diagnosis, activity, pid, user, groupname) VALUES
-- Ted Shaw
('2023-10-15', 'medical_problem', '', 'Type 2 Diabetes Mellitus', '2020-06-01', 'ICD10:E11.9', 1, 1, 'admin', 'Default'),
('2023-10-15', 'medical_problem', '', 'Hypertension', '2019-03-15', 'ICD10:I10', 1, 1, 'admin', 'Default'),
('2024-01-22', 'medical_problem', '', 'Chronic Kidney Disease, Stage 3a', '2024-01-22', 'ICD10:N18.31', 1, 1, 'admin', 'Default'),
('2024-07-17', 'medical_problem', '', 'Hyperlipidemia', '2024-07-17', 'ICD10:E78.5', 1, 1, 'admin', 'Default'),
-- Farrah Rolle
('2023-09-05', 'medical_problem', '', 'Asthma, moderate persistent', '2018-04-20', 'ICD10:J45.40', 1, 5, 'admin', 'Default'),
('2023-12-11', 'medical_problem', '', 'Gastroesophageal Reflux Disease', '2023-12-11', 'ICD10:K21.0', 1, 5, 'admin', 'Default'),
('2023-12-11', 'medical_problem', '', 'Hyperlipidemia', '2023-12-11', 'ICD10:E78.5', 1, 5, 'admin', 'Default'),
-- Nora Cohen
('2023-08-22', 'medical_problem', '', 'Hypothyroidism', '2015-09-10', 'ICD10:E03.9', 1, 8, 'admin', 'Default'),
('2023-08-22', 'medical_problem', '', 'Major Depressive Disorder, recurrent', '2023-08-22', 'ICD10:F33.1', 1, 8, 'admin', 'Default'),
('2024-06-19', 'medical_problem', '', 'Osteoporosis without current pathological fracture', '2024-06-19', 'ICD10:M81.0', 1, 8, 'admin', 'Default');

-- ============================================================
-- ALLERGIES (lists, type='allergy')
-- ============================================================

INSERT INTO lists (date, type, subtype, title, begdate, reaction, severity_al, activity, comments, pid, user, groupname) VALUES
-- Ted Shaw
('2023-10-15', 'allergy', '', 'Penicillin', '2023-10-15', 'Hives', 'mild', 1, 'Rash/hives with amoxicillin in 2019', 1, 'admin', 'Default'),
('2023-10-15', 'allergy', '', 'Sulfa drugs', '2023-10-15', 'Rash', 'mild', 1, 'Diffuse rash with trimethoprim-sulfamethoxazole', 1, 'admin', 'Default'),
-- Farrah Rolle
('2023-09-05', 'allergy', '', 'Aspirin', '2023-09-05', 'Bronchospasm', 'severe', 1, 'Aspirin-exacerbated respiratory disease, confirmed', 5, 'admin', 'Default'),
('2023-09-05', 'allergy', '', 'NSAIDs', '2023-09-05', 'Bronchospasm', 'severe', 1, 'Cross-reactive with aspirin sensitivity', 5, 'admin', 'Default'),
('2023-12-11', 'allergy', '', 'Latex', '2023-12-11', 'Contact dermatitis', 'moderate', 1, 'Reported by patient', 5, 'admin', 'Default'),
-- Nora Cohen
('2023-08-22', 'allergy', '', 'Codeine', '2023-08-22', 'Nausea/vomiting', 'moderate', 1, 'Severe nausea and vomiting reported', 8, 'admin', 'Default'),
('2023-08-22', 'allergy', '', 'Shellfish', '2023-08-22', 'Anaphylaxis', 'severe', 1, 'Anaphylactic reaction, carries epi-pen', 8, 'admin', 'Default');

-- ============================================================
-- MEDICATIONS (prescriptions)
-- ============================================================

INSERT INTO prescriptions (patient_id, date_added, provider_id, encounter, start_date, drug, dosage, quantity, route, note, active, indication, txDate, usage_category_title, request_intent_title) VALUES
-- Ted Shaw
(1, '2023-10-15', 1, 1001, '2023-10-15', 'Metformin HCl', '1000mg', '60', 'Oral', 'Take twice daily with meals', 1, 'Type 2 Diabetes Mellitus', '2023-10-15', '', ''),
(1, '2023-10-15', 1, 1001, '2023-10-15', 'Lisinopril', '10mg', '30', 'Oral', 'Take once daily; dose increased Oct 2024', 0, 'Hypertension, CKD renal protection', '2023-10-15', '', ''),
(1, '2024-10-30', 1, 1005, '2024-10-30', 'Lisinopril', '20mg', '30', 'Oral', 'Increased from 10mg - take once daily', 1, 'Hypertension - inadequate control on 10mg', '2024-10-30', '', ''),
(1, '2024-07-17', 1, 1004, '2024-07-17', 'Atorvastatin', '20mg', '30', 'Oral', 'Take once daily at bedtime', 1, 'Hyperlipidemia, cardiovascular risk reduction', '2024-07-17', '', ''),
(1, '2024-10-30', 1, 1005, '2024-10-30', 'Empagliflozin (Jardiance)', '10mg', '30', 'Oral', 'Take once daily in the morning', 1, 'T2DM management, CKD renal protection', '2024-10-30', '', ''),
-- Farrah Rolle
(5, '2023-09-05', 1, 2001, '2023-09-05', 'Fluticasone/Salmeterol (Advair)', '250/50 mcg', '1', 'Inhalation', 'Inhale 1 puff twice daily, rinse mouth after use', 0, 'Asthma, moderate persistent; stepped up Nov 2024', '2023-09-05', '', ''),
(5, '2024-07-02', 1, 2004, '2024-07-02', 'Fluticasone/Salmeterol (Advair)', '500/50 mcg', '1', 'Inhalation', 'Stepped up from 250/50 - inhale 1 puff twice daily', 1, 'Asthma, partially controlled', '2024-07-02', '', ''),
(5, '2023-09-05', 1, 2001, '2023-09-05', 'Albuterol HFA', '90 mcg/actuation', '1', 'Inhalation', 'Use 2 puffs every 4-6 hours as needed for rescue', 1, 'Asthma - rescue inhaler', '2023-09-05', '', ''),
(5, '2023-12-11', 1, 2002, '2023-12-11', 'Omeprazole', '20mg', '30', 'Oral', 'Take once daily 30 min before breakfast', 1, 'GERD', '2023-12-11', '', ''),
(5, '2023-12-11', 1, 2002, '2023-12-11', 'Rosuvastatin (Crestor)', '10mg', '30', 'Oral', 'Take once daily', 1, 'Hyperlipidemia', '2023-12-11', '', ''),
-- Nora Cohen
(8, '2023-08-22', 1, 3001, '2023-08-22', 'Levothyroxine', '100 mcg', '30', 'Oral', 'Take on empty stomach, 30 min before breakfast; dose increased Oct 2024', 0, 'Hypothyroidism', '2023-08-22', '', ''),
(8, '2024-10-08', 1, 3005, '2024-10-08', 'Levothyroxine', '112 mcg', '30', 'Oral', 'Increased from 100mcg - take on empty stomach, 30 min before breakfast', 1, 'Hypothyroidism - TSH drifting up', '2024-10-08', '', ''),
(8, '2023-08-22', 1, 3001, '2023-08-22', 'Sertraline (Zoloft)', '50mg', '30', 'Oral', 'Take once daily in the morning; dose increased Feb 2024', 0, 'Major Depressive Disorder', '2023-08-22', '', ''),
(8, '2024-02-14', 1, 3003, '2024-02-14', 'Sertraline (Zoloft)', '100mg', '30', 'Oral', 'Increased from 50mg - take once daily in the morning', 1, 'MDD - residual anhedonia on 50mg', '2024-02-14', '', ''),
(8, '2024-06-19', 1, 3004, '2024-06-19', 'Alendronate', '70mg', '4', 'Oral', 'Take once weekly same day, full glass of water, remain upright 30 min', 1, 'Osteoporosis', '2024-06-19', '', ''),
(8, '2023-11-07', 1, 3002, '2023-11-07', 'Calcium Carbonate + Vitamin D3', '1200mg/2000IU', '30', 'Oral', 'Take daily with food', 1, 'Osteoporosis prevention, Vitamin D deficiency', '2023-11-07', '', '');

SET FOREIGN_KEY_CHECKS=1;
