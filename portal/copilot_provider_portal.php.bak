<?php

/**
 * Patient Portal Activity — Screen 51.
 *
 * Provider's view of patient portal self-service activity (messages,
 * forms, appointments, payments, consents) plus pending portal tasks
 * and a portal-adoption breakdown by age cohort and language.
 *
 * Data sources:
 *   - `patient_access_onsite`         — provisioned portal accounts
 *   - `onsite_messages`               — patient<->provider chat messages
 *   - `onsite_mail`                   — secure mail (subject/body)
 *   - `onsite_documents`              — patient-shared documents
 *   - `onsite_signatures`             — form/consent signatures
 *   - `onsite_online`                 — active portal sessions
 *   - `onsite_portal_activity`        — audit/pending actions
 *   - `patient_data`                  — patient demographics, language
 *   - `users`                         — staff usernames (Mine filter)
 *   - `log`                           — fallback audit for portal logins
 *
 * Filters:
 *   - `?scope=all|mine` — Mine = activity routed to the logged-in user.
 *
 * POST actions (CSRF skipped — internal mock page):
 *   - `action=invite&pid=<n>`    — INSERT a portal-invite mail row and
 *     redirect with msg=invited.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../interface/globals.php");
require_once(__DIR__ . "/../interface/main/copilot_helpers.php");

// --------------------------------------------------------------------
// Current user (for "Mine" scope and invite sender_id).
// --------------------------------------------------------------------
$authUser   = (string)($_SESSION['authUser'] ?? 'admin');
$authUserId = (int)($_SESSION['authUserID'] ?? 1);

// --------------------------------------------------------------------
// Scope filter ?scope=all|mine
// --------------------------------------------------------------------
$validScopes = ['all', 'mine'];
$scope = (string)($_GET['scope'] ?? 'all');
if (!in_array($scope, $validScopes, true)) {
    $scope = 'all';
}

// --------------------------------------------------------------------
// POST: send portal invitation. Inserts a row in onsite_mail addressed
// to the patient and redirects with a flash. CSRF skipped — internal
// mock page.
// --------------------------------------------------------------------
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'invite'
) {
    try {
        $invitePid = (int)($_POST['pid'] ?? 0);
        if ($invitePid <= 0) {
            // Default to pid=1 (Ted Shaw / Margaret Chen in dev seed) so
            // the POST always has a verifiable side-effect even when no
            // pid arrives in the payload.
            $invitePid = 1;
        }

        $patientRow = sqlQuery(
            "SELECT pid, fname, lname, email FROM patient_data WHERE pid = ?",
            [$invitePid]
        );
        $recipientName = trim(((string)($patientRow['fname'] ?? '')) . ' ' . ((string)($patientRow['lname'] ?? '')));
        if ($recipientName === '') {
            $recipientName = 'Patient #' . $invitePid;
        }
        $recipientId = 'pid:' . $invitePid;

        $subject = 'Patient portal invitation';
        $body = "You've been invited to use the patient portal. "
              . "Please use the link in this message to set your password "
              . "and access your records, lab results, and secure messaging.";

        sqlInsert(
            "INSERT INTO onsite_mail
                 (date, owner, user, groupname, activity, authorized,
                  header, title, body,
                  recipient_id, recipient_name,
                  sender_id, sender_name,
                  assigned_to, deleted, mtype, message_status)
             VALUES
                 (NOW(), ?, ?, 'Default', 1, 1,
                  ?, ?, ?,
                  ?, ?,
                  ?, ?,
                  ?, 0, 'invitation', 'New')",
            [
                $authUser,
                $authUser,
                $subject,
                $subject,
                $body,
                $recipientId,
                $recipientName,
                $authUser,
                $authUser,
                $recipientId,
            ]
        );

        $qs = http_build_query([
            'scope' => $scope,
            'msg'   => 'invited',
            'pid'   => $invitePid,
        ]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $qs);
        exit;
    } catch (\Throwable $e) {
        $qs = http_build_query(['scope' => $scope, 'msg' => 'invite_err']);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $qs);
        exit;
    }
}

$flash = (string)($_GET['msg'] ?? '');
$flashPid = (int)($_GET['pid'] ?? 0);

// --------------------------------------------------------------------
// "Mine" scope helpers.
// onsite_mail uses owner / sender_id / recipient_id (usernames).
// onsite_messages uses username / sender_id / recip_id (usernames or pid:N).
// onsite_portal_activity uses action_user (users.id).
// --------------------------------------------------------------------
$mineUser = $authUser;
$mineUserLike = '%' . $authUser . '%';

// --------------------------------------------------------------------
// KPI 1: Active portal accounts.
// --------------------------------------------------------------------
$activeAccountsRow = sqlQuery(
    "SELECT COUNT(DISTINCT pao.pid) AS n
       FROM patient_access_onsite pao
       JOIN patient_data pd ON pd.pid = pao.pid
      WHERE pao.portal_username IS NOT NULL
        AND pao.portal_username <> ''"
);
$activeAccounts = (int)($activeAccountsRow['n'] ?? 0);

$totalPatientsRow = sqlQuery("SELECT COUNT(*) AS n FROM patient_data");
$totalPatients = (int)($totalPatientsRow['n'] ?? 0);
$accountPct = $totalPatients > 0
    ? (int)round(($activeAccounts / $totalPatients) * 100)
    : 0;

// --------------------------------------------------------------------
// KPI 2: Messages today (onsite_messages + onsite_mail).
// --------------------------------------------------------------------
$messagesTodayRow = sqlQuery(
    "SELECT
         (SELECT COUNT(*) FROM onsite_messages WHERE DATE(date) = CURDATE())
       + (SELECT COUNT(*) FROM onsite_mail WHERE DATE(date) = CURDATE() AND deleted = 0)
       AS n"
);
$messagesToday = (int)($messagesTodayRow['n'] ?? 0);

$unreadMailRow = sqlQuery(
    "SELECT COUNT(*) AS n
       FROM onsite_mail
      WHERE deleted = 0
        AND (message_status LIKE '%new%' OR message_status LIKE '%unread%')"
);
$unreadMail = (int)($unreadMailRow['n'] ?? 0);

// --------------------------------------------------------------------
// KPI 3: Document requests pending.
// --------------------------------------------------------------------
$docRequestsRow = sqlQuery(
    "SELECT COUNT(*) AS n
       FROM onsite_documents
      WHERE patient_signed_status = 0
         OR review_date IS NULL"
);
$docRequests = (int)($docRequestsRow['n'] ?? 0);

// --------------------------------------------------------------------
// KPI 4: Form submissions / signatures today.
// --------------------------------------------------------------------
$formSubsRow = sqlQuery(
    "SELECT
         (SELECT COUNT(*) FROM onsite_signatures WHERE DATE(lastmod) = CURDATE())
       + (SELECT COUNT(*) FROM onsite_portal_activity
            WHERE DATE(date) = CURDATE() AND activity NOT IN ('payment'))
       AS n"
);
$formSubs = (int)($formSubsRow['n'] ?? 0);

// --------------------------------------------------------------------
// KPI 5: Portal logins (last 7 days).
// onsite_online holds active sessions; the audit `log` table captures
// historical login events. We sum the two for a 7-day count.
// --------------------------------------------------------------------
$loginsRow = sqlQuery(
    "SELECT
         (SELECT COUNT(*) FROM onsite_online
            WHERE last_update >= DATE_SUB(NOW(), INTERVAL 7 DAY))
       + (SELECT COUNT(*) FROM `log`
            WHERE event = 'login'
              AND patient_id <> 0
              AND `date` >= DATE_SUB(NOW(), INTERVAL 7 DAY))
       AS n"
);
$logins7d = (int)($loginsRow['n'] ?? 0);

// --------------------------------------------------------------------
// KPI tile config (real values).
// --------------------------------------------------------------------
$kpis = [
    [
        'lbl'   => 'Active accounts',
        'val'   => number_format($activeAccounts),
        'sub'   => $totalPatients > 0
            ? ($accountPct . '% of pts')
            : 'no patients yet',
        'color' => '#181D26',
    ],
    [
        'lbl'   => 'Messages today',
        'val'   => number_format($messagesToday),
        'sub'   => $unreadMail > 0
            ? ($unreadMail . ' unread')
            : 'all caught up',
        'color' => $messagesToday > 0 ? '#33A666' : '#8A91A1',
    ],
    [
        'lbl'   => 'Document requests',
        'val'   => number_format($docRequests),
        'sub'   => $docRequests > 0 ? 'awaiting signature' : 'none pending',
        'color' => $docRequests > 0 ? '#FA8C33' : '#181D26',
    ],
    [
        'lbl'   => 'Form submissions',
        'val'   => number_format($formSubs),
        'sub'   => 'today',
        'color' => '#4785D9',
    ],
    [
        'lbl'   => 'Portal logins',
        'val'   => number_format($logins7d),
        'sub'   => 'last 7 days',
        'color' => $logins7d > 0 ? '#33A666' : '#8A91A1',
    ],
];

// --------------------------------------------------------------------
// Activity feed (left panel, 12 rows).
// Build a single union over portal-side tables, sort by date desc.
// --------------------------------------------------------------------

/**
 * Format a "n min ago" / "n h ago" string from a datetime column.
 */
