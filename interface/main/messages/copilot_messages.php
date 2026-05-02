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

// Inbox data — mirrors the Figma mock exactly. Each entry now also
// carries the detail-pane content so clicking a row in the inbox
// reloads the page with ?selected=N and the right detail renders.
$messages = [
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

// Pick the active message from ?selected=N (default first).
$selectedIdx = (int)($_GET['selected'] ?? 0);
if ($selectedIdx < 0 || $selectedIdx >= count($messages)) {
    $selectedIdx = 0;
}
foreach ($messages as $i => $_) {
    $messages[$i]['selected'] = ($i === $selectedIdx);
}
$selectedMsg = $messages[$selectedIdx];
$findings = $selectedMsg['findings'];

$filterTabs = ['All' => true, 'Inbox' => false, 'Sent' => false, 'Recalls' => false];
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
    <span class="cp-msg-count"><?php echo xlt('12 new'); ?></span>
  </div>
  <div class="cp-msg-spacer"></div>
  <div class="cp-msg-filter" role="tablist">
    <?php foreach ($filterTabs as $label => $active): ?>
      <button class="cp-msg-filter-item<?php echo $active ? ' active' : ''; ?>" type="button" role="tab"<?php if ($active) {
          echo ' aria-selected="true"';
      } ?>>
        <?php echo text($label); ?>
      </button>
    <?php endforeach; ?>
  </div>
  <button class="cp-msg-compose" type="button">
    <span class="cp-msg-compose-icon">✎</span>
    <span><?php echo xlt('Compose'); ?></span>
  </button>
</header>

<div class="cp-msg-panes">

  <aside class="cp-msg-inbox">
    <?php foreach ($messages as $i => $m): ?>
      <a class="cp-msg-item<?php echo $m['selected'] ? ' selected' : ''; ?>"
         href="?selected=<?php echo attr((string)$i); ?>"
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

  <section class="cp-msg-detail">
    <header class="cp-msg-detail-head">
      <h2 class="cp-msg-detail-subject"><?php echo text($selectedMsg['subject']); ?></h2>
      <div class="cp-msg-from">
        <div class="cp-msg-from-avatar" aria-hidden="true"></div>
        <div>
          <div class="cp-msg-from-name"><?php echo text($selectedMsg['sender_full']); ?></div>
          <div class="cp-msg-from-meta"><?php echo xlt('To'); ?>: <?php echo text($selectedMsg['to']); ?> • <?php echo text($selectedMsg['date_label']); ?></div>
        </div>
      </div>
    </header>

    <div class="cp-msg-detail-body">
      <p class="cp-msg-para" style="white-space:pre-line;"><?php echo text($selectedMsg['lead']); ?></p>

      <?php foreach ($findings as $f): ?>
        <div class="cp-msg-finding">
          <span class="cp-msg-finding-icon"><?php echo $f[0]; ?></span>
          <div>
            <div class="cp-msg-finding-title"><?php echo text($f[1]); ?></div>
            <div class="cp-msg-finding-detail"><?php echo text($f[2]); ?></div>
          </div>
        </div>
      <?php endforeach; ?>

      <p class="cp-msg-para" style="white-space:pre-line;"><?php echo text($selectedMsg['closer']); ?></p>
    </div>

    <div class="cp-msg-detail-actions">
      <button class="cp-msg-btn cp-msg-btn--primary" type="button">
        <span><?php echo xlt('↩ Reply'); ?></span>
      </button>
      <button class="cp-msg-btn" type="button">
        <span><?php echo xlt('↪ Forward'); ?></span>
      </button>
      <button class="cp-msg-btn cp-msg-btn--muted" type="button">
        <span><?php echo xlt('Archive'); ?></span>
      </button>
      <div class="cp-msg-actions-spacer"></div>
      <a class="cp-msg-link-chart" href="#"><?php echo xlt('Open in patient chart →'); ?></a>
    </div>
  </section>

</div>

</body>
</html>
