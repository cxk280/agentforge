<?php

/**
 * Pending Review — Screen 35.
 *
 * Provider's cross-patient queue of orders, results, documents and
 * messages awaiting review and sign-off. Two-pane layout: list of
 * pending items on the left, detail / co-pilot suggestion / provider
 * note for the selected item on the right.
 *
 * The chrome (top nav) is rendered by the parent shell — this page
 * renders only the body. Page is not patient-scoped.
 *
 * Backend wiring (Screen 35):
 *  - Source: procedure_result pr JOIN procedure_report rep
 *    JOIN procedure_order po JOIN patient_data pd, filtered to
 *    rep.review_status IS NULL OR rep.review_status='not reviewed'.
 *    (Note: the original brief named procedure_result.review_status,
 *    but the OpenEMR schema actually places review_status on
 *    procedure_report — verified via DESC procedure_result. We use
 *    the correct column.)
 *  - Filter pills: ?type=lab|imaging|doc|msg|critical|all
 *  - Selected row: ?selected=<procedure_report_id> (default first)
 *  - POST actions: reassign, bulk_sign, forward, sign_next
 *  - Right-pane HbA1c trend: same result_code over time, last 4 values
 *  - Provider note: most recent pnotes row for the selected patient
 *  - Co-Pilot suggestion bullets: static text — would call
 *    /api/copilot/suggest in production
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

// -----------------------------------------------------------------------------
// POST handlers (POST/redirect/GET) — must run before any output.
// CSRF skipped — internal mock page (per AgentForge brief).
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $self = $_SERVER['PHP_SELF'];

    if ($action === 'reassign') {
        $resultId = (int)($_POST['result_id'] ?? 0);
        $newProvider = (int)($_POST['new_provider'] ?? 0);
        if ($resultId > 0 && $newProvider > 0) {
            // Walk pr → rep → po; update po.provider_id.
            sqlStatement(
                "UPDATE procedure_order po
                 JOIN procedure_report rep ON rep.procedure_order_id = po.procedure_order_id
                 JOIN procedure_result pr ON pr.procedure_report_id = rep.procedure_report_id
                 SET po.provider_id = ?
                 WHERE pr.procedure_result_id = ?",
                [$newProvider, $resultId]
            );
            header('Location: ' . $self . '?msg=reassigned');
            exit;
        }
        header('Location: ' . $self . '?msg=reassign_failed');
        exit;
    }

    if ($action === 'bulk_sign') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) { $ids = []; }
        $reviewerId = (int)($_SESSION['authUserID'] ?? 0);
        $reviewerName = trim((string)($_SESSION['authUser'] ?? '')) ?: 'admin';
        $signed = 0;
        foreach ($ids as $rawId) {
            $repId = (int)$rawId;
            if ($repId <= 0) { continue; }
            $res = sqlStatement(
                "UPDATE procedure_report
                 SET review_status = 'reviewed',
                     report_notes = TRIM(CONCAT(COALESCE(report_notes, ''), ' | reviewed by ', ?))
                 WHERE procedure_report_id = ?
                   AND (review_status IS NULL OR review_status = 'not reviewed' OR review_status = 'received')",
                [$reviewerName, $repId]
            );
            $signed++;
        }
        header('Location: ' . $self . '?msg=signed_' . $signed);
        exit;
    }

    if ($action === 'forward') {
        $resultId = (int)($_POST['result_id'] ?? 0);
        $recipient = trim((string)($_POST['recipient'] ?? 'admin'));
        if ($recipient === '') { $recipient = 'admin'; }
        $sender = trim((string)($_SESSION['authUser'] ?? 'admin'));
        if ($resultId > 0) {
            $row = sqlQuery(
                "SELECT pr.result_text, pd.fname, pd.lname, pd.pid
                 FROM procedure_result pr
                 JOIN procedure_report rep ON pr.procedure_report_id = rep.procedure_report_id
                 JOIN procedure_order po ON rep.procedure_order_id = po.procedure_order_id
                 JOIN patient_data pd ON po.patient_id = pd.pid
                 WHERE pr.procedure_result_id = ?",
                [$resultId]
            );
            $patientName = $row ? trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? '')) : 'patient';
            $resultText = $row['result_text'] ?? 'pending result';
            $body = "Forwarded for review: {$patientName} — {$resultText} (result_id #{$resultId})";
            sqlInsert(
                "INSERT INTO onsite_messages (username, message, ip, date, sender_id, recip_id)
                 VALUES (?, ?, ?, NOW(), ?, ?)",
                [$sender, $body, ($_SERVER['REMOTE_ADDR'] ?? ''), $sender, $recipient]
            );
            header('Location: ' . $self . '?msg=forwarded');
            exit;
        }
        header('Location: ' . $self . '?msg=forward_failed');
        exit;
    }

    if ($action === 'sign_next' || $action === 'accept_all_sign') {
        $resultId = (int)($_POST['result_id'] ?? 0);
        $reviewerName = trim((string)($_SESSION['authUser'] ?? '')) ?: 'admin';
        if ($resultId > 0) {
            sqlStatement(
                "UPDATE procedure_report rep
                 JOIN procedure_result pr ON pr.procedure_report_id = rep.procedure_report_id
                 SET rep.review_status = 'reviewed',
                     rep.report_notes = TRIM(CONCAT(COALESCE(rep.report_notes, ''), ' | reviewed by ', ?))
                 WHERE pr.procedure_result_id = ?
                   AND (rep.review_status IS NULL OR rep.review_status = 'not reviewed' OR rep.review_status = 'received')",
                [$reviewerName, $resultId]
            );
        }
        // Find next unsigned (oldest first by collected_at, but UI sorts DESC,
        // so just pick the first remaining row).
        $next = sqlQuery(
            "SELECT pr.procedure_result_id
             FROM procedure_result pr
             JOIN procedure_report rep ON pr.procedure_report_id = rep.procedure_report_id
             WHERE rep.review_status IS NULL OR rep.review_status = 'not reviewed'
             ORDER BY rep.date_collected DESC, pr.procedure_result_id DESC
             LIMIT 1"
        );
        $loc = $self . '?msg=signed_1';
        if (!empty($next['procedure_result_id'])) {
            $loc .= '&selected=' . (int)$next['procedure_result_id'];
        }
        header('Location: ' . $loc);
        exit;
    }

    // Unknown action — bounce to GET to avoid duplicate POST.
    header('Location: ' . $self);
    exit;
}

// -----------------------------------------------------------------------------
// GET-side filters and data
// -----------------------------------------------------------------------------

/** Map a procedure_order_type / result-status string to a queue bucket. */
function cp_pr_bucket(string $orderType, string $abnormal): string
{
    $t = strtolower($orderType);
    $a = strtolower($abnormal);
    // Treat critical labs as critical regardless of bucket.
    if ($a === 'critical') {
        return 'critical';
    }
    if (str_contains($t, 'imag') || str_contains($t, 'rad')) {
        return 'imaging';
    }
    if (str_contains($t, 'doc')) {
        return 'doc';
    }
    if (str_contains($t, 'msg') || str_contains($t, 'message')) {
        return 'msg';
    }
    return 'lab';
}