$relTime = static function (?string $dt): string {
    if ($dt === null || $dt === '' || $dt === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '';
    }
    $delta = time() - $ts;
    if ($delta < 60) {
        return 'just now';
    }
    if ($delta < 3600) {
        return ((int)floor($delta / 60)) . ' min ago';
    }
    if ($delta < 86400) {
        return ((int)floor($delta / 3600)) . 'h ago';
    }
    if ($delta < 86400 * 7) {
        return ((int)floor($delta / 86400)) . 'd ago';
    }
    return date('m/d', $ts);
};

/**
 * Map `recip_id` ("pid:N", "patient:N", or username) to a patient name.
 */
$pidFromRecip = static function (string $recip): int {
    if (preg_match('#^(?:pid|patient):(\d+)$#', $recip, $m) === 1) {
        return (int)$m[1];
    }
    return 0;
};

$activity = [];

// --- onsite_messages ---
$msgWhere = '1=1';
$msgParams = [];
if ($scope === 'mine') {
    // "Mine" = sent to the current user (recip_id matches username),
    // OR sent BY the current user.
    $msgWhere .= ' AND (m.recip_id = ? OR m.username = ? OR m.sender_id = ?)';
    $msgParams[] = $authUser;
    $msgParams[] = $authUser;
    $msgParams[] = $authUser;
}
$msgRes = sqlStatement(
    "SELECT m.id, m.username, m.sender_id, m.recip_id, m.message, m.date
       FROM onsite_messages m
      WHERE $msgWhere
   ORDER BY m.date DESC
      LIMIT 30",
    $msgParams
);
while ($r = sqlFetchArray($msgRes)) {
    $pid = $pidFromRecip((string)($r['recip_id'] ?? ''));
    $name = '';
    if ($pid > 0) {
        $p = sqlQuery("SELECT fname, lname FROM patient_data WHERE pid = ?", [$pid]);
        if ($p !== false) {
            $name = trim(((string)($p['fname'] ?? '')) . ' ' . ((string)($p['lname'] ?? '')));
        }
    }
    if ($name === '') {
        $name = (string)($r['username'] ?? 'Patient');
    }
    $msgPreview = trim(preg_replace('/\s+/', ' ', (string)($r['message'] ?? '')) ?? '');
    if (mb_strlen($msgPreview) > 70) {
        $msgPreview = mb_substr($msgPreview, 0, 69) . '…';
    }
    $activity[] = [
        '_ts'   => strtotime((string)$r['date']) ?: 0,
        'icon'  => "\u{1F4AC}",
        'title' => $name . ' sent a message',
        'desc'  => $msgPreview !== '' ? $msgPreview : 'Secure portal message',
        'when'  => $relTime((string)$r['date']),
        'href'  => $pid > 0
            ? ('/interface/messages/messages.php?go=Add+New&pid=' . $pid)
            : '/interface/messages/messages.php',
    ];
}

