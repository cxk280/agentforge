<?php

/**
 * Electronic Reports — Screen 39.
 * Index of available compliance / regulatory / operational reports.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$reports = [
    ['MIPS Quality (CQM)',       'CMS',      'Active in 2026 PY',      'Last run: 04/14/2026',     '#008C8C'],
    ['Meaningful Use Stage 3',   'CMS',      'Annual attestation',     'Last run: 03/31/2026',     '#008C8C'],
    ['ePrescribing controlled',  'DEA',      'EPCS audit',             'Last run: 04/10/2026',     '#8561C7'],
    ['Immunization registry (TXIIS)','State','Daily push',             'Last sync: 04/12/2026',    '#33A68C'],
    ['Syndromic surveillance',   'State',    'Real-time stream',       'Online',                   '#33A68C'],
    ['HCC / RAF coding',         'CMS',      'Risk adjustment',        'Last run: 04/01/2026',     '#FA8C33'],
    ['340B drug pricing',        'HRSA',     'Quarterly',              'Last run: 03/15/2026',     '#0D1B2A'],
    ['HEDIS measures',           'NCQA',     'Annual',                 'Last run: 02/01/2026',     '#4785D9'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Electronic Reports'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-rep-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
  .cp-rep-card { background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 12px;
    padding: 16px; cursor: pointer; }
  .cp-rep-card:hover { border-color: #008C8C; }
  .cp-rep-card .ic { width: 36px; height: 36px; border-radius: 8px; display: inline-flex;
    align-items: center; justify-content: center; font-size: 16px; color: #FFFFFF; margin-bottom: 10px; }
  .cp-rep-card .n { font-weight: 700; font-size: 14px; }
  .cp-rep-card .a { font-size: 11px; color: #4F5763; margin-top: 2px; }
  .cp-rep-card .l { font-size: 11px; color: #8A91A1; margin-top: 6px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Electronic Reports'); ?></span>
    <span class="meta"><?php echo xlt('Compliance, regulatory, and operational reporting'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export schedule'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Add report'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-rep-grid">
    <?php foreach ($reports as [$name, $auth, $cadence, $last, $color]): ?>
      <div class="cp-rep-card">
        <div class="ic" style="background: <?php echo attr($color); ?>;">📊</div>
        <div class="n"><?php echo text($name); ?></div>
        <div class="a"><?php echo text($auth); ?> · <?php echo text($cadence); ?></div>
        <div class="l"><?php echo text($last); ?></div>
      </div>
    <?php endforeach; ?>
  </div>

</main>

</body>
</html>
