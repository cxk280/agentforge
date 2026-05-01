<?php

/**
 * New / Search Patient — implements Screen 22 of the AgentForge mockups.
 *
 * Two-column iframe content: LEFT pane is "Find existing patient" with
 * search input, status tab pills, and a RECENT-patients list keyed by
 * colored avatar bubbles. RIGHT pane is "Create new patient" — Identity,
 * Contact, Insurance, Provider sections — with Save-as-draft / Create
 * patient CTAs.
 *
 * The chrome (top nav, search bar, avatar) is rendered by the parent
 * shell. This page renders only the body.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$status_tabs = [
    ['All',         true],
    ['Active',      false],
    ['Last 7 days', false],
    ['Inactive',    false],
];

// Avatar tone tokens — match the Figma palette.
$recent = [
    ['name' => 'Margaret Chen',   'mrn' => '#004821', 'dob' => '03/14/1958', 'when' => 'Today',     'tone' => 'teal'],
    ['name' => 'Ted Shaw',        'mrn' => '#001',    'dob' => '03/12/1965', 'when' => 'Today',     'tone' => 'blue'],
    ['name' => 'Linda Martinez',  'mrn' => '#003918', 'dob' => '11/02/1947', 'when' => 'Yesterday', 'tone' => 'purple'],
    ['name' => 'David Kim',       'mrn' => '#006102', 'dob' => '06/18/1981', 'when' => 'Apr 28',    'tone' => 'orange'],
    ['name' => 'Allison Park',    'mrn' => '#002745', 'dob' => '09/30/1973', 'when' => 'Apr 26',    'tone' => 'green'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('New / Search Patient'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F6F7;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }
  button { font-family: inherit; cursor: pointer; }
  input, select { font-family: inherit; }

  /* Page header */
  .cp-np-head {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    height: 60px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-np-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-np-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-np-meta { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-np-spacer { flex: 1; }
  .cp-np-help {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px; font-weight: 500;
    color: #0D1B2A;
    line-height: 1;
  }
  .cp-np-help:hover { background: #F5F6F7; }

  /* Layout */
  .cp-np-body {
    padding: 20px 24px 32px;
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 16px;
  }

  /* Panel */
  .cp-panel {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 20px 20px 24px;
  }
  .cp-panel-lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.6px;
    line-height: 1;
    margin-bottom: 12px;
  }

  /* Find existing — search */
  .cp-search-wrap {
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 38px;
    display: flex; align-items: center;
    padding: 0 12px;
    margin-bottom: 12px;
  }
  .cp-search-wrap .ic { color: #8A91A1; font-size: 13px; margin-right: 8px; }
  .cp-search-wrap input {
    border: none; background: transparent; outline: none;
    flex: 1; min-width: 0;
    font-size: 13px; color: #0D1B2A;
  }
  .cp-search-wrap input::placeholder { color: #8A91A1; }

  /* Status tabs */
  .cp-tabs { display: flex; gap: 6px; margin-bottom: 18px; }
  .cp-tabs button {
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 11px; font-weight: 500;
    line-height: 1;
    background: #FFFFFF;
    color: #4F5763;
    border: 1px solid #E4E5E8;
  }
  .cp-tabs button.active {
    background: #FFFFFF;
    color: #008C8C;
    border-color: #008C8C;
  }

  /* Section sub-label */
  .cp-recent-lbl {
    font-size: 10px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.6px;
    line-height: 1;
    margin: 4px 0 10px;
  }

  /* Recent list */
  .cp-rec-list { display: flex; flex-direction: column; gap: 4px; }
  .cp-rec-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 6px;
    border-radius: 8px;
    cursor: pointer;
  }
  .cp-rec-row:hover { background: #F5F6F7; }
  .cp-rec-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    flex: 0 0 auto;
  }
  .cp-rec-avatar.teal   { background: #008C8C; }
  .cp-rec-avatar.blue   { background: #4785D9; }
  .cp-rec-avatar.purple { background: #8561C7; }
  .cp-rec-avatar.orange { background: #FA8C33; }
  .cp-rec-avatar.green  { background: #33A666; }
  .cp-rec-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
  .cp-rec-name { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cp-rec-sub  { font-size: 11px; color: #8A91A1; line-height: 1.2; }
  .cp-rec-when { font-size: 11px; color: #8A91A1; flex: 0 0 auto; }

  /* Form section */
  .cp-form-section { margin-bottom: 22px; }
  .cp-form-section h3 {
    font-size: 14px; font-weight: 700;
    color: #0D1B2A;
    margin: 0 0 12px;
    line-height: 1;
  }
  .cp-form-grid {
    display: grid; gap: 12px 14px;
  }
  .cp-form-grid.two   { grid-template-columns: 1fr 1fr; }
  .cp-form-grid.three { grid-template-columns: 1fr 1fr 1fr; }
  .cp-form-grid.full  { grid-template-columns: 1fr; }

  .cp-field { display: flex; flex-direction: column; gap: 5px; }
  .cp-field label {
    font-size: 11px; font-weight: 500; color: #4F5763;
    line-height: 1;
  }
  .cp-input {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 38px;
    padding: 0 12px;
    font-size: 13px; color: #0D1B2A;
    outline: none;
  }
  .cp-input:focus { border-color: #008C8C; }
  .cp-select {
    appearance: none; -webkit-appearance: none;
    background-image:
      linear-gradient(45deg, transparent 50%, #8A91A1 50%),
      linear-gradient(135deg, #8A91A1 50%, transparent 50%);
    background-position:
      calc(100% - 14px) calc(50% - 1px),
      calc(100% - 9px) calc(50% - 1px);
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat;
    padding-right: 28px;
  }

  /* Footer CTA */
  .cp-form-foot {
    display: flex; align-items: center; justify-content: flex-end;
    gap: 10px;
    margin-top: 4px;
  }
  .cp-btn {
    border-radius: 999px;
    padding: 9px 18px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    border: 1px solid transparent;
  }
  .cp-btn.secondary {
    background: #FFFFFF; color: #0D1B2A; border-color: #E4E5E8;
    font-weight: 500;
  }
  .cp-btn.secondary:hover { background: #F5F6F7; }
  .cp-btn.primary { background: #008C8C; color: #FFFFFF; }
  .cp-btn.primary:hover { background: #00787A; }
</style>
</head>
<body>

<header class="cp-np-head">
  <div class="cp-np-title"><?php echo xlt('New / Search Patient'); ?></div>
  <div class="cp-np-bullet">•</div>
  <div class="cp-np-meta"><?php echo xlt('Create or look up a patient record'); ?></div>
  <div class="cp-np-spacer"></div>
  <button type="button" class="cp-np-help">? <?php echo xlt('Help'); ?></button>
</header>

<main class="cp-np-body">

  <!-- LEFT — find existing -->
  <section class="cp-panel cp-panel-find">
    <div class="cp-panel-lbl"><?php echo xlt('FIND EXISTING PATIENT'); ?></div>

    <div class="cp-search-wrap">
      <span class="ic">🔍</span>
      <input type="text" placeholder="<?php echo xla('Name, MRN, DOB, phone, or email'); ?>">
    </div>

    <div class="cp-tabs">
      <?php foreach ($status_tabs as [$label, $active]): ?>
        <button type="button" class="<?php echo $active ? 'active' : ''; ?>"><?php echo text($label); ?></button>
      <?php endforeach; ?>
    </div>

    <div class="cp-recent-lbl"><?php echo xlt('RECENT'); ?></div>

    <div class="cp-rec-list">
      <?php foreach ($recent as $r): ?>
        <div class="cp-rec-row">
          <div class="cp-rec-avatar <?php echo attr($r['tone']); ?>"></div>
          <div class="cp-rec-info">
            <div class="cp-rec-name"><?php echo text($r['name']); ?></div>
            <div class="cp-rec-sub">MRN <?php echo text($r['mrn']); ?> • DOB <?php echo text($r['dob']); ?></div>
          </div>
          <div class="cp-rec-when"><?php echo text($r['when']); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- RIGHT — create new -->
  <section class="cp-panel cp-panel-create">
    <div class="cp-panel-lbl"><?php echo xlt('CREATE NEW PATIENT'); ?></div>

    <div class="cp-form-section">
      <h3><?php echo xlt('Identity'); ?></h3>
      <div class="cp-form-grid two">
        <div class="cp-field">
          <label><?php echo xlt('First name'); ?></label>
          <input class="cp-input" type="text" value="Margaret">
        </div>
        <div class="cp-field">
          <label><?php echo xlt('Last name'); ?></label>
          <input class="cp-input" type="text" value="Chen">
        </div>
        <div class="cp-field">
          <label><?php echo xlt('Date of birth'); ?></label>
          <input class="cp-input" type="text" value="03/14/1958">
        </div>
        <div class="cp-field">
          <label><?php echo xlt('Sex'); ?></label>
          <select class="cp-input cp-select">
            <option>Female</option>
            <option>Male</option>
            <option>Other</option>
          </select>
        </div>
      </div>
    </div>

    <div class="cp-form-section">
      <h3><?php echo xlt('Contact'); ?></h3>
      <div class="cp-form-grid two">
        <div class="cp-field">
          <label><?php echo xlt('Phone'); ?></label>
          <input class="cp-input" type="text" value="(512) 555-0142">
        </div>
        <div class="cp-field">
          <label><?php echo xlt('Email'); ?></label>
          <input class="cp-input" type="email" value="m.chen@example.com">
        </div>
      </div>
      <div class="cp-form-grid full" style="margin-top: 12px;">
        <div class="cp-field">
          <label><?php echo xlt('Address'); ?></label>
          <input class="cp-input" type="text" value="847 Main Street, Suite 200, Austin, TX 78701">
        </div>
      </div>
    </div>

    <div class="cp-form-section">
      <h3><?php echo xlt('Insurance'); ?></h3>
      <div class="cp-form-grid three">
        <div class="cp-field">
          <label><?php echo xlt('Plan'); ?></label>
          <select class="cp-input cp-select">
            <option>Blue Cross Blue Shield PPO</option>
            <option>Aetna HMO</option>
            <option>Self-pay</option>
          </select>
        </div>
        <div class="cp-field">
          <label><?php echo xlt('Group #'); ?></label>
          <input class="cp-input" type="text" value="BCBS-7281">
        </div>
        <div class="cp-field">
          <label><?php echo xlt('Member ID'); ?></label>
          <input class="cp-input" type="text" value="4QF23-991">
        </div>
      </div>
    </div>

    <div class="cp-form-section">
      <h3><?php echo xlt('Provider'); ?></h3>
      <div class="cp-form-grid two">
        <div class="cp-field">
          <label><?php echo xlt('Primary provider'); ?></label>
          <select class="cp-input cp-select">
            <option>Dr. Eduardo Rivera, MD</option>
            <option>Dr. Allison Park, DO</option>
            <option>Dr. James Patel, MD</option>
          </select>
        </div>
        <div class="cp-field">
          <label><?php echo xlt('Facility'); ?></label>
          <select class="cp-input cp-select">
            <option>Riverside Family Medicine</option>
            <option>Eastside Clinic</option>
          </select>
        </div>
      </div>
    </div>

    <div class="cp-form-foot">
      <button type="button" class="cp-btn secondary"><?php echo xlt('Save as draft'); ?></button>
      <button type="button" class="cp-btn primary"><?php echo xlt('Create patient'); ?>  →</button>
    </div>
  </section>

</main>

</body>
</html>
