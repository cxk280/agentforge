-- Seed 3 demo patients for Clinical Co-Pilot demo (OpenEMR 8.0.0.3 schema)
-- pids must match seed_clinical_data.sql references: 1, 5, 8

SET FOREIGN_KEY_CHECKS=0;

INSERT INTO `patient_data`
  (`id`, `pid`, `pubpid`, `title`, `fname`, `lname`, `mname`, `DOB`, `sex`,
   `street`, `city`, `state`, `postal_code`, `country_code`,
   `phone_home`, `phone_cell`, `email`,
   `language`, `financial`, `occupation`, `status`,
   `providerID`, `date`,
   `ss`, `drivers_license`, `pharmacy_id`,
   `contact_relationship`, `referrer`, `referrerID`,
   `ethnoracial`, `race`, `ethnicity`, `religion`,
   `interpretter`, `migrantseasonal`, `family_size`, `monthly_income`,
   `homeless`,
   `genericname1`, `genericval1`, `genericname2`, `genericval2`,
   `hipaa_mail`, `hipaa_voice`, `hipaa_notice`, `hipaa_message`,
   `hipaa_allowsms`, `hipaa_allowemail`)
VALUES
-- Ted Shaw: T2D, HTN, CKD Stage 2 (pid=1)
(1, 1, '1', 'Mr.', 'Ted', 'Shaw', 'R.', '1958-03-22', 'Male',
 '842 Ridgecrest Ave', 'Austin', 'TX', '78701', 'USA',
 '(512) 555-1001', '(512) 555-1002', 'tshaw@example.com',
 'english', '', 'Retired Teacher', 'married',
 1, '2022-05-01 08:00:00',
 '123-45-6789', '', 0,
 'Spouse', '', '',
 '', 'white', 'not_hispanic_or_latino', '',
 '', '', '2', '3500',
 '',
 '', '', '', '',
 'YES', 'YES', 'YES', 'YES',
 'YES', 'NO'),

-- Farrah Rolle: Asthma, Hyperlipidemia, GERD (pid=5)
(5, 5, '5', 'Ms.', 'Farrah', 'Rolle', 'A.', '1973-10-11', 'Female',
 '111 Main Street', 'San Luis', 'CA', '92101', 'USA',
 '(619) 555-2222', '(619) 555-3333', 'frolle@example.com',
 'english', '', 'Attorney', 'married',
 1, '2022-06-01 08:00:00',
 '456-78-9123', '', 0,
 'Spouse', '', '',
 'Latina', 'black', 'hispanic_or_latino', '',
 '', '', '3', '7200',
 '',
 '', '', '', '',
 'YES', 'YES', 'YES', 'YES',
 'NO', 'NO'),

-- Nora Cohen: MDD, Hypothyroid, Insomnia (pid=8)
(8, 8, '8', 'Ms.', 'Nora', 'Cohen', 'L.', '1989-07-04', 'Female',
 '330 Birchwood Dr', 'Boulder', 'CO', '80302', 'USA',
 '(720) 555-8810', '(720) 555-8811', 'ncohen@example.com',
 'english', '', 'Software Engineer', 'single',
 1, '2022-07-01 08:00:00',
 '789-01-2345', '', 0,
 '', '', '',
 '', 'white', 'not_hispanic_or_latino', '',
 '', '', '1', '6500',
 '',
 '', '', '', '',
 'YES', 'NO', 'YES', 'YES',
 'YES', 'YES');

SET FOREIGN_KEY_CHECKS=1;
