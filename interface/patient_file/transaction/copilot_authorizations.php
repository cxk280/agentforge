<?php

/**
 * Authorizations — Screen 34.
 * Insurance authorization requests + status queue.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$auths = [
    ['Margaret Chen',   'Foot Doppler imaging',           'BCBS PPO',    '2026-04-30', 'Approved',   'good',    '#A4892'],
    ['Linda Martinez',  'MRI lumbar w/o contrast',        'United HC',   '2026-04-29', 'Pending',    'warn',    '#A4881'],
    ['Robert Hayes',    'Specialist referral — Cardio',   'BCBS PPO',    '2026-04-28', 'Approved',   'good',    '#A4877'],
    ['Carol Bennett',   'Surgery — knee arthroscopy',     'Self-pay',    '2026-04-25', 'N/A — self-pay','neutral','—'],
    ['David Kim',       'Physical Therapy — 12 sessions', 'Cigna PPO',   '2026-04-24', 'Denied',     'danger',  '#A4870'],
    ['James Wong',      'CT abdomen w/ contrast',         'United HC',   '2026-04-22', 'Approved',   'good',    '#A4865'],
    ['Allison Park',    'Sleep study (in-lab)',           'Medicare',    '2026-04-20', 'Approved',   'good',    '#A4860'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Authorizations'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Authorizations'); ?></span>
    <span class="meta">7 <?php echo xlt('on this patient'); ?> • 1 <?php echo xlt('pending'); ?> • 1 <?php echo xlt('denied'); ?></span>
  </div>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Request authorization'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-filter">
    <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search by service, payer, auth#...'); ?>"></div>
    <div class="pills">
      <button type="button" class="active"><?php echo xlt('All'); ?></button>
      <button type="button"><?php echo xlt('Pending'); ?></button>
      <button type="button"><?php echo xlt('Approved'); ?></button>
      <button type="button"><?php echo xlt('Denied'); ?></button>
    </div>
  </div>

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('PATIENT'); ?></th>
          <th><?php echo xlt('SERVICE'); ?></th>
          <th><?php echo xlt('PAYER'); ?></th>
          <th><?php echo xlt('REQUESTED'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('AUTH #'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($auths as [$pat, $svc, $payer, $req, $status, $tone, $authNum]): ?>
          <tr>
            <td class="bold"><?php echo text($pat); ?></td>
            <td><?php echo text($svc); ?></td>
            <td class="muted"><?php echo text($payer); ?></td>
            <td class="muted"><?php echo text($req); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
            <td class="muted"><?php echo text($authNum); ?></td>
            <td>
              <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('View'); ?></button>
              <?php if ($status === 'Denied'): ?>
                <button type="button" class="cp-btn warn" style="padding:5px 10px; margin-left:4px;"><?php echo xlt('Appeal'); ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
