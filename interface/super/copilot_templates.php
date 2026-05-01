<?php

/**
 * Templates (admin) — bonus archetype page.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

$templates = [
    ['SOAP — Diabetes follow-up',     'Encounter', 'EN/ES', 'Active', 'good'],
    ['SOAP — Hypertension follow-up', 'Encounter', 'EN',    'Active', 'good'],
    ['SOAP — Annual Wellness',        'Encounter', 'EN/ES', 'Active', 'good'],
    ['Letter — Lab results normal',   'Letter',    'EN/ES', 'Active', 'good'],
    ['Letter — Referral',             'Letter',    'EN',    'Active', 'good'],
    ['Order set — Diabetes 90d',      'Orders',    'EN',    'Active', 'good'],
    ['Order set — CHF baseline',      'Orders',    'EN',    'Draft',  'warn'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Templates'); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Templates'); ?></span>
    <span class="meta">7 <?php echo xlt('templates'); ?> • <?php echo xlt('SOAP, Letter, Order sets'); ?></span>
  </div>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('New template'); ?></button>
</header>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('templates'); ?>
  <main class="cp-content tight">
    <div class="cp-tbl">
      <table>
        <thead><tr>
          <th><?php echo xlt('NAME'); ?></th>
          <th><?php echo xlt('TYPE'); ?></th>
          <th><?php echo xlt('LANG'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr></thead>
        <tbody>
          <?php foreach ($templates as [$name, $type, $lang, $status, $tone]): ?>
            <tr>
              <td class="bold"><?php echo text($name); ?></td>
              <td class="muted"><?php echo text($type); ?></td>
              <td class="muted"><?php echo text($lang); ?></td>
              <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
              <td><button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Edit'); ?></button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

</body>
</html>
