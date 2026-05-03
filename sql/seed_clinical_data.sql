-- Clinical Co-Pilot: Demo Patient Seed Data
-- Patients: Ted Shaw (pid=1), Farrah Rolle (pid=5), Nora Cohen (pid=8)
-- Provider ID: 1 (admin), Facility ID: 3

SET FOREIGN_KEY_CHECKS=0;

-- ============================================================
-- ENCOUNTERS (form_encounter)
-- encounter column = the encounter number used everywhere else
-- ============================================================

INSERT INTO form_encounter (id, date, reason, facility, facility_id, pid, encounter, provider_id, billing_facility, pc_catid, class_code) VALUES
-- Ted Shaw (pid=1): T2D, HTN, CKD
(1001, '2023-10-15 09:00:00', 'Annual wellness exam', 'Your Clinic Name Here', 3, 1, 1001, 1, 3, 5, 'AMB'),
(1002, '2024-01-22 10:30:00', 'Elevated creatinine follow-up', 'Your Clinic Name Here', 3, 1, 1002, 1, 3, 5, 'AMB'),
(1003, '2024-04-08 08:45:00', 'Diabetes management, blood pressure check', 'Your Clinic Name Here', 3, 1, 1003, 1, 3, 5, 'AMB'),
(1004, '2024-07-17 11:00:00', 'Routine follow-up, medication refill', 'Your Clinic Name Here', 3, 1, 1004, 1, 3, 5, 'AMB'),
(1005, '2024-10-30 09:15:00', 'Annual exam, worsening fatigue', 'Your Clinic Name Here', 3, 1, 1005, 1, 3, 5, 'AMB'),

-- Farrah Rolle (pid=5): Asthma, Hyperlipidemia, GERD
(2001, '2023-09-05 14:00:00', 'Asthma exacerbation', 'Your Clinic Name Here', 3, 5, 2001, 1, 3, 5, 'AMB'),
(2002, '2023-12-11 10:00:00', 'Annual wellness exam', 'Your Clinic Name Here', 3, 5, 2002, 1, 3, 5, 'AMB'),
(2003, '2024-03-20 09:00:00', 'Cholesterol follow-up, GERD management', 'Your Clinic Name Here', 3, 5, 2003, 1, 3, 5, 'AMB'),
(2004, '2024-07-02 11:30:00', 'Shortness of breath, asthma review', 'Your Clinic Name Here', 3, 5, 2004, 1, 3, 5, 'AMB'),
(2005, '2024-11-14 08:30:00', 'Routine follow-up, medication refill', 'Your Clinic Name Here', 3, 5, 2005, 1, 3, 5, 'AMB'),

-- Nora Cohen (pid=8): Hypothyroidism, Depression, Osteoporosis
(3001, '2023-08-22 13:00:00', 'Thyroid management, mood follow-up', 'Your Clinic Name Here', 3, 8, 3001, 1, 3, 5, 'AMB'),
(3002, '2023-11-07 09:30:00', 'Annual wellness exam', 'Your Clinic Name Here', 3, 8, 3002, 1, 3, 5, 'AMB'),
(3003, '2024-02-14 10:00:00', 'Depression follow-up, TSH recheck', 'Your Clinic Name Here', 3, 8, 3003, 1, 3, 5, 'AMB'),
(3004, '2024-06-19 14:30:00', 'Bone density results review', 'Your Clinic Name Here', 3, 8, 3004, 1, 3, 5, 'AMB'),
(3005, '2024-10-08 11:00:00', 'Routine follow-up, fatigue, weight gain', 'Your Clinic Name Here', 3, 8, 3005, 1, 3, 5, 'AMB');

