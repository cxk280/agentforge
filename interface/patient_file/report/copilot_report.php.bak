<?php

/**
 * Patient Report — implements Screen 14 of the AgentForge mockups.
 *
 * Renders the "Report" navtab content: toolbar with report-type segmented
 * control, date range, Print and Download PDF actions, and a centered
 * "paper" document with patient header, allergies, active problems, and
 * current medications.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$report_types = [
    ['Comprehensive',     true],
    ['Demographics only', false],
    ['Visit summary',     false],
    ['Custom…',           false],
];

$problems = [
    ['E11.9',  'Type 2 Diabetes Mellitus, without complications', 'Onset 2019 • Active'],
    ['I10',    'Essential (primary) hypertension',                 'Onset 2017 • Active'],
    ['E03.9',  'Hypothyroidism, unspecified',                       'Onset 2021 • Active'],
    ['M17.0',  'Bilateral primary osteoarthritis of knee',          'Onset 2022 • Active'],
];

$meds = [
    ['Metformin',     '1000 mg',  'BID with meals',           'Refilled 2024-10-30'],
    ['Lisinopril',    '10 mg',    'Daily — increased 04/01/2026', 'Refilled 2024-10-30'],
    ['Levothyroxine', '50 mcg',   'Daily, AM',                 'Refilled 2024-10-30'],
    ['Atorvastatin',  '40 mg',    'Nightly',                   'Refilled 2024-07-17'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Report'); ?></title>
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

  /* ── Toolbar ─────────────────────────────────────────────────────────── */
  .cp-rep-toolbar {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    height: 52px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-rep-title {
    font-size: 14px; font-weight: 700;
    color: #0D1B2A; line-height: 1;
  }
  .cp-seg {
    background: #F5F6F7;
    border-radius: 999px;
    padding: 4px;
    display: inline-flex; align-items: center;
  }
  .cp-seg .opt {
    border-radius: 999px;
    padding: 4px 12px;
    font-size: 12px; font-weight: 500;
    line-height: 1;
    color: #4F5763;
    cursor: pointer;
    background: transparent;
    border: none;
  }
  .cp-seg .opt.active {
    background: #FFFFFF;
    color: #008C8C;
  }
  .cp-rep-spacer { flex: 1; }

  .cp-pill {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 12px;
    color: #0D1B2A;
    line-height: 1;
    display: inline-flex; align-items: center; gap: 8px;
  }
  .cp-pill .pill-label { font-weight: 500; color: #4F5763; }
  .cp-pill .pill-caret { font-size: 10px; color: #8A91A1; }
  .cp-pill.print { padding: 7px 14px; gap: 6px; }
  .cp-pill.print:hover { background: #F5F6F7; }
  .cp-pdf-btn {
    background: #008C8C;
    color: #FFFFFF;
    border: none;
    border-radius: 999px;
    padding: 7px 16px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
  }
  .cp-pdf-btn:hover { background: #00787A; }

  /* ── Document container (centered paper) ─────────────────────────────── */
  .cp-rep-stage {
    background: #F5F6F7;
    padding: 24px;
    display: flex; justify-content: center;
    min-height: calc(100vh - 52px);
  }
  .cp-paper {
    background: #FFFFFF;
    border-radius: 4px;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.10);
    width: 880px;
    padding: 64px;
    display: flex; flex-direction: column; gap: 24px;
  }

  /* Letterhead */
  .cp-letterhead { display: flex; flex-direction: column; gap: 8px; }
  .cp-letterhead .top {
    display: flex; align-items: center; gap: 12px;
  }
  .cp-letterhead .logo {
    width: 36px; height: 36px;
    border-radius: 8px;
    background: #008C8C;
    flex: 0 0 auto;
  }
  .cp-letterhead .name {
    font-size: 14px; font-weight: 700; color: #0D1B2A; line-height: 1.1;
  }
  .cp-letterhead .addr {
    font-size: 10px; color: #8A91A1; line-height: 1.1;
  }
  .cp-letterhead .doctitle {
    margin-top: 4px;
    font-size: 22px; font-weight: 700;
    color: #0D1B2A;
    letter-spacing: 1px;
    line-height: 1.1;
  }
  .cp-letterhead .gen {
    font-size: 11px; color: #8A91A1; line-height: 1.1;
    margin-top: 4px;
  }
  .cp-letterhead .rule {
    margin-top: 8px;
    height: 2px;
    background: #0D1B2A;
    width: 752px;
  }

  /* Section heading */
  .cp-section { display: flex; flex-direction: column; gap: 6px; }
  .cp-section.gap8 { gap: 8px; }
  .cp-section .head {
    font-size: 12px; font-weight: 700;
    color: #008C8C;
    letter-spacing: 0.6px;
    line-height: 1;
  }

  /* Patient block */
  .cp-pat-box {
    background: #F5F6F7;
    border-radius: 6px;
    padding: 12px 16px;
    width: 752px;
    display: flex;
    gap: 32px;
  }
  .cp-pat-box .col { display: flex; flex-direction: column; gap: 4px; }
  .cp-pat-box .nm { font-size: 14px; font-weight: 700; color: #0D1B2A; line-height: 1.1; }
  .cp-pat-box .sub { font-size: 11px; color: #8A91A1; line-height: 1.2; }
  .cp-pat-box .ins { font-size: 12px; font-weight: 500; color: #0D1B2A; line-height: 1.2; }

  /* Allergies block */
  .cp-allergy-box {
    background: rgba(217, 56, 56, 0.05);
    border: 1px solid rgba(217, 56, 56, 0.30);
    border-radius: 6px;
    padding: 12px 16px;
    display: flex; flex-direction: column; gap: 4px;
    font-size: 12px; font-weight: 500; color: #0D1B2A;
    line-height: 1.3;
  }

  /* Tables (problems / meds) */
  .cp-table {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 6px;
    overflow: hidden;
    display: flex; flex-direction: column;
  }
  .cp-table .row {
    display: flex; align-items: center;
    padding: 8px 16px;
    border-bottom: 0.5px solid #E4E5E8;
    gap: 12px;
    height: 36px;
  }
  .cp-table .row:last-child { border-bottom: none; }
  .cp-table .row.med {
    height: 42px;
    padding: 10px 16px;
    gap: 8px;
  }
  .cp-icd {
    background: #F5F6F7;
    border-radius: 3px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.4px;
    color: #4F5763;
    line-height: 1.4;
    flex: 0 0 auto;
  }
  .cp-icd-name {
    font-size: 12px; font-weight: 500;
    color: #0D1B2A;
    line-height: 1;
  }
  .cp-flex { flex: 1; }
  .cp-meta {
    font-size: 11px; color: #8A91A1;
    line-height: 1;
  }

  .cp-med-name { font-size: 12px; font-weight: 600; color: #0D1B2A; line-height: 1; }
  .cp-med-dose { font-size: 12px; font-weight: 500; color: #008C8C; line-height: 1; }
  .cp-med-sep  { font-size: 14px; color: #8A91A1; line-height: 1; }
  .cp-med-freq { font-size: 11px; color: #4F5763; line-height: 1; }
  .cp-med-refill { font-size: 10px; color: #8A91A1; line-height: 1; }
</style>
</head>
<body>

<header class="cp-rep-toolbar">
  <div class="cp-rep-title"><?php echo xlt('Patient Report'); ?></div>
  <div class="cp-seg" role="tablist">
    <?php foreach ($report_types as [$label, $active]): ?>
      <button type="button" class="opt<?php echo $active ? ' active' : ''; ?>"><?php echo text($label); ?></button>
    <?php endforeach; ?>
  </div>
  <div class="cp-rep-spacer"></div>
  <span class="cp-pill">
    <span>📅</span>
    <span class="pill-label"><?php echo xlt('Last 12 months'); ?></span>
    <span class="pill-caret">▾</span>
  </span>
  <button type="button" class="cp-pill print">⎙ <?php echo xlt('Print'); ?></button>
  <button type="button" class="cp-pdf-btn"><?php echo xlt('Download PDF'); ?></button>
</header>

<main class="cp-rep-stage">
  <article class="cp-paper">
    <div class="cp-letterhead">
      <div class="top">
        <div class="logo"></div>
        <div>
          <div class="name">Riverside Family Medicine</div>
          <div class="addr">847 Main Street, Suite 200 • Austin, TX 78701 • (512) 555-0142</div>
        </div>
      </div>
      <div class="doctitle"><?php echo xlt('PATIENT REPORT'); ?></div>
      <div class="gen">Generated 04/29/2026 by Dr. Eduardo Rivera, MD</div>
      <div class="rule"></div>
    </div>

    <section class="cp-section">
      <div class="head"><?php echo xlt('PATIENT'); ?></div>
      <div class="cp-pat-box">
        <div class="col">
          <div class="nm">Margaret Chen</div>
          <div class="sub">F • 68 years • DOB 03/14/1958</div>
          <div class="sub">MRN #004821 • Member since 2017</div>
        </div>
        <div class="col">
          <div class="ins">Insurance: Blue Cross Blue Shield PPO</div>
          <div class="sub">Group #BCBS-7281 • Member ID 4QF23-991</div>
          <div class="sub">Primary Provider: Dr. Eduardo Rivera, MD</div>
        </div>
      </div>
    </section>

    <section class="cp-section">
      <div class="head"><?php echo xlt('ALLERGIES & REACTIONS'); ?></div>
      <div class="cp-allergy-box">
        <div>Penicillin — Mild reaction (itching, rash). Reviewed 02/18/2026.</div>
        <div>Sulfa drugs — Mild skin reaction. Reviewed 02/18/2026.</div>
      </div>
    </section>

    <section class="cp-section">
      <div class="head"><?php echo xlt('ACTIVE PROBLEMS'); ?></div>
      <div class="cp-table">
        <?php foreach ($problems as [$icd, $name, $meta]): ?>
          <div class="row">
            <span class="cp-icd"><?php echo text($icd); ?></span>
            <span class="cp-icd-name"><?php echo text($name); ?></span>
            <span class="cp-flex"></span>
            <span class="cp-meta"><?php echo text($meta); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="cp-section">
      <div class="head"><?php echo xlt('CURRENT MEDICATIONS') . ' (' . text(count($meds)) . ')'; ?></div>
      <div class="cp-table">
        <?php foreach ($meds as [$name, $dose, $freq, $refill]): ?>
          <div class="row med">
            <span class="cp-med-name"><?php echo text($name); ?></span>
            <span class="cp-med-dose"><?php echo text($dose); ?></span>
            <span class="cp-med-sep">•</span>
            <span class="cp-med-freq"><?php echo text($freq); ?></span>
            <span class="cp-flex"></span>
            <span class="cp-med-refill"><?php echo text($refill); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </article>
</main>

</body>
</html>
