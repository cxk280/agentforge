<?php

/**
 * e-Rx — implements Screen 25 of the AgentForge mockups.
 *
 * Two-column iframe content. LEFT: Drug Search results + Current
 * Medications list. RIGHT: prescription detail form (strength, dosage,
 * sig, quantity, refills, pharmacy, DAW, effective date, internal note)
 * with safety check and allergy/interaction summary cards below.
 *
 * The chrome (top nav, demographics banner) is rendered by the parent
 * shell — this page renders only the body.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$drug_search = [
    ['Lisinopril 10 mg tablet',    true],
    ['Lisinopril 20 mg tablet',    false],
    ['Lisinopril-HCTZ 10/12.5 mg', false],
    ['Lisinopril 5 mg tablet',     false],
];

$current_meds = [
    ['Metformin',     '1000 mg', 'BID with meals',       'Active'],
    ['Lisinopril',    '10 mg',   'Daily — increased 04/01', 'Active'],
    ['Levothyroxine', '50 mcg',  'Daily, AM',            'Active'],
    ['Atorvastatin',  '40 mg',   'Nightly',              'Active'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('e-Rx'); ?></title>
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
  input, select, textarea { font-family: inherit; }

  /* Header */
  .cp-rx-head {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 14px 24px;
    display: flex; align-items: center; gap: 10px;
  }
  .cp-rx-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-rx-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-rx-meta { color: #4F5763; font-size: 12px; line-height: 1; }
  .cp-rx-spacer { flex: 1; }
  .cp-rx-epcs {
    background: rgba(51, 166, 102, 0.14);
    color: #1F8C4D;
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 11px; font-weight: 600;
    line-height: 1.2;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-rx-epcs .ic { font-size: 11px; }
  .cp-rx-btn {
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    border: 1px solid transparent;
  }
  .cp-rx-btn.ghost { background: #FFFFFF; color: #4F5763; border-color: #E4E5E8; font-weight: 500; }
  .cp-rx-btn.primary { background: #008C8C; color: #FFFFFF; }

  /* Body */
  .cp-rx-body {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 16px;
    padding: 20px 24px 32px;
  }
  .cp-panel {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px 20px 20px;
  }
  .cp-panel-lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.6px;
    line-height: 1;
    margin-bottom: 12px;
  }
  .cp-panel-head {
    display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
  }
  .cp-panel-head h3 { font-size: 13px; font-weight: 700; color: #0D1B2A; margin: 0; line-height: 1; }
  .cp-panel-head .cnt {
    background: #F5F6F7; color: #4F5763;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 600;
  }
  .cp-panel-head .right {
    margin-left: auto;
    font-size: 11px; color: #008C8C; font-weight: 500;
    background: none; border: none;
  }

  /* Drug search list */
  .cp-rx-search {
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 38px;
    display: flex; align-items: center;
    padding: 0 12px;
    margin-bottom: 12px;
  }
  .cp-rx-search .ic { color: #8A91A1; font-size: 13px; margin-right: 8px; }
  .cp-rx-search input {
    border: none; background: transparent; outline: none;
    flex: 1; font-size: 13px; color: #0D1B2A;
  }

  .cp-drug-list { display: flex; flex-direction: column; }
  .cp-drug-row {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 8px;
    border-radius: 8px;
    font-size: 12px;
  }
  .cp-drug-row + .cp-drug-row { border-top: 1px solid #F0F1F3; }
  .cp-drug-row.selected { background: #F0FAFA; }
  .cp-drug-row .pill-ic {
    font-size: 11px;
    flex: 0 0 auto;
  }
  .cp-drug-row .name { flex: 1; color: #0D1B2A; }
  .cp-drug-row.selected .name { color: #008C8C; font-weight: 600; }
  .cp-drug-row .badge {
    background: #008C8C; color: #FFFFFF;
    border-radius: 999px;
    padding: 3px 9px;
    font-size: 10px; font-weight: 600;
  }

  /* Current meds */
  .cp-meds-list { display: flex; flex-direction: column; }
  .cp-meds-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 0;
    font-size: 12px;
  }
  .cp-meds-row + .cp-meds-row { border-top: 1px solid #F0F1F3; }
  .cp-meds-row .pill-ic { font-size: 11px; flex: 0 0 auto; }
  .cp-meds-info { flex: 1; min-width: 0; }
  .cp-meds-info .top { display: inline-flex; align-items: baseline; gap: 6px; }
  .cp-meds-info .top .n { font-weight: 600; color: #0D1B2A; }
  .cp-meds-info .top .d { color: #008C8C; font-weight: 500; font-size: 11px; }
  .cp-meds-info .sub { color: #8A91A1; font-size: 11px; margin-top: 2px; }
  .cp-meds-row .status {
    background: rgba(51, 166, 102, 0.14);
    color: #1F8C4D;
    border-radius: 999px;
    padding: 3px 9px;
    font-size: 10px; font-weight: 600;
  }

  /* Selected drug header strip */
  .cp-rx-drug {
    background: #F0FAFA;
    border: 1px solid #B3E0E0;
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 16px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-rx-drug .ic { font-size: 16px; }
  .cp-rx-drug .info { flex: 1; }
  .cp-rx-drug .info .n { font-weight: 700; color: #0D1B2A; font-size: 14px; }
  .cp-rx-drug .info .b { color: #4F5763; font-size: 11px; margin-top: 2px; }
  .cp-rx-drug a {
    color: #008C8C; font-weight: 600; font-size: 11px;
    text-decoration: none;
  }

  /* Form */
  .cp-rx-form { display: grid; gap: 14px 14px; grid-template-columns: 1fr 1fr; }
  .cp-rx-form .full { grid-column: 1 / -1; }
  .cp-rx-form .col3 { grid-column: span 1; }
  .cp-rx-form .grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; grid-column: 1 / -1; }
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
  .cp-input.ta { height: 60px; padding: 8px 12px; resize: none; }

  /* Safety / allergy cards */
  .cp-rx-safety { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }
  .cp-safety-card {
    border-radius: 10px;
    padding: 12px 14px;
    border: 1px solid;
  }
  .cp-safety-card.warn {
    background: #FFF8EC;
    border-color: #FACA7A;
  }
  .cp-safety-card.warn .head {
    color: #FA8C33; font-weight: 700; font-size: 11px;
    letter-spacing: 0.4px;
    margin-bottom: 4px;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-safety-card.ok {
    background: #EBF8F0;
    border-color: #B6E0C5;
  }
  .cp-safety-card.ok .head {
    color: #1F8C4D; font-weight: 700; font-size: 11px;
    letter-spacing: 0.4px;
    margin-bottom: 4px;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-safety-card .body { font-size: 12px; color: #0D1B2A; line-height: 1.5; }
</style>
</head>
<body>

<header class="cp-rx-head">
  <div class="cp-rx-title"><?php echo xlt('e-Rx'); ?></div>
  <div class="cp-rx-bullet">•</div>
  <div class="cp-rx-meta"><?php echo xlt('New prescription'); ?></div>
  <div class="cp-rx-spacer"></div>
  <span class="cp-rx-epcs"><span class="ic">🛡</span> EPCS active</span>
  <button type="button" class="cp-rx-btn ghost" style="margin-left: 12px;"><?php echo xlt('Save draft'); ?></button>
  <button type="button" class="cp-rx-btn primary"><?php echo xlt('Send to pharmacy'); ?> →</button>
</header>

<main class="cp-rx-body">

  <!-- LEFT — drug search & current meds -->
  <div style="display:flex; flex-direction:column; gap:16px;">

    <section class="cp-panel">
      <div class="cp-panel-lbl"><?php echo xlt('DRUG SEARCH'); ?></div>
      <div class="cp-rx-search">
        <span class="ic">🔍</span>
        <input type="text" value="lisinop">
      </div>
      <div class="cp-drug-list">
        <?php foreach ($drug_search as [$name, $sel]): ?>
          <div class="cp-drug-row <?php echo $sel ? 'selected' : ''; ?>">
            <span class="pill-ic">💊</span>
            <span class="name"><?php echo text($name); ?></span>
            <?php if ($sel): ?><span class="badge"><?php echo xlt('Selected'); ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="cp-panel">
      <div class="cp-panel-head">
        <h3><?php echo xlt('Current Medications'); ?></h3>
        <span class="cnt">4</span>
        <button type="button" class="right">⌖ <?php echo xlt('History'); ?></button>
      </div>
      <div class="cp-meds-list">
        <?php foreach ($current_meds as [$name, $dose, $freq, $st]): ?>
          <div class="cp-meds-row">
            <span class="pill-ic">💊</span>
            <div class="cp-meds-info">
              <div class="top"><span class="n"><?php echo text($name); ?></span><span class="d"><?php echo text($dose); ?></span></div>
              <div class="sub"><?php echo text($freq); ?></div>
            </div>
            <span class="status">● <?php echo text($st); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <!-- RIGHT — Rx detail form -->
  <section class="cp-panel">
    <div class="cp-rx-drug">
      <span class="ic">💊</span>
      <div class="info">
        <div class="n">Lisinopril 10 mg tablet</div>
        <div class="b">Brand: Prinivil/Zestril • Generic OK</div>
      </div>
      <a href="#"><?php echo xlt('Change drug'); ?></a>
    </div>

    <div class="cp-rx-form">
      <div class="cp-field">
        <label><?php echo xlt('Strength'); ?></label>
        <input class="cp-input" type="text" value="10 mg">
      </div>
      <div class="cp-field">
        <label><?php echo xlt('Dosage form'); ?></label>
        <input class="cp-input" type="text" value="Tablet">
      </div>

      <div class="cp-field full">
        <label><?php echo xlt('Sig (instructions to patient)'); ?></label>
        <input class="cp-input" type="text" value="Take 1 tablet by mouth once daily for blood pressure">
      </div>

      <div class="grid3">
        <div class="cp-field">
          <label><?php echo xlt('Quantity'); ?></label>
          <input class="cp-input" type="text" value="90">
        </div>
        <div class="cp-field">
          <label><?php echo xlt('Days supply'); ?></label>
          <input class="cp-input" type="text" value="90">
        </div>
        <div class="cp-field">
          <label><?php echo xlt('Refills'); ?></label>
          <input class="cp-input" type="text" value="3">
        </div>
      </div>

      <div class="cp-field">
        <label><?php echo xlt('Pharmacy'); ?></label>
        <input class="cp-input" type="text" value="CVS — 4500 Burnet Rd, Austin TX">
      </div>
      <div class="cp-field">
        <label><?php echo xlt('Delivery'); ?></label>
        <input class="cp-input" type="text" value="Pickup">
      </div>

      <div class="cp-field">
        <label>DAW</label>
        <input class="cp-input" type="text" value="No (allow generic)">
      </div>
      <div class="cp-field">
        <label><?php echo xlt('Effective date'); ?></label>
        <input class="cp-input" type="text" value="04/29/2026">
      </div>

      <div class="cp-field full">
        <label><?php echo xlt('Internal note'); ?></label>
        <textarea class="cp-input ta"></textarea>
      </div>
    </div>

    <div class="cp-rx-safety">
      <div class="cp-safety-card warn">
        <div class="head">⚠ 1 SAFETY CHECK</div>
        <div class="body">Dose increased from 5 mg → 10 mg on 04/01/2026 — confirm titration plan and 6-week recheck order.</div>
      </div>
      <div class="cp-safety-card ok">
        <div class="head">✓ NO ALLERGIES OR INTERACTIONS DETECTED</div>
        <div class="body">Lisinopril checked against Penicillin allergy and current medication list. No conflicts.</div>
      </div>
    </div>
  </section>

</main>

</body>
</html>