/** "2h ago", "1d ago" — kept simple, no i18n. */
function cp_pr_time_ago(?string $ts): string
{
    if (!$ts) { return '—'; }
    $minutes = (int)((time() - strtotime($ts)) / 60);
    if ($minutes < 1) { return 'just now'; }
    if ($minutes < 60) { return $minutes . 'm ago'; }
    $hours = (int)($minutes / 60);
    if ($hours < 48) { return $hours . 'h ago'; }
    $days = (int)($hours / 24);
    return $days . 'd ago';
}

$validTypes = ['all', 'lab', 'imaging', 'doc', 'msg', 'critical'];
$type = (string)($_GET['type'] ?? 'all');
if (!in_array($type, $validTypes, true)) { $type = 'all'; }

// Master query — every "pending" result row, joined to its report/order/patient.
// We pick the FIRST result per report (lowest procedure_result_id) so each
// queue row maps 1:1 to a report. The "secondary" results still exist in DB
// for the detail-view if needed.
$listSql = "
    SELECT
        pr.procedure_result_id,
        pr.result_code,
        pr.result_text,
        pr.result,
        pr.units,
        pr.range,
        pr.abnormal,
        pr.comments,
        rep.procedure_report_id,
        rep.review_status,
        rep.date_collected,
        rep.report_notes AS lab_facility,
        po.procedure_order_id,
        po.procedure_order_type,
        po.patient_id,
        po.provider_id,
        po.clinical_hx,
        pd.fname,
        pd.lname,
        pd.pubpid
    FROM procedure_result pr
    JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
    JOIN procedure_order po   ON po.procedure_order_id   = rep.procedure_order_id
    JOIN patient_data pd      ON pd.pid                  = po.patient_id
    WHERE (rep.review_status IS NULL OR rep.review_status = 'not reviewed')
      AND pr.procedure_result_id = (
          SELECT MIN(pr2.procedure_result_id)
          FROM procedure_result pr2
          WHERE pr2.procedure_report_id = pr.procedure_report_id
      )
    ORDER BY rep.date_collected DESC, pr.procedure_result_id DESC
";
$listRs = sqlStatement($listSql);

$queueRows = [];
$providerCache = [];
while ($r = sqlFetchArray($listRs)) {
    $bucket = cp_pr_bucket((string)$r['procedure_order_type'], (string)$r['abnormal']);
    $r['bucket'] = $bucket;
    // Provider name lookup, cached.
    $pid2 = (int)$r['provider_id'];
    if ($pid2 > 0 && !isset($providerCache[$pid2])) {
        $u = sqlQuery("SELECT username, fname, lname, title FROM users WHERE id = ?", [$pid2]);
        $providerCache[$pid2] = cp_format_provider_name($u ?: null);
    }
    $r['provider_display'] = $pid2 > 0 ? ($providerCache[$pid2] ?? '—') : '—';
    $queueRows[] = $r;
}

// Pre-filter counts (on the unfiltered set) for the pill badges.
$counts = ['all' => 0, 'lab' => 0, 'imaging' => 0, 'doc' => 0, 'msg' => 0, 'critical' => 0];
foreach ($queueRows as $r) {
    $counts['all']++;
    $counts[$r['bucket']] = ($counts[$r['bucket']] ?? 0) + 1;
    // Critical is computed independently from the bucket as a flag (could
    // overlap with 'lab' etc.). Bucket already promotes critical so the
    // counts above include them in 'critical' instead of 'lab' — but for
    // the displayed total per pill we want 'lab' to also include criticals.
    if ($r['bucket'] === 'critical' && strtolower((string)$r['procedure_order_type']) === 'laboratory_test') {
        $counts['lab'] = ($counts['lab'] ?? 0) + 1;
    }
}

// Apply the active filter pill.
$visibleRows = [];
foreach ($queueRows as $r) {
    if ($type === 'all') {
        $visibleRows[] = $r;
        continue;
    }
    if ($type === 'critical') {
        if (strtolower((string)$r['abnormal']) === 'critical') {
            $visibleRows[] = $r;
        }
        continue;
    }
    if ($type === $r['bucket']) {
        $visibleRows[] = $r;
    }
}

