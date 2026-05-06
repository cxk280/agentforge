<?php

/**
 * Delete a document + everything we know about it.
 *
 * Wipes the documents row, all category linkages, all derived
 * cp_* facts/citations/runs, and the on-disk file if it lives at
 * a `file://` URL. The user-facing Delete button on the Documents
 * tab POSTs here.
 *
 * POST fields:
 *   - docref            (int, required) documents.id to delete
 *   - csrf_token_form   (required)
 *
 * Returns JSON: { ok: true, doc_id, name } on success
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

$docId = (int)($_POST['docref'] ?? 0);
if ($docId <= 0) {
    _emit(['ok' => false, 'error' => 'Missing or invalid docref'], 400);
}

// Look up the row before deleting so we can surface its name + clean
// up the on-disk file. Also enforces the patient-scope cross-check
// (only the active patient's docs can be deleted via this endpoint).
$row = sqlQuery(
    "SELECT id, foreign_id, name, url FROM documents WHERE id = ? AND deleted = 0 LIMIT 1",
    [$docId]
);
if (!$row) {
    _emit(['ok' => false, 'error' => "Document {$docId} not found"], 404);
}
$activePid = (int)($_cpSession->get('pid') ?? 0);
if ($activePid && (int)$row['foreign_id'] !== $activePid) {
    _emit(['ok' => false, 'error' => 'Document does not belong to active patient'], 403);
}

// ─── Delete derived data first (FK order matters) ──────────────────────
//
// cp_extraction_citations.fact_id → cp_extracted_facts.id, so citations
// have to go before facts. cp_extraction_runs is independent of the
// other two but shares the document_id key.
sqlStatement(
    "DELETE FROM cp_extraction_citations
      WHERE fact_id IN (SELECT id FROM cp_extracted_facts WHERE document_id = ?)",
    [$docId]
);
sqlStatement("DELETE FROM cp_extracted_facts WHERE document_id = ?", [$docId]);
sqlStatement("DELETE FROM cp_extraction_runs WHERE document_id = ?", [$docId]);
sqlStatement("DELETE FROM categories_to_documents WHERE document_id = ?", [$docId]);
// cp_doc_routing is lazily created by copilot_lab_documents.php on its
// first page render, so it may not exist on a fresh DB. OpenEMR's
// sqlStatement() calls HelpfulDie() on missing-table errors instead
// of throwing, so try/catch isn't sufficient — we mirror the
// CREATE TABLE IF NOT EXISTS the lab-inbox page does so the DELETE
// can always run. A no-op on existing schemas, free schema repair on
// fresh ones.
sqlStatement(
    "CREATE TABLE IF NOT EXISTS cp_doc_routing (
        document_id   INT NOT NULL,
        state         VARCHAR(24) NOT NULL DEFAULT 'inbox',
        forwarded_to  INT NULL,
        note          VARCHAR(255) NULL,
        updated_by    VARCHAR(64) NOT NULL DEFAULT '',
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (document_id),
        KEY ix_cp_route_state (state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
sqlStatement("DELETE FROM cp_doc_routing WHERE document_id = ?", [$docId]);

// ─── On-disk file ──────────────────────────────────────────────────────
$url = (string)($row['url'] ?? '');
$path = str_starts_with($url, 'file://') ? substr($url, 7) : '';
if ($path !== '' && is_file($path) && is_writable($path)) {
    @unlink($path);
}

// ─── Finally, the documents row ────────────────────────────────────────
sqlStatement("DELETE FROM documents WHERE id = ?", [$docId]);

_emit([
    'ok'     => true,
    'doc_id' => $docId,
    'name'   => (string)($row['name'] ?? ''),
]);
