<?php

/**
 * Pending Review — Screen 35.
 * Provider's queue of orders/results awaiting review and sign-off.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$kpis = [
    ['Awaiting review', '14', '4 high priority',          '#FA8C33'],
    ['New since AM',    '8',  'Last 4 hours',             '#4785D9'],
    ['Critical flags',  '3',  'Hgb low, A1C high, Cr high','#D93838'],
    ['Avg turn-around', '14h','Target <24h',              '#1F8C4D'],
];

$queue = [
    ['Margaret Chen',  'HbA1c',         '7.9 %',   'High',     '04/12 09:14', 'High',    'danger'],
    ['Linda Martinez', 'BMP',           'Cr 1.92', 'High',     '04/12 09:18', 'High',    'danger'],
    ['Robert Hayes',   'Lipid Panel',   'LDL 142', 'High',     '04/12 09:22', 'Medium',  'warn'],
    ['Carol Bennett',  'TSH',           '6.4',     'High',     '04/12 09:35', 'Medium',  'warn'],
    ['Allison Park',   'CBC',           'Hgb 9.2', 'Critical', '04/12 09:42', 'Critical','danger'],
    ['James Wong',     'Foot X-ray',    '—',       'Normal',   '04/12 10:01', 'Routine', 'info'],
    ['David Kim',      'EKG',           '—',       'Normal',   '04/12 10:18', 'Routine', 'info'],
    ['Ted Shaw',       'CMP',           'Glucose 102','Normal','04/12 10:42', 'Routine', 'info'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Pending Review'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Pending Review'); ?></span>
    <span class="meta"><?php echo xlt('Orders & results awaiting provider sign-off'); ?> • Dr. E. Rivera</span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Snooze all'); ?></button>
  <button type="button" class="cp-btn primary"><?php echo xlt('Sign all critical'); ?> →</button>
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

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('PATIENT'); ?></th>
          <th><?php echo xlt('TEST / ORDER'); ?></th>
          <th><?php echo xlt('VALUE'); ?></th>
          <th><?php echo xlt('FLAG'); ?></th>
          <th><?php echo xlt('RECEIVED'); ?></th>
          <th><?php echo xlt('PRIORITY'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($queue as [$pat, $test, $val, $flag, $when, $pri, $tone]): ?>
          <tr>
            <td class="bold"><?php echo text($pat); ?></td>
            <td><?php echo text($test); ?></td>
            <td><?php echo text($val); ?></td>
            <td class="muted"><?php echo text($flag); ?></td>
            <td class="muted"><?php echo text($when); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($pri); ?></span></td>
            <td>
              <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Open'); ?></button>
              <button type="button" class="cp-btn primary" style="padding:5px 10px; margin-left:4px;"><?php echo xlt('Sign'); ?></button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
