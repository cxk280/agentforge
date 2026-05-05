<?php

/**
 * AgentForge demo-data seeder.
 *
 * Idempotent — uses a marker row in `globals` (gl_name='copilot_seed_v1')
 * to skip on subsequent runs. Run ONCE per environment (local + Railway).
 *
 * Seeds: additional users (providers, nurse, billing, front desk),
 * facilities (Eastside, Surgery Ctr, Telehealth Hub), drugs catalogue,
 * pharmacies, onotes (office notes), and document records.
 *
 * Existing tables (patient_data, form_encounter, prescriptions, log) are
 * NOT touched — the OpenEMR demo dataset already has rows there.
 *
 * URL: /interface/super/copilot_seed_demo_data.php?confirm=1
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    exit('Admin access required.');
}

if (($_GET['confirm'] ?? '') !== '1') {
    echo '<pre>Add ?confirm=1 to run the seeder.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

// ── ALWAYS-RUN: Backfill users_secure for seeded logins ────────────────
//
// AgentForge's demo seed has historically inserted bcrypt passwords
// into users.password (the LEGACY column) — but OpenEMR's modern auth
// reads from users_secure.password. Without a users_secure row the
// seeded provider/nurse/etc. accounts can't log in at all, which is
// why every login was bouncing back to admin.
//
// This block runs on EVERY page load (before the seed marker check)
// and idempotently inserts users_secure rows for any seeded login
// that's missing one. Existing deploys (where the seed already fired
// before this fix) get repaired on the next visit; fresh DBs work
// correctly the first time the seeder runs.
$seeded_logins = [
    'erivera', 'apark', 'jpatel', 'llee', 'kkim',
    'mnunez', 'schoi', 'bhudson',
];
$shared_pwd_hash = password_hash('demopass', PASSWORD_BCRYPT);
$backfilled = 0;
foreach ($seeded_logins as $u) {
    $row = sqlQuery("SELECT id FROM users WHERE username = ?", [$u]);
    if (!$row) {
        continue;
    }
    $userId = (int)$row['id'];
    $existing = sqlQuery("SELECT id FROM users_secure WHERE id = ?", [$userId]);
    if ($existing) {
        continue;
    }
    sqlStatement(
        "INSERT INTO users_secure
            (id, username, password, last_update_password, last_update)
         VALUES (?, ?, ?, NOW(), NOW())",
        [$userId, $u, $shared_pwd_hash]
    );
    $backfilled++;
}
if ($backfilled > 0) {
    echo "users_secure backfill: created {$backfilled} row(s) for seeded logins.\n";
    echo "Each seeded user now logs in with password 'demopass'.\n\n";
}

// ── ALWAYS-RUN: idempotent pnotes seed for the Messages demo ───────────
//
// Has to run outside the marker gate so existing deploys (where the
// main seed already fired) get the new messages on the next visit. The
// loop dedups by (user, assigned_to, subject) so re-runs are no-ops.
// References usernames that may not yet exist on a brand-new DB; the
// gated body below creates them, and the live Messages query uses a
// LEFT JOIN so unresolved usernames just render as the bare string
// until users land.
$pnotesAlwaysRun = [
    ['apark',   'erivera', 1,   false, 1,    'Re: Ted Shaw — diabetes follow-up',
     "Eduardo,\n\nReviewed Ted's chart ahead of the follow-up visit. A1C trending up (8.2% on 04/28). I'd suggest we add an SGLT2i given his CKD3a — empagliflozin 10mg daily is reasonable.\n\nLet me know if you'd like to discuss before Wednesday.\n\n— Allison"],
    ['llee',    'erivera', 3,   false, 5,    'Pulmonary follow-up — Farrah Rolle',
     "Eduardo, saw Farrah today — asthma well-controlled on her current ICS-formoterol regimen. No changes needed. Will see her again in 6 months unless symptoms recur.\n\n— Lin"],
    ['mnunez',  'erivera', 5,   true,  null, 'URGENT: Lab callback — Ted Shaw',
     "LabCorp called: Ted's potassium came back at 5.6. Lab tech wants verbal acknowledgment before they release the result. Please call back at (512) 555-0142 ext 4521 ASAP.\n\n— Maria"],
    ['admin',   'erivera', 22,  false, null, 'Reminder: Q3 chart review due',
     "Friendly reminder — your Q3 sample-of-10 chart review is due by end of next week. Pull from any 10 patients you saw 04/01–06/30.\n\nThanks,\nAdmin"],
    ['kkim',    'erivera', 50,  false, 1,    'Cardiology consult — Ted Shaw scheduled',
     "Got Ted on the books for 05/22 at 10:00. I'll review his BP trend + CKD context before the visit; let me know if there's anything specific you'd like me to focus on.\n\n— Karen"],
    ['erivera', 'apark',   2,   false, 1,    'Thanks — appreciate the SGLT2 input',
     "Allison, agreed on adding empagliflozin. I'll start him at 10mg and re-check labs in 4 weeks. Will loop you back if anything funky.\n\n— Eduardo"],
    ['jpatel',  'apark',   7,   false, null, 'Endo group meeting — agenda',
     "Allison, attaching the agenda for Friday's endo group meeting. Want to make sure we have time to discuss the new ADA guidance on SGLT2 first-line use.\n\n— James"],
    ['mnunez',  'apark',   26,  false, 8,    'Patient form question — Nora Cohen',
     "Quick one — Nora dropped off a new intake form, but the family-history section is blank. Should I send it back for her to complete or just file what we have?\n\n— Maria"],
    ['apark',   'jpatel',  6,   false, null, 'Re: Endo group meeting — agenda',
     "James, agenda looks good. Adding a 5-minute slot at the end for the new SGLT2 guidance discussion.\n\n— Allison"],
    ['admin',   'jpatel',  30,  false, null, 'Welcome to AgentForge!',
     "Hi James — welcome aboard. Your account is set up. Let me know if you have any questions getting started.\n\n— Admin"],
    ['erivera', 'llee',    4,   false, 5,    'Re: Pulmonary follow-up — Farrah Rolle',
     "Lin, thanks for the update. Will note that in her chart for the next visit.\n\n— Eduardo"],
    ['erivera', 'kkim',    49,  false, 1,    'Re: Cardiology consult — Ted Shaw',
     "Karen, please focus on whether his HTN regimen is appropriate given the recent BP creep + CKD3a. Considering uptitrating lisinopril vs adding amlodipine.\n\n— Eduardo"],
    ['erivera', 'mnunez',  4,   false, null, 'Re: URGENT lab callback — Ted Shaw',
     "Maria, I called LabCorp back and acknowledged. Ted is aware. Thanks for the quick relay.\n\n— Eduardo"],
    ['apark',   'mnunez',  25,  false, 8,    'Re: Nora Cohen intake form',
     "Maria, please have her redo the family-history page — it's important for the upcoming preventive workup.\n\n— Allison"],
    ['erivera', 'schoi',   8,   false, 1,    'Pre-visit prep — Ted Shaw 05/22',
     "Sandra, please pull recent vitals + lab trend chart for Ted before his 05/22 follow-up. Particularly interested in BP readings from the past 6 months.\n\n— Eduardo"],
    ['admin',   'schoi',   100, false, null, 'Quarterly compliance training',
     "Sandra — quarterly HIPAA training assignment is in your portal. Due end of month.\n\n— Admin"],
    ['admin',   'bhudson', 12,  false, null, 'BCBS denial follow-up needed',
     "Brian, BCBS is bouncing the 99396 claims with modifier-25 issues. Can you take the lead on the appeal batch?\n\n— Admin"],
    ['erivera', 'bhudson', 70,  false, 4,    'Self-pay arrangement — Eduardo Perez',
     "Brian, Eduardo Perez asked about a self-pay arrangement. He's between insurers right now. Can you call him with options?\n\n— Eduardo"],
];
$pnInserted = 0;
foreach ($pnotesAlwaysRun as [$fromUn, $toUn, $hoursAgo, $urgent, $pid, $subject, $body]) {
    $exists = sqlQuery(
        "SELECT id FROM pnotes
          WHERE user = ? AND assigned_to = ? AND title = ? AND deleted = 0
          LIMIT 1",
        [$fromUn, $toUn, $subject]
    );
    if ($exists) {
        continue;
    }
    $when = date('Y-m-d H:i:s', strtotime("-{$hoursAgo} hours"));
    $status = $urgent ? 'High' : 'New';
    sqlInsert(
        "INSERT INTO pnotes
            (date, body, pid, user, groupname, activity, authorized,
             title, assigned_to, deleted, message_status)
         VALUES (?, ?, ?, ?, 'Default', 1, 1, ?, ?, 0, ?)",
        [$when, $body, $pid, $fromUn, $subject, $toUn, $status]
    );
    $pnInserted++;
}
if ($pnInserted > 0) {
    echo "pnotes seed: inserted {$pnInserted} row(s) — Messages demo inboxes populated.\n\n";
}

$marker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'copilot_seed_v1'");
if (!empty($marker['gl_value'])) {
    echo "Already seeded (gl_name='copilot_seed_v1' = {$marker['gl_value']}). Skipping main seed.\n";
    echo "If you really need to re-run: DELETE FROM globals WHERE gl_name='copilot_seed_v1' (NOT recommended — UI changes will mix with re-seeded rows).\n";
    exit;
}

echo "AgentForge demo seed — starting at " . date('Y-m-d H:i:s') . "\n\n";

$inserted = ['users' => 0, 'facility' => 0, 'pharmacies' => 0, 'drugs' => 0, 'onotes' => 0, 'documents' => 0, 'immunizations' => 0, 'pnotes' => 0];

// ── USERS ─────────────────────────────────────────────────────────────
// Add 6 provider/staff users alongside admin/davis/hamming.
$users = [
    ['erivera',  'Eduardo',     'Rivera',    'erivera@example.com',  'MD',  1, '1234567890', 'BR-1234567'],
    ['apark',    'Allison',     'Park',      'apark@example.com',    'DO',  1, '1234567891', null],
    ['jpatel',   'James',       'Patel',     'jpatel@example.com',   'MD',  1, '1234567892', null],
    ['llee',     'Lin',         'Lee',       'llee@example.com',     'MD',  1, '1234567893', null],
    ['kkim',     'Karen',       'Kim',       'kkim@example.com',     'MD',  1, '1234567894', null],
    ['mnunez',   'Maria',       'Nunez',     'mnunez@example.com',   '',    0, null,         null],
    ['schoi',    'Sandra',      'Choi',      'schoi@example.com',    'RN',  0, null,         null],
    ['bhudson',  'Brian',       'Hudson',    'bhudson@example.com',  '',    0, null,         null],
];
$pwd_hash = password_hash('demopass', PASSWORD_BCRYPT);
foreach ($users as [$user, $fn, $ln, $email, $title, $auth, $npi, $dea]) {
    $exists = sqlQuery("SELECT id FROM users WHERE username = ?", [$user]);
    if ($exists) { continue; }
    sqlInsert(
        "INSERT INTO users (username, password, fname, lname, email, title, authorized, npi, federaldrugid, active, see_auth, facility_id, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 3, 0)",
        [$user, $pwd_hash, $fn, $ln, $email, $title, $auth, $npi, $dea]
    );
    // users_secure is the modern auth table — without a row here, the
    // login form rejects the user even though the legacy users.password
    // column has the right hash. See backfill block at the top of this
    // file for the explanation.
    $newId = sqlQuery("SELECT id FROM users WHERE username = ?", [$user]);
    if ($newId) {
        sqlStatement(
            "INSERT INTO users_secure
                (id, username, password, last_update_password, last_update)
             VALUES (?, ?, ?, NOW(), NOW())",
            [(int)$newId['id'], $user, $pwd_hash]
        );
    }
    $inserted['users']++;
}

// ── FACILITY ───────────────────────────────────────────────────────────
$facilities = [
    ['Riverside Family Medicine', '(512) 555-0142', '847 Main Street', 'Austin', 'TX', '78701', '20-1111111', '1234567000', '#008C8C'],
    ['Eastside Clinic',           '(512) 555-0188', '5500 Cesar Chavez St', 'Austin', 'TX', '78702', '20-2222222', '1234567001', '#4785D9'],
    ['Surgery Center',            '(512) 555-0211', '300 W. 38th Street', 'Austin', 'TX', '78705', '20-3333333', '1234567002', '#8561C7'],
    ['Telehealth Hub',            '',                'Virtual',            '',       'TX', '00000',  '20-4444444', '1234567003', '#33A68C'],
];
foreach ($facilities as [$name, $phone, $street, $city, $state, $zip, $tax, $npi, $color]) {
    $exists = sqlQuery("SELECT id FROM facility WHERE name = ?", [$name]);
    if ($exists) { continue; }
    sqlInsert(
        "INSERT INTO facility (name, phone, street, city, state, postal_code, country_code, federal_ein, facility_npi, service_location, billing_location, accepts_assignment, color) VALUES (?, ?, ?, ?, ?, ?, 'US', ?, ?, 1, 1, 1, ?)",
        [$name, $phone, $street, $city, $state, $zip, $tax, $npi, $color]
    );
    $inserted['facility']++;
}

// ── PHARMACIES ─────────────────────────────────────────────────────────
$pharmacies = [
    ['CVS — 4500 Burnet Rd',   100001, 1234567010],
    ['Walgreens — Lamar Blvd', 100002, 1234567011],
    ['HEB Pharmacy — Mueller', 100003, 1234567012],
    ['Costco Pharmacy',        100004, 1234567013],
];
foreach ($pharmacies as [$name, $ncpdp, $npi]) {
    $exists = sqlQuery("SELECT id FROM pharmacies WHERE name = ?", [$name]);
    if ($exists) { continue; }
    // pharmacies.id has no auto-increment — assign next free id
    $next = sqlQuery("SELECT COALESCE(MAX(id), 0) + 1 AS n FROM pharmacies");
    $newId = (int)($next['n'] ?? 1);
    sqlStatement(
        "INSERT INTO pharmacies (id, name, transmit_method, email, ncpdp, npi) VALUES (?, ?, 1, '', ?, ?)",
        [$newId, $name, $ncpdp, $npi]
    );
    $inserted['pharmacies']++;
}

// ── DRUGS ──────────────────────────────────────────────────────────────
$drugs = [
    ['Lisinopril 10 mg tablet',     '00904-6770-61', 50,  100, 'tablet', '90 ct',  'oral'],
    ['Lisinopril 20 mg tablet',     '00904-6771-61', 30,  60,  'tablet', '90 ct',  'oral'],
    ['Metformin 1000 mg',           '00093-1052-01', 60,  200, 'tablet', '100 ct', 'oral'],
    ['Levothyroxine 50 mcg',        '00378-1804-01', 20,  60,  'tablet', '30 ct',  'oral'],
    ['Atorvastatin 40 mg',          '00071-0156-23', 40,  100, 'tablet', '90 ct',  'oral'],
    ['Penicillin 500 mg',           '00093-1110-01', 10,  30,  'tablet', '30 ct',  'oral'],
    ['Insulin Glargine pen',        '00088-2220-01', 5,   20,  'pen',    '3 mL',   'subcut'],
    ['Albuterol HFA inhaler',       '66993-019-68',  4,   12,  'inhaler','17g',    'inhalation'],
    ['Sertraline 50 mg',            '00378-3550-93', 30,  60,  'tablet', '30 ct',  'oral'],
    ['Adderall XR 20 mg',           '54092-385-30',  10,  30,  'capsule','30 ct',  'oral'],
];
foreach ($drugs as [$name, $ndc, $reorder, $max, $form, $size, $route]) {
    $exists = sqlQuery("SELECT drug_id FROM drugs WHERE name = ?", [$name]);
    if ($exists) { continue; }
    sqlInsert(
        "INSERT INTO drugs (name, ndc_number, reorder_point, max_level, form, size, route, active, dispensable) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)",
        [$name, $ndc, $reorder, $max, $form, $size, $route]
    );
    $inserted['drugs']++;
}

// ── ONOTES (Office Notes) ──────────────────────────────────────────────
$onotes = [
    ['admin',     'Default', 'BCBS PPO is denying CPT 99396 — verify modifier 25 attached.', 1],
    ['erivera',   'Default', 'Pull Margaret Chen prior eye exam before next visit. Endo flagged.', 1],
    ['mnunez',    'Default', 'Voicemail from Linda Martinez requesting MRI auth update — left callback.', 1],
    ['bhudson',   'Default', 'Carol Bennett payment plan: $150/mo agreed. First payment posted.', 1],
    ['admin',     'Default', 'Network issue 1pm-1:15pm — confirmed iframe shell auth flow recovered.', 1],
    ['erivera',   'Default', 'Refill protocol updated: 90-day supply standard for Lisinopril, A1C-stable patients.', 1],
];
$now = date('Y-m-d H:i:s');
foreach ($onotes as $i => [$user, $group, $body, $activity]) {
    // No natural unique key — use body prefix as dedup
    $needle = substr($body, 0, 40);
    $exists = sqlQuery("SELECT id FROM onotes WHERE LEFT(body, 40) = ?", [$needle]);
    if ($exists) { continue; }
    $when = date('Y-m-d H:i:s', strtotime("-$i hours"));
    sqlInsert(
        "INSERT INTO onotes (date, body, user, groupname, activity) VALUES (?, ?, ?, ?, ?)",
        [$when, $body, $user, $group, $activity]
    );
    $inserted['onotes']++;
}

// ── PNOTES (Messages) ──────────────────────────────────────────────────
// The pnotes inbox seed lives in the always-run block at the top of
// this file, so existing deploys (with the marker already set) also
// get the messages on next visit. The marker-gated body intentionally
// does not duplicate the inserts — see $pnotesAlwaysRun.

// ── DOCUMENTS ──────────────────────────────────────────────────────────
$docs = [
    ['HbA1c — Quest Diagnostics',     '2026-04-12', 'application/pdf', 124000],
    ['BMP Panel — Quest',             '2026-04-12', 'application/pdf', 218000],
    ['Foot X-ray — Riverside Imaging','2026-04-08', 'application/pdf', 1430000],
    ['Lipid Panel — LabCorp',         '2026-02-18', 'application/pdf', 156000],
    ['Mammogram — Solis Imaging',     '2025-11-22', 'application/pdf', 982000],
    ['EKG — In-clinic',               '2025-11-18', 'application/pdf', 88000],
    ['CMP Panel — Quest',             '2025-08-10', 'application/pdf', 174000],
];
foreach ($docs as [$name, $date, $mime, $size]) {
    $exists = sqlQuery("SELECT id FROM documents WHERE name = ?", [$name]);
    if ($exists) { continue; }
    // documents.id has no auto-increment — assign next free id
    $next = sqlQuery("SELECT COALESCE(MAX(id), 0) + 1 AS n FROM documents");
    $newId = (int)($next['n'] ?? 1);
    sqlStatement(
        "INSERT INTO documents (id, type, name, mimetype, size, docdate, date, foreign_id, owner, list_id) VALUES (?, 'file_url', ?, ?, ?, ?, NOW(), 1, 1, 0)",
        [$newId, $name, $mime, $size, $date]
    );
    $inserted['documents']++;
}

// ── IMMUNIZATIONS ──────────────────────────────────────────────────────
$imms = [
    [1, 'Influenza, quadrivalent',  '2025-10-14', 'Riverside Family Medicine', 'FL-7281'],
    [1, 'COVID-19 Pfizer Bivalent', '2025-09-22', 'Riverside Family Medicine', 'CV-9942'],
    [1, 'Tdap (Boostrix)',          '2024-03-08', 'Riverside Family Medicine', 'TD-1144'],
    [1, 'Pneumococcal (Prevnar 20)','2023-04-19', 'Riverside Family Medicine', 'PC-8821'],
    [1, 'Shingrix dose 2',          '2022-11-04', 'Riverside Family Medicine', 'SH-4488'],
];
foreach ($imms as [$pid, $vaccine, $date, $facility, $lot]) {
    $exists = sqlQuery("SELECT id FROM immunizations WHERE patient_id = ? AND administered_date = ? AND lot_number = ?", [$pid, $date, $lot]);
    if ($exists) { continue; }
    sqlInsert(
        "INSERT INTO immunizations (patient_id, administered_date, administered_by, note, lot_number) VALUES (?, ?, ?, ?, ?)",
        [$pid, $date, $facility, $vaccine, $lot]
    );
    $inserted['immunizations']++;
}

// ── MARKER ─────────────────────────────────────────────────────────────
sqlStatement("INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('copilot_seed_v1', 0, ?)", [date('c')]);

echo "Done. Inserted:\n";
foreach ($inserted as $k => $v) {
    echo "  $k: $v\n";
}
echo "\nMarker set: gl_name='copilot_seed_v1' gl_value=" . date('c') . "\n";
echo "\nSubsequent runs will skip. To re-seed (NOT recommended): DELETE FROM globals WHERE gl_name='copilot_seed_v1'\n";
