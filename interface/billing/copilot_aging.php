<?php

/**
 * Daily Cash / Aging Report — Screen 59, billing archetype.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$buckets = [
    ['Current',   '$84,210', '63 claims', '#1F8C4D'],
    ['1–30 days', '$28,140', '24 claims', '#0D1B2A'],
    ['31–60 days','$12,310', '11 claims', '#FA8C33'],
    ['61–90 days', '$6,420',  '5 claims',  '#D9701A'],
    ['90+ days',   '$1,760',  '3 claims',  '#D93838'],
];

$rows = [
    ['Linda Martinez',   'United HC', '$310.00', '92 days', 'CLM-9399', '90+',  'danger'],
    ['Robert Hayes',     'BCBS PPO',  '$184.00', '78 days', 'CLM-9395', '61-90','warn'],
    ['David Kim',        'Cigna PPO', '$152.00', '54 days', 'CLM-9398', '31-60','warn'],
    ['Carol Bennett',    'Self-pay',  '$152.00', '38 days', 'CLM-9394', '31-60','warn'],
    ['James Wong',       'United HC', '$215.00', '22 days', 'CLM-9392', '1-30', 'info'],
    ['Allison Park',     'Medicare',  '$280.00', '12 days', 'CLM-9397', '1-30', 'info'],
    ['Margaret Chen',    'BCBS PPO',  '$152.00', '4 days',  'CLM-9402', 'Current','good'],
    ['Ted Shaw',         'Aetna HMO', '$215.00', '0 days',  'CLM-9401', 'Current','good'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Aging Report'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-aging-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
  .cp-aging .lbl { font-size: 10px; font-weight: 600; color: #8A91A1; letter-spacing: 0.5px; text-transform: uppercase; line-height: 1; }
  .cp-aging .val { font-size: 22px; font-weight: 700; line-height: 1.2; }
  .cp-aging .sub { font-size: 11px; color: #8A91A1; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('A/R Aging'); ?></span>
    <span class="meta"><?php echo xlt('Outstanding receivables by age bucket'); ?> • <?php echo xlt('Refreshed 09:42 AM'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export CSV'); ?></button>
  <button type="button" class="cp-btn primary"><?php echo xlt('Run collections'); ?> →</button>
</header>

<main class="cp-content tight">

  <div class="cp-aging-grid">
    <?php foreach ($buckets as [$lbl, $val, $sub, $color]): ?>
      <div class="cp-kpi cp-aging">
        <div class="lbl"><?php echo text($lbl); ?></div>
        <div class="val" style="color: <?php echo attr($color); ?>;"><?php echo text($val); ?></div>
        <div class="sub"><?php echo text($sub); ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cp-filter">
    <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search by patient, claim, or insurer...'); ?>"></div>
    <div class="pills">
      <button type="button" class="active"><?php echo xlt('All'); ?></button>
      <button type="button"><?php echo xlt('90+ days'); ?></button>
      <button type="button"><?php echo xlt('61-90'); ?></button>
      <button type="button"><?php echo xlt('31-60'); ?></button>
      <button type="button"><?php echo xlt('1-30'); ?></button>
      <button type="button"><?php echo xlt('Current'); ?></button>
    </div>
    <span class="cp-util util">📅 <?php echo xlt('As of today'); ?></span>
  </div>

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('PATIENT'); ?></th>
          <th><?php echo xlt('PAYER'); ?></th>
          <th><?php echo xlt('AMOUNT'); ?></th>
          <th><?php echo xlt('DAYS OUT'); ?></th>
          <th><?php echo xlt('CLAIM #'); ?></th>
          <th><?php echo xlt('BUCKET'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as [$pat, $payer, $amt, $days, $clm, $bucket, $tone]): ?>
          <tr>
            <td class="bold"><?php echo text($pat); ?></td>
            <td class="muted"><?php echo text($payer); ?></td>
            <td class="bold"><?php echo text($amt); ?></td>
            <td class="muted"><?php echo text($days); ?></td>
            <td class="muted"><?php echo text($clm); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($bucket); ?></span></td>
            <td>
              <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Statement'); ?></button>
              <button type="button" class="cp-btn ghost" style="padding:5px 10px; margin-left: 4px;"><?php echo xlt('Resubmit'); ?></button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
