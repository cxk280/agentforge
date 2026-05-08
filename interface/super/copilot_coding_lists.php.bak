<?php

/**
 * Coding & Lists (admin) — bonus archetype page.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

$lists = [
    ['ICD-10 (clinical)',       'Code system', '~70,000 entries', '2026 release',  'Synced',   'good'],
    ['CPT / HCPCS',             'Code system', '~10,400 entries', '2026 release',  'Synced',   'good'],
    ['SNOMED CT',               'Code system', 'Subset (~80k)',   '2025-09 update','Synced',   'good'],
    ['RxNorm',                  'Code system', '~150,000 entries','Daily sync',    'Synced',   'good'],
    ['LOINC',                   'Code system', '~95,000 entries', '2026-04 update','Synced',   'good'],
    ['Allergies (custom list)', 'List',        '38 entries',      'In-house',      'Local',    'info'],
    ['Pharmacies (favorites)',  'List',        '12 entries',      'In-house',      'Local',    'info'],
    ['Insurance plans',         'List',        '47 entries',      'In-house',      'Local',    'info'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Coding & Lists'); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Coding & Lists'); ?></span>
    <span class="meta">8 <?php echo xlt('lists'); ?> • 5 <?php echo xlt('synced from external'); ?> • 3 <?php echo xlt('local'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">↻ <?php echo xlt('Sync all'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('New list'); ?></button>
</header>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('coding_lists'); ?>
  <main class="cp-content tight">
    <div class="cp-tbl">
      <table>
        <thead><tr>
          <th><?php echo xlt('NAME'); ?></th>
          <th><?php echo xlt('TYPE'); ?></th>
          <th><?php echo xlt('SIZE'); ?></th>
          <th><?php echo xlt('VERSION'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr></thead>
        <tbody>
          <?php foreach ($lists as [$n, $t, $s, $v, $st, $tone]): ?>
            <tr>
              <td class="bold"><?php echo text($n); ?></td>
              <td class="muted"><?php echo text($t); ?></td>
              <td class="muted"><?php echo text($s); ?></td>
              <td class="muted"><?php echo text($v); ?></td>
              <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($st); ?></span></td>
              <td><button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Edit'); ?></button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

</body>
</html>
