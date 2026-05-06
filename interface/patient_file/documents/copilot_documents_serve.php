<?php

/**
 * Minimal PDF/file streamer for the W2 bbox viewer.
 *
 * Bypasses OpenEMR's `controller.php?document&retrieve` because that
 * path constructs `Controller` → `C_Document` → `CategoryTree` →
 * `Tree::load_tree()`, which on the AgentForge demo dataset hits an
 * infinite "Undefined array key -1" loop until PHP's max_execution_time
 * kills the request — the same bug already worked around in
 * `copilot_documents_upload.php`. We do the equivalent ACL + lookup
 * directly: authorize against patients/docs, read the row, stream the
 * file bytes with the right Content-Type / Content-Length headers.
 *
 * Used by `copilot_doc_viewer.php` (PDF.js fetches the raw PDF via this
 * route) and any "View source" link from a chat citation.
 *
 * Query params:
 *   docref  (int, required)  documents.id of the file to stream
 *
 * Returns:
 *   200 with the file bytes (Content-Type from documents.mimetype,
 *       inline disposition) on success.
 *   400 / 403 / 404 / 500 with a short text body on failure.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// ─── Auth ────────────────────────────────────────────────────────────────
//
// Two paths:
//   1. Browser: standard OpenEMR session + ACL (`patients/docs`).
//   2. Co-Pilot agent: internal token. The agent runs in a separate
//      container in every deploy (Mac docker dev + Railway dev/qa/prod),
//      so it can't read the OpenEMR `sitesvolume` from its own
//      filesystem; it has to fetch document bytes through this
//      endpoint. It has no OpenEMR session — we let it bypass the
//      auth check by presenting an opaque shared secret in the
//      `X-Copilot-Internal-Token` header that matches the
//      `COPILOT_INTERNAL_TOKEN` env var inside the OpenEMR container.
//      Set the same value on both sides via the deploy env (never
//      hardcoded). When the env var is empty the bypass is disabled
//      and the endpoint behaves exactly like the browser path.
//
// `$ignoreAuth = true` MUST be set before globals.php is loaded —
// otherwise OpenEMR's bootstrap intercepts the request and 302s us
// to the login screen long before our ACL check would run.
$expectedToken = (string)(getenv('COPILOT_INTERNAL_TOKEN') ?: '');
$presentedToken = (string)($_SERVER['HTTP_X_COPILOT_INTERNAL_TOKEN'] ?? '');
$bypassAcl = ($expectedToken !== '' && $presentedToken !== ''
              && hash_equals($expectedToken, $presentedToken));
if ($bypassAcl) {
    $ignoreAuth = true;
}

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\SessionWrapperFactory;

if (!$bypassAcl && !AclMain::aclCheckCore('patients', 'docs')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not authorized";
    exit;
}

$docId = isset($_GET['docref']) ? (int)$_GET['docref'] : 0;
if ($docId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing docref";
    exit;
}

$row = sqlQuery(
    "SELECT id, foreign_id, name, mimetype, url FROM documents WHERE id = ? AND deleted = 0 LIMIT 1",
    [$docId]
);
if (empty($row)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Document not found";
    exit;
}

// Cross-check patient scope: if the session has an active pid, the
// requested doc must belong to it. Skip the check when no pid is set
// (e.g. cross-patient lookups from the agent-side admin tooling).
// OpenEMR uses Symfony's namespaced session — `$_SESSION['pid']` is
// always null. Read via SessionWrapper instead.
$activePid = (int)(SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 0);
if ($activePid && (int)$row['foreign_id'] !== $activePid) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Document does not belong to active patient";
    exit;
}

// `url` is `file://<absolute_path>` (the convention copilot_documents_upload.php
// writes) or a bare path for older rows. Resolve both.
$url = (string)$row['url'];
$path = str_starts_with($url, 'file://') ? substr($url, 7) : $url;
if ($path === '' || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Underlying file missing on disk";
    exit;
}

$mime = $row['mimetype'] ?: 'application/octet-stream';
$size = (int)@filesize($path);
$safeName = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', (string)$row['name']) ?: 'document';

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
