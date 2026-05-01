<?php

/**
 * Patient Education lookup — Screen 48.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$topics = [
    ['Type 2 Diabetes', 'Living with diabetes — diet, exercise, and monitoring', 'MedlinePlus', 'EN/ES', 'Assigned', 'good'],
    ['Hypertension',    'Lifestyle changes to manage blood pressure',            'MedlinePlus', 'EN/ES', 'Assigned', 'good'],
    ['Lisinopril',      'How to take ACE inhibitors safely',                     'NIH',         'EN',    'Available', 'info'],
    ['Hypothyroidism',  'Understanding your thyroid medication',                 'AAFP',        'EN',    'Available', 'info'],
    ['HbA1c basics',    'What your A1C number means',                            'ADA',         'EN/ES', 'Available', 'info'],
    ['Mediterranean diet','Heart-healthy eating patterns',                       'AHA',         'EN',    'Available', 'info'],
    ['Smoking cessation','Resources to quit smoking',                            'CDC',         'EN/ES', 'Not relevant','neutral'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Education'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Patient Education'); ?></span>
    <span class="meta">2 <?php echo xlt('assigned'); ?> • 4 <?php echo xlt('available based on diagnoses'); ?></span>
  </div>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Send to portal'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-filter">
    <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search topics, conditions, medications...'); ?>"></div>
    <div class="pills">
      <button type="button" class="active"><?php echo xlt('All'); ?></button>
      <button type="button"><?php echo xlt('Conditions'); ?></button>
      <button type="button"><?php echo xlt('Medications'); ?></button>
      <button type="button"><?php echo xlt('Procedures'); ?></button>
      <button type="button"><?php echo xlt('Lifestyle'); ?></button>
    </div>
  </div>

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('TOPIC'); ?></th>
          <th><?php echo xlt('DESCRIPTION'); ?></th>
          <th><?php echo xlt('SOURCE'); ?></th>
          <th><?php echo xlt('LANG'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topics as [$t, $d, $src, $lang, $status, $tone]): ?>
          <tr>
            <td class="bold"><?php echo text($t); ?></td>
            <td><?php echo text($d); ?></td>
            <td class="muted"><?php echo text($src); ?></td>
            <td class="muted"><?php echo text($lang); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
            <td>
              <?php if ($status === 'Assigned'): ?>
                <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Preview'); ?></button>
              <?php else: ?>
                <button type="button" class="cp-btn primary" style="padding:5px 10px;"><?php echo xlt('Assign'); ?></button>
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
