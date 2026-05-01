<?php

/**
 * Prescription Report — Screen 44.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$kpis = [
    ['Rxs sent (30d)',   '342',   '+12% vs prior 30d',   '#0D1B2A'],
    ['Refills processed','148',   '94% within 24h',      '#1F8C4D'],
    ['Controlled (CII)', '24',    'EPCS-signed',         '#8561C7'],
    ['Rejected at pharm','7',     '2 prior auth required','#D93838'],
];

$rxs = [
    ['Margaret Chen',  'Lisinopril 10 mg',         '90 ct, 3 refills', 'CVS Burnet Rd',     '04/29 09:38', 'Sent',  'good'],
    ['Ted Shaw',       'Atorvastatin 40 mg',       '90 ct, 5 refills', 'Walgreens Lamar',   '04/29 09:14', 'Sent',  'good'],
    ['Linda Martinez', 'Metformin 1000 mg',        '60 ct, 2 refills', 'CVS Burnet Rd',     '04/28 14:55', 'Sent',  'good'],
    ['David Kim',      'Levothyroxine 50 mcg',     '90 ct, 5 refills', 'HEB Pharmacy',      '04/28 11:22', 'Sent',  'good'],
    ['Allison Park',   'Adderall XR 20 mg (CII)',  '30 ct, 0 refills', 'Walgreens Lamar',   '04/27 16:08', 'Sent (EPCS)', 'violet'],
    ['Robert Hayes',   'Amoxicillin 500 mg',       '21 ct, 0 refills', 'CVS Burnet Rd',     '04/27 13:42', 'Rejected — PA','danger'],
    ['Carol Bennett',  'Sertraline 50 mg',         '30 ct, 5 refills', 'Walgreens Lamar',   '04/26 10:18', 'Sent',  'good'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Prescription Report'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Prescription Report'); ?></span>
    <span class="meta"><?php echo xlt('Last 30 days'); ?> • <?php echo xlt('Updated 09:42 AM'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export'); ?></button>
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
          <th><?php echo xlt('DRUG'); ?></th>
          <th><?php echo xlt('SUPPLY'); ?></th>
          <th><?php echo xlt('PHARMACY'); ?></th>
          <th><?php echo xlt('SENT'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rxs as [$pat, $drug, $supply, $pharm, $sent, $status, $tone]): ?>
          <tr>
            <td class="bold"><?php echo text($pat); ?></td>
            <td><?php echo text($drug); ?></td>
            <td class="muted"><?php echo text($supply); ?></td>
            <td class="muted"><?php echo text($pharm); ?></td>
            <td class="muted"><?php echo text($sent); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