// Selected row: ?selected=<procedure_result_id>, default = first visible.
$selectedId = (int)($_GET['selected'] ?? 0);
if ($selectedId === 0 && !empty($visibleRows)) {
    $selectedId = (int)$visibleRows[0]['procedure_result_id'];
}

$selectedRow = null;
foreach ($queueRows as $r) {
    if ((int)$r['procedure_result_id'] === $selectedId) {
        $selectedRow = $r;
        break;
    }
}
// Fallback: if the selected is not in the visible filter, drop into first visible.
if ($selectedRow === null && !empty($visibleRows)) {
    $selectedRow = $visibleRows[0];
    $selectedId = (int)$selectedRow['procedure_result_id'];
}

// -----------------------------------------------------------------------------
// Right-pane: trend, provider note, secondary detail.
// -----------------------------------------------------------------------------
$trendValues = [];
$priorValueLabel = null;
$priorValueDate = null;

if ($selectedRow) {
    // Last-4 trend for the same patient + result_code (excluding the current
    // pending row), most-recent first then reversed for display.
    $trendRs = sqlStatement(
        "SELECT pr.result, COALESCE(rep.date_collected, pr.date) AS d
         FROM procedure_result pr
         JOIN procedure_report rep ON pr.procedure_report_id = rep.procedure_report_id
         JOIN procedure_order po   ON rep.procedure_order_id  = po.procedure_order_id
         WHERE po.patient_id = ?
           AND pr.result_code = ?
           AND pr.procedure_result_id <> ?
         ORDER BY COALESCE(rep.date_collected, pr.date) DESC
         LIMIT 3",
        [(int)$selectedRow['patient_id'], (string)$selectedRow['result_code'], (int)$selectedRow['procedure_result_id']]
    );
    $priorRows = [];
    while ($t = sqlFetchArray($trendRs)) {
        $priorRows[] = $t;
    }
    // Build the "Last 4" sequence: 3 prior values (oldest→newest) + current.
    foreach (array_reverse($priorRows) as $p) {
        $trendValues[] = (string)$p['result'];
    }
    $trendValues[] = (string)$selectedRow['result'];

    if (!empty($priorRows)) {
        $priorValueLabel = (string)$priorRows[0]['result'];
        $priorValueDate = (string)$priorRows[0]['d'];
    }
}

// Most-recent pnote for the selected patient (optional).
$existingNote = '';
if ($selectedRow) {
    $note = sqlQuery(
        "SELECT body FROM pnotes
         WHERE pid = ? AND deleted = 0 AND COALESCE(body, '') <> ''
         ORDER BY date DESC
         LIMIT 1",
        [(int)$selectedRow['patient_id']]
    );
    if ($note && !empty($note['body'])) {
        $existingNote = (string)$note['body'];
    }
}

// Provider list for the Reassign dropdown (active, authorized providers).
$providerListRs = sqlStatement(
    "SELECT id, username, fname, lname, title
     FROM users
     WHERE active = 1 AND authorized = 1
     ORDER BY lname, fname"
);
$providerList = [];
while ($u = sqlFetchArray($providerListRs)) {
    $providerList[] = $u;
}

// Reviewer list for Forward (any active user).
$recipientListRs = sqlStatement(
    "SELECT username, fname, lname FROM users WHERE active = 1 ORDER BY lname, fname"
);
$recipientList = [];
while ($u = sqlFetchArray($recipientListRs)) {
    $recipientList[] = $u;
}

// Suggested actions for the Co-Pilot panel.
// Static text — would call /api/copilot/suggest in production.
$suggestions = [
    'Notify patient via portal (template: A1C critical)',
    'Increase Metformin to 1000 mg BID OR consider GLP-1',
    'Schedule diabetes education referral',
    'Recheck A1C in 8-12 weeks',
];

// Pre-checked queue items: "checked" rows in the mock. We mark the first 6
// rows as checked by default — these are the ones the "Sign N selected"
// header button targets. The user can change selection client-side, but the
// initial server-rendered set drives the actual sign action.
$preChecked = [];
foreach ($visibleRows as $i => $r) {
    if ($i < 6) {
        $preChecked[(int)$r['procedure_report_id']] = true;
    }
}

// Header summary line (live counts).
$labCount = $counts['lab'] ?? 0;
$docCount = $counts['doc'] ?? 0;
$msgCount = $counts['msg'] ?? 0;
$summaryLine = sprintf(
    '%d %s, %d %s, %d %s %s',
    $labCount,
    xl('lab/imaging'),
    $docCount,
    xl('documents'),
    $msgCount,
    xl('messages'),
    xl('awaiting sign-off')
);

// Flash message.
$flashMsg = (string)($_GET['msg'] ?? '');
$flashLabel = '';
if ($flashMsg !== '') {
    if (str_starts_with($flashMsg, 'signed_')) {
        $n = (int)substr($flashMsg, 7);
        $flashLabel = $n . ' ' . xl('result(s) signed') . ' · ok';
    } elseif ($flashMsg === 'reassigned') {
        $flashLabel = xl('Result reassigned') . ' · ok';
    } elseif ($flashMsg === 'forwarded') {
        $flashLabel = xl('Forwarded') . ' · ok';
    } elseif ($flashMsg === 'reassign_failed' || $flashMsg === 'forward_failed') {
        $flashLabel = xl('Action failed — missing data');
    }
}

// Filter pill spec: [label, type-key, count].
$filters = [
    [xl('All'),         'all',      $counts['all'] ?? 0],
    [xl('Lab results'), 'lab',      $counts['lab'] ?? 0],
    [xl('Imaging'),     'imaging',  $counts['imaging'] ?? 0],
    [xl('Documents'),   'doc',      $counts['doc'] ?? 0],
    [xl('Messages'),    'msg',      $counts['msg'] ?? 0],
    [xl('Critical'),    'critical', $counts['critical'] ?? 0],
];

