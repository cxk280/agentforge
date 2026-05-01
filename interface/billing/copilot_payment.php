<?php

/**
 * Payment Intake — Screen 58, billing archetype.
 * Handles Payment / Checkout / Batch Payments / Posting Payments.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$kpis = [
    ['Today',             '$3,420',  '14 payments',                '#0D1B2A'],
    ['Cash on hand',      '$1,150',  '5 cash transactions',        '#1F8C4D'],
    ['Card payments',     '$2,070',  '8 transactions',             '#4785D9'],
    ['Outstanding posts', '$840',    '2 awaiting batch',           '#FA8C33'],
];

$payments = [
    ['10:42 AM', 'Margaret Chen',   '$25.00',  'Card',   '99213 Office Visit',  'Posted',     '#1F8C4D'],
    ['10:18 AM', 'Ted Shaw',        '$215.00', 'Card',   '99214',               'Posted',     '#1F8C4D'],
    ['09:55 AM', 'Linda Martinez',  '$60.00',  'Cash',   'Co-pay',              'Posted',     '#1F8C4D'],
    ['09:31 AM', 'David Kim',       '$152.00', 'Check',  '#1820 — 99213',       'Pending',    '#FA8C33'],
    ['09:12 AM', 'Allison Park',    '$280.00', 'Card',   '99396 Wellness',      'Posted',     '#1F8C4D'],
    ['08:48 AM', 'Robert Hayes',    '$184.00', 'Card',   '80050 CMP',           'Posted',     '#1F8C4D'],
    ['08:22 AM', 'Carol Bennett',   '$152.00', 'Cash',   '99213',               'Posted',     '#1F8C4D'],
    ['08:01 AM', 'James Wong',      '$80.00',  'Cash',   'Co-pay',              'Posted',     '#1F8C4D'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Payments'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-pay-body { display: grid; grid-template-columns: 360px 1fr; gap: 16px; padding: 20px 24px 32px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Payments'); ?></span>
    <span class="meta"><?php echo xlt('Intake, batch posting, and reconciliation'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Open batch'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('New payment'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-kpi-grid">
    <?php foreach ($kpis as [$lbl, $val, $sub, $color]): ?>
      <div class="cp-kpi">
        <div class="lbl"><?php echo text($lbl); ?></div>
        <div class="val" style="color: <?php echo attr($color); ?>;"><?php echo text($val); ?></div>
        <div class="sub"><?php echo text($sub); ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cp-pay-body">

    <section class="cp-panel">
      <div class="cp-panel-lbl"><?php echo xlt('TAKE PAYMENT'); ?></div>

      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Patient'); ?></label>
        <input class="cp-input" type="text" value="Margaret Chen — MRN 4821">
      </div>
      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Amount'); ?></label>
        <input class="cp-input" type="text" value="$25.00">
      </div>
      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Method'); ?></label>
        <select class="cp-input cp-select"><option>Card</option><option>Cash</option><option>Check</option></select>
      </div>
      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Apply to'); ?></label>
        <select class="cp-input cp-select"><option>99213 Office Visit — $152.00</option></select>
      </div>
      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Reference #'); ?></label>
        <input class="cp-input" type="text" placeholder="<?php echo xla('Auth code or check number'); ?>">
      </div>

      <button type="button" class="cp-btn primary" style="width:100%; justify-content:center; padding:10px;"><?php echo xlt('Charge'); ?> →</button>
    </section>

    <section class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt("Today's Payments"); ?></h3>
        <span class="cnt">14</span>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('TIME'); ?></th>
              <th><?php echo xlt('PATIENT'); ?></th>
              <th><?php echo xlt('AMOUNT'); ?></th>
              <th><?php echo xlt('METHOD'); ?></th>
              <th><?php echo xlt('APPLIED TO'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payments as [$time, $pat, $amt, $method, $applied, $status, $color]): ?>
              <tr>
                <td class="muted"><?php echo text($time); ?></td>
                <td class="bold"><?php echo text($pat); ?></td>
                <td class="bold"><?php echo text($amt); ?></td>
                <td class="muted"><?php echo text($method); ?></td>
                <td class="muted"><?php echo text($applied); ?></td>
                <td>
                  <span class="cp-status-pill <?php echo $status === 'Posted' ? 'good' : 'warn'; ?>"><?php echo text($status); ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  </div>

</main>

</body>
</html>
