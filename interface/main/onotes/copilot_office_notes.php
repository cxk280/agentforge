<?php

/**
 * Office Notes — Screen 49.
 * Internal staff notes (not part of the patient chart).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$notes = [
    ['CK', 'orange', 'Christopher King', '09:42 AM',  'Front desk: BCBS PPO is denying CPT 99396 — verify modifier 25 attached.'],
    ['ER', 'teal',   'Dr. E. Rivera',    '08:55 AM',  'Pull Margaret Chen prior eye exam before next visit. Endo flagged.'],
    ['MN', 'mint',   'Maria Nunez',      'Yesterday', 'Voicemail from Linda Martinez requesting MRI auth update — left callback.'],
    ['BH', 'green',  'Brian Hudson',     'Yesterday', 'Carol Bennett payment plan: $150/mo agreed. First payment posted.'],
    ['CK', 'orange', 'Christopher King', '2 days ago','Network issue 1pm-1:15pm — confirmed iframe shell auth flow recovered.'],
    ['ER', 'teal',   'Dr. E. Rivera',    '3 days ago','Refill protocol updated: 90-day supply standard for Lisinopril, A1C-stable patients.'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Office Notes'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-on-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 18px;
    display: flex; gap: 12px;
    margin-bottom: 8px;
  }
  .cp-on-card .info { flex: 1; min-width: 0; }
  .cp-on-card .head { display: flex; gap: 8px; align-items: baseline; }
  .cp-on-card .name { font-weight: 600; color: #0D1B2A; font-size: 13px; }
  .cp-on-card .when { font-size: 11px; color: #8A91A1; }
  .cp-on-card .body { font-size: 13px; color: #0D1B2A; margin-top: 6px; line-height: 1.5; }
  .cp-on-compose {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 12px;
    padding: 14px 18px; margin-bottom: 12px;
  }
  .cp-on-compose textarea {
    width: 100%; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 10px 12px; font-size: 13px; min-height: 64px; outline: none; resize: vertical;
  }
  .cp-on-compose-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Office Notes'); ?></span>
    <span class="meta"><?php echo xlt('Internal staff notes — not part of patient chart'); ?></span>
  </div>
</header>

<main class="cp-content tight">

  <div class="cp-on-compose">
    <textarea placeholder="<?php echo xla('Write a note for the office...'); ?>"></textarea>
    <div class="cp-on-compose-row">
      <span class="cp-status-pill neutral"><?php echo xlt('Visible to: Front Desk, Billing, Admin'); ?></span>
      <span style="flex:1;"></span>
      <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Cancel'); ?></button>
      <button type="button" class="cp-btn primary"><?php echo xlt('Post note'); ?></button>
    </div>
  </div>

  <?php foreach ($notes as [$ini, $tone, $name, $when, $body]): ?>
    <div class="cp-on-card">
      <span class="cp-avatar <?php echo attr($tone); ?>"><?php echo text($ini); ?></span>
      <div class="info">
        <div class="head">
          <span class="name"><?php echo text($name); ?></span>
          <span class="when"><?php echo text($when); ?></span>
        </div>
        <div class="body"><?php echo text($body); ?></div>
      </div>
    </div>
  <?php endforeach; ?>

</main>

</body>
</html>
