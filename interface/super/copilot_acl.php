<?php

/**
 * ACL Editor — Screen 53, AgentForge admin sub-page archetype.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

$roles = ['Admin', 'Provider', 'Nurse', 'Front Desk', 'Billing'];
// permissions matrix: rows are resources, columns are roles, value is access level
$resources = [
    ['Patients — Demographics', ['rw', 'rw', 'rw', 'rw', 'r']],
    ['Patients — Notes',        ['rw', 'rw', 'rw', '-',  '-']],
    ['Patients — Documents',    ['rw', 'rw', 'rw', 'r',  '-']],
    ['Encounters — Sign',       ['rw', 'rw', '-',  '-',  '-']],
    ['Orders — Lab/Imaging',    ['rw', 'rw', 'r',  '-',  '-']],
    ['e-Rx — Send',             ['rw', 'rw', '-',  '-',  '-']],
    ['Billing — Claims',        ['rw', 'r',  '-',  '-',  'rw']],
    ['Billing — Payments',      ['rw', '-',  '-',  '-',  'rw']],
    ['Reports',                 ['rw', 'r',  'r',  'r',  'r']],
    ['Admin Console',           ['rw', '-',  '-',  '-',  '-']],
    ['Audit Logs',              ['r',  '-',  '-',  '-',  '-']],
];

function aclCell(string $level): string
{
    $cls = ['rw' => 'good', 'r' => 'info', '-' => 'neutral'][$level] ?? 'neutral';
    $lbl = ['rw' => 'Read & Write', 'r' => 'Read', '-' => 'No Access'][$level] ?? '—';
    return '<span class="cp-status-pill ' . $cls . '">' . text($lbl) . '</span>';
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('ACL'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-acl-tbl th { white-space: nowrap; }
  .cp-acl-tbl td { white-space: nowrap; }
  .cp-acl-tbl td:first-child { font-weight: 500; color: #0D1B2A; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Access Control'); ?></span>
    <span class="meta">5 <?php echo xlt('roles'); ?> • 11 <?php echo xlt('resources'); ?> • <?php echo xlt('Saved 04/29/2026 by Admin'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Reset to defaults'); ?></button>
  <button type="button" class="cp-btn primary"><?php echo xlt('Save changes'); ?></button>
</header>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('acl'); ?>

  <main class="cp-content tight">
    <div class="cp-tbl cp-acl-tbl">
      <table>
        <thead>
          <tr>
            <th><?php echo xlt('RESOURCE'); ?></th>
            <?php foreach ($roles as $r): ?>
              <th><?php echo text(strtoupper($r)); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($resources as [$res, $access]): ?>
            <tr>
              <td><?php echo text($res); ?></td>
              <?php foreach ($access as $level): ?>
                <td><?php echo aclCell($level); ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

</body>
</html>
