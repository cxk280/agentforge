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

// Inbox data — mirrors the Figma mock exactly.
$messages = [
    [
        'sender'   => 'Dr. Sarah Chen',
        'time'     => '9:14 AM',
        'subject'  => 'Re: Margaret Chen lab results',
        'preview'  => "I've reviewed the A1C trend and would like to discuss…",
        'unread'   => true,
        'urgent'   => false,
        'selected' => true,
    ],
    [
        'sender'   => 'Lab — LabCorp',
        'time'     => '8:42 AM',
        'subject'  => 'Lab results available',
        'preview'  => 'Comprehensive metabolic panel and CBC for patient #004821…',
        'unread'   => true,
        'urgent'   => false,
        'selected' => false,
    ],
    [
        'sender'   => 'Pharmacy — CVS',
        'time'     => 'Yesterday',
        'subject'  => 'Refill request: Ted Shaw',
        'preview'  => 'Patient is requesting refill of Lisinopril 20mg, last filled…',
        'unread'   => true,
        'urgent'   => true,
        'selected' => false,
    ],
    [
        'sender'   => 'Patient Portal',
        'time'     => 'Yesterday',
        'subject'  => 'Question about medication',
        'preview'  => 'Hi Dr. Rivera, I noticed my new prescription bottle says…',
        'unread'   => false,
        'urgent'   => false,
        'selected' => false,
    ],
    [
        'sender'   => 'Front Desk',
        'time'     => 'Mon',
        'subject'  => 'Schedule update — Wed afternoon',
        'preview'  => 'Two slots opened up Wednesday afternoon, would you like…',
        'unread'   => false,
        'urgent'   => false,
        'selected' => false,
    ],
    [
        'sender'   => 'Dr. Patel',
        'time'     => 'Mon',
        'subject'  => 'Referral feedback for J. Wong',
        'preview'  => 'Saw your referral; orthopedic consult complete with…',
        'unread'   => false,
        'urgent'   => false,
        'selected' => false,
    ],
];

// Highlight rows in the message detail body (matches mock exactly)
$findings = [
    ['📈', 'A1C climbed from 7.2% to 7.9%',  'above her 7.0% target'],
    ['💊', 'Metformin still 1000 mg BID',    'may benefit from adding a GLP-1'],
    ['⚠',  'Microalbumin slightly elevated', 'monitor renal function'],
];

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
    <?php foreach ($messages as $m): ?>
      <div class="cp-msg-item<?php echo $m['selected'] ? ' selected' : ''; ?>">
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
      </div>
    <?php endforeach; ?>
  </aside>

  <section class="cp-msg-detail">
    <header class="cp-msg-detail-head">
      <h2 class="cp-msg-detail-subject"><?php echo xlt('Re: Margaret Chen lab results'); ?></h2>
      <div class="cp-msg-from">
        <div class="cp-msg-from-avatar" aria-hidden="true"></div>
        <div>
          <div class="cp-msg-from-name"><?php echo xlt('Dr. Sarah Chen — Endocrinology'); ?></div>
          <div class="cp-msg-from-meta"><?php echo xlt('To: Dr. E. Rivera • Today 9:14 AM'); ?></div>
        </div>
      </div>
    </header>

    <div class="cp-msg-detail-body">
      <p class="cp-msg-para"><?php echo xlt("Hi Eduardo,") . "\n\n" . xlt("I've reviewed Margaret Chen's recent lab results from 04/12/2026 and the trend over the past 18 months. A few observations:"); ?></p>

      <?php foreach ($findings as $f): ?>
        <div class="cp-msg-finding">
          <span class="cp-msg-finding-icon"><?php echo $f[0]; ?></span>
          <div>
            <div class="cp-msg-finding-title"><?php echo text($f[1]); ?></div>
            <div class="cp-msg-finding-detail"><?php echo text($f[2]); ?></div>
          </div>
        </div>
      <?php endforeach; ?>

      <p class="cp-msg-para"><?php echo xlt("I'm happy to discuss further or join the next visit if helpful. Let me know what works best.") . "\n\n" . xlt("— Sarah"); ?></p>
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
