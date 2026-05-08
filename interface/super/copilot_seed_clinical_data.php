<?php

/**
 * Clinical demo data seeder — imports `sql/copilot_seeds/clinical_demo_data_v1.sql`
 * (procedure_order/report/result, billing, ar_session, ar_activity,
 * drug_inventory). Idempotent.
 *
 * Used to populate the Patient Results / Lab Overview / Pending Review /
 * Lab Documents / Aging / Billing Manager / Inventory pages with real
 * cross-checkable data — without this, those pages render empty-state in
 * deployed envs where their backing tables ship empty.
 *
 * URL: /interface/super/copilot_seed_clinical_data.php?confirm=1
 *
 * Idempotent — uses globals.copilot_clinical_data_v1 marker plus
 * INSERT IGNORE on every row in the SQL file. Re-running on an
 * already-seeded DB is a no-op (the marker check exits early).
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
    echo '<pre>Add ?confirm=1 to import clinical demo data.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$marker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'copilot_clinical_data_v1'");
if (!empty($marker['gl_value'])) {
    echo "Already imported (gl_value = {$marker['gl_value']}). Skipping.\n";
    echo "If you really need to re-run: DELETE FROM globals WHERE gl_name='copilot_clinical_data_v1'\n";
    exit;
}

$sqlPath = realpath(__DIR__ . "/../../sql/copilot_seeds/clinical_demo_data_v1.sql");
if (!$sqlPath || !is_readable($sqlPath)) {
    echo "ERROR: cannot read sql/copilot_seeds/clinical_demo_data_v1.sql\n";
    exit;
}

echo "AgentForge clinical demo-data import — starting at " . date('Y-m-d H:i:s') . "\n";
echo "Source: $sqlPath\n\n";

$source = file_get_contents($sqlPath);

// Split on ;\n boundaries; skip comments / SET statements / blank lines.
$inserts = preg_split('/;\s*\n/', $source);

$counts = [];
$applied = 0;
$errors = 0;

foreach ($inserts as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || str_starts_with($stmt, '--')) { continue; }
    // Skip MySQL session-mode comments — they aren't valid statements
    // when handed to mysqli without the multi-query flag.
    if (str_starts_with($stmt, '/*!') || str_starts_with($stmt, '/*')) { continue; }
    if (!str_starts_with($stmt, 'INSERT IGNORE INTO')) { continue; }

    if (preg_match('/INSERT IGNORE INTO `([^`]+)`/', $stmt, $m)) {
        $table = $m[1];
        $counts[$table] = $counts[$table] ?? ['applied' => 0, 'errors' => 0];
    } else {
        $table = '?';
    }

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

echo "By statement:\n";
foreach ($counts as $t => $c) {
    echo sprintf("  %-20s applied=%-3d errors=%-3d\n", $t, $c['applied'], $c['errors']);
}

sqlStatement(
    "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('copilot_clinical_data_v1', 0, ?)",
    [date('c')]
);

echo "\nDone. Applied $applied INSERT statements (errors: $errors).\n";
echo "Marker set: gl_name='copilot_clinical_data_v1' gl_value=" . date('c') . "\n";
echo "\nVisit:\n";
echo "  - Patient Results (per patient)\n";
echo "  - Aging Report\n";
echo "  - Billing Manager\n";
echo "  - Inventory\n";
echo "to confirm the new rows render correctly.\n";