-- Register encounters in forms table
INSERT INTO forms (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir) VALUES
('2023-10-15 09:00:00', 1001, 'New Patient Encounter', 1001, 1, 'admin', 'Default', 1, 0, 'encounter'),
('2024-01-22 10:30:00', 1002, 'New Patient Encounter', 1002, 1, 'admin', 'Default', 1, 0, 'encounter'),
('2024-04-08 08:45:00', 1003, 'New Patient Encounter', 1003, 1, 'admin', 'Default', 1, 0, 'encounter'),
('2024-07-17 11:00:00', 1004, 'New Patient Encounter', 1004, 1, 'admin', 'Default', 1, 0, 'encounter'),
('2024-10-30 09:15:00', 1005, 'New Patient Encounter', 1005, 1, 'admin', 'Default', 1, 0, 'encounter'),
('2023-09-05 14:00:00', 2001, 'New Patient Encounter', 2001, 5, 'admin', 'Default', 1, 0, 'encounter'),
('2023-12-11 10:00:00', 2002, 'New Patient Encounter', 2002, 5, 'admin', 'Default', 1, 0, 'encounter'),
('2024-03-20 09:00:00', 2003, 'New Patient Encounter', 2003, 5, 'admin', 'Default', 1, 0, 'encounter'),
('2024-07-02 11:30:00', 2004, 'New Patient Encounter', 2004, 5, 'admin', 'Default', 1, 0, 'encounter'),
('2024-11-14 08:30:00', 2005, 'New Patient Encounter', 2005, 5, 'admin', 'Default', 1, 0, 'encounter'),
('2023-08-22 13:00:00', 3001, 'New Patient Encounter', 3001, 8, 'admin', 'Default', 1, 0, 'encounter'),
('2023-11-07 09:30:00', 3002, 'New Patient Encounter', 3002, 8, 'admin', 'Default', 1, 0, 'encounter'),
('2024-02-14 10:00:00', 3003, 'New Patient Encounter', 3003, 8, 'admin', 'Default', 1, 0, 'encounter'),
('2024-06-19 14:30:00', 3004, 'New Patient Encounter', 3004, 8, 'admin', 'Default', 1, 0, 'encounter'),
('2024-10-08 11:00:00', 3005, 'New Patient Encounter', 3005, 8, 'admin', 'Default', 1, 0, 'encounter');

-- ============================================================
-- SOAP NOTES
-- ============================================================

INSERT INTO form_soap (date, pid, user, groupname, authorized, activity, subjective, objective, assessment, plan) VALUES
-- Ted Shaw
('2023-10-15', 1, 'admin', 'Default', 1, 1,
 'Patient presents for annual wellness exam. Reports fatigue and increased thirst over the past 3 months. No chest pain or shortness of breath. Compliant with metformin. Denies hypoglycemic episodes.',
 'BP 148/92. HR 78. Weight 214 lbs. BMI 30.2. General: well-appearing, overweight male.',
 'Type 2 diabetes mellitus - suboptimal control. Hypertension - inadequately controlled. CKD stage 2.',
 'Increase metformin to 1000mg BID. Add lisinopril 10mg daily for BP and renal protection. Recheck HbA1c, CMP in 3 months. Referral to ophthalmology for diabetic eye exam. Annual flu vaccine administered.'),

('2024-01-22', 1, 'admin', 'Default', 1, 1,
 'Follow-up for elevated creatinine noted on last labs. Patient reports no change in urine output. Mild ankle swelling. Compliant with medications.',
 'BP 142/88. HR 74. Weight 216 lbs. Mild bilateral pedal edema.',
 'CKD stage 2-3, worsening. Hypertension - improved but not at goal. T2DM - HbA1c improved to 7.6.',
 'Continue current medications. Renal diet counseling. Nephrology referral placed. Repeat CMP, urine microalbumin in 3 months. Decrease NSAIDs use.'),

('2024-04-08', 1, 'admin', 'Default', 1, 1,
 'Diabetes and BP management. Patient reports compliance with medications. Less fatigue. Still drinks 2-3 beers/week.',
 'BP 138/86. HR 76. Weight 212 lbs. No edema today.',
 'T2DM - improving control. Hypertension - improving. CKD stable.',
 'Continue metformin 1000mg BID and lisinopril 10mg. HbA1c target <7. Schedule nephrology. Low-sodium diet reinforced.'),

