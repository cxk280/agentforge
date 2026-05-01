<?php

/**
 * Quality Measures (CQM) — Screen 46.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$kpis = [
    ['Performance score',  '74.2',  '+3.4 vs prior PY',     '#1F8C4D'],
    ['Measures reporting', '12 / 14','85.7% complete',       '#0D1B2A'],
    ['Patients in numerator','842', 'Out of 1,134 eligible','#4785D9'],
    ['Bonus points',       '6',    'Promoting Interop',      '#33A68C'],
];

$measures = [
    ['CMS122v9 — Diabetes A1C poor control',     'Inverse',  '24%',  '<30%',  'Met',     'good'],
    ['CMS165v9 — Controlling High BP',           'Direct',   '78%',  '>=70%', 'Met',     'good'],
    ['CMS68v10 — Documentation of meds',         'Direct',   '94%',  '>=85%', 'Met',     'good'],
    ['CMS134v9 — Diabetes nephropathy',          'Direct',   '62%',  '>=70%', 'At risk', 'warn'],
    ['CMS156v9 — Use of high-risk meds (elderly)','Inverse', '12%',  '<15%',  'Met',     'good'],
    ['CMS125v9 — Mammography screening',         'Direct',   '74%',  '>=75%', 'At risk', 'warn'],
    ['CMS50v9 — Closing referral loop',          'Direct',   '52%',  '>=60%', 'Below',   'danger'],
    ['CMS69v9 — BMI screening + follow-up',      'Direct',   '88%',  '>=70%', 'Met',     'good'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Quality Measures'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Quality Measures (CQM)'); ?></span>
    <span class="meta">2026 <?php echo xlt('Performance Year'); ?> • <?php echo xlt('Updated 04/12/2026'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export to QPP'); ?></button>
  <button type="button" class="cp-btn primary"><?php echo xlt('Submit measures'); ?> →</button>
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
          <th><?php echo xlt('MEASURE'); ?></th>
          <th><?php echo xlt('TYPE'); ?></th>
          <th><?php echo xlt('CURRENT'); ?></th>
          <th><?php echo xlt('THRESHOLD'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($measures as [$m, $type, $cur, $thr, $status, $tone]): ?>
          <tr>
            <td class="bold"><?php echo text($m); ?></td>
            <td class="muted"><?php echo text($type); ?></td>
            <td class="bold"><?php echo text($cur); ?></td>
            <td class="muted"><?php echo text($thr); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
            <td><button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Drill in'); ?></button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
