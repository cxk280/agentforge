<?php

/**
 * Copilot Messages — minimal CRUD API for openemr `pnotes`.
 *
 * POST endpoint consumed by copilot_messages.php's modal + action
 * buttons. Five actions:
 *   action=send      { to_username, subject, body, patient_id? }
 *   action=archive   { eid }   — flip pnotes.activity = 0
 *   action=restore   { eid }   — flip pnotes.activity = 1
 *   action=delete    { eid }   — soft delete (pnotes.deleted = 1)
 *   action=mark_read { eid }   — pnotes.message_status = 'Read'
 *
 * Returns JSON: {"ok": true, "eid": <int>}  on success.
 *               {"ok": false, "error": "<msg>"} on validation/auth fail.
 *
 * Security:
 *   - Requires patients/notes ACL.
 *   - CSRF: matches the token embedded in copilot_messages.php
 *           (CsrfUtils::collectCsrfToken / verifyCsrfToken).
 *   - Per-user ownership: archive / restore / delete refuse to touch
 *     rows where `assigned_to` is not the active user (sender can
 *     also archive their own sent messages — covered).
 *   - Inputs are bound via prepared statements; subject/body length-
 *     capped; patient_id verified against patient_data when present.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

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

if (!AclMain::aclCheckCore('patients', 'notes')) {
    _emit(['ok' => false, 'error' => 'Not authorized'], 403);
}

$_csrf = $_POST['csrf_token_form'] ?? '';
if (!is_string($_csrf) || !CsrfUtils::verifyCsrfToken($_csrf)) {
    _emit(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
}

$activeUserId = (int)($_SESSION['authUserID'] ?? 0);
if ($activeUserId <= 0) {
    _emit(['ok' => false, 'error' => 'No active session user'], 401);
}
$activeRow = sqlQuery("SELECT username FROM users WHERE id = ?", [$activeUserId]);
$activeUsername = (string)($activeRow['username'] ?? '');
if ($activeUsername === '') {
    _emit(['ok' => false, 'error' => 'Could not resolve active username'], 401);
}

$action = $_POST['action'] ?? '';
$validActions = ['send', 'archive', 'restore', 'delete', 'mark_read'];
if (!in_array($action, $validActions, true)) {
    _emit(['ok' => false, 'error' => "Unknown action: $action"], 400);
}

// ─── Helper: confirm the requesting user owns / received this message ───
function _ownedNote(int $eid, string $username): ?array
{
    $row = sqlQuery(
        "SELECT id, user, assigned_to FROM pnotes WHERE id = ? AND deleted = 0",
        [$eid]
    );
    if (!$row) {
        return null;
    }
    if ($row['user'] !== $username && $row['assigned_to'] !== $username) {
        return null;
    }
    return $row;
}

// ─── ARCHIVE / RESTORE / DELETE / MARK_READ ─────────────────────────────

if (in_array($action, ['archive', 'restore', 'delete', 'mark_read'], true)) {
    $eid = (int)($_POST['eid'] ?? 0);
    if ($eid <= 0) {
        _emit(['ok' => false, 'error' => 'Missing eid'], 400);
    }
    if (_ownedNote($eid, $activeUsername) === null) {
        _emit(['ok' => false, 'error' => 'Message not found or not yours'], 404);
    }

    if ($action === 'archive') {
        sqlStatement(
            "UPDATE pnotes SET activity = 0, update_by = ?, update_date = NOW()
              WHERE id = ?",
            [$activeUserId, $eid]
        );
    } elseif ($action === 'restore') {
        sqlStatement(
            "UPDATE pnotes SET activity = 1, update_by = ?, update_date = NOW()
              WHERE id = ?",
            [$activeUserId, $eid]
        );
    } elseif ($action === 'delete') {
        sqlStatement(
            "UPDATE pnotes SET deleted = 1, update_by = ?, update_date = NOW()
              WHERE id = ?",
            [$activeUserId, $eid]
        );
    } else { // mark_read
        sqlStatement(
            "UPDATE pnotes SET message_status = 'Read', update_by = ?, update_date = NOW()
              WHERE id = ?",
            [$activeUserId, $eid]
        );
    }
    _emit(['ok' => true, 'eid' => $eid]);
}

// ─── SEND ───────────────────────────────────────────────────────────────

$toUsername = trim((string)($_POST['to_username'] ?? ''));
$subject    = trim((string)($_POST['subject'] ?? ''));
$body       = trim((string)($_POST['body'] ?? ''));
$pidRaw     = trim((string)($_POST['patient_id'] ?? ''));

if ($toUsername === '' || mb_strlen($toUsername) > 64) {
    _emit(['ok' => false, 'error' => 'Recipient is required'], 400);
}
if ($subject === '' || mb_strlen($subject) > 200) {
    _emit(['ok' => false, 'error' => 'Subject is required (1-200 chars)'], 400);
}
if ($body === '' || mb_strlen($body) > 6000) {
    _emit(['ok' => false, 'error' => 'Body is required (1-6000 chars)'], 400);
}

// Confirm recipient is a real user.
$recipient = sqlQuery(
    "SELECT id, username FROM users WHERE username = ? AND active = 1",
    [$toUsername]
);
if (!$recipient) {
    _emit(['ok' => false, 'error' => "Recipient {$toUsername} not found"], 400);
}

// Optional patient.
$pid = null;
if ($pidRaw !== '') {
    if (!ctype_digit($pidRaw) || (int)$pidRaw <= 0) {
        _emit(['ok' => false, 'error' => 'Patient PID must be a positive integer'], 400);
    }
    $patient = sqlQuery("SELECT pid FROM patient_data WHERE pid = ?", [(int)$pidRaw]);
    if (!$patient) {
        _emit(['ok' => false, 'error' => "Patient pid={$pidRaw} not found"], 400);
    }
    $pid = (int)$pidRaw;
}

sqlStatement(
    "INSERT INTO pnotes
        (date, body, pid, user, groupname, activity, authorized,
         title, assigned_to, deleted, message_status, update_by, update_date)
     VALUES (NOW(), ?, ?, ?, 'Default', 1, 1, ?, ?, 0, 'New', ?, NOW())",
    [$body, $pid, $activeUsername, $subject, $toUsername, $activeUserId]
);
$eid = (int)(sqlQuery("SELECT LAST_INSERT_ID() AS id")['id'] ?? 0);

_emit(['ok' => true, 'eid' => $eid]);