('2024-07-17', 1, 'admin', 'Default', 1, 1,
 'Routine follow-up. Medication refill requested. Patient reports compliance. No new complaints.',
 'BP 136/84. HR 72. Weight 210 lbs. Labs reviewed.',
 'T2DM - HbA1c 7.2, improving. Hypertension - at goal. CKD stage 2, stable.',
 'Continue current regimen. Add atorvastatin 20mg for cardiovascular risk reduction. Recheck lipids in 3 months.'),

('2024-10-30', 1, 'admin', 'Default', 1, 1,
 'Annual exam. Patient reports increased fatigue over past 6 weeks. Nocturia x2. Denies chest pain.',
 'BP 144/90. HR 78. Weight 213 lbs. Labs notable for creatinine 1.7.',
 'T2DM - HbA1c 7.9, worsening. Hypertension - above goal. CKD stage 3a, progressing.',
 'Add jardiance 10mg daily - renal protective. Increase lisinopril to 20mg. Urgent nephrology referral. Repeat CMP in 6 weeks. Discuss SGLT2 inhibitor benefits.'),

-- Farrah Rolle
('2023-09-05', 5, 'admin', 'Default', 1, 1,
 'Asthma exacerbation x3 days. Wheezing, shortness of breath on exertion. Using rescue inhaler 3-4x/day. Triggered by smoke exposure at work.',
 'RR 20. O2 sat 95%. Wheezing bilateral on auscultation. No accessory muscle use.',
 'Asthma exacerbation, mild-moderate. Peak flow 72% predicted.',
 'Albuterol nebulization in office - improved. Prescribe prednisone 40mg x5 days. Step up to fluticasone/salmeterol 250/50 BID. Asthma action plan reviewed. Follow up in 2 weeks.'),

('2023-12-11', 5, 'admin', 'Default', 1, 1,
 'Annual wellness. Asthma well-controlled on current regimen. Heartburn 2-3x/week, especially after large meals. No dysphagia.',
 'BP 122/78. HR 68. Weight 168 lbs. BMI 26.1. Lungs clear to auscultation.',
 'Asthma - well-controlled. GERD - new diagnosis. Hyperlipidemia - LDL elevated at 148.',
 'Continue fluticasone/salmeterol. Add omeprazole 20mg daily for GERD. Start rosuvastatin 10mg for hyperlipidemia. Dietary counseling. Recheck lipids in 3 months.'),

('2024-03-20', 5, 'admin', 'Default', 1, 1,
 'Cholesterol and GERD follow-up. Heartburn improved on omeprazole. Compliance with asthma medications. No exacerbations since last visit.',
 'BP 118/76. HR 70. Weight 166 lbs. Lungs clear.',
 'GERD - well-controlled on PPI. Hyperlipidemia - LDL improved to 112. Asthma - controlled.',
 'Continue all medications. Consider omeprazole step-down trial in 3 months if GERD remains controlled. Annual mammogram due - ordered.'),

('2024-07-02', 5, 'admin', 'Default', 1, 1,
 'Shortness of breath x1 week, worse with exercise. No fever. Using rescue inhaler BID. Reports increased stress at work.',
 'BP 124/80. HR 82. Weight 170 lbs. O2 sat 97%. Mild expiratory wheeze.',
 'Asthma - partially controlled, stress-related exacerbation. GERD - stable.',
 'Adjust fluticasone/salmeterol to 500/50. Review inhaler technique - poor technique noted, corrected. Stress management discussed. Return if not improved in 2 weeks.'),

('2024-11-14', 5, 'admin', 'Default', 1, 1,
 'Routine follow-up. Asthma well-controlled after medication adjustment. Heartburn managed. Requesting medication refills.',
 'BP 120/78. HR 66. Weight 167 lbs. Lungs clear. No wheezing.',
 'Asthma - well-controlled. GERD - controlled. Hyperlipidemia - LDL at goal.',
 'Continue all medications. Annual flu vaccine given. Next annual exam in December 2025.'),

