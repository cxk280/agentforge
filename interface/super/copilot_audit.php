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

$events = [
    ['09:42:14', 'user.created',     'Site Admin', 'Christopher King', 'Created user "Lin Kim" with role Provider'],
    ['09:38:02', 'patient.viewed',   'Provider',   'Dr. E. Rivera',    'Viewed Margaret Chen (MRN 4821)'],
    ['09:31:18', 'rx.signed',        'Provider',   'Dr. E. Rivera',    'Signed Lisinopril 10 mg for Margaret Chen'],
    ['09:18:04', 'module.enabled',   'Site Admin', 'Christopher King', 'Enabled Carecoordination module'],
    ['08:51:33', 'acl.updated',      'Site Admin', 'Christopher King', 'Granted Nurse role patients/notes write'],
    ['08:42:11', 'login.success',    'Provider',   'Dr. A. Park',      'Logged in from 198.51.100.42'],
    ['08:39:55', 'login.failure',    'unknown',    'unknown',          'Failed login for "admin" from 198.51.100.7'],
    ['08:14:08', 'document.uploaded','Front Desk', 'Maria Nunez',      'Uploaded "Ins Auth.pdf" to Margaret Chen'],
    ['07:58:20', 'encounter.signed', 'Provider',   'Dr. E. Rivera',    'Signed encounter 99213 for Ted Shaw'],
    ['Yesterday','backup.completed', 'System',     '—',                'Daily backup uploaded to S3 (842 MB)'],
];

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
    <span class="meta"><?php echo xlt('All HIPAA-sensitive actions'); ?> • 12,408 <?php echo xlt('events in last 30 days'); ?></span>
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
