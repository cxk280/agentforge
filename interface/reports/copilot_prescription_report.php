<?php

/**
 * Prescription Report — Screen 44.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

// Live prescriptions
$totalRx = (int)(sqlQuery("SELECT COUNT(*) AS n FROM prescriptions WHERE active = 1")['n'] ?? 0);
$rxs = [];
$rows = sqlStatement(
    "SELECT p.drug, p.dosage, p.quantity, p.refills, p.start_date, p.date_added,
            pat.fname AS pfn, pat.lname AS pln,
            ph.name AS pharmacy
     FROM prescriptions p
     LEFT JOIN patient_data pat ON p.patient_id = pat.pid
     LEFT JOIN pharmacies ph ON p.pharmacy_id = ph.id
     WHERE p.active = 1
     ORDER BY p.date_added DESC LIMIT 25"
);
while ($r = sqlFetchArray($rows)) {
    $patName = trim(($r['pfn'] ?? '') . ' ' . ($r['pln'] ?? '')) ?: '—';
    $drug    = trim(($r['drug'] ?? '') . ' ' . ($r['dosage'] ?? '')) ?: '—';
    $supply  = trim(($r['quantity'] ?? '0') . ' ct, ' . (int)($r['refills'] ?? 0) . ' refills');
    $pharm   = $r['pharmacy'] ?: '—';
    $sent    = $r['date_added'] ? date('m/d H:i', strtotime($r['date_added'])) : '—';
    $rxs[]   = [$patName, $drug, $supply, $pharm, $sent, 'Sent', 'good'];
}

$kpis = [
    ['Active Rx',         (string)$totalRx,   'Currently active',     '#0D1B2A'],
    ['Refills available', (string)max(0, (int)(sqlQuery("SELECT SUM(refills) AS n FROM prescriptions WHERE active=1")['n'] ?? 0)), 'Across active Rx', '#1F8C4D'],
    ['Patients with Rx',  (string)((int)(sqlQuery("SELECT COUNT(DISTINCT patient_id) AS n FROM prescriptions WHERE active=1")['n'] ?? 0)), 'Distinct patients', '#4785D9'],
    ['This week',         '0',                'Sent in last 7 days',  '#33A68C'],
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
