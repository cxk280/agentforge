<?php

/**
 * OAuth-key reset — recovery from the OpenEMR encryption-key
 * drive↔DB drift documented in project_openemr_key_drift memory.
 *
 * When MySQL's volume resets but the openemr disk persists, the
 * `keys` table loses its KEK. The next request regenerates a new
 * KEK + DEK in `sites/default/documents/logs_and_misc/methods/`.
 * However the rows `oauth2key` and `oauth2passphrase` (encrypted
 * under the OLD DEK) and the OAuth signing keypair files
 * (`sites/default/documents/certificates/oa{private,public}.key`,
 * also encrypted under the OLD DEK) become unreadable. This makes
 * `/oauth2/default/token` return 500 with
 *   Security error - problem with authorization server keys.
 *
 * This script clears those stale rows so OpenEMR's
 * `OAuth2KeyConfig::createOrRecreateKeys()` regen path runs on the
 * next OAuth request, generating fresh values that match the
 * current DEK. Combine with deleting the stale .key files at
 * `sites/default/documents/certificates/oa*.key` (do that via
 * railway ssh; this script only touches DB).
 *
 * URL: /interface/super/copilot_oauth_keys_reset.php?confirm=1
 *
 * Output: lists deleted row counts, then surfaces remaining `keys`
 * table entries as a sanity check.
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
    echo '<pre>Add ?confirm=1 to delete oauth2key + oauth2passphrase rows from the `keys` table.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "OAuth keys-table reset — starting at " . date('Y-m-d H:i:s') . "\n\n";

// Pre-counts. `keys` is a reserved word in MySQL — backtick-quote it.
$pre = sqlQuery("SELECT COUNT(*) AS n FROM `keys` WHERE name IN ('oauth2key', 'oauth2passphrase')");
echo "Stale rows present before delete: " . (int) ($pre['n'] ?? 0) . "\n";

sqlStatement("DELETE FROM `keys` WHERE name IN ('oauth2key', 'oauth2passphrase')");

$post = sqlQuery("SELECT COUNT(*) AS n FROM `keys` WHERE name IN ('oauth2key', 'oauth2passphrase')");
echo "Stale rows present after delete:  " . (int) ($post['n'] ?? 0) . "\n\n";

$rs = sqlStatement("SELECT name, LENGTH(value) AS val_len, date FROM `keys` ORDER BY date DESC");
echo "Remaining `keys` table entries:\n";
while ($r = sqlFetchArray($rs)) {
    printf("  %-32s len=%-6d date=%s\n", $r['name'], (int) $r['val_len'], $r['date']);
}

echo "\nDone. Next /oauth2/default/token call should regenerate the rows + the\n";
echo "oa{private,public}.key files under the current encryption key.\n";