// Status pill helper for queue row.
function cp_pr_status_pill(array $r): array
{
    $abn = strtolower((string)$r['abnormal']);
    $bucket = (string)($r['bucket'] ?? '');
    if ($abn === 'critical') { return ['Critical', 'danger']; }
    if ($abn === 'high' || $abn === 'low' || $abn === 'abnormal') { return ['Abnormal', 'warn']; }
    if ($bucket === 'msg') { return ['—', 'plain']; }
    return ['Routine', 'info'];
}

// Sub-text for the queue row (lab + provider).
function cp_pr_subline(array $r): string
{
    $facility = trim((string)($r['lab_facility'] ?? ''));
    $prov = trim((string)($r['provider_display'] ?? ''));
    $bits = array_filter([$facility, $prov], static fn($s) => $s !== '' && $s !== '—');
    return implode(' · ', $bits) ?: '—';
}

// Title / sub for selected row's right-pane header.
$detPatientName = $selectedRow ? trim(($selectedRow['fname'] ?? '') . ' ' . ($selectedRow['lname'] ?? '')) : '';
$detTestName = $selectedRow ? (string)$selectedRow['result_text'] : '';
$detMrn = $selectedRow ? (string)$selectedRow['pubpid'] : '';
$detLab = $selectedRow ? (string)($selectedRow['lab_facility'] ?? '') : '';
$detDrawn = $selectedRow ? (string)$selectedRow['date_collected'] : '';
$detPid = $selectedRow ? (int)$selectedRow['patient_id'] : 0;
$detResultId = $selectedRow ? (int)$selectedRow['procedure_result_id'] : 0;
$detValue = $selectedRow ? (string)$selectedRow['result'] : '';
$detUnits = $selectedRow ? (string)$selectedRow['units'] : '';
$detRange = $selectedRow ? (string)$selectedRow['range'] : '';
$detIsCritical = $selectedRow && strtolower((string)$selectedRow['abnormal']) === 'critical';
$detBucket = $selectedRow ? (string)$selectedRow['bucket'] : 'lab';

// Pretty-format draw date.
$detDrawnPretty = '';
if ($detDrawn !== '') {
    $ts = strtotime($detDrawn);
    if ($ts !== false) {
        $detDrawnPretty = date('m/d/Y H:i', $ts);
    }
}

