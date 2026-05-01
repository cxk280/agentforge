<?php

/**
 * Users & Groups — Screen 52, AgentForge admin sub-page archetype.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

$users = [
    ['CK', 'orange', 'Christopher King',  'admin@example.com',     'Site Admin',  '2 min ago',     'Active'],
    ['ER', 'teal',   'Dr. Eduardo Rivera','erivera@example.com',   'Provider',    '15 min ago',    'Active'],
    ['AP', 'purple', 'Dr. Allison Park',  'apark@example.com',     'Provider',    '1 hr ago',      'Active'],
    ['JP', 'blue',   'Dr. James Patel',   'jpatel@example.com',    'Provider',    'Yesterday',     'Active'],
    ['MN', 'mint',   'Maria Nunez',       'mnunez@example.com',    'Front Desk',  'Yesterday',     'Active'],
    ['SC', 'pink',   'Sandra Choi',       'schoi@example.com',     'Nurse',       '3 days ago',    'Active'],
    ['BH', 'green',  'Brian Hudson',      'bhudson@example.com',   'Billing',     '1 week ago',    'Active'],
    ['LK', 'orange', 'Lin Kim',           'lkim@example.com',      'Provider',    '2 weeks ago',   'Inactive'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Users & Groups'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-user-cell { display: inline-flex; align-items: center; gap: 10px; }
  .cp-user-cell .name { font-weight: 600; color: #0D1B2A; }
  .cp-user-cell .email { font-size: 11px; color: #8A91A1; margin-top: 2px; }
  .cp-user-info { display: flex; flex-direction: column; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Users & Groups'); ?></span>
    <span class="meta">8 <?php echo xlt('users'); ?> • 4 <?php echo xlt('roles'); ?> • 1 <?php echo xlt('inactive'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Invite user'); ?></button>
</header>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('users'); ?>

  <main class="cp-content tight">

    <div class="cp-filter">
      <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search users by name, email, or role...'); ?>"></div>
      <div class="pills">
        <button type="button" class="active"><?php echo xlt('All'); ?></button>
        <button type="button"><?php echo xlt('Providers'); ?></button>
        <button type="button"><?php echo xlt('Front Desk'); ?></button>
        <button type="button"><?php echo xlt('Billing'); ?></button>
        <button type="button"><?php echo xlt('Inactive'); ?></button>
      </div>
    </div>

    <div class="cp-tbl">
      <table>
        <thead>
          <tr>
            <th><?php echo xlt('USER'); ?></th>
            <th><?php echo xlt('ROLE'); ?></th>
            <th><?php echo xlt('LAST LOGIN'); ?></th>
            <th><?php echo xlt('STATUS'); ?></th>
            <th><?php echo xlt('ACTIONS'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as [$initials, $tone, $name, $email, $role, $login, $st]): ?>
            <tr>
              <td>
                <span class="cp-user-cell">
                  <span class="cp-avatar <?php echo attr($tone); ?>"><?php echo text($initials); ?></span>
                  <span class="cp-user-info">
                    <span class="name"><?php echo text($name); ?></span>
                    <span class="email"><?php echo text($email); ?></span>
                  </span>
                </span>
              </td>
              <td class="muted"><?php echo text($role); ?></td>
              <td class="muted"><?php echo text($login); ?></td>
              <td>
                <span class="cp-status-pill <?php echo $st === 'Active' ? 'good' : 'neutral'; ?>"><?php echo text($st); ?></span>
              </td>
              <td>
                <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Edit'); ?></button>
                <span style="color:#8A91A1; padding:0 6px;">⋯</span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </main>
</div>

</body>
</html>
