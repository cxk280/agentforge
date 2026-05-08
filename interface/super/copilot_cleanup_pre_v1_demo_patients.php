<?php

/**
 * Demo-patient cleanup — brings deployed envs (dev/qa/prod) into
 * parity with the regenerated demo_patients_v1.sql cohort
 * {1, 4, 5, 8, 17}. Deletes rows for the 10 prior-cohort pids that
 * are no longer in the canonical dump:
 *
 *   18  Richard Jones
 *   22  Ilias Jenane
 *   25  John Dockerty
 *   26  James Janssen
 *   30  Jason Binder
 *   34  Robert Dickey
 *   35  Jillian Mahoney
 *   40  Wallace Buckley
 *   41  Brent Perez
 *   42  QA44893 TestPatient
 *
 * Scope: only the six tables the seed dump touches — patient_data,
 * form_encounter, form_vitals, lists, prescriptions, immunizations.
 * Audit log + stock OpenEMR tables are intentionally NOT touched.
 *
 * Idempotent — DELETE on a missing row is a 0-rows-affected no-op.
 * Uses globals.copilot_cleanup_pre_v1 as a "did this run" marker so
 * future operators can see when the env was reconciled, but the
 * marker does NOT short-circuit the script — re-runs are safe and
 * remain no-ops.
 *
 * URL: /interface/super/copilot_cleanup_pre_v1_demo_patients.php?confirm=1
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
    echo '<pre>Add ?confirm=1 to delete pids 18, 22, 25, 26, 30, 34, 35, 40, 41, 42 from the seed-dump tables.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "AgentForge demo-patient cleanup — starting at " . date('Y-m-d H:i:s') . "\n";
echo "Target pids: 18, 22, 25, 26, 30, 34, 35, 40, 41, 42\n\n";

$targetPids = [18, 22, 25, 26, 30, 34, 35, 40, 41, 42];
$placeholders = implode(',', array_fill(0, count($targetPids), '?'));

// Order: child rows first, parents last. Even though OpenEMR's
// tables aren't FK-constrained, deleting patient_data last avoids
// any noise from triggers / app-level invariants.
$plan = [
    ['form_vitals',    'pid'],
    ['form_encounter', 'pid'],
    ['lists',          'pid'],
    ['prescriptions',  'patient_id'],
    ['immunizations',  'patient_id'],
    ['patient_data',   'pid'],
];

$totalDeleted = 0;
foreach ($plan as [$table, $col]) {
    // Pre-count for the report. SELECT is cheap; the DELETE is the
    // load-bearing op.
    $preRow = sqlQuery(
        "SELECT COUNT(*) AS n FROM `$table` WHERE `$col` IN ($placeholders)",
        $targetPids
    );
    $pre = (int) ($preRow['n'] ?? 0);

    sqlStatement(
        "DELETE FROM `$table` WHERE `$col` IN ($placeholders)",
        $targetPids
    );

    // Confirm by post-counting — should be 0.
    $postRow = sqlQuery(
        "SELECT COUNT(*) AS n FROM `$table` WHERE `$col` IN ($placeholders)",
        $targetPids
    );
    $post = (int) ($postRow['n'] ?? 0);

    $deleted = $pre - $post;
    $totalDeleted += $deleted;
    printf("  %-16s deleted=%-3d remaining=%d\n", $table, $deleted, $post);
}

echo "\nTotal rows deleted across 6 tables: $totalDeleted\n";

// Set / refresh the marker (idempotent — INSERT IGNORE then UPDATE).
sqlStatement(
    "INSERT IGNORE INTO globals (gl_name, gl_index, gl_value) VALUES ('copilot_cleanup_pre_v1', 0, ?)",
    [date('c')]
);
sqlStatement(
    "UPDATE globals SET gl_value = ? WHERE gl_name = 'copilot_cleanup_pre_v1'",
    [date('c')]
);

echo "\nMarker set: gl_name='copilot_cleanup_pre_v1' gl_value=" . date('c') . "\n";

// Final-state summary so the operator can confirm parity at a glance.
$summary = sqlStatement("SELECT pid, fname, lname FROM patient_data ORDER BY pid");
echo "\nFinal patient_data cohort:\n";
while ($row = sqlFetchArray($summary)) {
    printf("  pid=%-3d %s %s\n", $row['pid'], $row['fname'], $row['lname']);
}
