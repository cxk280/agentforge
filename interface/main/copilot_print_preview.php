<?php

/**
 * Print Preview — Screen 61.
 * Single archetype for Demographics / Superbill / Referral / Letter / Labels.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$doc_types = [
    ['Patient Demographics', true],
    ['Superbill',            false],
    ['Referral',             false],
    ['Letter',               false],
    ['Labels',               false],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Print Preview'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-prn-shell { display: grid; grid-template-columns: 240px 1fr; min-height: calc(100vh - 60px); }
  .cp-prn-sidebar { background: #FFFFFF; border-right: 1px solid #E4E5E8; padding: 16px 0; }
  .cp-prn-cat { display: block; padding: 9px 24px; font-size: 13px; color: #4F5763;
    text-decoration: none; cursor: pointer; }
  .cp-prn-cat:hover { background: #F5F7F8; }
  .cp-prn-cat.active { background: rgba(0, 140, 140, 0.08); color: #008C8C; font-weight: 600; }
  .cp-prn-stage {
    background: #4F5763; padding: 32px;
    display: flex; align-items: flex-start; justify-content: center;
    overflow-y: auto;
  }
  .cp-prn-paper {
    background: #FFFFFF;
    width: 612px; min-height: 792px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.25);
    padding: 48px 56px;
    font-family: Georgia, 'Times New Roman', serif;
    color: #0D1B2A;
  }
  .cp-prn-paper h1 { font-size: 16px; margin: 0; letter-spacing: 1px; text-transform: uppercase; }
  .cp-prn-paper .clinic { font-size: 12px; color: #4F5763; margin-bottom: 32px; }
  .cp-prn-paper h2 { font-size: 14px; margin: 24px 0 8px; border-bottom: 2px solid #0D1B2A; padding-bottom: 4px; }
  .cp-prn-paper .row { display: flex; padding: 6px 0; font-size: 12px; }
  .cp-prn-paper .row .l { flex: 0 0 140px; color: #4F5763; }
  .cp-prn-paper .row .v { flex: 1; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Print Preview'); ?></span>
    <span class="meta"><?php echo xlt('Patient Demographics — Margaret Chen'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Send to PDF'); ?></button>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Email patient'); ?></button>
  <button type="button" class="cp-btn primary">⎙ <?php echo xlt('Print'); ?></button>
</header>

<div class="cp-prn-shell">

  <aside class="cp-prn-sidebar">
    <div class="cp-side-lbl"><?php echo xlt('DOCUMENT TYPE'); ?></div>
    <?php foreach ($doc_types as [$name, $active]): ?>
      <a class="cp-prn-cat <?php echo $active ? 'active' : ''; ?>"><?php echo text($name); ?></a>
    <?php endforeach; ?>
  </aside>

  <div class="cp-prn-stage">
    <div class="cp-prn-paper">
      <h1>Riverside Family Medicine</h1>
      <div class="clinic">847 Main Street · Austin, TX 78701 · (512) 555-0142</div>

      <h2>Patient Demographics</h2>
      <div class="row"><span class="l">Patient name</span><span class="v">Margaret S. Chen</span></div>
      <div class="row"><span class="l">MRN</span><span class="v">004821</span></div>
      <div class="row"><span class="l">DOB / Age</span><span class="v">03/14/1958 / 68 yrs</span></div>
      <div class="row"><span class="l">Sex</span><span class="v">Female</span></div>
      <div class="row"><span class="l">Phone</span><span class="v">(512) 555-0142</span></div>
      <div class="row"><span class="l">Email</span><span class="v">m.chen@example.com</span></div>
      <div class="row"><span class="l">Address</span><span class="v">847 Main Street, Suite 200, Austin, TX 78701</span></div>

      <h2>Insurance</h2>
      <div class="row"><span class="l">Plan</span><span class="v">Blue Cross Blue Shield PPO</span></div>
      <div class="row"><span class="l">Group #</span><span class="v">BCBS-7281</span></div>
      <div class="row"><span class="l">Member ID</span><span class="v">4QF23-991</span></div>
      <div class="row"><span class="l">Effective</span><span class="v">01/01/2026</span></div>

      <h2>Provider</h2>
      <div class="row"><span class="l">Primary</span><span class="v">Dr. Eduardo Rivera, MD</span></div>
      <div class="row"><span class="l">Facility</span><span class="v">Riverside Family Medicine</span></div>

      <h2>Active diagnoses</h2>
      <div class="row"><span class="l">E11.9</span><span class="v">Type 2 Diabetes Mellitus</span></div>
      <div class="row"><span class="l">I10</span><span class="v">Essential hypertension</span></div>

      <h2>Allergies</h2>
      <div class="row"><span class="l">Penicillin</span><span class="v">Mild — itching, rash</span></div>
      <div class="row"><span class="l">Sulfa drugs</span><span class="v">Mild — skin reaction</span></div>

      <div style="margin-top: 36px; font-size: 10px; color: #8A91A1; text-align: center;">
        Printed 04/29/2026 09:42 AM by Dr. E. Rivera · Confidential — for authorized use only
      </div>
    </div>
  </div>

</div>

</body>
</html>
