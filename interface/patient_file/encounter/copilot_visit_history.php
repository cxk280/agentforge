<?php

/**
 * Visit History (legacy encounters list) — Screen 30.
 * Distinct from the Co-Pilot History navtab (Screen 12).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$encounters = [
    ['2026-04-14','Office Visit','Dr. Rivera','Main Street','BP check & medication review',  'Signed','good'],
    ['2026-03-22','Telehealth',  'Dr. Patel', 'Remote',     'Flu-like symptoms, cough 5 days','Signed','good'],
    ['2026-02-10','Office Visit','Dr. Rivera','Main Street','Annual physical + labs',         'Signed','good'],
    ['2025-12-05','Procedure',   'Dr. Lee',   'Surgery Ctr','Mole removal — left forearm',     'Signed','good'],
    ['2025-11-18','Office Visit','Dr. Rivera','Main Street','Hypertension follow-up',         'Signed','good'],
    ['2025-10-02','Telehealth',  'Dr. Patel', 'Remote',     'Rx refill — lisinopril',         'Signed','good'],
    ['2025-08-15','Office Visit','Dr. Rivera','Main Street','Fatigue, shortness of breath',   'Locked','info'],
    ['2025-07-01','Office Visit','Dr. Rivera','Main Street','New patient intake',             'Locked','info'],
    ['2025-04-10','Office Visit','Dr. Kim',   'Eastside',   'Knee pain — sports injury',      'Locked','info'],
    ['2025-01-28','Telehealth',  'Dr. Patel', 'Remote',     'Sinus infection, antibiotics',   'Locked','info'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Visit History'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Visit History'); ?></span>
    <span class="meta">47 <?php echo xlt('encounters'); ?> • <?php echo xlt('All providers, all facilities'); ?></span>
  </div>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('New encounter'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-filter">
    <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search encounters...'); ?>"></div>
    <div class="pills">
      <button type="button" class="active"><?php echo xlt('All'); ?></button>
      <button type="button"><?php echo xlt('Office Visit'); ?></button>
      <button type="button"><?php echo xlt('Telehealth'); ?></button>
      <button type="button"><?php echo xlt('Procedure'); ?></button>
    </div>
    <span class="cp-util util">📅 2025-11-01 — 2026-05-01</span>
  </div>

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('DATE'); ?></th>
          <th><?php echo xlt('TYPE'); ?></th>
          <th><?php echo xlt('PROVIDER'); ?></th>
          <th><?php echo xlt('FACILITY'); ?></th>
          <th><?php echo xlt('CHIEF COMPLAINT'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($encounters as [$date, $type, $prov, $fac, $cc, $st, $tone]): ?>
          <tr>
            <td class="muted"><?php echo text($date); ?></td>
            <td class="bold"><?php echo text($type); ?></td>
            <td class="muted"><?php echo text($prov); ?></td>
            <td class="muted"><?php echo text($fac); ?></td>
            <td><?php echo text($cc); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($st); ?></span></td>
            <td><button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Open'); ?> →</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
