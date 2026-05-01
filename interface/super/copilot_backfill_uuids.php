<?php

/**
 * AgentForge UUID backfill — patient_data rows with NULL/empty uuid get
 * a freshly-generated v4 UUID. The Co-Pilot agent's FHIR resolver
 * requires non-null UUIDs; production patients seeded from snapshots
 * may lack them. Idempotent — uses globals.copilot_uuid_backfill_v1
 * marker.
 *
 * URL: /interface/super/copilot_backfill_uuids.php?confirm=1
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
    echo '<pre>Add ?confirm=1 to backfill missing patient UUIDs.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$marker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'copilot_uuid_backfill_v1'");
if (!empty($marker['gl_value'])) {
    echo "Already backfilled (gl_value = {$marker['gl_value']}). Skipping.\n";
    echo "If you really need to re-run: DELETE FROM globals WHERE gl_name='copilot_uuid_backfill_v1'\n";
    exit;
}

echo "AgentForge UUID backfill — starting at " . date('Y-m-d H:i:s') . "\n\n";

$missing = sqlStatement("SELECT pid, fname, lname FROM patient_data WHERE uuid IS NULL OR uuid = '' OR LENGTH(uuid) < 16");
$updated = 0;
while ($r = sqlFetchArray($missing)) {
    // Generate a v4 UUID, store as binary(16).
    $bytes = random_bytes(16);
    $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40); // version 4
    $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80); // variant
    sqlStatement("UPDATE patient_data SET uuid = ? WHERE pid = ?", [$bytes, (int)$r['pid']]);
    $updated++;
    echo "  pid={$r['pid']}  {$r['fname']} {$r['lname']}  → UUID generated\n";
}

sqlStatement(
    "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('copilot_uuid_backfill_v1', 0, ?)",
    [date('c')]
);

echo "\nDone. Updated $updated patient row(s).\n";
echo "Marker set: gl_name='copilot_uuid_backfill_v1' gl_value=" . date('c') . "\n";
