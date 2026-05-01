<?php

/**
 * Inventory (Drug + Destroyed) — Screen 60, billing/operational archetype.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$kpis = [
    ['Total SKUs',     '142',     '8 below reorder',     '#0D1B2A'],
    ['On hand value',  '$28,420', 'Avg cost basis',      '#1F8C4D'],
    ['Expiring 30d',   '6',       '$1,820 risk',         '#FA8C33'],
    ['Destroyed (mo)', '12',      'DEA-222 reconciled',  '#D93838'],
];

$items = [
    ['Lisinopril 10 mg tablet',   'tablet, 90ct', 248,  '$0.18',  '$44.64',  '2027-03-12', 'In stock',  'good'],
    ['Metformin 1000 mg',         'tablet, 100ct',124,  '$0.22',  '$27.28',  '2027-06-04', 'In stock',  'good'],
    ['Levothyroxine 50 mcg',      'tablet, 30ct', 32,   '$0.34',  '$10.88',  '2026-08-18', 'Low stock', 'warn'],
    ['Atorvastatin 40 mg',        'tablet, 90ct', 88,   '$0.27',  '$23.76',  '2027-11-30', 'In stock',  'good'],
    ['Penicillin 500 mg',         'tablet, 30ct', 16,   '$0.41',  '$6.56',   '2026-05-22', 'Reorder',   'warn'],
    ['Insulin Glargine pen',      '3 mL pen',     8,    '$74.00', '$592.00', '2026-09-15', 'In stock',  'good'],
    ['Albuterol HFA',             'inhaler',      6,    '$32.00', '$192.00', '2026-06-30', 'Low stock', 'warn'],
    ['Adderall XR 20 mg (CII)',   'capsule, 30ct',24,   '$1.85',  '$44.40',  '2026-12-01', 'Locked',    'info'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Inventory'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Inventory'); ?></span>
    <span class="meta"><?php echo xlt('Drug stock + destruction log (DEA-222)'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Destroy / log'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Receive shipment'); ?></button>
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

  <div class="cp-filter">
    <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search by name, NDC, or class...'); ?>"></div>
    <div class="pills">
      <button type="button" class="active"><?php echo xlt('All'); ?></button>
      <button type="button"><?php echo xlt('Low stock'); ?></button>
      <button type="button"><?php echo xlt('Reorder'); ?></button>
      <button type="button"><?php echo xlt('Expiring'); ?></button>
      <button type="button"><?php echo xlt('Controlled'); ?></button>
      <button type="button"><?php echo xlt('Destroyed'); ?></button>
    </div>
  </div>

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('ITEM'); ?></th>
          <th><?php echo xlt('UNIT'); ?></th>
          <th><?php echo xlt('ON HAND'); ?></th>
          <th><?php echo xlt('UNIT COST'); ?></th>
          <th><?php echo xlt('VALUE'); ?></th>
          <th><?php echo xlt('EXPIRES'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as [$name, $unit, $qty, $cost, $val, $exp, $status, $tone]): ?>
          <tr>
            <td class="bold"><?php echo text($name); ?></td>
            <td class="muted"><?php echo text($unit); ?></td>
            <td class="muted"><?php echo text($qty); ?></td>
            <td class="muted"><?php echo text($cost); ?></td>
            <td class="bold"><?php echo text($val); ?></td>
            <td class="muted"><?php echo text($exp); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
            <td>
              <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Adjust'); ?></button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
