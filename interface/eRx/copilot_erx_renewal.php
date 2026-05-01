<?php

/**
 * e-Rx Renewal Queue — Screen 41.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$queue = [
    ['Margaret Chen',  'Lisinopril 10 mg',     '90 days, 3 refills', 'CVS Burnet Rd',     '04/29 09:38', 'Pending',   'warn'],
    ['Ted Shaw',       'Atorvastatin 40 mg',   '90 days, 5 refills', 'Walgreens Lamar',   '04/29 08:14', 'Pending',   'warn'],
    ['Linda Martinez', 'Metformin 1000 mg',    '60 days, 2 refills', 'CVS Burnet Rd',     '04/28 17:55', 'Pending',   'warn'],
    ['David Kim',      'Levothyroxine 50 mcg', '90 days, 5 refills', 'HEB Pharmacy',      '04/28 11:22', 'Approved',  'good'],
    ['Allison Park',   'Sertraline 50 mg',     '30 days, 5 refills', 'Walgreens Lamar',   '04/27 16:08', 'Approved',  'good'],
    ['Robert Hayes',   'Albuterol HFA',        '1 inhaler, 3 refills','CVS Burnet Rd',    '04/26 13:42', 'Denied — needs visit','danger'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('e-Rx Renewals'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('e-Rx Renewals'); ?></span>
    <span class="meta">3 <?php echo xlt('pending'); ?> • 2 <?php echo xlt('approved today'); ?> • 1 <?php echo xlt('denied'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Bulk approve'); ?></button>
  <button type="button" class="cp-btn primary"><?php echo xlt('Approve all (3)'); ?> →</button>
</header>

<main class="cp-content tight">

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('PATIENT'); ?></th>
          <th><?php echo xlt('DRUG'); ?></th>
          <th><?php echo xlt('SUPPLY'); ?></th>
          <th><?php echo xlt('PHARMACY'); ?></th>
          <th><?php echo xlt('REQUESTED'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($queue as [$pat, $drug, $supply, $pharm, $req, $status, $tone]): ?>
          <tr>
            <td class="bold"><?php echo text($pat); ?></td>
            <td>💊 <?php echo text($drug); ?></td>
            <td class="muted"><?php echo text($supply); ?></td>
            <td class="muted"><?php echo text($pharm); ?></td>
            <td class="muted"><?php echo text($req); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
            <td>
              <?php if ($status === 'Pending'): ?>
                <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Deny'); ?></button>
                <button type="button" class="cp-btn primary" style="padding:5px 10px; margin-left:4px;"><?php echo xlt('Approve'); ?></button>
              <?php else: ?>
                <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('View'); ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
