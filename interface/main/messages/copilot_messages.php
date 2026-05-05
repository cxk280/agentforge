<?php

/**
 * Messages landing page — implements Screen 7 of the AgentForge mockups.
 *
 * Renders a two-pane inbox + message detail, mock-faithful to Figma
 * Screen 7. Real messaging functionality remains at
 * messages.original.php.bak (the original messages.php).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

if (!AclMain::aclCheckCore('patients', 'notes')) {
    http_response_code(403);
    echo xlt("Not authorized");
    exit;
}

// ─── Active user ────────────────────────────────────────────────────────
$activeUserId = (int)($_SESSION['authUserID'] ?? 0);
$activeUsername = '';
$activeUserDisplay = '';
if ($activeUserId) {
    $r = sqlQuery(
        "SELECT username, fname, lname FROM users WHERE id = ?",
        [$activeUserId]
    );
    if ($r) {
        $activeUsername = (string)($r['username'] ?? '');
        $activeUserDisplay = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''))
            ?: $activeUsername;
    }
}
if ($activeUsername === '') {
    http_response_code(401);
    echo xlt('No active session user');
    exit;
}

// ─── Tab + selection state ──────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'inbox';
if (!in_array($tab, ['inbox', 'sent', 'archived', 'all'], true)) {
    $tab = 'inbox';
}
$selectedEid = (int)($_GET['selected'] ?? 0);

// Map tab → WHERE clause. Pnotes columns:
//   pnotes.user        = sender username
//   pnotes.assigned_to = recipient username
//   pnotes.activity    = 1 active, 0 archived
//   pnotes.deleted     = 1 hard-deleted (soft-delete flag, never shown)
$whereByTab = [
    'inbox'    => "p.assigned_to = ? AND p.deleted = 0 AND p.activity = 1",
    'sent'     => "p.user        = ? AND p.deleted = 0",
    'archived' => "p.assigned_to = ? AND p.deleted = 0 AND p.activity = 0",
    'all'      => "(p.assigned_to = ? OR p.user = ?) AND p.deleted = 0",
];
$whereSql = $whereByTab[$tab];
$whereParams = ($tab === 'all')
    ? [$activeUsername, $activeUsername]
    : [$activeUsername];

// ─── Pull messages ──────────────────────────────────────────────────────
//
// Joining users twice — once for the sender display name, once for
// recipient — keeps "Dr. Sarah Chen" rendering even when the canonical
// pnotes row only carries the username. Patient join is optional;
// non-patient messages have pid IS NULL or pid = 0.
$rs = sqlStatement(
    "SELECT p.id, p.date, p.body, p.title, p.user AS sender_un, p.assigned_to AS to_un,
            p.activity, p.message_status, p.pid,
            u_from.fname AS from_fname, u_from.lname AS from_lname, u_from.title AS from_title,
            u_to.fname   AS to_fname,   u_to.lname   AS to_lname,
            pat.fname    AS pat_fname,  pat.lname    AS pat_lname
       FROM pnotes p
  LEFT JOIN users u_from ON u_from.username = p.user
  LEFT JOIN users u_to   ON u_to.username   = p.assigned_to
  LEFT JOIN patient_data pat ON (pat.pid IS NOT NULL AND p.pid IS NOT NULL
                                  AND p.pid != 0 AND pat.pid = p.pid)
      WHERE $whereSql
      ORDER BY p.date DESC, p.id DESC
      LIMIT 100",
    $whereParams
);

$messages = [];
$now = time();
while ($r = sqlFetchArray($rs)) {
    $eid = (int)$r['id'];
    $senderName = trim(($r['from_fname'] ?? '') . ' ' . ($r['from_lname'] ?? ''));
    if ($senderName === '') {
        $senderName = (string)($r['sender_un'] ?? 'Unknown');
    }
    if (!empty($r['from_title'])) {
        $senderFull = $senderName . ' — ' . $r['from_title'];
    } else {
        $senderFull = $senderName;
    }
    $toName = trim(($r['to_fname'] ?? '') . ' ' . ($r['to_lname'] ?? ''));
    if ($toName === '') {
        $toName = (string)($r['to_un'] ?? '');
    }

    // Friendly relative time. "Today 9:14 AM", "Yesterday", "Mon", or
    // a YYYY-MM-DD when more than a week old.
    $dateStr = (string)($r['date'] ?? '');
    $ts = $dateStr ? strtotime($dateStr) : 0;
    $time = '';
    $dateLabel = $dateStr;
    if ($ts > 0) {
        $delta = $now - $ts;
        $hms = date('g:i A', $ts);
        if (date('Y-m-d', $ts) === date('Y-m-d', $now)) {
            $time = $hms;
            $dateLabel = 'Today ' . $hms;
        } elseif (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day', $now))) {
            $time = 'Yesterday';
            $dateLabel = 'Yesterday ' . $hms;
        } elseif ($delta < 7 * 86400) {
            $time = date('D', $ts);
            $dateLabel = date('D ', $ts) . $hms;
        } else {
            $time = date('M j', $ts);
            $dateLabel = date('M j Y, ', $ts) . $hms;
        }
    }

    $title = (string)($r['title'] ?? '');
    $body = (string)($r['body'] ?? '');
    $patName = trim(($r['pat_fname'] ?? '') . ' ' . ($r['pat_lname'] ?? ''));

    $isUrgent = (stripos($title, 'URGENT') !== false)
                || (strtolower((string)$r['message_status']) === 'high');
    $isUnread = (strtolower((string)$r['message_status']) !== 'read')
                && ($r['sender_un'] !== $activeUsername);

    $messages[] = [
        'eid'         => $eid,
        'sender'      => $senderName,
        'sender_full' => $senderFull,
        'sender_un'   => (string)$r['sender_un'],
        'to_un'       => (string)$r['to_un'],
        'time'        => $time,
        'date_label'  => $dateLabel,
        'to'          => $toName,
        'subject'     => $title ?: '(no subject)',
        'preview'     => trim(preg_replace('/\s+/', ' ', mb_substr($body, 0, 110)))
                         . (mb_strlen($body) > 110 ? '…' : ''),
        'unread'      => $isUnread,
        'urgent'      => $isUrgent,
        'body'        => $body,
        'patient_id'  => (int)($r['pid'] ?? 0),
        'patient_name' => $patName,
        'archived'    => ((int)$r['activity'] === 0),
    ];
}

// Pick the active message from ?selected=<eid>; fall back to first.
$selectedIdx = 0;
if ($selectedEid > 0) {
    foreach ($messages as $i => $m) {
        if ($m['eid'] === $selectedEid) {
            $selectedIdx = $i;
            break;
        }
    }
}
if ($selectedIdx >= count($messages)) {
    $selectedIdx = 0;
}

// Mark the selected message as read on display (only when it's an
// inbox message addressed to me — sent messages don't get "read"
// flipped). Idempotent so refresh doesn't churn the row.
if (!empty($messages) && $messages[$selectedIdx]['to_un'] === $activeUsername
    && $messages[$selectedIdx]['unread']) {
    sqlStatement(
        "UPDATE pnotes SET message_status = 'Read' WHERE id = ?",
        [$messages[$selectedIdx]['eid']]
    );
    $messages[$selectedIdx]['unread'] = false;
}

$selectedMsg = $messages[$selectedIdx] ?? null;

// ─── Counts for badge + tab labels ──────────────────────────────────────
$unreadCount = (int)(sqlQuery(
    "SELECT COUNT(*) AS n FROM pnotes
      WHERE assigned_to = ? AND deleted = 0 AND activity = 1
        AND (message_status IS NULL OR message_status != 'Read')",
    [$activeUsername]
)['n'] ?? 0);

$mockMessages = [
    [
        'sender'   => 'Dr. Sarah Chen',
        'sender_full' => 'Dr. Sarah Chen — Endocrinology',
        'time'     => '9:14 AM',
        'date_label' => 'Today 9:14 AM',
        'to'       => 'Dr. E. Rivera',
        'subject'  => 'Re: Margaret Chen lab results',
        'preview'  => "I've reviewed the A1C trend and would like to discuss…",
        'unread'   => true,
        'urgent'   => false,
        'lead'     => "Hi Eduardo,\n\nI've reviewed Margaret Chen's recent lab results from 04/12/2026 and the trend over the past 18 months. A few observations:",
        'findings' => [
            ['📈', 'A1C climbed from 7.2% to 7.9%',  'above her 7.0% target'],
            ['💊', 'Metformin still 1000 mg BID',    'may benefit from adding a GLP-1'],
            ['⚠',  'Microalbumin slightly elevated', 'monitor renal function'],
        ],
        'closer'   => "I'm happy to discuss further or join the next visit if helpful. Let me know what works best.\n\n— Sarah",
    ],
    [
        'sender'   => 'Lab — LabCorp',
        'sender_full' => 'LabCorp Houston Lab',
        'time'     => '8:42 AM',
        'date_label' => 'Today 8:42 AM',
        'to'       => 'Dr. E. Rivera',
        'subject'  => 'Lab results available',
        'preview'  => 'Comprehensive metabolic panel and CBC for patient #004821…',
        'unread'   => true,
        'urgent'   => false,
        'lead'     => "Dr. Rivera,\n\nLab results are available for the following order set on patient #004821 (Margaret Chen). Drawn 04/12/2026, processed at our Houston facility.",
        'findings' => [
            ['🧪', 'CMP — within range',        'glucose 142 mg/dL flagged high (fasting)'],
            ['🩸', 'CBC — within range',        'Hgb 13.4 g/dL, WBC 7.2'],
            ['📎', 'Microalbumin — elevated',  '32 mg/g (ref < 30 mg/g) — repeat in 90 days'],
        ],
        'closer'   => "Full report attached to the patient chart under Documents → Lab Reports.\n\n— LabCorp Reporting",
    ],
    [
        'sender'   => 'Pharmacy — CVS',
        'sender_full' => 'CVS Pharmacy #4521',
        'time'     => 'Yesterday',
        'date_label' => 'Yesterday 4:12 PM',
        'to'       => 'Dr. E. Rivera',
        'subject'  => 'Refill request: Ted Shaw',
        'preview'  => 'Patient is requesting refill of Lisinopril 20mg, last filled…',
        'unread'   => true,
        'urgent'   => true,
        'lead'     => "Refill request from Ted Shaw (DOB 1947-03-11). Patient is on the following Rx and is requesting one 90-day refill.",
        'findings' => [
            ['💊', 'Lisinopril 20 mg tablet, 1 daily',  'last filled 02/14/2026, 0 refills remaining'],
            ['📞', 'Patient called pharmacy 3:47 PM',     'reports BP at home running 132/84'],
            ['🪪', 'Insurance copay $4 (BCBS)',           'no PA required for refill'],
        ],
        'closer'   => "Please approve via e-Rx or reply with a written script. We can hold for pickup once authorized.\n\n— CVS #4521 (512-555-0142)",
    ],
    [
        'sender'   => 'Patient Portal',
        'sender_full' => 'Patient Portal — Margaret Chen',
        'time'     => 'Yesterday',
        'date_label' => 'Yesterday 11:08 AM',
        'to'       => 'Dr. E. Rivera',
        'subject'  => 'Question about medication',
        'preview'  => 'Hi Dr. Rivera, I noticed my new prescription bottle says…',
        'unread'   => false,
        'urgent'   => false,
        'lead'     => "Hi Dr. Rivera,\n\nI noticed my new prescription bottle says 1000 mg, but I thought we discussed lowering my dose. Can you confirm what I should be taking and whether the new strength is right?",
        'findings' => [
            ['💊', 'Patient: Margaret Chen',           'MRN #004821 • DOB 03/14/1958'],
            ['📋', 'Active Rx: Metformin 1000 mg BID', 'last refill 04/01/2026 (90 days)'],
            ['📅', 'Last visit: 02/18/2026',           'titration plan documented'],
        ],
        'closer'   => "I want to make sure I'm not making a mistake — should I keep taking the new bottle or wait?\n\nThank you,\nMargaret",
    ],
    [
        'sender'   => 'Front Desk',
        'sender_full' => 'Maria Gonzalez — Front Desk',
        'time'     => 'Mon',
        'date_label' => 'Mon 2:34 PM',
        'to'       => 'Dr. E. Rivera',
        'subject'  => 'Schedule update — Wed afternoon',
        'preview'  => 'Two slots opened up Wednesday afternoon, would you like…',
        'unread'   => false,
        'urgent'   => false,
        'lead'     => "Hi Dr. Rivera,\n\nTwo slots opened up Wednesday afternoon (1:30 PM and 2:00 PM). Would you like me to fill them from the wait list or hold them for same-day add-ons?",
        'findings' => [
            ['📅', 'Wed 1:30 PM open',          '30 min slot'],
            ['📅', 'Wed 2:00 PM open',          '30 min slot'],
            ['📋', '4 patients on the wait list',  'top: Helen Garcia, follow-up'],
        ],
        'closer'   => "Let me know how you'd like to handle these.\n\n— Maria",
    ],
    [
        'sender'   => 'Dr. Patel',
        'sender_full' => 'Dr. Rajesh Patel — Orthopedics',
        'time'     => 'Mon',
        'date_label' => 'Mon 10:21 AM',
        'to'       => 'Dr. E. Rivera',
        'subject'  => 'Referral feedback for J. Wong',
        'preview'  => 'Saw your referral; orthopedic consult complete with…',
        'unread'   => false,
        'urgent'   => false,
        'lead'     => "Eduardo,\n\nSaw your referral for James Wong (R knee pain). Consult complete; full report posted to the chart. Quick summary below.",
        'findings' => [
            ['🦴', 'MRI: small medial meniscus tear',  'no surgical indication at this time'],
            ['💊', 'Recommend NSAID + PT',              '6-week trial before re-evaluation'],
            ['📅', 'Follow-up scheduled',                '06/14/2026 in our clinic'],
        ],
        'closer'   => "Happy to discuss anytime if you want to talk through the imaging.\n\n— Raj",
    ],
];
// $mockMessages is unused now that the live query above feeds $messages.
// Kept for reference and so the empty-state can demonstrate what the
// inbox looks like with rich content if seed data isn't present.
unset($mockMessages);

// Tag selection on each row so the existing render block ($m['selected'])
// keeps working without changes.
foreach ($messages as $i => $_unused) {
    $messages[$i]['selected'] = ($i === $selectedIdx);
}

// Tab labels — drives the four pills in the header. Active tab matches
// the resolved $tab value above.
$filterTabs = [
    'inbox'    => 'Inbox',
    'sent'     => 'Sent',
    'archived' => 'Archived',
    'all'      => 'All',
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Messages'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F7F8;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
  }
  button { font-family: inherit; }

  /* ── Page header (full width) ─────────────────────────────────────────── */
  .cp-msg-header {
    flex: 0 0 auto;
    width: 100%;
    height: 64px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 16px;
  }
  .cp-msg-title-block {
    display: flex; align-items: center; gap: 12px;
  }
  .cp-msg-title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-msg-count {
    background: #008C8C;
    color: #FFFFFF;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 11px; font-weight: 600;
    line-height: 1.4;
  }
  .cp-msg-spacer { flex: 1; }

  .cp-msg-filter {
    display: inline-flex;
    background: #F5F7F8;
    border-radius: 999px;
    padding: 4px;
  }
  .cp-msg-filter-item {
    padding: 4px 14px;
    border-radius: 999px;
    font-size: 12px; font-weight: 500;
    color: #4F5662;
    line-height: 1.2;
    background: transparent;
    border: none;
    cursor: pointer;
  }
  .cp-msg-filter-item.active {
    background: #FFFFFF;
    color: #008C8C;
    box-shadow: 0 1px 2px rgba(13, 27, 42, 0.05);
  }

  .cp-msg-compose {
    display: inline-flex; align-items: center; gap: 6px;
    background: #008C8C;
    color: #FFFFFF;
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 13px; font-weight: 600;
    border: none;
    cursor: pointer;
    line-height: 1;
  }
  .cp-msg-compose:hover { background: #006F6F; }
  .cp-msg-compose-icon { font-size: 14px; line-height: 1; }

  /* ── Two-pane layout ──────────────────────────────────────────────────── */
  .cp-msg-panes {
    flex: 1 1 auto;
    display: flex;
    background: #FFFFFF;
    overflow: hidden;
    min-height: 0;
  }

  /* Inbox column */
  .cp-msg-inbox {
    flex: 0 0 420px;
    width: 420px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    overflow-y: auto;
  }
  .cp-msg-item {
    position: relative;
    padding: 14px 18px;
    border-bottom: 1px solid #F0F1F3;
    background: #FFFFFF;
    cursor: pointer;
  }
  .cp-msg-item.selected {
    background: #F2F8F8;
  }
  .cp-msg-item.selected::before {
    content: '';
    position: absolute;
    top: 0; bottom: 0; left: 0;
    width: 3px;
    background: #008C8C;
  }
  .cp-msg-item-top {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .cp-msg-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: #008C8C;
    flex: 0 0 auto;
  }
  .cp-msg-sender {
    font-size: 13px; font-weight: 500; color: #0D1B2A;
    line-height: 1.2;
  }
  .cp-msg-sender.unread { font-weight: 600; }
  .cp-msg-time {
    margin-left: auto;
    font-size: 11px; color: #8A91A0;
    flex: 0 0 auto;
  }
  .cp-msg-subject {
    margin-top: 4px;
    font-size: 13px; font-weight: 500; color: #0D1B2A;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .cp-msg-subject.unread { font-weight: 600; }
  .cp-msg-preview {
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .cp-msg-preview-text {
    font-size: 12px; color: #8A91A0;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .cp-msg-urgent {
    background: rgba(217, 56, 56, 0.15);
    color: #D93838;
    font-size: 9px; font-weight: 700;
    letter-spacing: 0.4px;
    padding: 1px 6px;
    border-radius: 4px;
    flex: 0 0 auto;
    line-height: 1.4;
  }

  /* Detail column */
  .cp-msg-detail {
    flex: 1 1 auto;
    background: #FFFFFF;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-width: 0;
  }
  .cp-msg-detail-head {
    flex: 0 0 auto;
    padding: 22px 28px;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-msg-detail-subject {
    font-size: 18px; font-weight: 700; color: #0D1B2A;
    margin: 0 0 14px;
    line-height: 1.2;
  }
  .cp-msg-from {
    display: flex; align-items: center; gap: 12px;
  }
  .cp-msg-from-avatar {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: #5FD0D0;
    flex: 0 0 auto;
  }
  .cp-msg-from-name { font-size: 13px; font-weight: 500; color: #0D1B2A; line-height: 1.2; }
  .cp-msg-from-meta { font-size: 11px; color: #8A91A0; margin-top: 1px; line-height: 1.2; }

  .cp-msg-detail-body {
    flex: 1 1 auto;
    padding: 28px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .cp-msg-para {
    font-size: 14px; color: #0D1B2A;
    line-height: 1.55;
    margin: 0;
    white-space: pre-line;
  }
  .cp-msg-finding {
    background: #F5F7F8;
    border-radius: 8px;
    padding: 10px 14px;
    display: inline-flex;
    align-items: center;
    gap: 12px;
    width: fit-content;
    max-width: 100%;
  }
  .cp-msg-finding-icon { font-size: 18px; line-height: 1; }
  .cp-msg-finding-title {
    font-size: 13px; font-weight: 600; color: #0D1B2A;
    line-height: 1.3;
  }
  .cp-msg-finding-detail {
    font-size: 12px; color: #8A91A0;
    line-height: 1.3;
    margin-top: 1px;
  }

  .cp-msg-detail-actions {
    flex: 0 0 auto;
    height: 72px;
    padding: 0 28px;
    border-top: 1px solid #E4E5E8;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .cp-msg-btn {
    display: inline-flex; align-items: center; gap: 6px;
    border-radius: 999px;
    padding: 9px 18px;
    font-size: 13px; font-weight: 500;
    line-height: 1;
    cursor: pointer;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    color: #0D1B2A;
  }
  .cp-msg-btn--primary {
    background: #008C8C;
    border-color: #008C8C;
    color: #FFFFFF;
    font-weight: 600;
  }
  .cp-msg-btn--primary:hover { background: #006F6F; border-color: #006F6F; }
  .cp-msg-btn--muted { color: #4F5662; }
  .cp-msg-actions-spacer { flex: 1; }
  .cp-msg-link-chart {
    font-size: 12px; font-weight: 500; color: #008C8C;
    text-decoration: none;
  }
  .cp-msg-link-chart:hover { text-decoration: underline; }
</style>
</head>
<body>

<header class="cp-msg-header">
  <div class="cp-msg-title-block">
    <div class="cp-msg-title"><?php echo xlt('Messages'); ?></div>
    <?php if ($unreadCount > 0): ?>
      <span class="cp-msg-count"><?php echo (int)$unreadCount; ?> <?php echo xlt('new'); ?></span>
    <?php endif; ?>
  </div>
  <div class="cp-msg-spacer"></div>
  <div class="cp-msg-filter" role="tablist">
    <?php foreach ($filterTabs as $tabKey => $label):
      $isActive = ($tabKey === $tab);
    ?>
      <a href="?tab=<?php echo urlencode($tabKey); ?>"
         class="cp-msg-filter-item<?php echo $isActive ? ' active' : ''; ?>"
         role="tab"<?php echo $isActive ? ' aria-selected="true"' : ''; ?>
         style="text-decoration:none">
        <?php echo text($label); ?>
      </a>
    <?php endforeach; ?>
  </div>
  <button class="cp-msg-compose" type="button" id="cp-msg-compose-btn">
    <span class="cp-msg-compose-icon">✎</span>
    <span><?php echo xlt('Compose'); ?></span>
  </button>
</header>

<div class="cp-msg-panes">

  <aside class="cp-msg-inbox">
    <?php if (empty($messages)): ?>
      <div style="padding:32px 16px;text-align:center;color:#8A91A0;font-size:13px">
        <?php echo xlt('No messages in this view.'); ?><br>
        <span style="font-size:11px;color:#AAB1BD">
          <?php echo text("Logged in as " . $activeUserDisplay . " (" . $activeUsername . ")"); ?>
        </span>
      </div>
    <?php endif; ?>
    <?php foreach ($messages as $i => $m): ?>
      <a class="cp-msg-item<?php echo $m['selected'] ? ' selected' : ''; ?>"
         href="?tab=<?php echo urlencode($tab); ?>&selected=<?php echo (int)$m['eid']; ?>"
         style="text-decoration:none; color:inherit; display:block;">
        <div class="cp-msg-item-top">
          <?php if ($m['unread']): ?>
            <span class="cp-msg-dot"></span>
          <?php endif; ?>
          <span class="cp-msg-sender<?php echo $m['unread'] ? ' unread' : ''; ?>"><?php echo text($m['sender']); ?></span>
          <span class="cp-msg-time"><?php echo text($m['time']); ?></span>
        </div>
        <div class="cp-msg-subject<?php echo $m['unread'] ? ' unread' : ''; ?>"><?php echo text($m['subject']); ?></div>
        <div class="cp-msg-preview">
          <?php if ($m['urgent']): ?>
            <span class="cp-msg-urgent"><?php echo xlt('URGENT'); ?></span>
          <?php endif; ?>
          <span class="cp-msg-preview-text"><?php echo text($m['preview']); ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </aside>

  <?php if ($selectedMsg !== null): ?>
  <section class="cp-msg-detail">
    <header class="cp-msg-detail-head">
      <h2 class="cp-msg-detail-subject"><?php echo text($selectedMsg['subject']); ?></h2>
      <div class="cp-msg-from">
        <div class="cp-msg-from-avatar" aria-hidden="true"></div>
        <div>
          <div class="cp-msg-from-name"><?php echo text($selectedMsg['sender_full']); ?></div>
          <div class="cp-msg-from-meta">
            <?php echo xlt('To'); ?>: <?php echo text($selectedMsg['to']); ?>
            • <?php echo text($selectedMsg['date_label']); ?>
            <?php if (!empty($selectedMsg['patient_name'])): ?>
              • <span style="color:#1f3a68;font-weight:600"><?php echo text($selectedMsg['patient_name']); ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </header>

    <div class="cp-msg-detail-body">
      <p class="cp-msg-para" style="white-space:pre-line;"><?php echo text($selectedMsg['body']); ?></p>
    </div>

    <div class="cp-msg-detail-actions">
      <button class="cp-msg-btn cp-msg-btn--primary" type="button"
              id="cp-msg-reply-btn"
              data-eid="<?php echo (int)$selectedMsg['eid']; ?>"
              data-to-un="<?php echo attr($selectedMsg['sender_un']); ?>"
              data-subject="Re: <?php echo attr($selectedMsg['subject']); ?>">
        <span><?php echo xlt('↩ Reply'); ?></span>
      </button>
      <?php if ($tab !== 'archived'): ?>
        <button class="cp-msg-btn cp-msg-btn--muted" type="button"
                id="cp-msg-archive-btn"
                data-eid="<?php echo (int)$selectedMsg['eid']; ?>">
          <span><?php echo xlt('Archive'); ?></span>
        </button>
      <?php else: ?>
        <button class="cp-msg-btn" type="button"
                id="cp-msg-restore-btn"
                data-eid="<?php echo (int)$selectedMsg['eid']; ?>">
          <span><?php echo xlt('Restore'); ?></span>
        </button>
      <?php endif; ?>
      <button class="cp-msg-btn cp-msg-btn--muted" type="button"
              id="cp-msg-delete-btn"
              data-eid="<?php echo (int)$selectedMsg['eid']; ?>"
              style="color:#B7432A">
        <span><?php echo xlt('Delete'); ?></span>
      </button>
      <div class="cp-msg-actions-spacer"></div>
      <?php if ($selectedMsg['patient_id'] > 0): ?>
        <a class="cp-msg-link-chart"
           href="/interface/patient_file/summary/demographics.php?set_pid=<?php echo (int)$selectedMsg['patient_id']; ?>">
          <?php echo xlt('Open in patient chart →'); ?>
        </a>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

</div>

<!-- Compose / reply modal -->
<div id="cp-msg-modal-bg"
     style="position:fixed;inset:0;background:rgba(13,27,42,.35);display:none;
            align-items:center;justify-content:center;z-index:60">
  <form id="cp-msg-modal" autocomplete="off"
        style="background:#FFFFFF;border-radius:14px;width:520px;max-width:92vw;
               box-shadow:0 16px 48px rgba(13,27,42,.25);padding:22px 24px">
    <h2 id="cp-msg-modal-title" style="margin:0 0 12px;font-size:17px;font-weight:700">
      <?php echo xlt('Compose message'); ?>
    </h2>
    <div id="cp-msg-modal-error"
         style="display:none;background:#FBEFEB;border:1px solid #ECC8BE;color:#B7432A;
                padding:8px 10px;border-radius:8px;font-size:12px;margin:0 0 12px"></div>
    <div style="margin-bottom:12px">
      <label style="font-size:11px;font-weight:600;color:#4F5662;text-transform:uppercase">
        <?php echo xlt('To'); ?>
      </label>
      <select id="cp-msg-to" required
              style="width:100%;border:1px solid #E4E5E8;border-radius:8px;
                     padding:8px 10px;font-size:13px;font-family:inherit;margin-top:4px">
        <option value="">— <?php echo xlt('Select recipient'); ?> —</option>
        <?php
        $u = sqlStatement(
            "SELECT username, fname, lname, title FROM users
              WHERE active = 1 AND username IS NOT NULL AND username != ''
                AND username != ?
              ORDER BY lname, fname",
            [$activeUsername]
        );
        while ($ur = sqlFetchArray($u)):
            $disp = trim(($ur['fname'] ?? '') . ' ' . ($ur['lname'] ?? ''));
            if ($disp === '') {
                $disp = $ur['username'];
            }
            if (!empty($ur['title'])) {
                $disp .= ' (' . $ur['title'] . ')';
            }
        ?>
          <option value="<?php echo attr($ur['username']); ?>"><?php echo text($disp); ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div style="margin-bottom:12px">
      <label style="font-size:11px;font-weight:600;color:#4F5662;text-transform:uppercase">
        <?php echo xlt('Subject'); ?>
      </label>
      <input id="cp-msg-subject" type="text" required maxlength="200"
             style="width:100%;border:1px solid #E4E5E8;border-radius:8px;
                    padding:8px 10px;font-size:13px;font-family:inherit;margin-top:4px">
    </div>
    <div style="margin-bottom:12px">
      <label style="font-size:11px;font-weight:600;color:#4F5662;text-transform:uppercase">
        <?php echo xlt('Patient (optional PID)'); ?>
      </label>
      <input id="cp-msg-pid" type="number" min="0"
             style="width:100%;border:1px solid #E4E5E8;border-radius:8px;
                    padding:8px 10px;font-size:13px;font-family:inherit;margin-top:4px">
    </div>
    <div style="margin-bottom:14px">
      <label style="font-size:11px;font-weight:600;color:#4F5662;text-transform:uppercase">
        <?php echo xlt('Message'); ?>
      </label>
      <textarea id="cp-msg-body" required rows="6" maxlength="6000"
                style="width:100%;border:1px solid #E4E5E8;border-radius:8px;
                       padding:8px 10px;font-size:13px;font-family:inherit;margin-top:4px;
                       resize:vertical"></textarea>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="flex:1"></span>
      <button type="button" id="cp-msg-cancel"
              style="border:1px solid #E4E5E8;background:#FFFFFF;color:#0D1B2A;
                     border-radius:999px;padding:8px 16px;font-size:13px;cursor:pointer">
        <?php echo xlt('Cancel'); ?>
      </button>
      <button type="submit"
              style="background:#008C8C;color:#FFFFFF;border:none;
                     border-radius:999px;padding:8px 16px;font-size:13px;font-weight:600;
                     cursor:pointer">
        <?php echo xlt('Send'); ?>
      </button>
    </div>
  </form>
</div>

<script>
(function () {
  const API = './copilot_messages_api.php';
  const CSRF = <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>;
  const ACTIVE_USERNAME = <?php echo json_encode($activeUsername); ?>;
  const modalBg = document.getElementById('cp-msg-modal-bg');
  const form    = document.getElementById('cp-msg-modal');
  const titleEl = document.getElementById('cp-msg-modal-title');
  const toIn    = document.getElementById('cp-msg-to');
  const subIn   = document.getElementById('cp-msg-subject');
  const pidIn   = document.getElementById('cp-msg-pid');
  const bodyIn  = document.getElementById('cp-msg-body');
  const errEl   = document.getElementById('cp-msg-modal-error');

  function showError(msg) {
    errEl.textContent = msg; errEl.style.display = 'block';
  }
  function clearError() {
    errEl.textContent = ''; errEl.style.display = 'none';
  }

  function openCompose({ replyTo = null, replySubject = null, replyBody = null } = {}) {
    clearError();
    titleEl.textContent = replyTo ? 'Reply' : 'Compose message';
    toIn.value     = replyTo || '';
    subIn.value    = replySubject || '';
    pidIn.value    = '';
    bodyIn.value   = replyBody || '';
    modalBg.style.display = 'flex';
    setTimeout(() => (replyTo ? bodyIn : toIn).focus(), 50);
  }
  function closeCompose() { modalBg.style.display = 'none'; }

  async function callApi(payload) {
    const fd = new FormData();
    Object.entries(payload).forEach(([k, v]) => fd.append(k, v ?? ''));
    fd.set('csrf_token_form', CSRF);
    const resp = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
    let body;
    try { body = await resp.json(); } catch { body = { ok: false, error: 'Bad JSON' }; }
    if (!resp.ok || !body.ok) throw new Error(body.error || ('HTTP ' + resp.status));
    return body;
  }

  document.getElementById('cp-msg-compose-btn').addEventListener('click', () => openCompose());
  document.getElementById('cp-msg-cancel').addEventListener('click', closeCompose);
  modalBg.addEventListener('click', evt => { if (evt.target === modalBg) closeCompose(); });
  document.addEventListener('keydown', evt => {
    if (evt.key === 'Escape' && modalBg.style.display === 'flex') closeCompose();
  });

  const replyBtn = document.getElementById('cp-msg-reply-btn');
  if (replyBtn) {
    replyBtn.addEventListener('click', () => {
      const toUn = replyBtn.dataset.toUn;
      // Don't reply to yourself (sent items have sender = self).
      if (toUn === ACTIVE_USERNAME) {
        openCompose();
        return;
      }
      openCompose({
        replyTo:      toUn,
        replySubject: replyBtn.dataset.subject,
      });
    });
  }
  const archiveBtn = document.getElementById('cp-msg-archive-btn');
  if (archiveBtn) {
    archiveBtn.addEventListener('click', async () => {
      try {
        await callApi({ action: 'archive', eid: archiveBtn.dataset.eid });
        window.location.search = '?tab=<?php echo urlencode($tab); ?>';
      } catch (err) { alert('Archive failed: ' + err.message); }
    });
  }
  const restoreBtn = document.getElementById('cp-msg-restore-btn');
  if (restoreBtn) {
    restoreBtn.addEventListener('click', async () => {
      try {
        await callApi({ action: 'restore', eid: restoreBtn.dataset.eid });
        window.location.search = '?tab=<?php echo urlencode($tab); ?>';
      } catch (err) { alert('Restore failed: ' + err.message); }
    });
  }
  const deleteBtn = document.getElementById('cp-msg-delete-btn');
  if (deleteBtn) {
    deleteBtn.addEventListener('click', async () => {
      if (!confirm('Delete this message? Cannot be undone.')) return;
      try {
        await callApi({ action: 'delete', eid: deleteBtn.dataset.eid });
        window.location.search = '?tab=<?php echo urlencode($tab); ?>';
      } catch (err) { alert('Delete failed: ' + err.message); }
    });
  }

  form.addEventListener('submit', async evt => {
    evt.preventDefault();
    clearError();
    if (!toIn.value || !subIn.value || !bodyIn.value) {
      showError('To, Subject, and Message are all required.');
      return;
    }
    try {
      await callApi({
        action: 'send',
        to_username: toIn.value,
        subject:     subIn.value,
        body:        bodyIn.value,
        patient_id:  pidIn.value,
      });
      closeCompose();
      // Land in Sent so the new message is visible.
      window.location.search = '?tab=sent';
    } catch (err) { showError(err.message || String(err)); }
  });
})();
</script>

</body>
</html>