// Header "Sign N selected" — count of pre-checked rows.
$preCheckedCount = count($preChecked);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Pending Review'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header dot separator (matches batch results) */
  .cp-pagehead .dot { color: #008C8C; font-size: 16px; line-height: 1; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #4F5763; font-size: 12px; line-height: 1; }

  /* Flash message */
  .pr-flash {
    background: #E6F4F4; color: #0D5757; padding: 8px 14px;
    border-bottom: 1px solid #B5DEDE; font-size: 12px;
  }
  .pr-flash.fail { background: #FCE7E7; color: #842121; border-bottom-color: #ECC2C2; }

  /* Filter strip — sits flush below pagehead, no rounded card */
  .pr-filterbar {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 10px 24px;
    display: flex; align-items: center; gap: 8px;
    flex: 0 0 auto;
  }
  .pr-filterbar .pills { display: flex; gap: 6px; }
  .pr-filterbar .pills a {
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 12px; font-weight: 500;
    line-height: 1.2;
    background: #FFFFFF;
    color: #4F5763;
    border: 1px solid #E4E5E8;
    text-decoration: none;
    display: inline-flex; align-items: center;
  }
  .pr-filterbar .pills a.active {
    background: rgba(0, 140, 140, 0.08);
    color: #008C8C;
    border-color: #008C8C;
    font-weight: 600;
  }
  .pr-filterbar .pills a .ct {
    background: transparent;
    color: inherit;
    margin-left: 6px;
    font-size: 11px;
    font-weight: 600;
    opacity: 0.85;
  }
  .pr-filterbar .pills a.active .ct { color: #008C8C; }

  /* Two-pane body */
  .pr-body {
    flex: 1 1 auto;
    display: grid;
    grid-template-columns: minmax(440px, 1fr) minmax(520px, 1.05fr);
    gap: 0;
    background: #F5F6F7;
    min-height: 0;
    padding: 14px 18px 18px;
    align-items: start;
  }
  .pr-pane {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
    display: flex; flex-direction: column;
  }
  .pr-pane.left { margin-right: 8px; }
  .pr-pane.right { margin-left: 8px; }

  /* List header */
  .pr-list-head {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    border-bottom: 1px solid #E4E5E8;
  }
  .pr-list-head .sort {
    color: #4F5763;
    font-size: 12px;
    font-weight: 500;
  }
  .pr-list-head .sort .caret { color: #8A91A1; font-size: 9px; margin-left: 4px; }
  .pr-list-head .count {
    margin-left: auto;
    color: #8A91A1;
    font-size: 11px;
    font-weight: 500;
  }

  /* Checkbox */
  .pr-cb {
    width: 16px; height: 16px;
    border: 1.5px solid #C9CDD4; border-radius: 4px;
    background: #FFFFFF; display: inline-block; vertical-align: middle;
    position: relative;
    flex: 0 0 16px;
    appearance: none;
    -webkit-appearance: none;
    cursor: pointer;
    margin: 0;
  }
  .pr-cb:checked, .pr-cb.on { background: #008C8C; border-color: #008C8C; }
  .pr-cb:checked::after, .pr-cb.on::after {
    content: '\2713'; color: #FFFFFF; font-size: 11px; font-weight: 700;
    position: absolute; left: 2px; top: -1px;
  }
  .pr-cb.indeterminate { background: #008C8C; border-color: #008C8C; }
  .pr-cb.indeterminate::after {
    content: ''; position: absolute;
    left: 3px; right: 3px; top: 6.5px;
    height: 2px; background: #FFFFFF; border-radius: 1px;
  }

  /* List rows */
  .pr-list { display: flex; flex-direction: column; }
  .pr-row {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    border-bottom: 1px solid #F0F1F3;
    cursor: pointer;
    position: relative;
    text-decoration: none;
    color: inherit;
  }
  .pr-row:hover { background: #FAFBFC; }
  .pr-row.active { background: rgba(0, 140, 140, 0.08); }
  .pr-row.active::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px; background: #008C8C;
  }
  .pr-row .icon {
    width: 20px; height: 20px;
    flex: 0 0 20px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #33A68C;
  }
  .pr-row .icon.imaging { color: #4785D9; }
  .pr-row .icon.doc { color: #8A91A1; }
  .pr-row .icon.msg { color: #4F5763; }
  .pr-row .icon.critical { color: #D93838; }
  .pr-row .core {
    flex: 1 1 auto;
    min-width: 0;
    display: flex; flex-direction: column; gap: 3px;
  }
  .pr-row .core .ttl {
    font-size: 12.5px; font-weight: 600;
    color: #0D1B2A;
    line-height: 1.3;
  }
  .pr-row .core .sub {
    font-size: 12px;
    color: #4F5763;
    line-height: 1.3;
  }
  .pr-row .meta {
    display: flex; flex-direction: column; align-items: flex-end; gap: 6px;
    flex: 0 0 auto;
  }
  .pr-row .meta .when {
    font-size: 11px;
    color: #8A91A1;
    line-height: 1;
  }
  .pr-row .kebab {
    color: #C9CDD4;
    font-size: 14px;
    line-height: 1;
    padding: 0 2px 0 4px;
    flex: 0 0 auto;
    cursor: pointer;
  }

  .cp-status-pill.plain { background: transparent; color: #8A91A1; padding-left: 0; padding-right: 0; }

  /* Right pane — detail */
  .pr-detail-head {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 18px 12px;
    border-bottom: 1px solid #E4E5E8;
  }
  .pr-detail-head .icon {
    width: 22px; height: 22px;
    color: #33A68C;
    flex: 0 0 22px;
  }
  .pr-detail-head .info { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
  .pr-detail-head .info .ttl {
    font-size: 15px; font-weight: 700; color: #0D1B2A; line-height: 1.2;
  }
  .pr-detail-head .info .sub {
    font-size: 11.5px; color: #4F5763; line-height: 1;
  }
  .pr-detail-head .info .ttl a { color: inherit; text-decoration: none; }

  .pr-detail-body {
    padding: 14px 18px 16px;
    display: flex; flex-direction: column; gap: 14px;
    overflow-y: auto;
  }
  .pr-secLbl {
    font-size: 10px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.6px;
    line-height: 1;
  }

  /* Critical value pill at top of right pane */
  .pr-crit-pill {
    background: #FCE7E7;
    color: #D93838;
    border-radius: 4px;
    font-size: 9.5px; font-weight: 700;
    letter-spacing: 0.6px;
    padding: 3px 7px;
    text-transform: uppercase;
    align-self: flex-start;
  }

  /* Result panel — coloration shifts based on critical-ness */
  .pr-result {
    background: #FCE7E7;
    border-radius: 10px;
    padding: 14px 16px 16px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 24px;
  }
  .pr-result.routine { background: #F0F4FA; }
  .pr-result.warn    { background: #FFF6E6; }
  .pr-result .lblTop { grid-column: 1 / -1; }
  .pr-result .big {
    display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;
  }
  .pr-result .big .name { font-size: 13px; font-weight: 600; color: #0D1B2A; }
  .pr-result .big .val {
    font-size: 26px; font-weight: 700; color: #D93838;
    line-height: 1.1;
  }
  .pr-result.routine .big .val { color: #0D1B2A; }
  .pr-result.warn .big .val { color: #C26900; }
  .pr-result .big .delta { font-size: 11.5px; font-weight: 500; color: #D93838; }
  .pr-result.routine .big .delta { color: #4F5763; }
  .pr-result.warn .big .delta { color: #C26900; }
  .pr-result .last {
    font-size: 11.5px; color: #4F5763;
    line-height: 1.4;
    grid-column: 1 / 2;
  }
  .pr-result .ref { display: flex; flex-direction: column; gap: 4px; }
  .pr-result .ref .lbl { font-size: 11px; color: #4F5763; font-weight: 500; }
  .pr-result .ref .v { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .pr-result .trend {
    font-size: 11.5px; color: #4F5763;
    grid-column: 2 / 3;
  }

  /* Tiny inline sparkline (SVG) */
  .pr-spark { display: inline-block; vertical-align: middle; margin-left: 6px; }

  /* Co-pilot suggestion box */
  .pr-cp {
    background: #F0F4FA;
    border: 1px solid #DCE5F2;
    border-radius: 10px;
    padding: 12px 14px 14px;
  }
  .pr-cp .head {
    font-size: 12px; font-weight: 700;
    color: #0D1B2A;
    margin-bottom: 8px;
    display: flex; align-items: center; gap: 6px;
  }
  .pr-cp .head .spark { color: #008C8C; font-size: 13px; }
  .pr-cp ul {
    margin: 0 0 12px 18px;
    padding: 0;
    color: #0D1B2A;
    font-size: 12px;
    line-height: 1.7;
  }
  .pr-cp .actions {
    display: flex; align-items: center; gap: 8px;
  }
  .pr-cp .actions .cp-btn.primary { padding: 8px 14px; }
  .pr-cp .actions form { display: inline; }

  /* Provider note */
  .pr-note textarea {
    width: 100%;
    min-height: 90px;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    padding: 10px 12px;
    font-family: inherit;
    font-size: 12px;
    color: #0D1B2A;
    resize: vertical;
    outline: none;
    line-height: 1.5;
  }
  .pr-note textarea:focus { border-color: #008C8C; }
  .pr-note .tplBtn {
    margin-top: 10px;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    color: #4F5763;
    border-radius: 999px;
    font-size: 11px; font-weight: 500;
    padding: 6px 12px;
    display: inline-flex; align-items: center; gap: 6px;
    line-height: 1;
  }

  /* Footer action bar */
  .pr-foot {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 18px;
    border-top: 1px solid #E4E5E8;
    background: #FFFFFF;
  }
  .pr-foot .spacer { flex: 1; }
  .pr-foot .cp-btn { padding: 9px 18px; font-size: 12px; }
  .pr-foot .cp-btn.primary { padding: 9px 22px; }
  .pr-foot form { display: inline; }

  /* SVG icon defaults */
  .pr-ico { width: 100%; height: 100%; display: block; }

  /* Header forms reset */
  .cp-pagehead form { display: inline; margin: 0; }

  /* Provider dropdown inside header for reassign */
  .pr-hdr-select {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    color: #0D1B2A;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    margin-right: 4px;
  }
</style>
</head>
<body class="cp-arch">

<?php if ($flashLabel !== ''): ?>
  <div class="pr-flash<?php echo (str_ends_with($flashMsg, 'failed')) ? ' fail' : ''; ?>">
    <?php echo text($flashLabel); ?>
  </div>
<?php endif; ?>

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:6px;">
      <span class="title"><?php echo xlt('Pending Review'); ?></span>
      <span class="dot">&middot;</span>
      <span class="meta-light"><?php echo text($summaryLine); ?></span>
    </div>
  </div>

  <!-- Reassign: small inline form, dropdown picks new provider -->
  <?php if ($selectedRow): ?>
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>">
    <input type="hidden" name="action" value="reassign">
    <input type="hidden" name="result_id" value="<?php echo attr((string)$detResultId); ?>">
    <select name="new_provider" class="pr-hdr-select" required>
      <option value=""><?php echo xlt('Reassign to…'); ?></option>
      <?php foreach ($providerList as $p):
        if ((int)$p['id'] === (int)$selectedRow['provider_id']) { continue; }
      ?>
        <option value="<?php echo attr((string)$p['id']); ?>"><?php echo text(cp_format_provider_name($p)); ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="cp-btn ghost">&#8646; <?php echo xlt('Reassign'); ?></button>
  </form>
  <?php endif; ?>

  <!-- Bulk-sign the pre-checked rows -->
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>">
    <input type="hidden" name="action" value="bulk_sign">
    <?php foreach (array_keys($preChecked) as $rid): ?>
      <input type="hidden" name="ids[]" value="<?php echo attr((string)$rid); ?>">
    <?php endforeach; ?>
    <button type="submit" class="cp-btn primary"<?php echo $preCheckedCount === 0 ? ' disabled' : ''; ?>>
      &#9998; <?php echo xlt('Sign'); ?> <?php echo text((string)$preCheckedCount); ?> <?php echo xlt('selected'); ?>
    </button>
  </form>

  <button type="button" class="cp-btn ghost" disabled title="<?php echo xla('Help — coming soon'); ?>">? <?php echo xlt('Help'); ?></button>
</header>

<div class="pr-filterbar">
  <div class="pills">
    <?php foreach ($filters as [$lbl, $key, $ct]): ?>
      <a href="?type=<?php echo attr($key); ?>" class="<?php echo $type === $key ? 'active' : ''; ?>">
        <?php echo text($lbl); ?> <span class="ct"><?php echo text((string)$ct); ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="pr-body">

  <!-- Left pane: list -->
  <section class="pr-pane left">
    <div class="pr-list-head">
      <span class="pr-cb indeterminate"></span>
      <span class="sort"><?php echo xlt('Sort: Newest'); ?> <span class="caret">&#9662;</span></span>
      <span class="count"><?php echo text((string)count($visibleRows)); ?> <?php echo xlt('results'); ?></span>
    </div>
    <div class="pr-list">
      <?php if (empty($visibleRows)): ?>
        <div style="padding:24px; color:#8A91A1; font-size:12px; text-align:center;">
          <?php echo xlt('No pending results match this filter.'); ?>
        </div>
      <?php endif; ?>
      <?php foreach ($visibleRows as $r):
        $rid = (int)$r['procedure_result_id'];
        $repId = (int)$r['procedure_report_id'];
        $isSel = ($rid === $selectedId);
        $bucket = (string)$r['bucket'];
        [$stLbl, $stTone] = cp_pr_status_pill($r);
        $name = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''));
        $sub = cp_pr_subline($r);
        $when = cp_pr_time_ago($r['date_collected'] ?? null);
        $iconClass = $bucket; // lab|imaging|doc|msg|critical
      ?>
        <a href="?type=<?php echo attr($type); ?>&selected=<?php echo attr((string)$rid); ?>"
           class="pr-row<?php echo $isSel ? ' active' : ''; ?>">
          <span class="pr-cb<?php echo isset($preChecked[$repId]) ? ' on' : ''; ?>"></span>
          <span class="icon <?php echo attr($iconClass); ?>">
            <?php if ($bucket === 'imaging'): ?>
              <svg class="pr-ico" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                <rect x="3" y="3" width="14" height="14" rx="2"/>
                <circle cx="8" cy="8" r="1.5"/>
                <path d="M3 14l4-4 3 3 3-3 4 4"/>
              </svg>
            <?php elseif ($bucket === 'doc'): ?>
              <svg class="pr-ico" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M5 2h7l4 4v12H5z"/>
                <path d="M12 2v4h4"/>
                <path d="M7 10h6M7 13h6M7 16h4"/>
              </svg>
            <?php elseif ($bucket === 'msg'): ?>
              <svg class="pr-ico" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M3 5h14v9H8l-4 3V5z"/>
              </svg>
            <?php else: ?>
              <!-- lab / critical default flask -->
              <svg class="pr-ico" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M8 3v5L4 15a2 2 0 0 0 1.7 3h8.6A2 2 0 0 0 16 15l-4-7V3"/>
                <path d="M7 3h6"/>
                <circle cx="9" cy="13" r="0.6" fill="currentColor"/>
                <circle cx="11.5" cy="15" r="0.6" fill="currentColor"/>
              </svg>
            <?php endif; ?>
          </span>
          <div class="core">
            <div class="ttl"><?php echo text($name); ?> &middot; <?php echo text((string)$r['result_text']); ?></div>
            <div class="sub"><?php echo text($sub); ?></div>
          </div>
          <div class="meta">
            <?php if ($stTone === 'plain'): ?>
              <span class="cp-status-pill plain"><?php echo text($stLbl); ?></span>
            <?php else: ?>
              <span class="cp-status-pill <?php echo attr($stTone); ?>"><?php echo text($stLbl); ?></span>
            <?php endif; ?>
            <span class="when"><?php echo text($when); ?></span>
          </div>
          <span class="kebab">&#8943;</span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Right pane: detail -->
  <section class="pr-pane right">
    <?php if ($selectedRow): ?>
    <div class="pr-detail-head">
      <span class="icon">
        <?php if ($detBucket === 'imaging'): ?>
          <svg class="pr-ico" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="3" y="3" width="14" height="14" rx="2"/>
            <circle cx="8" cy="8" r="1.5"/>
            <path d="M3 14l4-4 3 3 3-3 4 4"/>
          </svg>
        <?php elseif ($detBucket === 'doc'): ?>
          <svg class="pr-ico" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M5 2h7l4 4v12H5z"/><path d="M12 2v4h4"/>
            <path d="M7 10h6M7 13h6M7 16h4"/>
          </svg>
        <?php else: ?>
          <svg class="pr-ico" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M8 3v5L4 15a2 2 0 0 0 1.7 3h8.6A2 2 0 0 0 16 15l-4-7V3"/>
            <path d="M7 3h6"/>
            <circle cx="9" cy="13" r="0.6" fill="currentColor"/>
            <circle cx="11.5" cy="15" r="0.6" fill="currentColor"/>
          </svg>
        <?php endif; ?>
      </span>
      <div class="info">
        <span class="ttl"><?php echo text($detPatientName); ?> &mdash; <?php echo text($detTestName); ?></span>
        <span class="sub">
          <?php if ($detMrn !== ''): ?>MRN #<?php echo text($detMrn); ?> &middot; <?php endif; ?>
          <?php if ($detDrawnPretty !== ''): ?><?php echo xlt('Drawn'); ?> <?php echo text($detDrawnPretty); ?> &middot; <?php endif; ?>
          <?php echo text($detLab !== '' ? $detLab : 'Lab'); ?>
        </span>
      </div>
      <a class="cp-btn ghost"
         href="/interface/patient_file/encounter/copilot_encounter.php?pid=<?php echo attr((string)$detPid); ?>">
        <?php echo xlt('Open chart'); ?> &rarr;
      </a>
    </div>

    <div class="pr-detail-body">

      <div>
        <div class="pr-secLbl" style="margin-bottom:6px;"><?php echo xlt('RESULT'); ?></div>
        <?php if ($detIsCritical): ?>
          <div style="margin-bottom:10px;"><span class="pr-crit-pill"><?php echo xlt('CRITICAL VALUE'); ?></span></div>
        <?php endif; ?>
        <?php
          $resTone = $detIsCritical ? '' : (in_array(strtolower((string)$selectedRow['abnormal']), ['high','low','abnormal'], true) ? 'warn' : 'routine');
        ?>
        <div class="pr-result <?php echo attr($resTone); ?>">
          <div class="big">
            <span class="name"><?php echo text($detTestName); ?></span>
            <span class="val">
              <?php echo text($detValue); ?><?php if ($detUnits !== ''): ?> <?php echo text($detUnits); ?><?php endif; ?>
            </span>
            <?php if ($priorValueLabel !== null && $priorValueLabel !== '' && is_numeric($priorValueLabel) && is_numeric($detValue)): ?>
              <?php
                $arrow = ((float)$detValue > (float)$priorValueLabel) ? '↑' : (((float)$detValue < (float)$priorValueLabel) ? '↓' : '→');
                $ptDate = $priorValueDate ? date('m/d/Y', strtotime($priorValueDate)) : '';
              ?>
              <span class="delta"><?php echo text($arrow); ?> <?php echo xlt('from'); ?> <?php echo text($priorValueLabel); ?><?php if ($detUnits !== ''): ?> <?php echo text($detUnits); ?><?php endif; ?><?php if ($ptDate !== ''): ?> (<?php echo text($ptDate); ?>)<?php endif; ?></span>
            <?php endif; ?>
          </div>
          <div class="ref">
            <span class="lbl"><?php echo xlt('Reference range'); ?></span>
            <span class="v"><?php echo text($detRange !== '' ? $detRange : '—'); ?><?php if ($detUnits !== ''): ?> <?php echo text($detUnits); ?><?php endif; ?></span>
          </div>
          <?php if (!empty($trendValues)): ?>
          <div class="last">
            <?php echo xlt('Last 4'); ?>:
            <?php
              $rendered = array_slice($trendValues, -4);
              $lastN = count($rendered);
              foreach ($rendered as $i => $tv) {
                  echo text($tv);
                  if ($i < $lastN - 1) {
                      echo ' &rarr; ';
                  }
              }
            ?>
            <?php if ($lastN >= 2):
              // Simple SVG sparkline.
              $nums = array_filter($rendered, 'is_numeric');
              if (count($nums) >= 2):
                $min = min($nums); $max = max($nums);
                $range = ($max - $min) ?: 1;
                $w = 60; $h = 16; $pts = [];
                $step = $w / max(1, count($rendered) - 1);
                foreach ($rendered as $i => $tv) {
                    if (!is_numeric($tv)) { continue; }
                    $x = (int)round($i * $step);
                    $y = (int)round($h - (((float)$tv - $min) / $range) * $h);
                    $pts[] = $x . ',' . $y;
                }
                $polyline = implode(' ', $pts);
            ?>
              <svg class="pr-spark" width="60" height="16" viewBox="0 0 60 16" fill="none">
                <polyline points="<?php echo attr($polyline); ?>" stroke="#008C8C" stroke-width="1.5" fill="none"/>
              </svg>
            <?php endif; endif; ?>
          </div>
          <?php endif; ?>
          <div class="trend">
            <?php if (!empty($trendValues) && count($trendValues) >= 2 && is_numeric($trendValues[0]) && is_numeric($trendValues[count($trendValues)-1])):
                $delta = (float)$trendValues[count($trendValues)-1] - (float)$trendValues[0];
                $direction = $delta > 0 ? xl('rising') : ($delta < 0 ? xl('falling') : xl('stable'));
            ?>
              <?php echo xlt('Trend'); ?>: <?php echo text($direction); ?>
              <?php echo text(sprintf('%+0.1f', $delta)); ?>
              <?php if ($detUnits !== ''): ?><?php echo text($detUnits); ?><?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div>
        <div class="pr-secLbl" style="margin-bottom:6px;"><?php echo xlt('CO-PILOT SUGGESTION'); ?></div>
        <div class="pr-cp">
          <div class="head"><span class="spark">&#10022;</span> <?php echo xlt('Suggested actions'); ?></div>
          <ul>
            <?php // static text — would call /api/copilot/suggest in production ?>
            <?php foreach ($suggestions as $s): ?>
              <li><?php echo text($s); ?></li>
            <?php endforeach; ?>
          </ul>
          <div class="actions">
            <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>">
              <input type="hidden" name="action" value="accept_all_sign">
              <input type="hidden" name="result_id" value="<?php echo attr((string)$detResultId); ?>">
              <button type="submit" class="cp-btn primary"><?php echo xlt('Accept all & sign'); ?></button>
            </form>
            <a class="cp-btn ghost"
               href="/interface/copilot/index.php?pid=<?php echo attr((string)$detPid); ?>">
              <?php echo xlt('Open in Co-Pilot'); ?> &rarr;
            </a>
          </div>
        </div>
      </div>

      <div class="pr-note">
        <div class="pr-secLbl" style="margin-bottom:8px;"><?php echo xlt('PROVIDER NOTE'); ?></div>
        <textarea><?php echo text($existingNote); ?></textarea>
        <button type="button" class="tplBtn"><?php echo xlt('Use template'); ?> &#9662;</button>
      </div>

    </div>

    <div class="pr-foot">
      <!-- Forward to colleague -->
      <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>">
        <input type="hidden" name="action" value="forward">
        <input type="hidden" name="result_id" value="<?php echo attr((string)$detResultId); ?>">
        <select name="recipient" class="pr-hdr-select" style="margin-right:0;">
          <?php foreach ($recipientList as $u):
            if ((string)$u['username'] === (string)($_SESSION['authUser'] ?? '')) { continue; }
          ?>
            <option value="<?php echo attr((string)$u['username']); ?>">
              <?php echo text(trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')) ?: (string)$u['username']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="cp-btn ghost"><?php echo xlt('Forward'); ?></button>
      </form>

      <?php
        // Skip → next unsigned in the visible filtered list.
        $nextId = null;
        $foundCurrent = false;
        foreach ($visibleRows as $vr) {
            if ($foundCurrent) { $nextId = (int)$vr['procedure_result_id']; break; }
            if ((int)$vr['procedure_result_id'] === $detResultId) { $foundCurrent = true; }
        }
        if ($nextId === null && !empty($visibleRows)) {
            // Wrap to first if at end.
            $nextId = (int)$visibleRows[0]['procedure_result_id'];
        }
      ?>
      <a class="cp-btn ghost"
         href="?type=<?php echo attr($type); ?>&amp;selected=<?php echo attr((string)($nextId ?? $detResultId)); ?>">
        <?php echo xlt('Skip'); ?>
      </a>

      <span class="spacer"></span>

      <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>">
        <input type="hidden" name="action" value="sign_next">
        <input type="hidden" name="result_id" value="<?php echo attr((string)$detResultId); ?>">
        <button type="submit" class="cp-btn primary"><?php echo xlt('Sign & next'); ?> &rarr;</button>
      </form>
    </div>
    <?php else: ?>
    <div style="padding:48px; text-align:center; color:#8A91A1; font-size:13px;">
      <?php echo xlt('No pending results to review.'); ?>
    </div>
    <?php endif; ?>
  </section>

</div>

</body>
</html>
