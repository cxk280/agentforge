<?php

/**
 * Patient Tracker / Flow board — Screen 32.
 * Exam-room kanban for the day's patient flow.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$columns = [
    ['ARRIVED',       '#4785D9', [
        ['James Okafor',   '9:15 AM', 'Office Visit', 'Dr. Rivera', '12 min'],
        ['Linda Torres',   '9:30 AM', 'Telehealth',   'Dr. Patel',  '5 min'],
        ['Michael Chen',   '10:00 AM','Office Visit', 'Dr. Lee',    '—'],
    ]],
    ['ROOMING',       '#8561C7', [
        ['Sarah Mitchell', '9:00 AM', 'Office Visit', 'Dr. Rivera', '22 min'],
    ]],
    ['READY',         '#33A68C', [
        ['Patricia Nguyen','8:45 AM', 'Office Visit', 'Dr. Rivera', '34 min'],
        ['Robert Davis',   '9:15 AM', 'Procedure',    'Dr. Kim',    '18 min'],
    ]],
    ['WITH PROVIDER', '#008C8C', [
        ['Emma Wilson',    '8:30 AM', 'Telehealth',   'Dr. Patel',  '48 min'],
        ['David Garcia',   '8:45 AM', 'Office Visit', 'Dr. Lee',    '42 min'],
    ]],
    ['CHECKOUT',      '#FA8C33', [
        ['Helen Park',     '7:45 AM', 'Office Visit', 'Dr. Rivera', '1h 5m'],
    ]],
    ['DONE',          '#33A666', [
        ['Thomas Brown',   '7:30 AM', 'Procedure',    'Dr. Kim',    'done'],
        ['Olivia Smith',   '7:45 AM', 'Office Visit', 'Dr. Patel',  'done'],
        ['George White',   '8:00 AM', 'Office Visit', 'Dr. Rivera', 'done'],
    ]],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Tracker'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-kb { display: flex; gap: 12px; padding: 20px 24px 32px; overflow-x: auto; }
  .cp-kb-col { flex: 0 0 220px; display: flex; flex-direction: column; gap: 8px; }
  .cp-kb-col-head {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 10px 14px; display: flex; align-items: center; gap: 8px;
    position: relative;
  }
  .cp-kb-col-head::before {
    content: ''; position: absolute; left: 0; right: 0; top: 0; height: 3px;
    border-radius: 8px 8px 0 0;
  }
  .cp-kb-col-head .lbl { font-size: 11px; font-weight: 700; color: #4F5763; letter-spacing: 0.5px; }
  .cp-kb-col-head .cnt { background: #F5F6F7; border-radius: 999px; padding: 2px 8px;
    font-size: 10px; font-weight: 600; color: #4F5763; margin-left: auto; }
  .cp-kb-card {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 12px 14px; position: relative;
    display: flex; flex-direction: column; gap: 4px;
  }
  .cp-kb-card::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
    border-radius: 8px 0 0 8px;
  }
  .cp-kb-card .name { font-weight: 700; font-size: 13px; }
  .cp-kb-card .meta { font-size: 11px; color: #4F5763; }
  .cp-kb-card .prov { font-size: 11px; color: #8A91A1; }
  .cp-kb-card .foot { display: flex; gap: 6px; align-items: center; margin-top: 6px; }
  .cp-kb-wait { background: #F5F6F7; border-radius: 999px; padding: 3px 9px;
    font-size: 10px; font-weight: 500; color: #4F5763; }
  .cp-kb-wait.over { background: #D93838; color: #FFFFFF; }
  .cp-kb-wait.done { background: #33A666; color: #FFFFFF; }
  .cp-kb-add { background: #FFFFFF; border: 1px dashed #E4E5E8; border-radius: 8px;
    padding: 8px 14px; font-size: 11px; color: #8A91A1; text-align: center; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Patient Tracker'); ?></span>
    <span class="meta"><?php echo xlt('Thu, May 1 2026'); ?> • <?php echo xlt('Today’s exam-room flow'); ?></span>
  </div>
  <span class="cp-util util" style="background:#F5F6F7;">All Providers ⌄</span>
  <span class="cp-util util" style="background:#F5F6F7;">All Rooms ⌄</span>
  <button type="button" class="cp-btn primary">↻ <?php echo xlt('Refresh'); ?></button>
</header>

<div class="cp-kb">
  <?php foreach ($columns as [$label, $color, $cards]): ?>
    <div class="cp-kb-col">
      <div class="cp-kb-col-head" style="--col: <?php echo attr($color); ?>;">
        <style>.cp-kb-col-head[style*="<?php echo $color; ?>"]::before { background: <?php echo $color; ?>; }</style>
        <span class="lbl"><?php echo text($label); ?></span>
        <span class="cnt"><?php echo count($cards); ?></span>
      </div>
      <?php foreach ($cards as [$name, $appt, $type, $prov, $wait]): ?>
        <div class="cp-kb-card" style="--col: <?php echo $color; ?>;">
          <style>.cp-kb-card[style*="<?php echo $color; ?>"]::before { background: <?php echo $color; ?>; }</style>
          <div class="name"><?php echo text($name); ?></div>
          <div class="meta"><?php echo text($appt); ?> · <?php echo text($type); ?></div>
          <div class="prov"><?php echo text($prov); ?></div>
          <div class="foot">
            <?php
              $wcls = $wait === 'done' ? 'done' : (str_contains($wait, 'h') ? 'over' : '');
              $wlbl = $wait === 'done' ? '✓ Done' : $wait;
            ?>
            <span class="cp-kb-wait <?php echo $wcls; ?>"><?php echo text($wlbl); ?></span>
            <span style="flex:1;"></span>
            <button type="button" class="cp-btn ghost" style="padding:3px 8px; font-size:11px;"><?php echo xlt('Open'); ?> →</button>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="cp-kb-add">+ <?php echo xlt('Add patient'); ?></div>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
