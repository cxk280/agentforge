<?php

/**
 * e-Rx EPCS — Screen 42.
 * Controlled-substance signing flow.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('e-Rx — EPCS'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-epcs-body { display: grid; grid-template-columns: 1fr 360px; gap: 16px; padding: 20px 24px 32px; }
  .cp-epcs-card {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 12px;
    padding: 18px 22px;
  }
  .cp-epcs-warn {
    background: #FCE7E7; border: 1px solid #F9A6A6; border-radius: 10px;
    padding: 12px 14px; color: #8B1818; font-size: 12px; line-height: 1.5;
    margin-bottom: 12px; font-weight: 500;
  }
  .cp-epcs-row { display: flex; justify-content: space-between;
    padding: 6px 0; font-size: 12px; border-top: 1px solid #F0F1F3; }
  .cp-epcs-row:first-of-type { border-top: 0; }
  .cp-epcs-row .l { color: #8A91A1; }
  .cp-epcs-row .v { color: #0D1B2A; font-weight: 500; }
  .cp-pin-wrap {
    background: #F5F6F7; border: 2px solid #008C8C; border-radius: 10px;
    padding: 14px; text-align: center;
  }
  .cp-pin-wrap input {
    width: 100%; max-width: 200px;
    text-align: center; font-size: 22px; letter-spacing: 8px;
    border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 12px; outline: none; font-family: ui-monospace, monospace;
  }
  .cp-pin-wrap .lbl { font-size: 11px; color: #008C8C; font-weight: 600; letter-spacing: 0.5px; margin-bottom: 8px; }
  .cp-token { background: #008C8C20; color: #008C8C; border-radius: 999px;
    padding: 4px 12px; font-size: 11px; font-weight: 600; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('e-Rx — EPCS Signing'); ?></span>
    <span class="meta"><?php echo xlt('Controlled substance — 2-factor required'); ?></span>
  </div>
  <span class="cp-token">🛡 EPCS active</span>
  <button type="button" class="cp-btn ghost" style="margin-left: 12px;"><?php echo xlt('Cancel'); ?></button>
</header>

<div class="cp-epcs-body">

  <section class="cp-epcs-card">
    <h2 style="margin:0 0 12px; font-size: 14px;"><?php echo xlt('Prescription review'); ?></h2>

    <div class="cp-epcs-warn">
      ⚠ <?php echo xlt('CONTROLLED SUBSTANCE'); ?> — <?php echo xlt('Schedule II'); ?>.
      <?php echo xlt('You will be required to sign this prescription with your DEA token (PIN + push notification). EPCS audit is logged.'); ?>
    </div>

    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Patient'); ?></span><span class="v">Allison Park — DOB 09/30/1973</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Drug'); ?></span><span class="v">Adderall XR 20 mg capsule</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Schedule'); ?></span><span class="v">CII</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Sig'); ?></span><span class="v">Take 1 capsule by mouth daily in AM</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Quantity'); ?></span><span class="v">30 capsules</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Days supply'); ?></span><span class="v">30</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Refills'); ?></span><span class="v">0</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Pharmacy'); ?></span><span class="v">Walgreens Lamar — Austin, TX</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Effective'); ?></span><span class="v">04/29/2026</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Provider DEA'); ?></span><span class="v">BR-1234567</span></div>
    <div class="cp-epcs-row"><span class="l"><?php echo xlt('Patient state'); ?></span><span class="v">TX (PMP queried 04/29)</span></div>
  </section>

  <section class="cp-epcs-card" style="display:flex; flex-direction:column; gap:14px;">
    <h2 style="margin:0; font-size: 14px;"><?php echo xlt('Two-factor sign'); ?></h2>

    <div class="cp-pin-wrap">
      <div class="lbl"><?php echo xlt('STEP 1 — ENTER DEA PIN'); ?></div>
      <input type="password" maxlength="6" placeholder="••••••" value="●●●●●●">
    </div>

    <div class="cp-pin-wrap" style="border-color:#FA8C33; background:#FFF8EC;">
      <div class="lbl" style="color:#FA8C33;"><?php echo xlt('STEP 2 — APPROVE ON PHONE'); ?></div>
      <div style="font-size:13px; color:#8A4800;">📱 <?php echo xlt('Push notification sent to'); ?> <strong>+1 (512) ***-4912</strong>
        <br><span style="font-size:11px;"><?php echo xlt('Tap "Approve" within 60 seconds'); ?></span></div>
    </div>

    <button type="button" class="cp-btn primary" style="width:100%; justify-content:center; padding:12px; font-weight:700;">
      🛡 <?php echo xlt('SIGN & SEND TO PHARMACY'); ?>
    </button>

    <div style="font-size: 10px; color: #8A91A1; line-height: 1.4; text-align: center;">
      <?php echo xlt('By signing, I attest the prescription is legitimate, authored for a real patient, with proper authorization. EPCS audit logged.'); ?>
    </div>
  </section>

</div>

</body>
</html>
