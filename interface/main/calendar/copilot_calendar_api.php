<?php

/**
 * Copilot Calendar — minimal CRUD API for openemr_postcalendar_events.
 *
 * POST endpoint consumed by copilot_calendar.php's modal. Three actions:
 *   action=create   { title, event_date, start_time, end_time,
 *                     patient_id?, status?, notes? }
 *   action=update   { eid, title, event_date, start_time, end_time,
 *                     patient_id?, status?, notes? }
 *   action=delete   { eid }
 *
 * Returns JSON: {"ok": true, "eid": <int>}  on success.
 *               {"ok": false, "error": "<msg>"} on validation/auth fail.
 *
 * Security:
 *   - Requires patients/appt ACL.
 *   - Update/Delete refuse to touch events that don't belong to the
 *     active user (pc_aid != $authUserID) — defense in depth on top of
 *     OpenEMR's existing access control.
 *   - All inputs sanitized via prepared statements; pc_apptstatus
 *     constrained to a small enum set; titles capped at 150 chars.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Acl\AclMain;

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

if (!AclMain::aclCheckCore('patients', 'appt')) {
    _emit(['ok' => false, 'error' => 'Not authorized'], 403);
}

$activeUserId = (int)($_SESSION['authUserID'] ?? 0);
if ($activeUserId <= 0) {
    _emit(['ok' => false, 'error' => 'No active session user'], 401);
}

$action = $_POST['action'] ?? '';
if (!in_array($action, ['create', 'update', 'delete'], true)) {
    _emit(['ok' => false, 'error' => "Unknown action: $action"], 400);
}

// ─── Helpers ────────────────────────────────────────────────────────────

/** Validate YYYY-MM-DD (cheap regex; SQL still binds as a string). */
function _validDate(string $s): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

/** Validate HH:MM (24h). */
function _validTime(string $s): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s);
}

/** Confirm event belongs to the active user. Returns event row or null. */
function _ownedEvent(int $eid, int $userId): ?array
{
    $row = sqlQuery(
        "SELECT pc_eid, pc_aid FROM openemr_postcalendar_events WHERE pc_eid = ?",
        [$eid]
    );
    if (!$row) {
        return null;
    }
    if ((int)$row['pc_aid'] !== $userId) {
        return null;
    }
    return $row;
}

$ALLOWED_STATUS = ['-', '@', '~', '>', '$', 'x', '?'];

// ─── DELETE ─────────────────────────────────────────────────────────────

if ($action === 'delete') {
    $eid = (int)($_POST['eid'] ?? 0);
    if ($eid <= 0) {
        _emit(['ok' => false, 'error' => 'Missing eid'], 400);
    }
    if (_ownedEvent($eid, $activeUserId) === null) {
        _emit(['ok' => false, 'error' => 'Event not found or not yours'], 404);
    }
    sqlStatement("DELETE FROM openemr_postcalendar_events WHERE pc_eid = ?", [$eid]);
    _emit(['ok' => true, 'eid' => $eid]);
}

// ─── Validate shared fields for create / update ─────────────────────────

$title  = trim((string)($_POST['title'] ?? ''));
$date   = (string)($_POST['event_date'] ?? '');
$start  = (string)($_POST['start_time'] ?? '');
$end    = (string)($_POST['end_time']   ?? '');
$pidRaw = trim((string)($_POST['patient_id'] ?? ''));
$status = (string)($_POST['status'] ?? '-');
$notes  = (string)($_POST['notes'] ?? '');

if ($title === '' || mb_strlen($title) > 150) {
    _emit(['ok' => false, 'error' => 'Title is required (1-150 chars)'], 400);
}
if (!_validDate($date)) {
    _emit(['ok' => false, 'error' => 'Invalid date'], 400);
}
if (!_validTime($start) || !_validTime($end)) {
    _emit(['ok' => false, 'error' => 'Invalid time format (HH:MM)'], 400);
}
if (strcmp($end, $start) <= 0) {
    _emit(['ok' => false, 'error' => 'End time must be after start time'], 400);
}
if (!in_array($status, $ALLOWED_STATUS, true)) {
    $status = '-';
}
if (mb_strlen($notes) > 2000) {
    $notes = mb_substr($notes, 0, 2000);
}

// pc_pid is varchar(11) historically — accept either an int pid or empty.
$pid = '';
if ($pidRaw !== '') {
    if (!ctype_digit($pidRaw) || (int)$pidRaw <= 0) {
        _emit(['ok' => false, 'error' => 'Patient PID must be a positive integer'], 400);
    }
    // Confirm the patient exists.
    $patient = sqlQuery("SELECT pid FROM patient_data WHERE pid = ?", [(int)$pidRaw]);
    if (!$patient) {
        _emit(['ok' => false, 'error' => "Patient pid={$pidRaw} not found"], 400);
    }
    $pid = (string)(int)$pidRaw;
}

// pc_time = pc_eventDate + pc_startTime (legacy convention). pc_duration
// in seconds.
$pcTime = $date . ' ' . $start . ':00';
$startStamp = strtotime($pcTime);
$endStamp   = strtotime($date . ' ' . $end . ':00');
$duration   = max(0, ($endStamp - $startStamp));

// ─── CREATE ─────────────────────────────────────────────────────────────

if ($action === 'create') {
    sqlStatement(
        "INSERT INTO openemr_postcalendar_events
            (pc_catid, pc_aid, pc_pid, pc_title, pc_time,
             pc_hometext, pc_eventDate, pc_duration,
             pc_startTime, pc_endTime, pc_apptstatus,
             pc_eventstatus, pc_sharing, pc_recurrtype, pc_alldayevent)
         VALUES (9, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 0, 0)",
        [
            $activeUserId,
            $pid,
            $title,
            $pcTime,
            $notes,
            $date,
            $duration,
            $start . ':00',
            $end . ':00',
            $status,
        ]
    );
    $eid = (int)(sqlQuery("SELECT LAST_INSERT_ID() AS id")['id'] ?? 0);
    _emit(['ok' => true, 'eid' => $eid]);
}

// ─── UPDATE ─────────────────────────────────────────────────────────────

if ($action === 'update') {
    $eid = (int)($_POST['eid'] ?? 0);
    if ($eid <= 0) {
        _emit(['ok' => false, 'error' => 'Missing eid'], 400);
    }
    if (_ownedEvent($eid, $activeUserId) === null) {
        _emit(['ok' => false, 'error' => 'Event not found or not yours'], 404);
    }
    sqlStatement(
        "UPDATE openemr_postcalendar_events
            SET pc_pid       = ?,
                pc_title     = ?,
                pc_time      = ?,
                pc_hometext  = ?,
                pc_eventDate = ?,
                pc_duration  = ?,
                pc_startTime = ?,
                pc_endTime   = ?,
                pc_apptstatus = ?
          WHERE pc_eid = ?",
        [
            $pid, $title, $pcTime, $notes, $date, $duration,
            $start . ':00', $end . ':00', $status, $eid,
        ]
    );
    _emit(['ok' => true, 'eid' => $eid]);
}

// Should be unreachable (we already validated $action above).
_emit(['ok' => false, 'error' => 'Unhandled action'], 500);
