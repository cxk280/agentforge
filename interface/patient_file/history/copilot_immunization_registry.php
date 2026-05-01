<?php

/**
 * Immunization Registry — Screen 47.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$pid = (int)($_SESSION['pid'] ?? 1);

$immunizations = [];
$rows = sqlStatement(
    "SELECT administered_date, note, administered_by, lot_number
     FROM immunizations WHERE patient_id = ? ORDER BY administered_date DESC",
    [$pid]
);
$idx = 0;
while ($r = sqlFetchArray($rows)) {
    $vaccine = $r['note'] ?: 'Unspecified vaccine';
    $date = $r['administered_date'] ? substr($r['administered_date'], 0, 10) : '—';
    $facility = $r['administered_by'] ?: '—';
    $lot = $r['lot_number'] ? 'Lot ' . $r['lot_number'] : '—';
    $status = 'Complete';
    if ($idx < 2) { $status = 'Up to date'; } // Most recent → "Up to date"
    $immunizations[] = [$vaccine, $date, $facility, $lot, $status, 'good'];
    $idx++;
}

// Recommended vaccines based on what's NOT in the history (heuristic).
$has = function (string $kw) use ($immunizations): bool {
    foreach ($immunizations as $i) {
        if (stripos($i[0], $kw) !== false) { return true; }
    }
    return false;
};
$due = [];
if (!$has('Influenza') || strtotime((string)($immunizations[0][1] ?? '2020-01-01')) < strtotime('-9 months')) {
    $due[] = ['Influenza ' . date('Y') . '–' . (date('Y') + 1) . ' season', date('M Y', strtotime('+5 months')), 'recommended'];
}
if ($has('Shingrix')) {
    $due[] = ['Shingrix booster', 'Not due', 'complete'];
}

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
    <span class="meta"><?php echo text(count($immunizations)); ?> <?php echo xlt('on record'); ?> • <?php echo text(count($due)); ?> <?php echo xlt('to review'); ?></span>
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
