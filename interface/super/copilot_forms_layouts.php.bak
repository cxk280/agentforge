<?php

/**
 * Forms & Layouts (admin) — bonus archetype page.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

$forms = [
    ['Patient Intake (new patient)',  '8 sections',  'Active',     'good'],
    ['Diabetes Encounter Template',   '5 sections',  'Active',     'good'],
    ['Hypertension Follow-up',        '4 sections',  'Active',     'good'],
    ['Annual Wellness',               '12 sections', 'Active',     'good'],
    ['Telehealth Consent',            '1 section',   'Active',     'good'],
    ['Pediatric Intake',              '6 sections',  'Draft',      'warn'],
    ['Old Visit Form (legacy)',       '3 sections',  'Archived',   'neutral'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Forms & Layouts'); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Forms & Layouts'); ?></span>
    <span class="meta">7 <?php echo xlt('forms'); ?> • 5 <?php echo xlt('active'); ?> • 1 <?php echo xlt('draft'); ?></span>
  </div>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('New form'); ?></button>
</header>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('forms_layouts'); ?>
  <main class="cp-content tight">
    <div class="cp-tbl">
      <table>
        <thead>
          <tr>
            <th><?php echo xlt('FORM'); ?></th>
            <th><?php echo xlt('SECTIONS'); ?></th>
            <th><?php echo xlt('STATUS'); ?></th>
            <th><?php echo xlt('ACTIONS'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($forms as [$name, $sections, $status, $tone]): ?>
            <tr>
              <td class="bold"><?php echo text($name); ?></td>
              <td class="muted"><?php echo text($sections); ?></td>
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