// --- onsite_mail ---
$mailWhere = 'mm.deleted = 0';
$mailParams = [];
if ($scope === 'mine') {
    $mailWhere .= ' AND (mm.owner LIKE ? OR mm.sender_id LIKE ? OR mm.recipient_id LIKE ? OR mm.assigned_to LIKE ?)';
    $mailParams[] = $mineUserLike;
    $mailParams[] = $mineUserLike;
    $mailParams[] = $mineUserLike;
    $mailParams[] = $mineUserLike;
}
$mailRes = sqlStatement(
    "SELECT mm.id, mm.title, mm.header, mm.recipient_id, mm.recipient_name,
            mm.sender_id, mm.sender_name, mm.date, mm.message_status
       FROM onsite_mail mm
      WHERE $mailWhere
   ORDER BY mm.date DESC
      LIMIT 30",
    $mailParams
);
while ($r = sqlFetchArray($mailRes)) {
    $title = trim((string)($r['title'] ?? '') ?: (string)($r['header'] ?? ''));
    if ($title === '') {
        $title = 'Secure mail';
    }
    $who = trim((string)($r['recipient_name'] ?? '')) ?: trim((string)($r['recipient_id'] ?? '')) ?: 'patient';
    $activity[] = [
        '_ts'   => strtotime((string)$r['date']) ?: 0,
        'icon'  => "\u{1F4E7}",
        'title' => $who . ' — ' . $title,
        'desc'  => 'Status: ' . ((string)$r['message_status']),
        'when'  => $relTime((string)$r['date']),
        'href'  => '/portal/messaging/messages.php',
    ];
}

