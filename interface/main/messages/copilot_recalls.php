<?php

/**
 * Recalls — Screen 33.
 * Clinic-wide recall queue with bulk message actions.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$recalls = [
    ['Harriet Johnson','Annual Physical', '2025-03-10', '2026-03-10', '52d',  'Dr. Rivera', 'Overdue',   'danger',  true],
    ['Marcus Lee',     'Colonoscopy',     '2019-06-01', '2024-06-01', '2y',   'Dr. Kim',    'Critical',  'danger',  true],
    ['Nancy Wu',       'Mammogram',       '2025-01-15', '2026-01-15', '106d', 'Dr. Patel',  'Overdue',   'danger',  true],
    ['Carl Fischer',   'Diabetes A1C',    '2026-02-01', '2026-05-01', '0d',   'Dr. Rivera', 'Due today', 'warn',    true],
    ['Diane Torres',   'Hypertension F/U','2026-02-14', '2026-05-14', '-13d', 'Dr. Rivera', 'Upcoming',  'info',    false],
    ['Peter Kowalski', 'Annual Physical', '2025-05-10', '2026-05-10', '9d',   'Dr. Lee',    'Overdue',   'danger',  true],
    ['Alice Ramirez',  'Flu Vaccine',     '2025-10-01', '2026-10-01', '-5m',  'Dr. Patel',  'Upcoming',  'info',    false],
    ['Samuel Brown',   'Colonoscopy',     '2021-04-01', '2026-04-01', '30d',  'Dr. Kim',    'Overdue',   'danger',  true],
    ['Grace Kim',      'Mammogram',       '2025-04-20', '2026-04-20', '11d',  'Dr. Patel',  'Overdue',   'danger',  false],
    ['Walter Dunn',    'Diabetes A1C',    '2025-11-01', '2026-02-01', '3m',   'Dr. Rivera', 'Critical',  'danger',  true],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Recalls'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-bulkbar { background: #E6F4FF; border: 1px solid #4785D9; border-radius: 12px;
    padding: 10px 16px; display: flex; align-items: center; gap: 10px; }
  .cp-bulkbar .sel { font-weight: 600; }
  .cp-chk-tbl { width: 16px; height: 16px; border: 1px solid #C7CBD2; border-radius: 3px; background: #FFFFFF;
    display: inline-flex; align-items: center; justify-content: center; color: #FFFFFF; font-size: 10px; }
  .cp-chk-tbl.on { background: #008C8C; border-color: #008C8C; }
  .cp-overdue { color: #D93838; font-weight: 600; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Recalls'); ?></span>
    <span class="meta">24 <?php echo xlt('overdue'); ?> • 2 <?php echo xlt('due today'); ?> • 7 <?php echo xlt('upcoming'); ?></span>
  </div>
</header>

<main class="cp-content tight">

  <div class="cp-bulkbar">
    <span class="sel">7 <?php echo xlt('patients selected'); ?></span>
    <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Clear'); ?></button>
    <span style="flex:1;"></span>
    <button type="button" class="cp-btn dark" style="background:#4785D9;">✉ <?php echo xlt('Send SMS'); ?> (7)</button>
    <button type="button" class="cp-btn ghost" style="border-color:#4785D9; color:#4785D9;">✉ <?php echo xlt('Send Letter'); ?></button>
    <button type="button" class="cp-btn ghost" style="border-color:#4785D9; color:#4785D9;">+ <?php echo xlt('Add Appt'); ?></button>
  </div>

  <div class="cp-filter">
    <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search patients...'); ?>"></div>
    <div class="pills">
      <button type="button" class="active"><?php echo xlt('All'); ?></button>
      <button type="button"><?php echo xlt('Annual Physical'); ?></button>
      <button type="button"><?php echo xlt('Mammogram'); ?></button>
      <button type="button"><?php echo xlt('Colonoscopy'); ?></button>
      <button type="button"><?php echo xlt('Diabetes'); ?></button>
    </div>
  </div>

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th style="width:40px;"><span class="cp-chk-tbl">✓</span></th>
          <th><?php echo xlt('PATIENT'); ?></th>
          <th><?php echo xlt('RECALL TYPE'); ?></th>
          <th><?php echo xlt('LAST VISIT'); ?></th>
          <th><?php echo xlt('DUE DATE'); ?></th>
          <th><?php echo xlt('OVERDUE BY'); ?></th>
          <th><?php echo xlt('PROVIDER'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recalls as [$n, $r, $last, $due, $over, $p, $st, $tone, $sel]): ?>
          <tr<?php if ($sel): ?> style="background:#F0F7FF;"<?php endif; ?>>
            <td><span class="cp-chk-tbl <?php echo $sel ? 'on' : ''; ?>"><?php echo $sel ? '✓' : ''; ?></span></td>
            <td class="bold"><?php echo text($n); ?></td>
            <td class="muted"><?php echo text($r); ?></td>
            <td class="muted"><?php echo text($last); ?></td>
            <td class="muted"><?php echo text($due); ?></td>
            <td<?php if ($tone === 'danger' || $tone === 'warn'): ?> class="cp-overdue"<?php else: ?> class="muted"<?php endif; ?>><?php echo text($over); ?></td>
            <td class="muted"><?php echo text($p); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($st); ?></span></td>
            <td><button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Schedule'); ?></button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
