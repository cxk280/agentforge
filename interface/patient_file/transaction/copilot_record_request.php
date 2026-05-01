<?php

/**
 * Patient Record Request — Screen 31.
 * Outbound records request form + sent-queue status.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$queue = [
    ['Riverside Medical Center',    'Lab Results, Imaging',     '2026-04-20', 'Pending',    'warn'],
    ['Dr. Nguyen — Cardiology',     'Progress Notes (2025)',    '2026-04-08', 'Delivered',  'good'],
    ['BlueCross PPO Auth Dept',     'Surgical Reports',         '2026-03-15', 'Delivered',  'good'],
    ['State Health Dept',           'Immunization Records',     '2026-03-01', 'Failed',     'danger'],
    ['General Hospital Records',    'Discharge Summary 2025',   '2026-02-14', 'Delivered',  'good'],
];

$record_types = ['Progress Notes', 'Lab Results', 'Imaging', 'Surgical Reports', 'Discharge Summary'];
$rt_checked   = [true, true, false, false, false];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Record Request'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-rr-body { display: grid; grid-template-columns: 480px 1fr; gap: 16px; padding: 20px 24px 32px; }
  .cp-chk { display: inline-flex; align-items: center; gap: 8px; font-size: 12px; }
  .cp-chk-box { width: 16px; height: 16px; border: 1px solid #C7CBD2; border-radius: 4px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 10px; color: #FFFFFF; background: #FFFFFF; }
  .cp-chk-box.on { background: #008C8C; border-color: #008C8C; }
  .cp-rr-q-row {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px;
    border: 1px solid #E4E5E8; border-radius: 10px; background: #FFFFFF;
    margin-bottom: 8px;
  }
  .cp-rr-q-row .info { flex: 1; min-width: 0; }
  .cp-rr-q-row .dest { font-weight: 600; color: #0D1B2A; }
  .cp-rr-q-row .types { font-size: 11px; color: #4F5763; }
  .cp-rr-q-row .sent { font-size: 11px; color: #8A91A1; margin-top: 2px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Record Request'); ?></span>
    <span class="meta"><?php echo xlt('Request medical records from external providers'); ?> • 5 <?php echo xlt('outbound'); ?></span>
  </div>
</header>

<div class="cp-rr-body">

  <section class="cp-panel">
    <div class="cp-panel-lbl"><?php echo xlt('NEW RECORD REQUEST'); ?></div>

    <div class="cp-row" style="grid-template-columns: 140px 1fr;">
      <label><?php echo xlt('Requesting facility'); ?></label>
      <input class="cp-input" type="text" value="General Hospital — Records Dept.">
    </div>
    <div class="cp-row" style="grid-template-columns: 140px 1fr;">
      <label><?php echo xlt('Contact / fax'); ?></label>
      <input class="cp-input" type="text" value="(312) 555-0182 — fax">
    </div>
    <div class="cp-row" style="grid-template-columns: 140px 1fr;">
      <label><?php echo xlt('Record types'); ?></label>
      <div style="display: flex; flex-wrap: wrap; gap: 12px;">
        <?php foreach ($record_types as $i => $rt): ?>
          <span class="cp-chk">
            <span class="cp-chk-box <?php echo $rt_checked[$i] ? 'on' : ''; ?>"><?php echo $rt_checked[$i] ? '✓' : ''; ?></span>
            <?php echo text($rt); ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="cp-row" style="grid-template-columns: 140px 1fr;">
      <label><?php echo xlt('Date range'); ?></label>
      <div style="display:flex; gap:8px;">
        <input class="cp-input" type="text" value="2025-01-01">
        <input class="cp-input" type="text" value="2026-05-01">
      </div>
    </div>
    <div class="cp-row" style="grid-template-columns: 140px 1fr;">
      <label><?php echo xlt('Purpose'); ?></label>
      <input class="cp-input" type="text" value="Continuing care — hypertension management">
    </div>

    <div style="background:#FFF8EC; border:1px solid #FACA7A; border-radius:8px; padding:10px 12px; font-size:12px; color:#8A4800; margin-top:12px;">
      📋 <?php echo xlt('Patient authorization on file — signed 2026-03-22.'); ?>
      <a href="#" style="color:#FA8C33; font-weight:600; text-decoration:none;">View →</a>
    </div>

    <button type="button" class="cp-btn primary" style="width:100%; justify-content:center; padding:10px; margin-top:14px;"><?php echo xlt('Send Record Request'); ?> →</button>
  </section>

  <section class="cp-panel">
    <div class="cp-panel-lbl"><?php echo xlt('OUTBOUND QUEUE'); ?></div>
    <?php foreach ($queue as [$dest, $types, $sent, $status, $tone]): ?>
      <div class="cp-rr-q-row">
        <div class="info">
          <div class="dest"><?php echo text($dest); ?></div>
          <div class="types"><?php echo text($types); ?></div>
          <div class="sent"><?php echo xlt('Sent'); ?> <?php echo text($sent); ?></div>
        </div>
        <span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span>
        <?php if ($status !== 'Delivered'): ?>
          <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo $status === 'Failed' ? xlt('Retry') : xlt('Cancel'); ?></button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

</div>

</body>
</html>
