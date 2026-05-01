<?php

/**
 * Chart Tracker — Screen 50.
 * Kanban tracking chart status (open / pending sign / locked).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../interface/globals.php");

$columns = [
    ['DRAFTS',         '#8A91A1', [
        ['Sarah Mitchell', 'Office Visit',  'Dr. Rivera', '04/29 09:00'],
        ['Patricia Nguyen','Office Visit',  'Dr. Rivera', '04/29 08:45'],
        ['Helen Park',     'Office Visit',  'Dr. Rivera', '04/27 14:15'],
    ]],
    ['NEEDS REVIEW',   '#FA8C33', [
        ['James Okafor',   'Office Visit',  'Dr. Rivera', '04/30 09:15'],
        ['Linda Torres',   'Telehealth',    'Dr. Patel',  '04/30 09:30'],
        ['Robert Davis',   'Procedure',     'Dr. Kim',    '04/30 09:15'],
    ]],
    ['SIGNED',         '#1F8C4D', [
        ['Margaret Chen',  'Office Visit',  'Dr. Rivera', '04/14 10:30'],
        ['Ted Shaw',       'Office Visit',  'Dr. Rivera', '04/14 11:15'],
        ['David Garcia',   'Office Visit',  'Dr. Lee',    '04/13 14:00'],
    ]],
    ['LOCKED',         '#4785D9', [
        ['Allison Park',   'Office Visit',  'Dr. Rivera', '04/11 09:00'],
        ['Robert Hayes',   'Office Visit',  'Dr. Rivera', '04/10 13:45'],
    ]],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Chart Tracker'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-kb { display: flex; gap: 12px; padding: 20px 24px 32px; overflow-x: auto; }
  .cp-kb-col { flex: 0 0 270px; display: flex; flex-direction: column; gap: 8px; }
  .cp-kb-col-head {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 10px 14px; display: flex; align-items: center; gap: 8px;
  }
  .cp-kb-col-head .lbl { font-size: 11px; font-weight: 700; letter-spacing: 0.5px; }
  .cp-kb-col-head .cnt { background: #F5F6F7; border-radius: 999px; padding: 2px 8px;
    font-size: 10px; font-weight: 600; color: #4F5763; margin-left: auto; }
  .cp-kb-card { background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 12px 14px; }
  .cp-kb-card .name { font-weight: 700; font-size: 13px; }
  .cp-kb-card .meta { font-size: 11px; color: #4F5763; margin-top: 2px; }
  .cp-kb-card .when { font-size: 11px; color: #8A91A1; margin-top: 2px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Chart Tracker'); ?></span>
    <span class="meta"><?php echo xlt('Encounter sign-off pipeline'); ?> • <?php echo xlt('11 charts in progress'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('My charts only'); ?></button>
  <button type="button" class="cp-btn primary"><?php echo xlt('Bulk sign'); ?></button>
</header>

<div class="cp-kb">
  <?php foreach ($columns as [$label, $color, $cards]): ?>
    <div class="cp-kb-col">
      <div class="cp-kb-col-head">
        <span class="lbl" style="color: <?php echo attr($color); ?>;"><?php echo text($label); ?></span>
        <span class="cnt"><?php echo count($cards); ?></span>
      </div>
      <?php foreach ($cards as [$name, $type, $prov, $when]): ?>
        <div class="cp-kb-card">
          <div class="name"><?php echo text($name); ?></div>
          <div class="meta"><?php echo text($type); ?> · <?php echo text($prov); ?></div>
          <div class="when"><?php echo text($when); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
