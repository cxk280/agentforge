<?php

/**
 * System / Backup Status — Screen 56, admin archetype.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

$kpis = [
    ['Uptime',         '99.98%',  'Last 30 days',          '#1F8C4D'],
    ['DB Size',        '14.2 GB', '+182 MB this week',     '#0D1B2A'],
    ['Active Sessions','47',      '8 over last hour',      '#4785D9'],
    ['Last Backup',    '2h ago',  'S3 — 842 MB',           '#33A68C'],
];

$services = [
    ['OpenEMR PHP',         '8.2.20', 'Healthy',  'good',   '12ms p50'],
    ['MariaDB',             '10.11',  'Healthy',  'good',   '4ms p50'],
    ['FastAPI Co-Pilot',    '0.4.2',  'Healthy',  'good',   '320ms p50'],
    ['Langfuse',            '2.91.0', 'Healthy',  'good',   '—'],
    ['New Relic agent',     '10.2.1', 'Pending',  'warn',   '—'],
    ['Redis cache',         '7.2',    'Healthy',  'good',   '0.4ms p50'],
];

$backups = [
    ['2026-05-01 02:00', 'Daily',   '842 MB',  'S3 — primary',       'Success'],
    ['2026-04-30 02:00', 'Daily',   '838 MB',  'S3 — primary',       'Success'],
    ['2026-04-29 02:00', 'Daily',   '836 MB',  'S3 — primary',       'Success'],
    ['2026-04-28 02:00', 'Daily',   '835 MB',  'S3 — primary',       'Success'],
    ['2026-04-27 02:00', 'Daily',   '833 MB',  'S3 — primary',       'Success'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('System'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('System & Backup'); ?></span>
    <span class="meta"><?php echo xlt('All services up'); ?> • <?php echo xlt('Healthy'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">↻ <?php echo xlt('Run backup now'); ?></button>
  <button type="button" class="cp-btn primary"><?php echo xlt('Open Grafana'); ?> →</button>
</header>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('system'); ?>

  <main class="cp-content tight">

    <div class="cp-kpi-grid">
      <?php foreach ($kpis as [$lbl, $val, $sub, $col]): ?>
        <div class="cp-kpi">
          <div class="lbl"><?php echo text($lbl); ?></div>
          <div class="val" style="color: <?php echo attr($col); ?>;"><?php echo text($val); ?></div>
          <div class="sub"><?php echo text($sub); ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt('Service Health'); ?></h3>
        <span class="cnt"><?php echo count($services); ?></span>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('SERVICE'); ?></th>
              <th><?php echo xlt('VERSION'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
              <th><?php echo xlt('LATENCY'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($services as [$name, $ver, $status, $tone, $lat]): ?>
              <tr>
                <td class="bold"><?php echo text($name); ?></td>
                <td class="muted"><?php echo text($ver); ?></td>
                <td><span class="cp-status-pill <?php echo attr($tone); ?>">● <?php echo text($status); ?></span></td>
                <td class="muted"><?php echo text($lat); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt('Recent Backups'); ?></h3>
        <span class="cnt">last 5</span>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('TIME'); ?></th>
              <th><?php echo xlt('TYPE'); ?></th>
              <th><?php echo xlt('SIZE'); ?></th>
              <th><?php echo xlt('DESTINATION'); ?></th>
              <th><?php echo xlt('RESULT'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($backups as [$time, $type, $size, $dest, $result]): ?>
              <tr>
                <td class="muted"><?php echo text($time); ?></td>
                <td class="muted"><?php echo text($type); ?></td>
                <td class="bold"><?php echo text($size); ?></td>
                <td class="muted"><?php echo text($dest); ?></td>
                <td><span class="cp-status-pill good">✓ <?php echo text($result); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

</body>
</html>
