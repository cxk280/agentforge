<?php

/**
 * Patient Results timeline — Screen 36.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$results = [
    ['2026-04-12', 'Lab',     'HbA1c',          '7.9 %',     'High',   '<7.0',     'good'],
    ['2026-04-12', 'Lab',     'BMP — Glucose',  '142 mg/dL', 'High',   '70-99',    'good'],
    ['2026-04-12', 'Lab',     'BMP — Creatinine','1.04 mg/dL','Normal','0.6-1.2',  'good'],
    ['2026-04-12', 'Lab',     'TSH',            '2.4 mIU/L', 'Normal', '0.4-4.0',  'good'],
    ['2026-04-12', 'Lab',     'Microalbumin',   '32 mg/g',   'High',   '<30',      'good'],
    ['2026-04-08', 'Imaging', 'Foot X-ray',     '—',         'See report — no fracture', '—', 'good'],
    ['2026-02-18', 'Lab',     'Lipid Panel — LDL','98 mg/dL','Normal', '<100',     'good'],
    ['2026-02-18', 'Lab',     'Lipid Panel — HDL','52 mg/dL','Normal', '>=40',     'good'],
    ['2026-02-18', 'Lab',     'HbA1c',          '7.4 %',     'High',   '<7.0',     'good'],
    ['2025-11-22', 'Imaging', 'Mammogram',      '—',         'BI-RADS 1 — negative', '—','good'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Results'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-flag-high { color: #FA8C33; font-weight: 600; }
  .cp-flag-low  { color: #4785D9; font-weight: 600; }
  .cp-flag-norm { color: #1F8C4D; font-weight: 500; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Results'); ?></span>
    <span class="meta">10 <?php echo xlt('results in last 6 months'); ?> • 4 <?php echo xlt('flagged high'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Export PDF'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Order new'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-filter">
    <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search results...'); ?>"></div>
    <div class="pills">
      <button type="button" class="active"><?php echo xlt('All'); ?></button>
      <button type="button"><?php echo xlt('Lab'); ?></button>
      <button type="button"><?php echo xlt('Imaging'); ?></button>
      <button type="button"><?php echo xlt('Procedure'); ?></button>
      <button type="button"><?php echo xlt('Flagged'); ?></button>
    </div>
  </div>

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('DATE'); ?></th>
          <th><?php echo xlt('TYPE'); ?></th>
          <th><?php echo xlt('TEST / STUDY'); ?></th>
          <th><?php echo xlt('VALUE'); ?></th>
          <th><?php echo xlt('FLAG'); ?></th>
          <th><?php echo xlt('REFERENCE'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as [$date, $type, $test, $val, $flag, $ref, $tone]):
          $flagCls = $flag === 'High' ? 'cp-flag-high' : ($flag === 'Low' ? 'cp-flag-low' : 'cp-flag-norm');
        ?>
          <tr>
            <td class="muted"><?php echo text($date); ?></td>
            <td><span class="cp-status-pill info"><?php echo text($type); ?></span></td>
            <td class="bold"><?php echo text($test); ?></td>
            <td><?php echo text($val); ?></td>
            <td><span class="<?php echo $flagCls; ?>"><?php echo text($flag); ?></span></td>
            <td class="muted"><?php echo text($ref); ?></td>
            <td><button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Open'); ?> →</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
