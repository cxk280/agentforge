<?php

/**
 * Minimal multipart upload handler for the W2 Documents tab.
 *
 * Bypasses OpenEMR's `addNewDocument()` because that path constructs
 * `C_Document`, which constructs `CategoryTree`, which calls
 * `Tree::load_tree()` — and on the AgentForge demo dataset that
 * function hits an infinite "Undefined array key -1" loop until
 * PHP's max_execution_time kills the request. We do the equivalent
 * work directly: save the file to disk under sites/<site>/documents/
 * <pid>/, INSERT into the `documents` table, INSERT the row in
 * `categories_to_documents`. The bbox viewer + extraction pipeline
 * read those tables directly so they don't care which path created
 * the row.
 *
 * POST fields:
 *   - file (multipart)         the PDF/image to upload
 *   - patient_id               pid the document is filed under
 *   - category_id (optional)   document category (defaults to 1)
 *   - csrf_token_form          required
 *
 * Returns JSON: { ok: true, doc_id: <int>, name: "...", url: "..." }
 *               { ok: false, error: "..." } on failure
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

$_cpSession = SessionWrapperFactory::getInstance()->getActiveSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function _emit(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    _emit(['ok' => false, 'error' => 'POST required'], 405);
}
if (!AclMain::aclCheckCore('patients', 'docs')) {
    _emit(['ok' => false, 'error' => 'Not authorized'], 403);
}

$_csrf = $_POST['csrf_token_form'] ?? '';
if (!is_string($_csrf) || !CsrfUtils::verifyCsrfToken($_csrf, $_cpSession)) {
    _emit(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
}

$pid = (int)($_POST['patient_id'] ?? 0);
if ($pid <= 0) {
    _emit(['ok' => false, 'error' => 'Missing patient_id'], 400);
}
$catId = (int)($_POST['category_id'] ?? 1);
if ($catId <= 0) {
    $catId = 1;
}

if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
    _emit(['ok' => false, 'error' => 'No file uploaded (field "file")'], 400);
}
$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    _emit(['ok' => false, 'error' => 'Upload error code ' . (int)$f['error']], 400);
}
$tmp = (string)($f['tmp_name'] ?? '');
if (!is_uploaded_file($tmp)) {
    _emit(['ok' => false, 'error' => 'tmp_name is not an uploaded file'], 400);
}
if ((int)$f['size'] > 10 * 1024 * 1024) {
    _emit(['ok' => false, 'error' => 'File too large (max 10 MB)'], 413);
}

// ─── Destination on disk ────────────────────────────────────────────────
//
// Layout matches OpenEMR's stock convention so the file is reachable
// through the existing controller.php?document&retrieve route AND the
// FHIR DocumentReference layer: sites/<site>/documents/<pid>/<id>_<name>
$siteDir = $GLOBALS['OE_SITE_DIR'] ?? '/var/www/localhost/htdocs/openemr/sites/default';
$baseDir = rtrim($siteDir, '/') . '/documents/' . $pid;
if (!is_dir($baseDir)) {
    if (!@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        _emit(['ok' => false, 'error' => "Could not create $baseDir"], 500);
    }
}

// Sanitize the filename — OpenEMR replaces non-alnum with underscores.
$safeName = preg_replace('/[^A-Za-z0-9_.\-]+/', '_', (string)$f['name']) ?: 'upload';

// ─── Insert documents row first to get the id, then save file ──────────
$activeUserId = (int)($_SESSION['authUserID'] ?? 0);
$mime = (string)($f['type'] ?? 'application/octet-stream');
$size = (int)$f['size'];
$now  = date('Y-m-d H:i:s');

// OpenEMR's `documents.id` is an INT NOT NULL DEFAULT 0 (no
// auto_increment in this deployment). Compute the next id ourselves.
$nextRow = sqlQuery("SELECT COALESCE(MAX(id), 0) + 1 AS n FROM documents");
$docId = (int)($nextRow['n'] ?? 1);

sqlStatement(
    "INSERT INTO documents
        (id, type, size, date, url, mimetype, name, pages, owner, foreign_id, deleted, revision)
     VALUES (?, 'file_url', ?, ?, '', ?, ?, 0, ?, ?, 0, NOW())",
    [$docId, $size, $now, $mime, $safeName, $activeUserId, $pid]
);
$check = sqlQuery("SELECT id FROM documents WHERE id = ?", [$docId]);
if (!$check) {
    _emit(['ok' => false, 'error' => "INSERT into documents failed (id={$docId})"], 500);
}

$diskName = $docId . '_' . $safeName;
$diskPath = $baseDir . '/' . $diskName;
if (!@move_uploaded_file($tmp, $diskPath)) {
    // Roll back the row if we couldn't save the file.
    sqlStatement("DELETE FROM documents WHERE id = ?", [$docId]);
    _emit(['ok' => false, 'error' => "Could not write $diskPath"], 500);
}

$url = 'file://' . $diskPath;
sqlStatement("UPDATE documents SET url = ? WHERE id = ?", [$url, $docId]);

// Link to the requested category. Use INSERT IGNORE so re-uploads
// (which would re-INSERT the same pair) don't blow up.
sqlStatement(
    "INSERT IGNORE INTO categories_to_documents (category_id, document_id) VALUES (?, ?)",
    [$catId, $docId]
);

_emit([
    'ok'     => true,
    'doc_id' => $docId,
    'name'   => $safeName,
    'url'    => $url,
]);
