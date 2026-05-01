<?php

/**
 * Provider Portal Dashboard — Screen 51.
 * Provider's view of patient portal activity (messages, requests, etc).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../interface/globals.php");

$kpis = [
    ['Active patients',  '1,420', '32 onboarded this week',  '#0D1B2A'],
    ['Unread messages',  '47',    '8 marked urgent',         '#FA8C33'],
    ['Refill requests',  '14',    '4 from last 24h',         '#4785D9'],
    ['Form submissions', '23',    'Intake + PRO + consent',  '#1F8C4D'],
];

$messages = [
    ['Margaret Chen',  '09:42 AM',  'Question about Lisinopril dose',     'Urgent', 'warn'],
    ['Linda Martinez', '09:18 AM',  'Insurance authorization update?',    'Standard','info'],
    ['Robert Hayes',   '08:55 AM',  'Cardiology referral status',         'Standard','info'],
    ['David Kim',      'Yesterday', 'Refill: Atorvastatin 40 mg',         'Refill', 'good'],
    ['Carol Bennett',  'Yesterday', 'Lab results — when can I expect?',   'Standard','info'],
];

$requests = [
    ['Refill — Lisinopril 10 mg',     'Margaret Chen',  '09:38 AM',  'Open'],
    ['Refill — Atorvastatin 40 mg',   'David Kim',      'Yesterday', 'Open'],
    ['Form intake submitted',          'Helen Park',     'Yesterday', 'Review'],
    ['Consent — Telehealth',           'James Wong',     '2 days ago','Signed'],
    ['PRO PHQ-9 submitted',            'Allison Park',   '2 days ago','Posted'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Portal'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-pp-body { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Patient Portal'); ?></span>
    <span class="meta"><?php echo xlt("Provider's view of portal activity"); ?> • Dr. E. Rivera</span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Portal settings'); ?></button>
  <button type="button" class="cp-btn primary"><?php echo xlt('Compose message'); ?></button>
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

  <div class="cp-pp-body">

    <section class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt('Recent Messages'); ?></h3>
        <span class="cnt"><?php echo count($messages); ?></span>
        <button type="button" class="cp-btn ghost right" style="padding:5px 10px;"><?php echo xlt('See all'); ?></button>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('PATIENT'); ?></th>
              <th><?php echo xlt('WHEN'); ?></th>
              <th><?php echo xlt('SUBJECT'); ?></th>
              <th><?php echo xlt('TYPE'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($messages as [$pat, $when, $subj, $type, $tone]): ?>
              <tr>
                <td class="bold"><?php echo text($pat); ?></td>
                <td class="muted"><?php echo text($when); ?></td>
                <td><?php echo text($subj); ?></td>
                <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($type); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt('Requests & Forms'); ?></h3>
        <span class="cnt"><?php echo count($requests); ?></span>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('REQUEST'); ?></th>
              <th><?php echo xlt('PATIENT'); ?></th>
              <th><?php echo xlt('WHEN'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($requests as [$req, $pat, $when, $status]):
              $tone = $status === 'Open' ? 'warn' : ($status === 'Review' ? 'info' : 'good');
            ?>
              <tr>
                <td><?php echo text($req); ?></td>
                <td class="bold"><?php echo text($pat); ?></td>
                <td class="muted"><?php echo text($when); ?></td>
                <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  </div>
</main>

</body>
</html>