-- Nora Cohen
('2023-08-22', 8, 'admin', 'Default', 1, 1,
 'Thyroid management. Fatigue, weight gain 8 lbs over 6 months. Cold intolerance. Mood low, not enjoying usual activities. Not on any antidepressants.',
 'BP 118/74. HR 58. Weight 162 lbs. BMI 26.4. Thyroid not palpably enlarged. Affect blunted.',
 'Hypothyroidism - suboptimal control, TSH elevated. Depression - moderate, likely exacerbated by hypothyroidism.',
 'Increase levothyroxine from 75mcg to 100mcg. Start sertraline 50mg for depression. Recheck TSH in 6 weeks. PHQ-9 score today: 14 (moderate). Follow up in 6 weeks.'),

('2023-11-07', 8, 'admin', 'Default', 1, 1,
 'Annual wellness. Mood significantly improved on sertraline. Less fatigue. Still occasional cold intolerance but better.',
 'BP 116/72. HR 64. Weight 158 lbs. Affect brighter. TSH improved to 2.1.',
 'Hypothyroidism - well-controlled on levothyroxine 100mcg. Depression - improving. Osteoporosis risk elevated (postmenopausal, 57yo).',
 'Continue levothyroxine 100mcg. Continue sertraline 50mg. Order DEXA scan for osteoporosis screening. Start calcium 1200mg + Vitamin D 2000IU daily. PHQ-9: 6 (mild).'),

('2024-02-14', 8, 'admin', 'Default', 1, 1,
 'Depression follow-up, TSH recheck. Mood stable. Sleeping better. Still some anhedonia. TSH on recent labs: 1.8.',
 'BP 114/70. HR 62. Weight 156 lbs. Mood improved from baseline.',
 'Hypothyroidism - well-controlled. Depression - mild, stable on sertraline. DEXA ordered, pending.',
 'Continue current medications. Increase sertraline to 100mg for residual anhedonia. Recheck PHQ-9 at next visit. PHQ-9 today: 8.'),

('2024-06-19', 8, 'admin', 'Default', 1, 1,
 'DEXA results review. Patient reports no bone pain or falls. Taking calcium and Vitamin D as prescribed.',
 'BP 118/72. HR 60. Weight 155 lbs. No focal tenderness.',
 'Osteoporosis - lumbar T-score -2.6, femoral neck T-score -2.4. Hypothyroidism - stable. Depression - mild.',
 'Start alendronate 70mg weekly for osteoporosis. Continue calcium/Vitamin D. Fall risk assessment completed. Gait stable. Repeat DEXA in 2 years.'),

('2024-10-08', 8, 'admin', 'Default', 1, 1,
 'Routine follow-up. Fatigue returned over past month. Weight up 4 lbs. Cold intolerance worsening. Mood remains stable.',
 'BP 120/74. HR 56. Weight 159 lbs. TSH on recent labs: 4.8 (above range).',
 'Hypothyroidism - undertreated, TSH drifting up. Depression - stable. Osteoporosis - on treatment.',
 'Increase levothyroxine to 112mcg. Recheck TSH in 6 weeks. Continue sertraline, alendronate. PHQ-9: 7, stable.');

-- Register SOAP notes in forms table
INSERT INTO forms (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir)
SELECT date, pid+1000*(pid=1)+1000*(pid=5 AND date<'2024-01-01')+2000*(pid=5 AND date>='2024-01-01')+2000*(pid=8 AND date<'2024-01-01')+3000*(pid=8 AND date>='2024-01-01'),
       'SOAP Note', id, pid, 'admin', 'Default', 1, 0, 'soap'
FROM form_soap;

-- ============================================================
-- VITALS (form_vitals)
-- ============================================================

