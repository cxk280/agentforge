<?php

/**
 * Facilities — Screen 54, AgentForge admin sub-page archetype.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

$facilities = [
    ['Riverside Family Medicine',  '847 Main Street, Austin, TX 78701',     '(512) 555-0142', 'Primary',   '12 providers', 'Active'],
    ['Eastside Clinic',            '5500 Cesar Chavez St, Austin, TX 78702','(512) 555-0188', 'Branch',    '4 providers',  'Active'],
    ['Surgery Center',             '300 W. 38th Street, Austin, TX 78705',  '(512) 555-0211', 'Specialty', '3 surgeons',   'Active'],
    ['Telehealth Hub',             'Virtual',                                'N/A',           'Virtual',   '8 providers',  'Active'],
    ['Old Westside Office',        '1820 W. Slaughter Ln, Austin, TX 78748','(512) 555-0099', 'Branch',    '0 providers',  'Closed'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Facilities'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Facilities'); ?></span>
    <span class="meta">5 <?php echo xlt('facilities'); ?> • 4 <?php echo xlt('active'); ?> • 1 <?php echo xlt('closed'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Add facility'); ?></button>
</header>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('facilities'); ?>

  <main class="cp-content tight">
    <div class="cp-tbl">
      <table>
        <thead>
          <tr>
            <th><?php echo xlt('NAME'); ?></th>
            <th><?php echo xlt('ADDRESS'); ?></th>
            <th><?php echo xlt('PHONE'); ?></th>
            <th><?php echo xlt('TYPE'); ?></th>
            <th><?php echo xlt('STAFF'); ?></th>
            <th><?php echo xlt('STATUS'); ?></th>
            <th><?php echo xlt('ACTIONS'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($facilities as [$name, $addr, $phone, $type, $staff, $status]): ?>
            <tr>
              <td class="bold"><?php echo text($name); ?></td>
              <td class="muted"><?php echo text($addr); ?></td>
              <td class="muted"><?php echo text($phone); ?></td>
              <td class="muted"><?php echo text($type); ?></td>
              <td class="muted"><?php echo text($staff); ?></td>
              <td>
                <span class="cp-status-pill <?php echo $status === 'Active' ? 'good' : 'neutral'; ?>"><?php echo text($status); ?></span>
              </td>
              <td>
                <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Edit'); ?></button>
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
