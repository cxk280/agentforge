<?php

/**
 * Module Installer (admin) — Screen 57.
 * Distinct from the patient-context Modules navtab (Screen 21).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

$installed = [
    ['Carecoordination',    '✓', 'v2.4.1', 'OpenEMR Foundation', '4 patients use', 'Enabled'],
    ['Clinical Decision Rules', '✨', 'v1.9.3', 'OpenEMR Foundation', 'CQM engine',  'Enabled'],
    ['EasiPRO',             '📊', 'v3.1.0', 'Northwestern',     '12 patients',   'Enabled'],
    ['ClinicalTables FHIR', '🔗', 'v0.7.2', 'NLM',              'Code-set lookup','Update available'],
    ['Telehealth Studio',   '📹', 'v4.2.0', 'OpenEMR Foundation', '8 visits/wk',   'Disabled'],
];

$marketplace = [
    ['Diabetes Coach',      '🩸', 'v1.2.0', 'RiversideHealth',   'Glucose log integration'],
    ['Care Plan Templates', '📋', 'v0.9.1', 'OpenEMR Foundation','Condition-specific plans'],
    ['Pharmacy Sync',       '💊', 'v2.0.1', 'Surescripts',       'Two-way med history sync'],
    ['Imaging DICOM Bridge','🖥', 'v1.0.4', 'OpenEMR Foundation','PACS integration'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Modules'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-mod-row { display: inline-flex; align-items: center; gap: 10px; }
  .cp-mod-icon { width: 32px; height: 32px; border-radius: 8px; background: #F5F6F7; display:inline-flex; align-items:center; justify-content:center; font-size: 16px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Modules'); ?></span>
    <span class="meta">5 <?php echo xlt('installed'); ?> • 4 <?php echo xlt('enabled'); ?> • 1 <?php echo xlt('update available'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Browse Marketplace'); ?> →</button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Install module'); ?></button>
</header>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('module_installer'); ?>

  <main class="cp-content tight">

    <div class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt('Installed Modules'); ?></h3>
        <span class="cnt">5</span>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('MODULE'); ?></th>
              <th><?php echo xlt('VERSION'); ?></th>
              <th><?php echo xlt('VENDOR'); ?></th>
              <th><?php echo xlt('USAGE'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
              <th><?php echo xlt('ACTIONS'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($installed as [$name, $icon, $ver, $vendor, $usage, $status]):
              $tone = $status === 'Enabled' ? 'good' : ($status === 'Disabled' ? 'neutral' : 'warn');
            ?>
              <tr>
                <td>
                  <span class="cp-mod-row">
                    <span class="cp-mod-icon"><?php echo text($icon); ?></span>
                    <span class="bold"><?php echo text($name); ?></span>
                  </span>
                </td>
                <td class="muted"><?php echo text($ver); ?></td>
                <td class="muted"><?php echo text($vendor); ?></td>
                <td class="muted"><?php echo text($usage); ?></td>
                <td><span class="cp-status-pill <?php echo $tone; ?>"><?php echo text($status); ?></span></td>
                <td>
                  <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo $status === 'Update available' ? xlt('Update') : xlt('Configure'); ?></button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt('Marketplace'); ?></h3>
        <span class="cnt"><?php echo count($marketplace); ?></span>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('MODULE'); ?></th>
              <th><?php echo xlt('VERSION'); ?></th>
              <th><?php echo xlt('VENDOR'); ?></th>
              <th><?php echo xlt('DESCRIPTION'); ?></th>
              <th><?php echo xlt('ACTION'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($marketplace as [$name, $icon, $ver, $vendor, $desc]): ?>
              <tr>
                <td>
                  <span class="cp-mod-row">
                    <span class="cp-mod-icon"><?php echo text($icon); ?></span>
                    <span class="bold"><?php echo text($name); ?></span>
                  </span>
                </td>
                <td class="muted"><?php echo text($ver); ?></td>
                <td class="muted"><?php echo text($vendor); ?></td>
                <td class="muted"><?php echo text($desc); ?></td>
                <td><button type="button" class="cp-btn primary"><?php echo xlt('Install'); ?></button></td>
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