INSERT INTO form_vitals (date, pid, user, groupname, authorized, activity, bps, bpd, weight, height, temperature, pulse, respiration, BMI, BMI_status, oxygen_saturation) VALUES
-- Ted Shaw (pid=1)
('2023-10-15 09:00:00', 1, 'admin', 'Default', 1, 1, '148', '92', 214.000000, 70.000000, 98.400000, 78.000000, 16.000000, 30.200000, 'Obese', 97.00),
('2024-01-22 10:30:00', 1, 'admin', 'Default', 1, 1, '142', '88', 216.000000, 70.000000, 98.200000, 74.000000, 16.000000, 30.900000, 'Obese', 97.00),
('2024-04-08 08:45:00', 1, 'admin', 'Default', 1, 1, '138', '86', 212.000000, 70.000000, 98.600000, 76.000000, 16.000000, 30.400000, 'Obese', 98.00),
('2024-07-17 11:00:00', 1, 'admin', 'Default', 1, 1, '136', '84', 210.000000, 70.000000, 98.400000, 72.000000, 15.000000, 30.100000, 'Obese', 98.00),
('2024-10-30 09:15:00', 1, 'admin', 'Default', 1, 1, '144', '90', 213.000000, 70.000000, 98.600000, 78.000000, 16.000000, 30.600000, 'Obese', 97.00),
-- Farrah Rolle (pid=5)
('2023-09-05 14:00:00', 5, 'admin', 'Default', 1, 1, '128', '82', 168.000000, 65.000000, 98.800000, 92.000000, 20.000000, 26.100000, 'Overweight', 95.00),
('2023-12-11 10:00:00', 5, 'admin', 'Default', 1, 1, '122', '78', 168.000000, 65.000000, 98.600000, 68.000000, 16.000000, 26.100000, 'Overweight', 99.00),
('2024-03-20 09:00:00', 5, 'admin', 'Default', 1, 1, '118', '76', 166.000000, 65.000000, 98.600000, 70.000000, 15.000000, 25.800000, 'Overweight', 99.00),
('2024-07-02 11:30:00', 5, 'admin', 'Default', 1, 1, '124', '80', 170.000000, 65.000000, 98.800000, 82.000000, 18.000000, 26.400000, 'Overweight', 97.00),
('2024-11-14 08:30:00', 5, 'admin', 'Default', 1, 1, '120', '78', 167.000000, 65.000000, 98.600000, 66.000000, 15.000000, 25.900000, 'Overweight', 99.00),
-- Nora Cohen (pid=8)
('2023-08-22 13:00:00', 8, 'admin', 'Default', 1, 1, '118', '74', 162.000000, 63.000000, 97.800000, 58.000000, 14.000000, 26.400000, 'Overweight', 98.00),
('2023-11-07 09:30:00', 8, 'admin', 'Default', 1, 1, '116', '72', 158.000000, 63.000000, 98.000000, 64.000000, 14.000000, 25.700000, 'Overweight', 99.00),
('2024-02-14 10:00:00', 8, 'admin', 'Default', 1, 1, '114', '70', 156.000000, 63.000000, 98.200000, 62.000000, 14.000000, 25.400000, 'Overweight', 99.00),
('2024-06-19 14:30:00', 8, 'admin', 'Default', 1, 1, '118', '72', 155.000000, 63.000000, 98.200000, 60.000000, 14.000000, 25.200000, 'Normal', 99.00),
('2024-10-08 11:00:00', 8, 'admin', 'Default', 1, 1, '120', '74', 159.000000, 63.000000, 97.800000, 56.000000, 14.000000, 25.900000, 'Overweight', 98.00);

-- Register the vitals rows in `forms` so the FHIR Observation endpoint
-- can find them (vital-signs reads join `forms` → `form_vitals`). Without
-- this block, vitals are silently invisible to FHIR consumers including
-- the Co-Pilot agent. See sql/copilot_seeds/register_vitals_in_forms.sql
-- for the standalone idempotent version.
--
-- IMPORTANT: this SQL block is not sufficient on its own. The FHIR
-- vital-signs path also needs `uuid_mapping` rows tying each
-- form_vitals.uuid to each LOINC code. Those rows are generated by
-- PHP, not SQL. After running this seed, invoke:
--     scripts/fix-vitals-seed.sh    (local docker stack)
-- or, on a deployed env, hit the autoPopulateAllMissingUuids() entry
-- point (e.g. via sql_upgrade.php or a one-shot admin script).
-- Without that step, vitals queries still come back empty.
INSERT INTO forms
    (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir)
