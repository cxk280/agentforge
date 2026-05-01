<?php

/**
 * Fee Sheet — implements Screen 24 of the AgentForge mockups.
 *
 * Two-column iframe content. LEFT: Code Picker with search, category
 * filter pills, and a popular-codes list (CPT codes with prices and
 * Add buttons). RIGHT: Selected for this visit summary with CPT/ICD
 * line items and a totals box.
 *
 * The chrome (top nav, demographics banner) is rendered by the parent
 * shell — this page renders only the body.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$cat_pills = [
    ['Office Visits',  true],
    ['Labs',           false],
    ['Procedures',     false],
    ['Diagnostics',    false],
    ['Vaccinations',   false],
    ['Codes (ICD-10)', false],
];

$codes = [
    ['99211', 'Established patient, minimal',     '$45.00',  'add'],
    ['99212', 'Established patient, level 2',     '$98.00',  'add'],
    ['99213', 'Established patient, level 3',     '$152.00', 'added'],
    ['99214', 'Established patient, level 4',     '$215.00', 'add'],
    ['99215', 'Established patient, level 5',     '$310.00', 'add'],
    ['99396', 'Annual wellness, age 40-64',       '$280.00', 'add'],
    ['99397', 'Annual wellness, age 65+',         '$305.00', 'add'],
    ['80050', 'Comprehensive metabolic panel',    '$184.00', 'add'],
];

$selected = [
    ['CPT', '99213', 'Office visit, level 3', 'Qty: 1', '$152.00'],
    ['CPT', '36415', 'Venipuncture',          'Qty: 1', '$15.00'],
    ['ICD', 'E11.9', 'Type 2 Diabetes Mellitus', '', ''],
    ['ICD', 'I10',   'Essential hypertension',    '', ''],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Fee Sheet'); ?></title>
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

  /* Header */
  .cp-fs-head {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 14px 24px;
    display: flex; align-items: center; gap: 10px;
  }
  .cp-fs-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-fs-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-fs-meta { color: #4F5763; font-size: 12px; line-height: 1; }
  .cp-fs-spacer { flex: 1; }
  .cp-fs-subtotal { display: inline-flex; align-items: baseline; gap: 6px; margin-right: 12px; }
  .cp-fs-subtotal .l { font-size: 11px; color: #8A91A1; font-weight: 500; }
  .cp-fs-subtotal .v { font-size: 16px; font-weight: 700; color: #0D1B2A; }
  .cp-fs-btn {
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    border: 1px solid transparent;
  }
  .cp-fs-btn.ghost { background: #FFFFFF; color: #4F5763; border-color: #E4E5E8; font-weight: 500; }
  .cp-fs-btn.ghost:hover { background: #F5F6F7; }
  .cp-fs-btn.primary { background: #008C8C; color: #FFFFFF; }
  .cp-fs-btn.primary:hover { background: #00787A; }

  /* Body */
  .cp-fs-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
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
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 14px;
  }
  .cp-panel-head h3 { font-size: 14px; font-weight: 700; color: #0D1B2A; margin: 0; line-height: 1; }
  .cp-panel-head .cnt {
    background: #F5F6F7; color: #4F5763;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 600;
  }

  /* Search */
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
    flex: 1; font-size: 13px; color: #0D1B2A;
  }
  .cp-search-wrap input::placeholder { color: #8A91A1; }

  /* Category pills */
  .cp-cats { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
  .cp-cats button {
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 11px; font-weight: 500;
    line-height: 1.2;
    background: #FFFFFF;
    color: #4F5763;
    border: 1px solid #E4E5E8;
  }
  .cp-cats button.active {
    background: #FFFFFF;
    color: #008C8C;
    border-color: #008C8C;
  }

  .cp-cat-sub {
    font-size: 10px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
    line-height: 1;
  }

  /* Code list */
  .cp-code-list { display: flex; flex-direction: column; }
  .cp-code-row {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 8px;
    border-radius: 8px;
    font-size: 12px;
  }
  .cp-code-row + .cp-code-row { border-top: 1px solid #F0F1F3; }
  .cp-code-row.selected { background: #F0FAFA; }
  .cp-code-row .code {
    color: #4F5763; font-weight: 500;
    flex: 0 0 50px;
  }
  .cp-code-row .label { flex: 1; color: #0D1B2A; }
  .cp-code-row .price { color: #4F5763; font-weight: 500; flex: 0 0 70px; text-align: right; }

  .cp-add-btn {
    border-radius: 999px;
    padding: 4px 11px;
    font-size: 11px; font-weight: 500;
    line-height: 1.2;
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    color: #008C8C;
  }
  .cp-add-btn.added {
    background: #D6F0F0; color: #008C8C;
    border-color: #008C8C; font-weight: 600;
  }
  .cp-add-btn:hover:not(.added) { background: #F5F6F7; }

  /* Selected panel */
  .cp-sel-list { display: flex; flex-direction: column; gap: 4px; margin-bottom: 18px; }
  .cp-sel-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 0;
    font-size: 12px;
  }
  .cp-sel-row + .cp-sel-row { border-top: 1px solid #F0F1F3; }
  .cp-sel-row .tag {
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 10px; font-weight: 600;
    flex: 0 0 32px; text-align: center;
  }
  .cp-sel-row .tag.cpt { background: #F0F4F9; color: #4785D9; }
  .cp-sel-row .tag.icd { background: #F0FAFA; color: #008C8C; }
  .cp-sel-row .code {
    font-weight: 500; color: #4F5763;
    flex: 0 0 56px;
  }
  .cp-sel-row .label { flex: 1; color: #0D1B2A; }
  .cp-sel-row .qty { color: #8A91A1; font-size: 11px; flex: 0 0 56px; text-align: right; }
  .cp-sel-row .price { color: #0D1B2A; font-weight: 500; flex: 0 0 70px; text-align: right; }
  .cp-sel-row .x {
    background: none; border: none; color: #8A91A1; font-size: 14px;
    padding: 4px;
  }
  .cp-sel-row .x:hover { color: #D93838; }

  .cp-totals { border-top: 1px solid #E4E5E8; padding-top: 14px; }
  .cp-totals-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 4px 0;
    font-size: 12px;
    color: #4F5763;
  }
  .cp-totals-row .v { font-weight: 500; color: #0D1B2A; }
  .cp-totals-row.neg .v { color: #4F5763; }
  .cp-totals-row.total {
    border-top: 1px solid #E4E5E8;
    margin-top: 4px;
    padding-top: 10px;
    font-size: 14px;
    font-weight: 700;
    color: #0D1B2A;
  }
  .cp-totals-row.total .v { font-size: 16px; }
</style>
</head>
<body>

<header class="cp-fs-head">
  <div class="cp-fs-title"><?php echo xlt('Fee Sheet'); ?></div>
  <div class="cp-fs-bullet">•</div>
  <div class="cp-fs-meta"><?php echo xlt("Today's visit"); ?> • <?php echo xlt('Charges and CPT/ICD selection'); ?></div>
  <div class="cp-fs-spacer"></div>
  <div class="cp-fs-subtotal">
    <span class="l"><?php echo xlt('Subtotal'); ?></span>
    <span class="v">$152.00</span>
  </div>
  <button type="button" class="cp-fs-btn ghost"><?php echo xlt('Save draft'); ?></button>
  <button type="button" class="cp-fs-btn primary"><?php echo xlt('Save & close'); ?> →</button>
</header>

<main class="cp-fs-body">

  <section class="cp-panel">
    <div class="cp-panel-lbl"><?php echo xlt('CODE PICKER'); ?></div>

    <div class="cp-search-wrap">
      <span class="ic">🔍</span>
      <input type="text" placeholder="<?php echo xla('Search by code, name, or CPT...'); ?>">
    </div>

    <div class="cp-cats">
      <?php foreach ($cat_pills as [$lbl, $active]): ?>
        <button type="button" class="<?php echo $active ? 'active' : ''; ?>"><?php echo text($lbl); ?></button>
      <?php endforeach; ?>
    </div>

    <div class="cp-cat-sub"><?php echo xlt('POPULAR FOR PRIMARY CARE'); ?></div>

    <div class="cp-code-list">
      <?php foreach ($codes as [$code, $label, $price, $state]): ?>
        <div class="cp-code-row <?php echo $state === 'added' ? 'selected' : ''; ?>">
          <span class="code"><?php echo text($code); ?></span>
          <span class="label"><?php echo text($label); ?></span>
          <span class="price"><?php echo text($price); ?></span>
          <button type="button" class="cp-add-btn <?php echo $state === 'added' ? 'added' : ''; ?>">
            <?php echo $state === 'added' ? '✓ ' . xlt('Added') : '+ ' . xlt('Add'); ?>
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="cp-panel">
    <div class="cp-panel-head">
      <h3><?php echo xlt('Selected for this visit'); ?></h3>
      <span class="cnt">4</span>
    </div>

    <div class="cp-sel-list">
      <?php foreach ($selected as [$type, $code, $label, $qty, $price]): ?>
        <div class="cp-sel-row">
          <span class="tag <?php echo strtolower($type); ?>"><?php echo text($type); ?></span>
          <span class="code"><?php echo text($code); ?></span>
          <span class="label"><?php echo text($label); ?></span>
          <?php if ($qty): ?><span class="qty"><?php echo text($qty); ?></span><?php endif; ?>
          <?php if ($price): ?><span class="price"><?php echo text($price); ?></span><?php endif; ?>
          <button type="button" class="x">✕</button>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="cp-totals">
      <div class="cp-totals-row">
        <span><?php echo xlt('Subtotal'); ?></span>
        <span class="v">$167.00</span>
      </div>
      <div class="cp-totals-row neg">
        <span><?php echo xlt('Insurance estimate'); ?></span>
        <span class="v">−$142.00</span>
      </div>
      <div class="cp-totals-row">
        <span><?php echo xlt('Patient responsibility'); ?></span>
        <span class="v">$25.00</span>
      </div>
      <div class="cp-totals-row total">
        <span><?php echo xlt('Total billed'); ?></span>
        <span class="v">$167.00</span>
      </div>
    </div>
  </section>

</main>

</body>
</html>
