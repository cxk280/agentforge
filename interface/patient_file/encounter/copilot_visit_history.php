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

// Live encounters from `form_encounter` joined with users for provider name.
$encounters = [];
$totalCount = (int)(sqlQuery("SELECT COUNT(*) AS n FROM form_encounter")['n'] ?? 0);
$rows = sqlStatement(
    "SELECT fe.date, fe.reason, fe.facility, fe.last_level_billed, fe.last_level_closed,
            u.fname AS pfname, u.lname AS plname, u.title AS ptitle
     FROM form_encounter fe
     LEFT JOIN users u ON fe.provider_id = u.id
     ORDER BY fe.date DESC LIMIT 30"
);
$idx = 0;
while ($r = sqlFetchArray($rows)) {
    $date = substr($r['date'] ?? '', 0, 10) ?: '—';
    $type = 'Office Visit'; // form_encounter doesn't store visit type natively; default
    $prov = 'Provider';
    if ($r['plname']) {
        $prov = ($r['ptitle'] ? $r['ptitle'] . ' ' : '') . trim($r['pfname'] . ' ' . $r['plname']);
        if (!$r['ptitle']) { $prov = 'Dr. ' . trim($r['pfname'] . ' ' . $r['plname']); }
    }
    $facility = $r['facility'] ?: 'Main Street';
    $reason = trim((string)$r['reason']);
    if (!$reason) { $reason = '—'; }
    if (strlen($reason) > 80) { $reason = substr($reason, 0, 77) . '…'; }
    // Status: closed → Signed, otherwise In progress
    if ((int)($r['last_level_closed'] ?? 0) > 0) { $status = 'Signed'; $tone = 'good'; }
    elseif ((int)($r['last_level_billed'] ?? 0) > 0) { $status = 'Billed'; $tone = 'info'; }
    else { $status = 'In progress'; $tone = 'warn'; }
    $encounters[] = [$date, $type, $prov, $facility, $reason, $status, $tone];
    $idx++;
}

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
    <span class="meta"><?php echo text($totalCount); ?> <?php echo xlt('encounters'); ?> • <?php echo xlt('All providers, all facilities'); ?></span>
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