SELECT v.date, e.encounter, 'Vitals', v.id, v.pid, 'admin', 'Default', 1, 0, 'vitals'
FROM form_vitals v
JOIN form_encounter e ON e.pid = v.pid AND DATE(e.date) = DATE(v.date)
WHERE NOT EXISTS (
    SELECT 1 FROM forms f WHERE f.formdir = 'vitals' AND f.form_id = v.id
);

-- ============================================================
-- LAB RESULTS (form_observation)
-- LOINC codes: 4548-4=HbA1c, 2160-0=Creatinine, 2345-7=Glucose,
-- 2093-3=Total Cholesterol, 2571-8=Triglycerides, 18262-6=LDL,
-- 2085-9=HDL, 3016-3=TSH, 14959-1=Microalbumin/Creatinine,
-- 33914-3=eGFR, 718-7=Hemoglobin, 6768-6=ALP, 1742-6=ALT, 1920-8=AST
-- ============================================================

INSERT INTO form_observation (date, pid, encounter, user, groupname, authorized, activity, code, observation, ob_value, ob_unit, description, code_type, ob_code, ob_type, result_status, category) VALUES
-- Ted Shaw: HbA1c trend (worsening then improving then worsening again)
('2023-10-15', 1, '1001', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '8.4', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),
('2024-01-22', 1, '1002', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '7.6', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),
('2024-04-08', 1, '1003', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '7.4', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),
('2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '7.2', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),
('2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '4548-4', 'Hemoglobin A1c', '7.9', '%', 'Hemoglobin A1c/Hemoglobin.total in Blood', 'LOINC', '4548-4', 'numeric', 'final', 'laboratory'),

-- Ted Shaw: Creatinine trend (rising - CKD progression)
('2023-10-15', 1, '1001', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.3', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),
('2024-01-22', 1, '1002', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.5', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),
('2024-04-08', 1, '1003', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.5', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),
('2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.6', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),
('2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '2160-0', 'Creatinine', '1.7', 'mg/dL', 'Creatinine [Mass/volume] in Serum or Plasma', 'LOINC', '2160-0', 'numeric', 'final', 'laboratory'),

-- Ted Shaw: Fasting glucose
('2023-10-15', 1, '1001', 'admin', 'Default', 1, 1, '2345-7', 'Glucose', '184', 'mg/dL', 'Glucose [Mass/volume] in Serum or Plasma', 'LOINC', '2345-7', 'numeric', 'final', 'laboratory'),
('2024-04-08', 1, '1003', 'admin', 'Default', 1, 1, '2345-7', 'Glucose', '152', 'mg/dL', 'Glucose [Mass/volume] in Serum or Plasma', 'LOINC', '2345-7', 'numeric', 'final', 'laboratory'),
('2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '2345-7', 'Glucose', '168', 'mg/dL', 'Glucose [Mass/volume] in Serum or Plasma', 'LOINC', '2345-7', 'numeric', 'final', 'laboratory'),

-- Ted Shaw: Lipids
('2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '198', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
('2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '118', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),
('2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '2085-9', 'HDL Cholesterol', '42', 'mg/dL', 'High density lipoprotein cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2085-9', 'numeric', 'final', 'laboratory'),
('2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '182', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
('2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '98', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),

-- Ted Shaw: eGFR trend
('2023-10-15', 1, '1001', 'admin', 'Default', 1, 1, '33914-3', 'eGFR', '68', 'mL/min/1.73 m2', 'Glomerular filtration rate/1.73 sq M.predicted', 'LOINC', '33914-3', 'numeric', 'final', 'laboratory'),
('2024-01-22', 1, '1002', 'admin', 'Default', 1, 1, '33914-3', 'eGFR', '61', 'mL/min/1.73 m2', 'Glomerular filtration rate/1.73 sq M.predicted', 'LOINC', '33914-3', 'numeric', 'final', 'laboratory'),
('2024-07-17', 1, '1004', 'admin', 'Default', 1, 1, '33914-3', 'eGFR', '58', 'mL/min/1.73 m2', 'Glomerular filtration rate/1.73 sq M.predicted', 'LOINC', '33914-3', 'numeric', 'final', 'laboratory'),
('2024-10-30', 1, '1005', 'admin', 'Default', 1, 1, '33914-3', 'eGFR', '54', 'mL/min/1.73 m2', 'Glomerular filtration rate/1.73 sq M.predicted', 'LOINC', '33914-3', 'numeric', 'final', 'laboratory'),

-- Farrah Rolle: Lipid panels (improving on statin)
('2023-12-11', 5, '2002', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '224', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
('2023-12-11', 5, '2002', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '148', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),
('2023-12-11', 5, '2002', 'admin', 'Default', 1, 1, '2085-9', 'HDL Cholesterol', '52', 'mg/dL', 'High density lipoprotein cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2085-9', 'numeric', 'final', 'laboratory'),
('2023-12-11', 5, '2002', 'admin', 'Default', 1, 1, '2571-8', 'Triglycerides', '188', 'mg/dL', 'Triglyceride [Mass/volume] in Serum or Plasma', 'LOINC', '2571-8', 'numeric', 'final', 'laboratory'),
('2024-03-20', 5, '2003', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '192', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
('2024-03-20', 5, '2003', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '112', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),
('2024-11-14', 5, '2005', 'admin', 'Default', 1, 1, '2093-3', 'Total Cholesterol', '178', 'mg/dL', 'Cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2093-3', 'numeric', 'final', 'laboratory'),
('2024-11-14', 5, '2005', 'admin', 'Default', 1, 1, '18262-6', 'LDL Cholesterol', '94', 'mg/dL', 'Low density lipoprotein cholesterol [Mass/volume] in Serum or Plasma by Direct assay', 'LOINC', '18262-6', 'numeric', 'final', 'laboratory'),
('2024-11-14', 5, '2005', 'admin', 'Default', 1, 1, '2085-9', 'HDL Cholesterol', '56', 'mg/dL', 'High density lipoprotein cholesterol [Mass/volume] in Serum or Plasma', 'LOINC', '2085-9', 'numeric', 'final', 'laboratory'),

-- Nora Cohen: TSH trend (undertreated, treated, stable, drifting again)
('2023-08-22', 8, '3001', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '7.8', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),
('2023-11-07', 8, '3002', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '2.1', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),
('2024-02-14', 8, '3003', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '1.8', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),
('2024-06-19', 8, '3004', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '2.4', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),
('2024-10-08', 8, '3005', 'admin', 'Default', 1, 1, '3016-3', 'TSH', '4.8', 'mIU/L', 'Thyrotropin [Units/volume] in Serum or Plasma', 'LOINC', '3016-3', 'numeric', 'final', 'laboratory'),

-- Nora Cohen: CBC
('2023-11-07', 8, '3002', 'admin', 'Default', 1, 1, '718-7', 'Hemoglobin', '12.8', 'g/dL', 'Hemoglobin [Mass/volume] in Blood', 'LOINC', '718-7', 'numeric', 'final', 'laboratory'),
('2024-10-08', 8, '3005', 'admin', 'Default', 1, 1, '718-7', 'Hemoglobin', '12.4', 'g/dL', 'Hemoglobin [Mass/volume] in Blood', 'LOINC', '718-7', 'numeric', 'final', 'laboratory'),

-- Nora Cohen: Vitamin D
('2023-11-07', 8, '3002', 'admin', 'Default', 1, 1, '14635-7', 'Vitamin D', '18', 'ng/mL', '25-hydroxyvitamin D3 [Mass/volume] in Serum or Plasma', 'LOINC', '14635-7', 'numeric', 'final', 'laboratory'),
('2024-06-19', 8, '3004', 'admin', 'Default', 1, 1, '14635-7', 'Vitamin D', '34', 'ng/mL', '25-hydroxyvitamin D3 [Mass/volume] in Serum or Plasma', 'LOINC', '14635-7', 'numeric', 'final', 'laboratory');

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
('2023-10-15', 'allergy', '', 'Penicillin', '2023-10-15', 'Hives', 'mild', 1, 'Rash/hives reported with amoxicillin in 2019', 1, 'admin', 'Default'),
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

INSERT INTO prescriptions (patient_id, date_added, provider_id, encounter, start_date, drug, dosage, quantity, route, note, active, active, indication, end_date) VALUES
-- Ted Shaw
(1, '2023-10-15', 1, 1001, '2023-10-15', 'Metformin HCl', '1000mg', '60', 'Oral', 'Take twice daily with meals', 1, 1, 'Type 2 Diabetes Mellitus', NULL),
(1, '2023-10-15', 1, 1001, '2023-10-15', 'Lisinopril', '10mg', '30', 'Oral', 'Take once daily', 1, 1, 'Hypertension, CKD renal protection', NULL),
(1, '2024-10-30', 1, 1005, '2024-10-30', 'Lisinopril', '20mg', '30', 'Oral', 'Dose increased - take once daily', 1, 1, 'Hypertension - inadequate control', NULL),
(1, '2024-07-17', 1, 1004, '2024-07-17', 'Atorvastatin', '20mg', '30', 'Oral', 'Take once daily at bedtime', 1, 1, 'Hyperlipidemia, cardiovascular risk reduction', NULL),
(1, '2024-10-30', 1, 1005, '2024-10-30', 'Empagliflozin (Jardiance)', '10mg', '30', 'Oral', 'Take once daily in the morning', 1, 1, 'T2DM, CKD renal protection', NULL),
-- Farrah Rolle
(5, '2023-09-05', 1, 2001, '2023-09-05', 'Fluticasone/Salmeterol (Advair)', '250/50 mcg', '1', 'Inhalation', 'Inhale 1 puff twice daily, rinse mouth after', 1, 1, 'Asthma, moderate persistent', NULL),
(5, '2023-09-05', 1, 2001, '2023-09-05', 'Albuterol HFA', '90 mcg/actuation', '1', 'Inhalation', 'Use 2 puffs every 4-6 hours as needed for rescue', 1, 1, 'Asthma - rescue inhaler', NULL),
(5, '2023-12-11', 1, 2002, '2023-12-11', 'Omeprazole', '20mg', '30', 'Oral', 'Take once daily 30 minutes before breakfast', 1, 1, 'GERD', NULL),
(5, '2023-12-11', 1, 2002, '2023-12-11', 'Rosuvastatin (Crestor)', '10mg', '30', 'Oral', 'Take once daily', 1, 1, 'Hyperlipidemia', NULL),
-- Nora Cohen
(8, '2023-08-22', 1, 3001, '2023-08-22', 'Levothyroxine', '100 mcg', '30', 'Oral', 'Take on empty stomach, 30 min before breakfast', 1, 1, 'Hypothyroidism', NULL),
(8, '2023-08-22', 1, 3001, '2023-08-22', 'Sertraline (Zoloft)', '50mg', '30', 'Oral', 'Take once daily in the morning', 1, 1, 'Major Depressive Disorder', NULL),
(8, '2024-02-14', 1, 3003, '2024-02-14', 'Sertraline (Zoloft)', '100mg', '30', 'Oral', 'Dose increased - take once daily in the morning', 1, 1, 'MDD - residual symptoms', NULL),
(8, '2024-06-19', 1, 3004, '2024-06-19', 'Alendronate', '70mg', '4', 'Oral', 'Take once weekly on same day, with full glass of water, remain upright 30 min', 1, 1, 'Osteoporosis', NULL),
(8, '2023-11-07', 1, 3002, '2023-11-07', 'Calcium Carbonate + Vitamin D3', '1200mg/2000IU', '30', 'Oral', 'Take daily with food', 1, 1, 'Osteoporosis prevention, Vitamin D deficiency', NULL);

SET FOREIGN_KEY_CHECKS=1;
