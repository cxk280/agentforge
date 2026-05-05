<?php

/**
 * Minimal multipart upload handler for the W2 Documents tab.
 *
 * Wraps OpenEMR's legacy `addNewDocument()` so the Upload button on
 * copilot_documents.php has a working server endpoint without going
 * through the full add_document.php / controller.php flow (which
 * redirects + re-renders the legacy UI).
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
require_once($GLOBALS['srcdir'] . "/documents.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

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
if (!is_string($_csrf) || !CsrfUtils::verifyCsrfToken($_csrf)) {
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
if (!is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
    _emit(['ok' => false, 'error' => 'tmp_name is not an uploaded file'], 400);
}

// Cap reasonable size (10 MB) — sample PDFs run a few KB; lab PDFs
// in the wild are usually ≤ 5 MB. Bigger files belong on a real
// file upload pipeline.
if ((int)$f['size'] > 10 * 1024 * 1024) {
    _emit(['ok' => false, 'error' => 'File too large (max 10 MB)'], 413);
}

$activeUserId = (int)($_SESSION['authUserID'] ?? 0);
$result = addNewDocument(
    (string)$f['name'],
    (string)($f['type'] ?? 'application/octet-stream'),
    (string)$f['tmp_name'],
    (int)($f['error'] ?? 0),
    (int)$f['size'],
    $activeUserId,
    (string)$pid,
    $catId
);

if ($result === false || empty($result['doc_id'])) {
    _emit(['ok' => false, 'error' => 'addNewDocument() returned false'], 500);
}

_emit([
    'ok'     => true,
    'doc_id' => (int)$result['doc_id'],
    'name'   => $result['name'] ?? (string)$f['name'],
    'url'    => $result['url']  ?? '',
]);
