<?php

/**
 * AgentForge — enable the OpenEMR REST + FHIR APIs and OAuth2 password
 * grant. Required so the Co-Pilot agent can fetch patient data via
 * FHIR. Idempotent — safe to re-run.
 *
 * URL: /interface/super/copilot_enable_api.php?confirm=1
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
    echo '<pre>Add ?confirm=1 to enable APIs.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$flags = [
    'rest_api'                       => '1',  // legacy REST API
    'rest_fhir_api'                  => '1',  // FHIR R4 API
    'rest_portal_api'                => '0',
    'oauth_password_grant'           => '1',  // password-grant flow (what the agent uses)
    'oauth_app_manual_approval'      => '0',  // skip per-client approval prompts
    'site_addr_oath'                 => 'https://openemr-production-971e.up.railway.app',
];

echo "AgentForge — enabling OpenEMR APIs\n";
echo str_repeat('=', 60) . "\n";

foreach ($flags as $name => $value) {
    $exists = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = ?", [$name]);
    if ($exists) {
        $current = $exists['gl_value'];
        if ((string)$current !== (string)$value) {
            sqlStatement("UPDATE globals SET gl_value = ? WHERE gl_name = ?", [$value, $name]);
            echo sprintf("  %-32s : %s -> %s\n", $name, $current, $value);
        } else {
            echo sprintf("  %-32s : already %s\n", $name, $value);
        }
    } else {
        sqlStatement(
            "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES (?, 0, ?)",
            [$name, $value]
        );
        echo sprintf("  %-32s : INSERTED %s\n", $name, $value);
    }
}

echo "\nOAuth2 client registration check:\n";
$client = sqlQuery("SELECT client_id, client_name, is_enabled FROM oauth_clients ORDER BY client_id LIMIT 5");
if ($client) {
    while ($client) {
        echo "  client_id={$client['client_id']}  name={$client['client_name']}  enabled={$client['is_enabled']}\n";
        $client = false; // sqlQuery returns one row; for full list use sqlStatement
    }
    $rows = sqlStatement("SELECT client_id, client_name, is_enabled FROM oauth_clients ORDER BY client_id");
    while ($r = sqlFetchArray($rows)) {
        echo "  client_id={$r['client_id']}  name={$r['client_name']}  enabled={$r['is_enabled']}\n";
    }
} else {
    echo "  (no oauth_clients rows yet — agent will fail to authenticate until registered)\n";
}

echo "\nDone.\n";