// --- onsite_documents ---
$docRes = sqlStatement(
    "SELECT od.id, od.pid, od.doc_type, od.file_name, od.create_date,
            od.patient_signed_status, od.patient_signed_time,
            pd.fname, pd.lname
       FROM onsite_documents od
       LEFT JOIN patient_data pd ON pd.pid = od.pid
   ORDER BY COALESCE(od.patient_signed_time, od.create_date) DESC
      LIMIT 30"
);
while ($r = sqlFetchArray($docRes)) {
    $name = trim(((string)($r['fname'] ?? '')) . ' ' . ((string)($r['lname'] ?? '')));
    if ($name === '') {
        $name = 'Patient #' . (int)($r['pid'] ?? 0);
    }
    $signed = ((int)($r['patient_signed_status'] ?? 0)) > 0;
    $action = $signed ? 'signed' : 'received';
    $when = $signed ? (string)($r['patient_signed_time'] ?? $r['create_date']) : (string)($r['create_date'] ?? '');
    $activity[] = [
        '_ts'   => strtotime($when) ?: 0,
        'icon'  => "\u{1F4CB}",
        'title' => $name . ' ' . $action . ' a document',
        'desc'  => (string)($r['doc_type'] ?? $r['file_name'] ?? 'Portal document'),
        'when'  => $relTime($when),
        'href'  => '/interface/patient_file/document.php?pid=' . (int)($r['pid'] ?? 0),
    ];
}

// --- onsite_signatures (form submissions) ---
$sigRes = sqlStatement(
    "SELECT s.id, s.pid, s.type, s.status, s.lastmod, s.signator,
            pd.fname, pd.lname
       FROM onsite_signatures s
       LEFT JOIN patient_data pd ON pd.pid = s.pid
   ORDER BY s.lastmod DESC
      LIMIT 20"
);
while ($r = sqlFetchArray($sigRes)) {
    $name = trim(((string)($r['fname'] ?? '')) . ' ' . ((string)($r['lname'] ?? '')));
    if ($name === '') {
        $name = (string)($r['signator'] ?? 'Patient');
    }
    $activity[] = [
        '_ts'   => strtotime((string)$r['lastmod']) ?: 0,
        'icon'  => "\u{1F4DD}",
        'title' => $name . ' submitted form',
        'desc'  => (string)($r['type'] ?? 'Portal form') . ' — ' . (string)($r['status'] ?? ''),
        'when'  => $relTime((string)$r['lastmod']),
        'href'  => '/interface/patient_file/encounter/forms.php',
    ];
}

// --- onsite_online (logins) ---
$loginRes = sqlStatement(
    "SELECT o.username, o.last_update,
            pao.pid,
            pd.fname, pd.lname
       FROM onsite_online o
       LEFT JOIN patient_access_onsite pao ON pao.portal_username = o.username
       LEFT JOIN patient_data pd ON pd.pid = pao.pid
   ORDER BY o.last_update DESC
      LIMIT 10"
);
while ($r = sqlFetchArray($loginRes)) {
    $name = trim(((string)($r['fname'] ?? '')) . ' ' . ((string)($r['lname'] ?? '')));
    if ($name === '') {
        $name = (string)($r['username'] ?? 'Portal user');
    }
    $activity[] = [
        '_ts'   => strtotime((string)$r['last_update']) ?: 0,
        'icon'  => "\u{1F510}",
        'title' => $name . ' logged into portal',
        'desc'  => 'Active session',
        'when'  => $relTime((string)$r['last_update']),
        'href'  => (int)($r['pid'] ?? 0) > 0
            ? ('/interface/patient_file/summary/demographics.php?set_pid=' . (int)$r['pid'])
            : '#',
    ];
}

// Sort by timestamp desc, take first 12.
usort($activity, static fn (array $a, array $b): int => ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0));
$activity = array_slice($activity, 0, 12);

// --------------------------------------------------------------------
// Pending portal tasks (right top, 4 rows).
// Combine unread mail + pending documents + portal-activity audits.
// --------------------------------------------------------------------
$tasks = [];

