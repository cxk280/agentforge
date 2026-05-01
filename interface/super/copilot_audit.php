<?php

/**
 * Audit / Activity Log — Screen 55, admin archetype.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

// Live audit log from `log` table — most recent 50 entries.
$events = [];
$rows = sqlStatement("SELECT date, event, user, comments FROM log ORDER BY id DESC LIMIT 50");
while ($r = sqlFetchArray($rows)) {
    $time = date('H:i:s', strtotime($r['date']));
    $tag  = strtolower(str_replace(' ', '.', $r['event'] ?: 'event'));
    $actor = $r['user'] ?: 'system';
    // Role inference based on username
    if ($actor === 'admin') { $role = 'Site Admin'; }
    elseif (in_array($actor, ['erivera','apark','jpatel','llee','kkim'], true)) { $role = 'Provider'; }
    elseif ($actor === 'mnunez') { $role = 'Front Desk'; }
    elseif ($actor === 'schoi') { $role = 'Nurse'; }
    elseif ($actor === 'bhudson') { $role = 'Billing'; }
    elseif ($actor === 'system') { $role = 'System'; }
    else { $role = 'User'; }
    $details = cp_decode_log_comment((string)($r['comments'] ?: ''), 100);
    $events[] = [$time, $tag, $role, $actor, $details];
}
$totalEvents = sqlQuery("SELECT COUNT(*) AS n FROM log")['n'] ?? 0;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Audit Log'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-evt-tag {
    background: #F0F4F9; color: #4785D9;
    border-radius: 4px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 600;
    font-family: ui-monospace, 'SF Mono', monospace;
    letter-spacing: 0.3px;
  }
  .cp-evt-time { color: #8A91A1; font-family: ui-monospace, 'SF Mono', monospace; font-size: 11px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Audit Log'); ?></span>
    <span class="meta"><?php echo xlt('All HIPAA-sensitive actions'); ?> • <?php echo text(number_format((int)$totalEvents)); ?> <?php echo xlt('events total'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export'); ?></button>
  <button type="button" class="cp-btn dark"><?php echo xlt('Subscribe to alerts'); ?></button>
</header>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('audit'); ?>

  <main class="cp-content tight">

    <div class="cp-filter">
      <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search by user, action, patient...'); ?>"></div>
      <div class="pills">
        <button type="button" class="active"><?php echo xlt('All'); ?></button>
        <button type="button"><?php echo xlt('Logins'); ?></button>
        <button type="button"><?php echo xlt('Patient access'); ?></button>
        <button type="button"><?php echo xlt('Admin'); ?></button>
        <button type="button"><?php echo xlt('System'); ?></button>
      </div>
      <span class="cp-util util">📅 <?php echo xlt('Last 24 hours'); ?></span>
    </div>

    <div class="cp-tbl">
      <table>
        <thead>
          <tr>
            <th><?php echo xlt('TIME'); ?></th>
            <th><?php echo xlt('EVENT'); ?></th>
            <th><?php echo xlt('ROLE'); ?></th>
            <th><?php echo xlt('ACTOR'); ?></th>
            <th><?php echo xlt('DETAILS'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($events as [$time, $tag, $role, $actor, $details]): ?>
            <tr>
              <td><span class="cp-evt-time"><?php echo text($time); ?></span></td>
              <td><span class="cp-evt-tag"><?php echo text($tag); ?></span></td>
              <td class="muted"><?php echo text($role); ?></td>
              <td class="bold"><?php echo text($actor); ?></td>
              <td><?php echo text($details); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </main>
</div>

</body>
</html>
