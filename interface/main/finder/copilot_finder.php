<?php

/**
 * Patient Finder landing page — implements Figma Screen 10.
 *
 * Search box + filter chips + results table. Replaces the legacy
 * dynamic finder UI; the original is preserved in
 * dynamic_finder.original.php.bak.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$filters = [
    ['My panel',           true],
    ['All providers',      false],
    ['Active only',        true],
    ['Insurance: Any',     false],
    ['Last visit ≤ 12 mo', false],
];

// Mock-faithful patient roster — colors are the avatar tints, flag pills
// drive the right-most column. Selected flag highlights the row.
$rows = [
    [
        'initials' => 'MC', 'avatar_color' => '#5FD0D0',
        'name' => 'Chen, Margaret', 'mrn' => '#004821',
        'dob' => '03/14/1958 (68 yrs)', 'provider' => 'Dr. E. Rivera',
        'insurance' => 'Blue Cross PPO', 'last_visit' => 'Today', 'today' => true,
        'selected' => true,
        'flags' => [['warn', '⚠ Penicillin'], ['cond', '🩺 T2DM']],
    ],
    [
        'initials' => 'RC', 'avatar_color' => '#4885D9',
        'name' => 'Chen, Robert', 'mrn' => '#005713',
        'dob' => '11/02/1971 (54 yrs)', 'provider' => 'Dr. E. Rivera',
        'insurance' => 'Aetna PPO', 'last_visit' => 'Oct 18', 'today' => false,
        'selected' => false,
        'flags' => [['cond', '🩺 HTN']],
    ],
    [
        'initials' => 'LC', 'avatar_color' => '#FA8C33',
        'name' => 'Chen, Lily', 'mrn' => '#006904',
        'dob' => '08/22/1985 (40 yrs)', 'provider' => 'Dr. A. Patel',
        'insurance' => 'United HMO', 'last_visit' => 'Sep 03', 'today' => false,
        'selected' => false,
        'flags' => [],
    ],
    [
        'initials' => 'WC', 'avatar_color' => '#8561C7',
        'name' => 'Chen, Wei', 'mrn' => '#004112',
        'dob' => '01/30/1949 (76 yrs)', 'provider' => 'Dr. E. Rivera',
        'insurance' => 'Medicare A+B', 'last_visit' => 'Aug 28', 'today' => false,
        'selected' => false,
        'flags' => [['warn', '⚠ Sulfa'], ['cond', '🩺 CHF']],
    ],
    [
        'initials' => 'JC', 'avatar_color' => '#33A68C',
        'name' => 'Chen-Wong, Jasmine', 'mrn' => '#007231',
        'dob' => '05/12/1992 (33 yrs)', 'provider' => 'Dr. S. Chen',
        'insurance' => 'Cigna PPO', 'last_visit' => 'Jul 15', 'today' => false,
        'selected' => false,
        'flags' => [],
    ],
    [
        'initials' => 'DC', 'avatar_color' => '#D9668C',
        'name' => 'Chen, Daniel', 'mrn' => '#005002',
        'dob' => '12/04/2003 (22 yrs)', 'provider' => 'Dr. A. Patel',
        'insurance' => 'Self Pay', 'last_visit' => 'May 22', 'today' => false,
        'selected' => false,
        'flags' => [['rx', '💊 Refill due']],
    ],
];

$cols = [
    ['NAME',       'col-name'],
    ['MRN',        'col-mrn'],
    ['DOB / AGE',  'col-dob'],
    ['PROVIDER',   'col-provider'],
    ['INSURANCE',  'col-insurance'],
    ['LAST VISIT', 'col-visit'],
    ['FLAGS',      'col-flags'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Finder'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F7F8;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
  }
  button, input { font-family: inherit; }

  /* ── Page header ─────────────────────────────────────────────────────── */
  .cp-pf-header {
    width: 100%;
    height: 64px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 16px;
  }
  .cp-pf-title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-pf-spacer { flex: 1; }

  .cp-pf-search {
    display: inline-flex; align-items: center; gap: 10px;
    background: #F5F7F8;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 8px 18px;
    height: 40px;
    min-width: 200px;
  }
  .cp-pf-search-icon { font-size: 14px; color: #8A91A0; line-height: 1; }
  .cp-pf-search-input {
    flex: 1;
    border: 0;
    background: transparent;
    color: #0D1B2A;
    font-size: 14px; font-weight: 500;
    line-height: 1;
    outline: none;
    padding: 0;
    min-width: 60px;
  }

  .cp-pf-new {
    display: inline-flex; align-items: center; gap: 6px;
    background: #008C8C;
    color: #FFFFFF;
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 13px; font-weight: 600;
    border: none;
    cursor: pointer;
    line-height: 1;
  }
  .cp-pf-new:hover { background: #006F6F; }
  .cp-pf-new-plus { font-size: 16px; font-weight: 700; line-height: 1; }

  /* ── Filter row ──────────────────────────────────────────────────────── */
  .cp-pf-filters {
    width: 100%;
    height: 52px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 8px;
  }
  .cp-pf-filter-label {
    font-size: 12px; font-weight: 500; color: #8A91A0;
    margin-right: 4px;
    line-height: 1;
  }
  .cp-pf-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: #F5F7F8;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 12px; font-weight: 500;
    color: #4F5662;
    cursor: pointer;
    line-height: 1.2;
  }
  .cp-pf-chip.active {
    background: rgba(0, 140, 140, 0.10);
    border-color: #008C8C;
    color: #008C8C;
  }
  .cp-pf-chip-x {
    font-size: 12px; font-weight: 700;
    color: #008C8C;
    line-height: 1;
  }
  .cp-pf-results-count {
    margin-left: auto;
    font-size: 12px; font-weight: 500; color: #8A91A0;
    line-height: 1;
  }

  /* ── Results table ───────────────────────────────────────────────────── */
  .cp-pf-table-wrap {
    padding: 20px 24px;
  }
  .cp-pf-table {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
    width: 100%;
  }
  .cp-pf-thead {
    display: grid;
    grid-template-columns:
      280px 120px 180px 180px 200px 140px 1fr;
    height: 44px;
    background: #F5F7F8;
    align-items: center;
    padding: 0 18px;
  }
  .cp-pf-th {
    font-size: 11px; font-weight: 600;
    color: #8A91A0;
    letter-spacing: 0.5px;
    line-height: 1;
  }
  .cp-pf-row {
    display: grid;
    grid-template-columns:
      280px 120px 180px 180px 200px 140px 1fr;
    height: 84px;
    align-items: center;
    padding: 0 18px;
    border-top: 1px solid #E4E5E8;
    background: #FFFFFF;
  }
  .cp-pf-row.selected { background: rgba(0, 140, 140, 0.04); }
  .cp-pf-row:hover { background: #FAFBFB; }
  .cp-pf-row.selected:hover { background: rgba(0, 140, 140, 0.06); }

  .cp-pf-cell-name {
    display: inline-flex; align-items: center; gap: 12px;
  }
  .cp-pf-avatar {
    width: 36px; height: 36px;
    border-radius: 18px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #FFFFFF;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    flex: 0 0 auto;
  }
  .cp-pf-name { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }

  .cp-pf-mrn   { font-size: 12px; font-weight: 500; color: #4F5662; }
  .cp-pf-dob   { font-size: 12px; color: #0D1B2A; }
  .cp-pf-prov  { font-size: 12px; color: #0D1B2A; }
  .cp-pf-ins   { font-size: 12px; color: #0D1B2A; }
  .cp-pf-visit { font-size: 12px; color: #0D1B2A; }
  .cp-pf-visit.today { color: #008C8C; font-weight: 600; }

  .cp-pf-flags { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .cp-pf-flag {
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 10px; font-weight: 500;
    line-height: 1.2;
  }
  .cp-pf-flag.warn { background: rgba(217, 56, 56, 0.12); color: #D93838; }
  .cp-pf-flag.cond { background: rgba(0, 140, 140, 0.12); color: #008C8C; }
  .cp-pf-flag.rx   { background: rgba(0, 140, 140, 0.12); color: #008C8C; }
</style>
</head>
<body>

<header class="cp-pf-header">
  <div class="cp-pf-title"><?php echo xlt('Patient Finder'); ?></div>
  <div class="cp-pf-spacer"></div>
  <label class="cp-pf-search">
    <span class="cp-pf-search-icon">🔍</span>
    <input class="cp-pf-search-input" type="text" value="Chen" placeholder="<?php echo xla('Search patients'); ?>">
  </label>
  <button class="cp-pf-new" type="button">
    <span class="cp-pf-new-plus">+</span>
    <span><?php echo xlt('New Patient'); ?></span>
  </button>
</header>

<div class="cp-pf-filters">
  <span class="cp-pf-filter-label"><?php echo xlt('Filters:'); ?></span>
  <?php foreach ($filters as $f): ?>
    <span class="cp-pf-chip<?php echo $f[1] ? ' active' : ''; ?>">
      <span><?php echo text($f[0]); ?></span>
      <?php if ($f[1]): ?>
        <span class="cp-pf-chip-x">×</span>
      <?php endif; ?>
    </span>
  <?php endforeach; ?>
  <span class="cp-pf-results-count">23 <?php echo xlt('results'); ?></span>
</div>

<div class="cp-pf-table-wrap">
  <div class="cp-pf-table">
    <div class="cp-pf-thead">
      <?php foreach ($cols as $c): ?>
        <div class="cp-pf-th"><?php echo text($c[0]); ?></div>
      <?php endforeach; ?>
    </div>
    <?php foreach ($rows as $r): ?>
      <div class="cp-pf-row<?php echo $r['selected'] ? ' selected' : ''; ?>">
        <div class="cp-pf-cell-name">
          <span class="cp-pf-avatar" style="background-color: <?php echo attr($r['avatar_color']); ?>;"><?php echo text($r['initials']); ?></span>
          <span class="cp-pf-name"><?php echo text($r['name']); ?></span>
        </div>
        <div class="cp-pf-mrn"><?php echo text($r['mrn']); ?></div>
        <div class="cp-pf-dob"><?php echo text($r['dob']); ?></div>
        <div class="cp-pf-prov"><?php echo text($r['provider']); ?></div>
        <div class="cp-pf-ins"><?php echo text($r['insurance']); ?></div>
        <div class="cp-pf-visit<?php echo $r['today'] ? ' today' : ''; ?>"><?php echo text($r['last_visit']); ?></div>
        <div class="cp-pf-flags">
          <?php foreach ($r['flags'] as $fl): ?>
            <span class="cp-pf-flag <?php echo attr($fl[0]); ?>"><?php echo text($fl[1]); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

</body>
</html>