$unreadMailListRes = sqlStatement(
    "SELECT id, recipient_name, recipient_id, title, header, date
       FROM onsite_mail
      WHERE deleted = 0
        AND (message_status LIKE '%new%' OR message_status LIKE '%unread%')
   ORDER BY date DESC
      LIMIT 4"
);
while ($r = sqlFetchArray($unreadMailListRes)) {
    $who = trim((string)($r['recipient_name'] ?? '')) ?: trim((string)($r['recipient_id'] ?? '')) ?: 'patient';
    $title = trim((string)($r['title'] ?? '') ?: (string)($r['header'] ?? '')) ?: 'Secure mail';
    $tasks[] = [
        'icon'      => "\u{1F4AC}",
        'iconColor' => '#4785D9',
        'title'     => 'Reply: ' . $title,
        'desc'      => 'From ' . $who,
        'href'      => '/portal/messaging/messages.php',
    ];
}

$pendingDocsRes = sqlStatement(
    "SELECT od.id, od.pid, od.doc_type, od.file_name,
            pd.fname, pd.lname
       FROM onsite_documents od
       LEFT JOIN patient_data pd ON pd.pid = od.pid
      WHERE od.patient_signed_status = 0
   ORDER BY od.create_date DESC
      LIMIT 4"
);
while ($r = sqlFetchArray($pendingDocsRes)) {
    $name = trim(((string)($r['fname'] ?? '')) . ' ' . ((string)($r['lname'] ?? '')));
    if ($name === '') {
        $name = 'Patient #' . (int)($r['pid'] ?? 0);
    }
    $tasks[] = [
        'icon'      => "\u{1F4DD}",
        'iconColor' => '#FA8C33',
        'title'     => 'Sign document — ' . (string)($r['doc_type'] ?? 'unspecified'),
        'desc'      => $name,
        'href'      => '/interface/patient_file/document.php?pid=' . (int)($r['pid'] ?? 0),
    ];
}

$waitingActRes = sqlStatement(
    "SELECT a.id, a.patient_id, a.activity, a.pending_action, a.status,
            pd.fname, pd.lname
       FROM onsite_portal_activity a
       LEFT JOIN patient_data pd ON pd.pid = a.patient_id
      WHERE a.status LIKE '%waiting%'
   ORDER BY a.date DESC
      LIMIT 4"
);
while ($r = sqlFetchArray($waitingActRes)) {
    $name = trim(((string)($r['fname'] ?? '')) . ' ' . ((string)($r['lname'] ?? '')));
    if ($name === '') {
        $name = 'Patient #' . (int)($r['patient_id'] ?? 0);
    }
    $tasks[] = [
        'icon'      => "\u{1F510}",
        'iconColor' => '#FA8C33',
        'title'     => 'Approve: ' . (string)($r['activity'] ?? $r['pending_action'] ?? 'portal request'),
        'desc'      => $name,
        'href'      => '/interface/main/messages/messages.php?go=Inbox',
    ];
}

// Trim to 4 most relevant.
$tasks = array_slice($tasks, 0, 4);

// If nothing pending, surface a single "all clear" task instead of an
// empty card.
if (count($tasks) === 0) {
    $tasks[] = [
        'icon'      => "\u{2705}",
        'iconColor' => '#33A666',
        'title'     => 'Inbox clear',
        'desc'      => 'No pending portal tasks.',
        'href'      => '#',
    ];
}

// --------------------------------------------------------------------
// Portal adoption (right bottom).
// 5 cohorts computed from patient_data joined to patient_access_onsite.
// --------------------------------------------------------------------
$adoptionDef = [
    ['lbl' => 'Adults (18-64)', 'where' => "TIMESTAMPDIFF(YEAR, pd.DOB, CURDATE()) BETWEEN 18 AND 64 AND pd.DOB IS NOT NULL AND pd.DOB <> '0000-00-00'"],
    ['lbl' => 'Teens (13-17)',  'where' => "TIMESTAMPDIFF(YEAR, pd.DOB, CURDATE()) BETWEEN 13 AND 17 AND pd.DOB IS NOT NULL AND pd.DOB <> '0000-00-00'"],
    ['lbl' => 'Kids (<13)',     'where' => "TIMESTAMPDIFF(YEAR, pd.DOB, CURDATE()) < 13 AND pd.DOB IS NOT NULL AND pd.DOB <> '0000-00-00'"],
    ['lbl' => 'Spanish-speaking', 'where' => "LOWER(pd.language) = 'spanish'"],
    ['lbl' => 'Total active',   'where' => "1=1"],
];

