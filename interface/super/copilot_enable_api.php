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

// site_addr_oath must be the URL of THIS OpenEMR instance — every
// env (local/dev/qa/prod) needs its own value. We derive it from
// the OE_SELF_BASE_URL env var when set (each Railway env exports
// its own); otherwise fall back to the request's own scheme+host.
// Do NOT hardcode a specific env's URL here — that breaks
// environment isolation (see feedback_environment_isolation.md):
// running this script in qa/dev with a prod URL baked in writes
// prod's URL into qa/dev's globals table.
$siteAddrOath = getenv('OE_SELF_BASE_URL');
if ($siteAddrOath === false || $siteAddrOath === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $siteAddrOath = $scheme . '://' . $host;
}

$flags = [
    'rest_api'                       => '1',  // legacy REST API
    'rest_fhir_api'                  => '1',  // FHIR R4 API
    'rest_portal_api'                => '0',
    'oauth_password_grant'           => '1',  // password-grant flow (what the agent uses)
    'oauth_app_manual_approval'      => '0',  // skip per-client approval prompts
    'site_addr_oath'                 => $siteAddrOath,
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

echo "\nForce-enable all OAuth2 clients (idempotent):\n";
$enabled = sqlStatement("UPDATE oauth_clients SET is_enabled = 1 WHERE COALESCE(is_enabled, 0) <> 1");
echo "  oauth_clients UPDATE done\n";

// AgentForge agent client registration. The agent authenticates via
// the password grant using OPENEMR_CLIENT_ID + OPENEMR_CLIENT_SECRET
// from its Railway env vars; the matching row has to exist in
// oauth_clients on this OpenEMR instance for that to work. On a
// fresh container the row isn't seeded, hence "invalid_client" 401s.
//
// We INSERT (or refresh) the row using the same flow OpenEMR's
// dynamic client registration uses, encrypting the secret via
// CryptoGen::encryptStandard so the OAuth server can decrypt it
// back at token-grant time.
$agentClientId = getenv('OPENEMR_CLIENT_ID');
$agentClientSecret = getenv('OPENEMR_CLIENT_SECRET');
if ($agentClientId && $agentClientSecret) {
    // Wrap the lookup + crypto init + INSERT/UPDATE in a try/catch so a
    // missing encryption key file (which would otherwise silently abort
    // the script via OpenEMR's exception handler) surfaces as a visible
    // error in the response rather than a confusing "no oauth_clients
    // rows yet" message a few lines down.
    $existing = false;
    $encryptedSecret = null;
    try {
        $existing = sqlQuery(
            "SELECT client_id, is_enabled FROM oauth_clients WHERE client_id = ?",
            [$agentClientId]
        );
        $cryptoGen = \OpenEMR\BC\ServiceContainer::getCrypto();
        $encryptedSecret = $cryptoGen->encryptStandard($agentClientSecret);
    } catch (Throwable $e) {
        echo "  ERROR registering agent client: " . $e->getMessage() . "\n";
    }
    if ($encryptedSecret === null) {
        echo "  (skipping insert/update due to error above)\n";
    } elseif (!$existing) {
        // Fresh row — insert with sane defaults for a confidential
        // service-to-service client using the password grant.
        sqlStatement(
            "INSERT INTO oauth_clients
                (client_id, client_role, client_name, client_secret,
                 registration_token, registration_uri_path,
                 register_date, contacts, redirect_uri, grant_types,
                 scope, user_id, site_id, is_confidential,
                 logout_redirect_uris, is_enabled, dsi_type)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $agentClientId,
                'user',
                'AgentForge Co-Pilot agent',
                $encryptedSecret,
                '',                    // no registration_token (out-of-band registration)
                '',                    // no dynamic registration_uri_path
                'agentforge@local',    // contacts
                $siteAddrOath . '/oauth2/default/registration', // redirect_uri (unused for password grant)
                'password',            // grant_types
                'openid api:fhir user/Patient.read user/Observation.read user/MedicationRequest.read user/Condition.read user/AllergyIntolerance.read user/Encounter.read offline_access',
                1,                     // user_id (admin)
                'default',             // site_id
                1,                     // is_confidential
                null,                  // logout_redirect_uris
                1,                     // is_enabled
                0,                     // dsi_type (none)
            ]
        );
        echo "  REGISTERED agent OAuth client : client_id=$agentClientId\n";
    } else {
        // Row exists — refresh the encrypted secret so a rotated
        // env var lands cleanly without a manual re-registration.
        sqlStatement(
            "UPDATE oauth_clients
                SET client_secret = ?, is_enabled = 1
              WHERE client_id = ?",
            [$encryptedSecret, $agentClientId]
        );
        echo "  REFRESHED agent OAuth client  : client_id=$agentClientId\n";
    }
} else {
    echo "  (OPENEMR_CLIENT_ID / OPENEMR_CLIENT_SECRET not set in env — skipping agent client registration)\n";
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
