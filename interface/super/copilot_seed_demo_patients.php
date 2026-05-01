<?php

/**
 * Demo-patient seeder — imports `sql/copilot_seeds/demo_patients_v1.sql`,
 * which is a dump of patient_data + form_encounter + form_vitals +
 * prescriptions + lists + immunizations from the local dev environment.
 *
 * Production OpenEMR (Railway) ships without demo patients; the
 * Co-Pilot agent's FHIR resolver requires real patient rows to
 * function. Run this once per environment to land the demo dataset.
 *
 * Idempotent — uses globals.copilot_demo_patients_v1 marker.
 *
 * URL: /interface/super/copilot_seed_demo_patients.php?confirm=1
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
    echo '<pre>Add ?confirm=1 to import demo patients.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$marker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'copilot_demo_patients_v1'");
if (!empty($marker['gl_value'])) {
    echo "Already imported (gl_value = {$marker['gl_value']}). Skipping.\n";
    echo "If you really need to re-run: DELETE FROM globals WHERE gl_name='copilot_demo_patients_v1'\n";
    exit;
}

$sqlPath = realpath(__DIR__ . "/../../sql/copilot_seeds/demo_patients_v1.sql");
if (!$sqlPath || !is_readable($sqlPath)) {
    echo "ERROR: cannot read sql/copilot_seeds/demo_patients_v1.sql\n";
    exit;
}

echo "AgentForge demo-patient import — starting at " . date('Y-m-d H:i:s') . "\n";
echo "Source: $sqlPath\n\n";

$source = file_get_contents($sqlPath);

// The dump uses INSERT INTO `tableName` (...) VALUES (...);
// Apply each statement, skipping rows that would collide on PK.
// We split on `;\n` boundaries since this is a non-extended dump.
$inserts = preg_split('/;\s*\n/', $source);

$counts = [];
$applied = 0;
$skipped = 0;
$errors = 0;

foreach ($inserts as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || str_starts_with($stmt, '/*') || str_starts_with($stmt, '--')) {
        continue;
    }
    if (!str_starts_with($stmt, 'INSERT INTO')) {
        continue;
    }
    // Extract table name for stats
    if (preg_match('/INSERT INTO `([^`]+)`/', $stmt, $m)) {
        $table = $m[1];
        $counts[$table] = $counts[$table] ?? ['applied' => 0, 'skipped' => 0, 'errors' => 0];
    } else {
        $table = '?';
    }
    // Replace `INSERT INTO` with `INSERT IGNORE INTO` so duplicate-PK
    // collisions skip gracefully rather than aborting.
    $stmt = preg_replace('/^INSERT INTO/', 'INSERT IGNORE INTO', $stmt, 1);
    try {
        sqlStatement($stmt);
        $applied++;
        $counts[$table]['applied']++;
    } catch (\Throwable $e) {
        $errors++;
        $counts[$table]['errors']++;
        echo "  ERROR on $table: " . $e->getMessage() . "\n";
    }
}

echo "By table:\n";
foreach ($counts as $t => $c) {
    echo sprintf("  %-20s applied=%-3d errors=%-3d\n", $t, $c['applied'], $c['errors']);
}

sqlStatement(
    "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('copilot_demo_patients_v1', 0, ?)",
    [date('c')]
);

echo "\nDone. Applied $applied INSERTs (errors: $errors).\n";
echo "Marker set: gl_name='copilot_demo_patients_v1' gl_value=" . date('c') . "\n";
echo "\nNext: visit /interface/super/copilot_backfill_uuids.php?confirm=1 if any patients\n";
echo "lack UUIDs (they should ship with UUIDs from local, but the backfill is idempotent).\n";