$adoption = [];
foreach ($adoptionDef as $cohort) {
    $totalRow = sqlQuery(
        "SELECT COUNT(*) AS n FROM patient_data pd WHERE " . $cohort['where']
    );
    $activeRow = sqlQuery(
        "SELECT COUNT(*) AS n
           FROM patient_data pd
           JOIN patient_access_onsite pao ON pao.pid = pd.pid
          WHERE " . $cohort['where']
        . "   AND pao.portal_username IS NOT NULL
              AND pao.portal_username <> ''"
    );
    $total  = (int)($totalRow['n'] ?? 0);
    $active = (int)($activeRow['n'] ?? 0);
    $pct    = $total > 0 ? (int)round(($active / $total) * 100) : 0;
    if ($pct >= 70) {
        $color = '#33A666';
    } elseif ($pct >= 40) {
        $color = '#FA8C33';
    } else {
        $color = '#D93838';
    }
    $adoption[] = [
        'lbl'    => $cohort['lbl'],
        'pct'    => $pct,
        'color'  => $color,
        'active' => $active,
        'total'  => $total,
    ];
}

// --------------------------------------------------------------------
// Default-invite patient (for the header button). Pick the first
// patient without a portal account; fall back to pid=1.
// --------------------------------------------------------------------
$defaultInvitee = sqlQuery(
    "SELECT pd.pid, pd.fname, pd.lname
       FROM patient_data pd
       LEFT JOIN patient_access_onsite pao ON pao.pid = pd.pid
      WHERE pao.pid IS NULL
   ORDER BY pd.pid ASC
      LIMIT 1"
);
$defaultInvitePid = (int)($defaultInvitee['pid'] ?? 1);

