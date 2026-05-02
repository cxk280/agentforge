<?php

/**
 * EPCS demo seeder for Co-Pilot Screen 42.
 *
 * The base `prescriptions` schema does not model DEA scheduling or
 * EPCS sign-off state, so we extend it with four nullable columns the
 * EPCS signing-queue page needs:
 *
 *   - `cp_dea_schedule`   VARCHAR(8)   - 'II' / 'III' / 'IV' / 'V'
 *   - `cp_dispense_status` VARCHAR(32) - 'awaiting_sign' / 'signed' / 'transmitted'
 *   - `cp_date_signed`    DATETIME     - when EPCS sign-off occurred
 *   - `cp_signer_user_id` INT          - users.id of the signer
 *
 * Then we INSERT four controlled-substance prescriptions across two
 * real patients so the page renders a meaningful queue.
 *
 * Idempotent — uses globals.copilot_epcs_v1 marker. Adding a column
 * twice is a no-op (information_schema check guards it).
 *
 * URL: /interface/super/copilot_seed_epcs.php?confirm=1
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
    echo '<pre>Add ?confirm=1 to seed Co-Pilot EPCS demo prescriptions.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$marker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'copilot_epcs_v1'");
if (!empty($marker['gl_value'])) {
    echo "Already seeded (gl_value = {$marker['gl_value']}). Skipping.\n";
    echo "If you really need to re-run: DELETE FROM globals WHERE gl_name='copilot_epcs_v1'\n";
    exit;
}

echo "AgentForge Co-Pilot EPCS seed - starting at " . date('Y-m-d H:i:s') . "\n";

// ---- 1. Extend prescriptions schema if needed ---------------------------
$dbName = sqlQuery("SELECT DATABASE() AS d")['d'] ?? '';
$columnsToAdd = [
    'cp_dea_schedule'    => "ALTER TABLE prescriptions ADD COLUMN cp_dea_schedule VARCHAR(8) NULL DEFAULT NULL",
    'cp_dispense_status' => "ALTER TABLE prescriptions ADD COLUMN cp_dispense_status VARCHAR(32) NULL DEFAULT NULL",
    'cp_date_signed'     => "ALTER TABLE prescriptions ADD COLUMN cp_date_signed DATETIME NULL DEFAULT NULL",
    'cp_signer_user_id'  => "ALTER TABLE prescriptions ADD COLUMN cp_signer_user_id INT NULL DEFAULT NULL",
];
foreach ($columnsToAdd as $col => $ddl) {
    $exists = sqlQuery(
        "SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'prescriptions' AND COLUMN_NAME = ?",
        [$dbName, $col]
    );
    if (!empty($exists['x'])) {
        echo "  column $col already exists - skip\n";
        continue;
    }
    sqlStatement($ddl);
    echo "  added column $col\n";
}

// ---- 2. Add a seed-anchor flag so we can dedupe inserts -----------------
//
// We re-purpose the `external_id` column (varchar(20), nullable) to store
// a stable token "EPCS_SEED_<n>" so the loop is idempotent without a new
// schema column.

$providerId = (int)(sqlQuery(
    "SELECT id FROM users WHERE username = 'erivera' LIMIT 1"
)['id'] ?? 1);

// Pick two real seeded patients to attach the demo controlled-substance Rx
// to. Ted Shaw (pid=1) and Farrah Rolle (pid=5) are guaranteed to exist
// per copilot_seed_demo_patients.php.
$rxs = [
    // [external_id, pid, drug, drug_id, dosage, qty, sig, dea, refills]
    ['EPCS_SEED_1', 1, 'Tramadol 50 mg',     0, '50 mg',  '20', 'Cap, q6h PRN pain',           'IV', 0],
    ['EPCS_SEED_2', 1, 'Oxycodone 5 mg',     0, '5 mg',   '15', 'Tab, q4-6h PRN severe pain',  'II', 0],
    ['EPCS_SEED_3', 5, 'Lorazepam 1 mg',     0, '1 mg',   '30', 'Tab, qHS PRN anxiety',        'IV', 0],
    ['EPCS_SEED_4', 5, 'Adderall XR 10 mg', 10, '10 mg',  '30', 'Cap, daily AM',               'II', 0],
];

$inserted = 0;
$skipped = 0;
foreach ($rxs as [$ext, $pid, $drug, $drugId, $dosage, $qty, $sig, $dea, $refills]) {
    // Patient must exist.
    $patient = sqlQuery("SELECT pid FROM patient_data WHERE pid = ?", [$pid]);
    if (empty($patient)) {
        echo "  pid=$pid not found - skip $ext\n";
        $skipped++;
        continue;
    }
    // Dedup by external_id token.
    $exists = sqlQuery("SELECT id FROM prescriptions WHERE external_id = ?", [$ext]);
    if (!empty($exists)) {
        echo "  $ext already inserted - skip\n";
        $skipped++;
        continue;
    }
    sqlInsert(
        "INSERT INTO prescriptions
            (patient_id, provider_id, drug, drug_id, dosage, quantity, refills,
             pharmacy_id, start_date, date_added, txDate, active, user, note,
             external_id, cp_dea_schedule, cp_dispense_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW(), CURDATE(), 0, 'admin',
                 'CVS Pharmacy #4291 · Austin, TX', ?, ?, 'awaiting_sign')",
        [
            $pid, $providerId, $drug, $drugId, $dosage, $qty, $refills,
            (int)(sqlQuery("SELECT id FROM pharmacies LIMIT 1")['id'] ?? 1),
            $ext, $dea,
        ]
    );
    echo "  inserted $ext (pid=$pid, $drug, Schedule $dea)\n";
    $inserted++;
}

echo "\nInserted $inserted EPCS demo prescriptions ($skipped skipped).\n";

// ---- 3. Mark seed complete ---------------------------------------------
sqlStatement(
    "INSERT INTO globals (gl_name, gl_index, gl_value)
     VALUES ('copilot_epcs_v1', 0, ?)
     ON DUPLICATE KEY UPDATE gl_value = VALUES(gl_value)",
    [date('Y-m-d H:i:s')]
);

echo "Done. Marker copilot_epcs_v1 set.\n";
