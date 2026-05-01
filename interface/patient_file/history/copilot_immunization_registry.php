<?php

/**
 * Immunization Registry — Screen 47.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$immunizations = [
    ['Influenza (quadrivalent)',     '2025-10-14', 'Riverside Family Medicine', 'Lot FL-7281',  'Up to date', 'good'],
    ['COVID-19 (Pfizer Bivalent)',   '2025-09-22', 'Riverside Family Medicine', 'Lot CV-9942',  'Up to date', 'good'],
    ['Tdap (Boostrix)',              '2024-03-08', 'Riverside Family Medicine', 'Lot TD-1144',  '2034 due',   'good'],
    ['Pneumococcal (Prevnar 20)',    '2023-04-19', 'Riverside Family Medicine', 'Lot PC-8821',  'Complete',   'good'],
    ['Shingrix dose 2',              '2022-11-04', 'Riverside Family Medicine', 'Lot SH-4488',  'Complete',   'good'],
    ['Hepatitis B series',           '2018-06-15', 'Pediatric records',         'Imported',     'Complete',   'good'],
    ['MMR',                          'Childhood',  'Pediatric records',         'Imported',     'Complete',   'good'],
    ['HPV (Gardasil 9)',             'Refused',    '—',                          '—',            'Refused',    'neutral'],
];

$due = [
    ['Influenza 2026-2027 season', 'Sep 2026', 'recommended'],
    ['Shingrix booster',           'Not due',  'complete'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Immunizations'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Immunizations'); ?></span>
    <span class="meta">8 <?php echo xlt('on record'); ?> • 1 <?php echo xlt('due in 4 months'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Print'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Record vaccine'); ?></button>
</header>

<main class="cp-content tight">

  <section class="cp-panel flush">
    <div class="cp-panel-head">
      <h3><?php echo xlt('Recommended'); ?></h3>
      <span class="cnt"><?php echo count($due); ?></span>
    </div>
    <div class="cp-tbl" style="border:none; border-radius:0;">
      <table>
        <thead>
          <tr>
            <th><?php echo xlt('VACCINE'); ?></th>
            <th><?php echo xlt('NEXT DUE'); ?></th>
            <th><?php echo xlt('STATUS'); ?></th>
            <th><?php echo xlt('ACTIONS'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($due as [$name, $when, $status]): ?>
            <tr>
              <td class="bold"><?php echo text($name); ?></td>
              <td class="muted"><?php echo text($when); ?></td>
              <td><span class="cp-status-pill <?php echo $status === 'recommended' ? 'warn' : 'good'; ?>"><?php echo text($status === 'recommended' ? 'Recommended' : 'Complete'); ?></span></td>
              <td>
                <?php if ($status === 'recommended'): ?>
                  <button type="button" class="cp-btn primary" style="padding:5px 10px;"><?php echo xlt('Schedule'); ?></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="cp-panel flush">
    <div class="cp-panel-head">
      <h3><?php echo xlt('History'); ?></h3>
      <span class="cnt"><?php echo count($immunizations); ?></span>
    </div>
    <div class="cp-tbl" style="border:none; border-radius:0;">
      <table>
        <thead>
          <tr>
            <th><?php echo xlt('VACCINE'); ?></th>
            <th><?php echo xlt('DATE'); ?></th>
            <th><?php echo xlt('FACILITY'); ?></th>
            <th><?php echo xlt('LOT'); ?></th>
            <th><?php echo xlt('STATUS'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($immunizations as [$name, $date, $fac, $lot, $status, $tone]): ?>
            <tr>
              <td class="bold"><?php echo text($name); ?></td>
              <td class="muted"><?php echo text($date); ?></td>
              <td class="muted"><?php echo text($fac); ?></td>
              <td class="muted"><?php echo text($lot); ?></td>
              <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

</main>

</body>
</html>