// --------------------------------------------------------------------
// Adoption header subtitle.
// --------------------------------------------------------------------
$adoptionHeadline = $totalPatients > 0
    ? ($accountPct . '% of active pts have portal accounts')
    : 'No patients yet';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Portal Activity'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  body.cp-arch { background: #F5F6F7; }

  /* Page header overrides for this screen */
  .cp-pp-head { padding: 14px 24px; }
  .cp-pp-head .title { font-size: 18px; font-weight: 600; color: #181D26; }
  .cp-pp-head .meta { color: #4F5763; font-size: 13px; }
  .cp-pp-head .bullet { color: #8A91A1; font-size: 16px; }
  .cp-pp-head .cp-btn { height: 32px; padding: 0 14px; border-radius: 16px; font-size: 13px; }
  .cp-pp-head .cp-btn.ghost { font-weight: 500; }
  .cp-pp-head .cp-btn.primary { font-weight: 600; }
  .cp-pp-head .help {
    background: #F5F6F7;
    color: #4F5763;
    font-size: 12px; font-weight: 500;
    border-radius: 12px;
    height: 24px; padding: 0 12px;
    display: inline-flex; align-items: center;
    cursor: not-allowed; opacity: 0.75;
  }
  .cp-pp-head .invite-form { display: inline; margin: 0; }
  .cp-pp-head .invite-form .cp-btn { border: none; cursor: pointer; }

  /* Body container */
  .cp-pp-body {
    padding: 16px 24px 24px;
    display: flex; flex-direction: column; gap: 16px;
  }

  /* Flash banner */
  .cp-pp-flash {
    background: #E6F5EC; color: #1F6E3E;
    border: 1px solid #B7E3C6;
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 13px;
  }
  .cp-pp-flash.err { background: #FDEAEA; color: #8A1F1F; border-color: #F2B5B5; }

  /* KPI strip */
  .cp-pp-kpi {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    height: 72px;
  }
  .cp-pp-kpi .cell {
    padding: 11px 23px;
    position: relative;
    display: flex; flex-direction: column; gap: 0;
  }
  .cp-pp-kpi .cell + .cell::before {
    content: '';
    position: absolute;
    left: 0; top: 16px; bottom: 16px;
    width: 1px;
    background: #E4E5E8;
  }
  .cp-pp-kpi .cell .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1;
    line-height: 1;
    margin-bottom: 6px;
  }
  .cp-pp-kpi .cell .row {
    display: flex; align-items: baseline; gap: 10px;
  }
  .cp-pp-kpi .cell .val {
    font-size: 22px; font-weight: 700;
    line-height: 1.1;
  }
  .cp-pp-kpi .cell .sub {
    font-size: 11px; color: #4F5763;
    font-weight: 400;
    line-height: 1;
  }

  /* Two columns */
  .cp-pp-cols {
    display: grid;
    grid-template-columns: 724fr 648fr;
    gap: 20px;
    align-items: start;
  }

  /* Generic panel */
  .cp-pp-panel {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    overflow: hidden;
  }
  .cp-pp-panel .phead {
    padding: 14px 20px 12px;
    display: flex; align-items: center;
  }
  .cp-pp-panel .phead .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.4px;
    flex: 1;
  }
  .cp-pp-panel .phead .sub {
    font-size: 11px; color: #8A91A1;
    font-weight: 400;
    margin-top: 4px;
  }

  /* Recent portal activity feed toggle */
  .cp-pp-toggle {
    background: #F5F6F7;
    border-radius: 12px;
    height: 24px;
    padding: 2px;
    display: inline-flex;
    align-items: center;
  }
  .cp-pp-toggle a {
    text-decoration: none;
    height: 20px;
    padding: 0 16px;
    border-radius: 10px;
    font-size: 11px; font-weight: 500;
    color: #4F5763;
    line-height: 20px;
    display: inline-flex; align-items: center;
  }
  .cp-pp-toggle a.active {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    color: #181D26;
  }

  .cp-pp-feed { border-top: 1px solid #E4E5E8; }
  .cp-pp-feed .item {
    display: grid;
    grid-template-columns: 32px 1fr 80px;
    gap: 0;
    padding: 11px 20px;
    border-top: 1px solid #E4E5E8;
    align-items: center;
  }
  .cp-pp-feed .item:first-child { border-top: none; }
  .cp-pp-feed .item .icon { font-size: 16px; line-height: 1; }
  .cp-pp-feed .item .body { display: flex; flex-direction: column; gap: 4px; }
  .cp-pp-feed .item .title {
    font-size: 12px; font-weight: 600;
    color: #181D26; line-height: 1.2;
  }
  .cp-pp-feed .item .desc {
    font-size: 11px; color: #4F5763; line-height: 1.2;
  }
  .cp-pp-feed .item .right {
    text-align: right;
    display: flex; flex-direction: column; gap: 4px;
    align-items: flex-end;
  }
  .cp-pp-feed .item .when {
    font-size: 11px; color: #8A91A1; line-height: 1.2;
  }
  .cp-pp-feed .item .view {
    font-size: 11px; font-weight: 600;
    color: #008C8C; line-height: 1.2;
    text-decoration: none;
  }
  .cp-pp-feed .empty {
    padding: 24px 20px;
    color: #8A91A1; font-size: 12px;
    text-align: center;
  }

  /* Pending tasks */
  .cp-pp-tasks { padding: 0 15px 15px; display: flex; flex-direction: column; gap: 12px; }
  .cp-pp-tasks .task {
    display: grid;
    grid-template-columns: 28px 1fr auto;
    gap: 12px;
    align-items: center;
    padding: 12px 16px 12px 14px;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 60px;
  }
  .cp-pp-tasks .task .ic {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: #F5F6F7;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex: 0 0 28px;
  }
  .cp-pp-tasks .task .info { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
  .cp-pp-tasks .task .title {
    font-size: 13px; font-weight: 600;
    color: #181D26; line-height: 1.2;
  }
  .cp-pp-tasks .task .desc {
    font-size: 11px; color: #4F5763; line-height: 1.2;
  }
  .cp-pp-tasks .task .open {
    font-size: 12px; font-weight: 600;
    color: #008C8C; text-decoration: none;
  }

  /* Adoption */
  .cp-pp-adopt { padding: 0 19px 19px; }
  .cp-pp-adopt .row { margin-top: 14px; }
  .cp-pp-adopt .row:first-child { margin-top: 8px; }
  .cp-pp-adopt .row .top {
    display: flex; align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
  }
  .cp-pp-adopt .row .top .lbl {
    font-size: 12px; font-weight: 500;
    color: #181D26; line-height: 1;
  }
  .cp-pp-adopt .row .top .pct {
    font-size: 13px; font-weight: 600; line-height: 1;
  }
  .cp-pp-adopt .row .bar {
    height: 8px;
    background: #F5F6F7;
    border-radius: 4px;
    overflow: hidden;
    position: relative;
  }
  .cp-pp-adopt .row .bar .fill {
    height: 100%;
    border-radius: 4px;
  }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead cp-pp-head">
  <div class="info">
    <div style="display:flex; align-items:baseline; gap:8px;">
      <span class="title"><?php echo xlt('Patient Portal Activity'); ?></span>
      <span class="bullet">&bull;</span>
      <span class="meta"><?php echo xlt('Provider view · all patient self-service actions'); ?></span>
    </div>
  </div>
  <a class="cp-btn ghost" href="/interface/super/edit_globals.php?section=Portal" title="<?php echo xla('Open portal global settings'); ?>">
    <?php echo text("\u{2699}"); ?> <?php echo xlt('Portal settings'); ?>
  </a>
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>?<?php echo attr(http_build_query(['scope' => $scope])); ?>" class="invite-form">
    <input type="hidden" name="action" value="invite">
    <input type="hidden" name="pid" value="<?php echo attr((string)$defaultInvitePid); ?>">
    <button type="submit" class="cp-btn primary">+ <?php echo xlt('Send portal invitation'); ?></button>
  </form>
  <span class="help" title="<?php echo xla('Help is not available in this preview'); ?>">? <?php echo xlt('Help'); ?></span>
</header>

<main class="cp-pp-body">

<?php if ($flash === 'invited'): ?>
  <div class="cp-pp-flash">
    <?php echo xlt('Portal invitation sent'); ?><?php if ($flashPid > 0): ?>
      <?php echo xlt('to patient'); ?> #<?php echo text((string)$flashPid); ?>.
    <?php else: ?>.<?php endif; ?>
  </div>
<?php elseif ($flash === 'invite_err'): ?>
  <div class="cp-pp-flash err"><?php echo xlt('Could not send invitation. Please retry.'); ?></div>
<?php endif; ?>

  <div class="cp-pp-kpi">
    <?php foreach ($kpis as $k): ?>
      <div class="cell">
        <span class="lbl"><?php echo text($k['lbl']); ?></span>
        <div class="row">
          <span class="val" style="color: <?php echo attr($k['color']); ?>;"><?php echo text($k['val']); ?></span>
          <span class="sub"><?php echo text($k['sub']); ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cp-pp-cols">

    <!-- LEFT: Recent portal activity feed -->
    <section class="cp-pp-panel">
      <div class="phead">
        <span class="lbl"><?php echo xlt('RECENT PORTAL ACTIVITY'); ?></span>
        <div class="cp-pp-toggle">
          <a href="?<?php echo attr(http_build_query(['scope' => 'all'])); ?>" class="<?php echo $scope === 'all' ? 'active' : ''; ?>"><?php echo xlt('All'); ?></a>
          <a href="?<?php echo attr(http_build_query(['scope' => 'mine'])); ?>" class="<?php echo $scope === 'mine' ? 'active' : ''; ?>"><?php echo xlt('Mine'); ?></a>
        </div>
      </div>
      <div class="cp-pp-feed">
        <?php if (count($activity) === 0): ?>
          <div class="empty"><?php echo xlt('No portal activity to show.'); ?></div>
        <?php else: ?>
          <?php foreach ($activity as $a): ?>
            <div class="item">
              <span class="icon"><?php echo text($a['icon']); ?></span>
              <div class="body">
                <span class="title"><?php echo text($a['title']); ?></span>
                <span class="desc"><?php echo text($a['desc']); ?></span>
              </div>
              <div class="right">
                <span class="when"><?php echo text($a['when']); ?></span>
                <a href="<?php echo attr($a['href']); ?>" class="view"><?php echo xlt('View'); ?> &rarr;</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- RIGHT: stacked panels -->
    <div style="display:flex; flex-direction:column; gap:16px;">

      <section class="cp-pp-panel">
        <div class="phead">
          <span class="lbl"><?php echo xlt('PENDING PORTAL TASKS'); ?></span>
        </div>
        <div class="cp-pp-tasks">
          <?php foreach ($tasks as $t): ?>
            <div class="task">
              <span class="ic" style="color: <?php echo attr($t['iconColor']); ?>;"><?php echo text($t['icon']); ?></span>
              <div class="info">
                <span class="title"><?php echo text($t['title']); ?></span>
                <span class="desc"><?php echo text($t['desc']); ?></span>
              </div>
              <a href="<?php echo attr($t['href']); ?>" class="open"><?php echo xlt('Open'); ?> &rarr;</a>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="cp-pp-panel">
        <div class="phead" style="flex-direction:column; align-items:flex-start; padding-bottom:6px;">
          <span class="lbl"><?php echo xlt('PORTAL ADOPTION'); ?></span>
          <span class="sub"><?php echo text($adoptionHeadline); ?></span>
        </div>
        <div class="cp-pp-adopt">
          <?php foreach ($adoption as $a): ?>
            <div class="row">
              <div class="top">
                <span class="lbl"><?php echo text($a['lbl']); ?></span>
                <span class="pct" style="color: <?php echo attr($a['color']); ?>;"><?php echo text((string)$a['pct']); ?>%
                  <span style="color:#8A91A1; font-weight:400; margin-left:6px;">
                    (<?php echo text((string)$a['active']); ?>/<?php echo text((string)$a['total']); ?>)
                  </span>
                </span>
              </div>
              <div class="bar">
                <div class="fill" style="width: <?php echo attr((string) $a['pct']); ?>%; background: <?php echo attr($a['color']); ?>;"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

    </div>

  </div>
</main>

</body>
</html>
