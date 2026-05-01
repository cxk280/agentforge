<?php

/**
 * Batch Results — Screen 38.
 * Bulk-process incoming lab batch from interface.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$batches = [
    ['Batch #B-9421', 'Quest Diagnostics',  '2026-04-12 09:14', '14 results', 'Posted',     'good'],
    ['Batch #B-9420', 'LabCorp',            '2026-04-12 08:55', '8 results',  'Pending',    'warn'],
    ['Batch #B-9419', 'Riverside Imaging',  '2026-04-12 07:42', '3 reports',  'Posted',     'good'],
    ['Batch #B-9418', 'Quest Diagnostics',  '2026-04-11 17:21', '12 results', 'Posted',     'good'],
    ['Batch #B-9417', 'BioReference',       '2026-04-11 14:08', '5 results',  'Errors (2)', 'danger'],
    ['Batch #B-9416', 'LabCorp',            '2026-04-11 11:30', '9 results',  'Posted',     'good'],
];

$batch_items = [
    ['Margaret Chen',  'HbA1c',         '7.9 %',     'High',  'Posted'],
    ['Margaret Chen',  'BMP — Glucose', '142 mg/dL', 'High',  'Posted'],
    ['Margaret Chen',  'BMP — Creatinine','1.04',    'Normal','Posted'],
    ['Margaret Chen',  'Microalbumin',  '32 mg/g',   'High',  'Posted'],
    ['Ted Shaw',       'CMP — Glucose', '102',       'Normal','Posted'],
    ['Linda Martinez', 'BMP — Creatinine','1.92',    'High',  'Posted'],
    ['David Kim',      'TSH',           '6.4',       'High',  'Posted'],
    ['Carol Bennett',  'CBC — Hgb',     '9.2',       'Low',   'Posted'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Batch Results'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-batch-body { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Batch Results'); ?></span>
    <span class="meta"><?php echo xlt('Inbound batch processing'); ?> • 6 <?php echo xlt('batches today'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Re-run failed'); ?></button>
  <button type="button" class="cp-btn primary"><?php echo xlt('Pull new batch'); ?></button>
</header>

<main class="cp-content tight">
  <div class="cp-batch-body">

    <section class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt('Recent Batches'); ?></h3>
        <span class="cnt">6</span>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('BATCH'); ?></th>
              <th><?php echo xlt('SOURCE'); ?></th>
              <th><?php echo xlt('RECEIVED'); ?></th>
              <th><?php echo xlt('COUNT'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($batches as [$b, $src, $when, $count, $status, $tone]): ?>
              <tr>
                <td class="bold"><?php echo text($b); ?></td>
                <td class="muted"><?php echo text($src); ?></td>
                <td class="muted"><?php echo text($when); ?></td>
                <td class="muted"><?php echo text($count); ?></td>
                <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt('Batch #B-9421 — Items'); ?></h3>
        <span class="cnt"><?php echo count($batch_items); ?></span>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('PATIENT'); ?></th>
              <th><?php echo xlt('TEST'); ?></th>
              <th><?php echo xlt('VALUE'); ?></th>
              <th><?php echo xlt('FLAG'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($batch_items as [$pat, $t, $v, $f, $s]):
              $tone = $f === 'High' || $f === 'Low' ? 'warn' : 'good';
            ?>
              <tr>
                <td class="bold"><?php echo text($pat); ?></td>
                <td class="muted"><?php echo text($t); ?></td>
                <td><?php echo text($v); ?></td>
                <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($f); ?></span></td>
                <td><span class="cp-status-pill good"><?php echo text($s); ?></span></td>
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
