<?php

/**
 * Recalls demo seeder for Co-Pilot Screen 33.
 *
 * `medex_recalls` is OpenEMR's recall queue. The base schema
 * (`r_ID, r_PRACTID, r_pid, r_eventDate, r_facility, r_provider,
 *  r_reason, r_created`) doesn't model contact-attempt state, so we
 * extend it with three nullable columns the Co-Pilot UI needs:
 *
 *   - `cp_status`        VARCHAR(32)  - workflow state
 *   - `cp_last_contact`  DATETIME     - timestamp of last outreach
 *   - `cp_attempts`      INT          - count of outreach attempts
 *
 * Then we insert ~14 demo recalls — one per seeded patient — wired to
 * real `users.id` provider rows so the queue page renders real names.
 *
 * Idempotent — uses globals.copilot_recalls_v1 marker. Adding a column
 * twice is a no-op (information_schema check guards it).
 *
 * URL: /interface/super/copilot_seed_recalls.php?confirm=1
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    exit('Admin access required.');
}

if (($_GET['confirm'] ?? '') !== '1') {
    echo '<pre>Add ?confirm=1 to seed Co-Pilot recall queue.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$marker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'copilot_recalls_v1'");
if (!empty($marker['gl_value'])) {
    echo "Already seeded (gl_value = {$marker['gl_value']}). Skipping.\n";
    echo "If you really need to re-run: DELETE FROM globals WHERE gl_name='copilot_recalls_v1'\n";
    exit;
}

echo "AgentForge Co-Pilot recalls seed - starting at " . date('Y-m-d H:i:s') . "\n";

// ---- 1. Extend medex_recalls schema if needed ---------------------------
$dbName = sqlQuery("SELECT DATABASE() AS d")['d'] ?? '';
$columnsToAdd = [
    'cp_status'       => "ALTER TABLE medex_recalls ADD COLUMN cp_status VARCHAR(32) NULL DEFAULT 'pending_outreach'",
    'cp_last_contact' => "ALTER TABLE medex_recalls ADD COLUMN cp_last_contact DATETIME NULL DEFAULT NULL",
    'cp_attempts'     => "ALTER TABLE medex_recalls ADD COLUMN cp_attempts INT NOT NULL DEFAULT 0",
];
foreach ($columnsToAdd as $col => $ddl) {
    $exists = sqlQuery(
        "SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'medex_recalls' AND COLUMN_NAME = ?",
        [$dbName, $col]
    );
    if (!empty($exists['x'])) {
        echo "  column $col already exists - skip\n";
        continue;
    }
    sqlStatement($ddl);
    echo "  added column $col\n";
}

// ---- 2. Build a deterministic recall row per seeded patient -------------
//
// $recalls is a list of:
//   [pid, recall_type, days_offset_from_today, status,
//    last_contact_method (null|string), last_contact_days_ago, attempts,
//    provider_user_id]
//
// days_offset_from_today: negative = overdue, positive = future due date
// last_contact_method: null = no outreach yet, otherwise free-text token
//
// Provider user IDs come from the seeded users table:
//   5=erivera (Dr. Rivera, MD), 6=apark (Dr. Park, DO),
//   7=jpatel (Dr. Patel, MD), 8=llee (Dr. Lee, MD),
//   9=kkim (Dr. Kim, MD), 11=schoi (Sandra Choi, RN)
$recalls = [
    // [pid, type,                days,   status,             contact_method, days_ago, attempts, prov]
    [1,  'Mammogram',              13,  'sent_awaiting',    'Letter',       30,  2, 5],
    [4,  'Annual physical',        18,  'scheduled',        'Portal',       18,  1, 5],
    [5,  'HbA1c lab',              -7,  'no_response',      'Phone',        35,  3, 5],
    [8,  'Colonoscopy',            31,  'pending_outreach', null,            0,  0, 9],
    [17, 'DEXA scan',             -78,  'refused',          'Letter',       77,  4, 5],
    [18, 'Annual physical',         6,  'lm_voicemail',     'Phone',        10,  1, 9],
    [22, 'Flu shot',             -200,  'no_response',      'Email',       200,  2, 11],
    [25, 'BP recheck',              8,  'scheduled',        'Portal',        4,  1, 9],
    [26, 'Pap smear',              28,  'pending_outreach', null,            0,  0, 7],
    [30, 'Diabetes f/u',           16,  'sent_awaiting',    'Letter',       22,  2, 9],
    [34, 'Annual physical',        41,  'pending_outreach', null,            0,  0, 11],
    [35, 'New patient intake',      3,  'sent_awaiting',    'Email',         2,  1, 7],
    [40, 'Cholesterol panel',     -14,  'no_response',      'Phone',        21,  2, 8],
    [41, 'Annual physical',        24,  'lm_voicemail',     'Phone',        14,  2, 5],
];

$inserted = 0;
$skipped = 0;
foreach ($recalls as $r) {
    [$pid, $type, $days, $status, $method, $daysAgo, $attempts, $provId] = $r;

    // Skip if patient row missing (defensive)
    $exists = sqlQuery("SELECT pid FROM patient_data WHERE pid = ?", [$pid]);
    if (empty($exists)) {
        echo "  pid=$pid not found - skip recall '$type'\n";
        $skipped++;
        continue;
    }

    // r_PRACTID is part of a UNIQUE(r_PRACTID, r_pid) index. The MedEx
    // module uses it as a per-practice scope id; for Co-Pilot we just
    // pin it to 1 and rely on r_pid uniqueness within practice 1.
    $eventDate = date('Y-m-d', strtotime("today $days days"));
    $lastContact = ($method === null)
        ? null
        : date('Y-m-d H:i:s', strtotime("today -$daysAgo days") + (9 * 3600) + (mt_rand(0, 480) * 60));
    $reason = $method === null
        ? $type
        : "$type ($method outreach)";

    // INSERT IGNORE so re-running the seed (after manual marker delete)
    // doesn't crash on the (r_PRACTID, r_pid) unique constraint.
    sqlStatement(
        "INSERT IGNORE INTO medex_recalls
            (r_PRACTID, r_pid, r_eventDate, r_facility, r_provider, r_reason,
             cp_status, cp_last_contact, cp_attempts)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [1, $pid, $eventDate, 3, $provId, $reason, $status, $lastContact, $attempts]
    );
    $inserted++;
}

echo "\nInserted $inserted recall rows ($skipped skipped).\n";

// ---- 3. Mark seed complete ---------------------------------------------
sqlStatement(
    "INSERT INTO globals (gl_name, gl_index, gl_value)
     VALUES ('copilot_recalls_v1', 0, ?)
     ON DUPLICATE KEY UPDATE gl_value = VALUES(gl_value)",
    [date('Y-m-d H:i:s')]
);

echo "Done. Marker copilot_recalls_v1 set.\n";
