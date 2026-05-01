<?php

/**
 * Create Visit — Screen 29.
 * Visit-type picker + provider/facility/date/template + Start Visit CTA.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$visit_types = [
    ['🏥', 'Office Visit', 'In-person exam room', true],
    ['💻', 'Telehealth',   'Video or phone visit', false],
    ['🔬', 'Procedure',    'Minor procedure / surgery', false],
    ['👥', 'Group',        'Group therapy / education', false],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('New Visit'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-vt-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
  .cp-vt-card {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 12px;
    padding: 16px; display: flex; gap: 12px; align-items: flex-start;
  }
  .cp-vt-card.sel { border-color: #008C8C; border-width: 2px; }
  .cp-vt-icon { width: 40px; height: 40px; border-radius: 10px; background: #F5F6F7;
    display: inline-flex; align-items: center; justify-content: center; font-size: 18px; flex: 0 0 auto; }
  .cp-vt-card.sel .cp-vt-icon { background: #D6F0F0; }
  .cp-vt-info { flex: 1; }
  .cp-vt-info .n { font-weight: 700; font-size: 13px; }
  .cp-vt-card.sel .cp-vt-info .n { color: #008C8C; }
  .cp-vt-info .d { font-size: 11px; color: #8A91A1; margin-top: 2px; }
  .cp-vt-check { width: 18px; height: 18px; border-radius: 999px; background: #008C8C; color: #FFFFFF; font-size: 11px;
    display: inline-flex; align-items: center; justify-content: center; }
  .cp-form-grid { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
  .cp-form-grid.three { grid-template-columns: 1fr 1fr 1fr; }
  .cp-field { display: flex; flex-direction: column; gap: 5px; }
  .cp-field label { font-size: 11px; font-weight: 500; color: #4F5763; }
  .cp-cta-bar { display: flex; gap: 10px; justify-content: flex-end; margin-top: 12px; }
  .cp-hint {
    background: #F0FAFA; border: 1px solid #B3E0E0;
    border-radius: 10px; padding: 12px 14px;
    color: #008C8C; font-size: 12px; line-height: 1.5;
  }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('New Visit'); ?></span>
    <span class="meta"><?php echo xlt('Select visit type, then configure details to start the encounter.'); ?></span>
  </div>
</header>

<main class="cp-content tight">

  <div class="cp-vt-grid">
    <?php foreach ($visit_types as [$icon, $name, $desc, $sel]): ?>
      <div class="cp-vt-card <?php echo $sel ? 'sel' : ''; ?>">
        <div class="cp-vt-icon"><?php echo $icon; ?></div>
        <div class="cp-vt-info">
          <div class="n"><?php echo text($name); ?></div>
          <div class="d"><?php echo text($desc); ?></div>
        </div>
        <?php if ($sel): ?><span class="cp-vt-check">✓</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <section class="cp-section">
    <h2><?php echo xlt('Visit details'); ?></h2>
    <div class="cp-form-grid">
      <div class="cp-field"><label><?php echo xlt('Provider'); ?></label><select class="cp-input cp-select"><option>Dr. James Rivera, MD</option></select></div>
      <div class="cp-field"><label><?php echo xlt('Facility'); ?></label><select class="cp-input cp-select"><option>Riverside Family Medicine</option></select></div>
    </div>
    <div class="cp-form-grid three" style="margin-top:12px;">
      <div class="cp-field"><label><?php echo xlt('Date'); ?></label><input class="cp-input" type="text" value="2026-05-01"></div>
      <div class="cp-field"><label><?php echo xlt('Time'); ?></label><input class="cp-input" type="text" value="10:30 AM"></div>
      <div class="cp-field"><label><?php echo xlt('Duration'); ?></label><input class="cp-input" type="text" value="30 min"></div>
    </div>
    <div class="cp-form-grid" style="margin-top:12px;">
      <div class="cp-field"><label><?php echo xlt('Visit template'); ?></label><select class="cp-input cp-select"><option>Diabetes follow-up</option></select></div>
      <div class="cp-field"><label><?php echo xlt('Chief complaint'); ?></label><input class="cp-input" type="text" value="Follow-up for hypertension management"></div>
    </div>
  </section>

  <div class="cp-hint">✦ <?php echo xlt('Co-Pilot ready'); ?> — <?php echo xlt('Will pre-populate SOAP note from last visit and flag overdue orders.'); ?></div>

  <div class="cp-cta-bar">
    <button type="button" class="cp-btn ghost"><?php echo xlt('Cancel'); ?></button>
    <button type="button" class="cp-btn primary"><?php echo xlt('Start Visit'); ?> →</button>
  </div>

</main>

</body>
</html>
