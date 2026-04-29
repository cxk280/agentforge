<?php

/**
 * Patient Dashboard — implements Screen 11 of the AgentForge mockups.
 *
 * Renders the content of the "Dashboard" navtab inside the patient
 * chart iframe: 4 vital metric cards + 3 summary panels + 2 detail
 * panels (Recent Lab Results, Recent Visits).
 *
 * The chrome (top nav), demographics banner, and patient navtab strip
 * are rendered by the parent shell — this page renders only the body.
 *
 * Mock data is hard-coded to match the Figma reference exactly. Live
 * patient data binding is a follow-up task.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$vitals = [
    ['label' => 'BP',  'value' => '130/82', 'unit' => 'mmHg',   'trend' => '↓ from 145/90', 'trend_color' => '#26A65B'],
    ['label' => 'A1C', 'value' => '7.9%',   'unit' => 'current', 'trend' => '↑ from 7.2%',   'trend_color' => '#FA8C33'],
    ['label' => 'LDL', 'value' => '98',     'unit' => 'mg/dL',  'trend' => '↓ from 112',    'trend_color' => '#26A65B'],
    ['label' => 'BMI', 'value' => '29.4',   'unit' => 'kg/m²',  'trend' => '↑ from 28.8',   'trend_color' => '#FA8C33'],
];

$allergies = [
    ['icon' => '⚠', 'kind' => 'alert', 'name' => 'Penicillin',  'sub' => 'Mild — itching, rash'],
    ['icon' => '⚠', 'kind' => 'alert', 'name' => 'Sulfa drugs', 'sub' => 'Mild — skin reaction'],
    ['icon' => '+', 'kind' => 'note',  'name' => 'No food allergies recorded', 'sub' => 'Reviewed 02/18/2026'],
];

$problems = [
    ['name' => 'Type 2 Diabetes Mellitus', 'sub' => 'Since 2019 • Active'],
    ['name' => 'Hypertension',             'sub' => 'Since 2017 • Active'],
    ['name' => 'Hypothyroidism',           'sub' => 'Since 2021 • Active'],
    ['name' => 'Osteoarthritis (knees)',   'sub' => 'Since 2022 • Active'],
];

$medications = [
    ['name' => 'Metformin 1000 mg',     'sub' => 'BID with meals'],
    ['name' => 'Lisinopril 10 mg',      'sub' => 'Daily — increased 04/01'],
    ['name' => 'Levothyroxine 50 mcg',  'sub' => 'Daily, AM'],
    ['name' => 'Atorvastatin 40 mg',    'sub' => 'Nightly'],
];

// status: 'normal' (green dot), 'high' (amber dot), 'low' (amber dot)
$labs = [
    ['test' => 'HbA1c',        'value' => '7.9 %',     'status' => 'high',   'range' => '<7.0',    'date' => '04/12/2026'],
    ['test' => 'LDL',          'value' => '98 mg/dL',  'status' => 'normal', 'range' => '<100',    'date' => '04/12/2026'],
    ['test' => 'Creatinine',   'value' => '1.04 mg/dL','status' => 'normal', 'range' => '0.6–1.2', 'date' => '04/12/2026'],
    ['test' => 'TSH',          'value' => '2.4 mIU/L', 'status' => 'normal', 'range' => '0.4–4.0', 'date' => '04/12/2026'],
    ['test' => 'Microalbumin', 'value' => '32 mg/g',   'status' => 'high',   'range' => '<30',     'date' => '04/12/2026'],
];

$visits = [
    ['title' => 'Annual physical — Dr. Rivera',     'sub' => '02/18/2026 • 30 min • Signed'],
    ['title' => 'Diabetes follow-up — Dr. Rivera',  'sub' => '11/15/2025 • 20 min • Signed'],
    ['title' => 'Lab review — Dr. Chen',            'sub' => '08/22/2025 • Telehealth • Signed'],
    ['title' => 'Annual physical — Dr. Rivera',     'sub' => '02/12/2025 • 30 min • Signed'],
    ['title' => 'Acute visit (URI) — Dr. Patel',    'sub' => '10/04/2024 • 15 min • Signed'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Dashboard'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F7F8;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }

  .cp-dash { padding: 20px 24px 32px; display: flex; flex-direction: column; gap: 16px; }

  /* ── Card primitives ─────────────────────────────────────────────────── */
  .cp-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 18px;
  }
  .cp-card-head {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 10px;
  }
  .cp-card-title {
    font-size: 14px; font-weight: 600; color: #0D1B2A;
    line-height: 1;
  }
  .cp-card-link {
    margin-left: auto;
    font-size: 12px; color: #008C8C;
    text-decoration: none;
    font-weight: 500;
  }
  .cp-card-link:hover { text-decoration: underline; }

  /* ── Vitals row ──────────────────────────────────────────────────────── */
  .cp-vitals {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
  }
  .cp-vital { padding: 16px 18px; }
  .cp-vital .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A0; letter-spacing: 0.6px;
    text-transform: uppercase;
    margin-bottom: 8px;
  }
  .cp-vital .row {
    display: flex; align-items: baseline; gap: 6px;
  }
  .cp-vital .val {
    font-size: 24px; font-weight: 700; color: #0D1B2A;
    line-height: 1.1;
  }
  .cp-vital .unit {
    font-size: 12px; color: #8A91A0;
  }
  .cp-vital .trend {
    margin-top: 8px;
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px;
  }
  .cp-vital .trend .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: currentColor;
    display: inline-block;
  }

  /* ── Three-up panels (Allergies / Problems / Meds) ───────────────────── */
  .cp-three-up {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
  }
  .cp-list { display: flex; flex-direction: column; }
  .cp-list-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 10px 0;
  }
  .cp-list-item + .cp-list-item {
    border-top: 1px solid #F0F1F3;
  }
  .cp-list-item .ico {
    width: 22px;
    flex: 0 0 auto;
    text-align: center;
    font-size: 14px;
    line-height: 18px;
    padding-top: 1px;
  }
  .cp-list-item .ico.alert { color: #D93838; }
  .cp-list-item .ico.note  { color: #008C8C; }
  .cp-list-item .body { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
  .cp-list-item .name { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cp-list-item .sub  { font-size: 12px; color: #8A91A0; line-height: 1.3; }

  /* ── Two-up panels (Labs / Visits) ───────────────────────────────────── */
  .cp-two-up {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }

  /* ── Lab table ───────────────────────────────────────────────────────── */
  .cp-lab-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }
  .cp-lab-table thead th {
    text-align: left;
    font-size: 11px; font-weight: 600;
    color: #8A91A0;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    padding: 8px 12px;
    border-bottom: 1px solid #F0F1F3;
    background: #F8F9FB;
  }
  .cp-lab-table thead th:first-child { padding-left: 0; }
  .cp-lab-table thead tr th:first-child { border-top-left-radius: 6px; }
  .cp-lab-table thead tr th:last-child  { border-top-right-radius: 6px; }
  .cp-lab-table tbody td {
    padding: 10px 12px;
    border-bottom: 1px solid #F0F1F3;
    color: #0D1B2A;
    line-height: 1.3;
    vertical-align: middle;
  }
  .cp-lab-table tbody td:first-child { padding-left: 0; font-weight: 500; }
  .cp-lab-table tbody tr:last-child td { border-bottom: none; }
  .cp-lab-table .val-cell {
    display: inline-flex; align-items: center; gap: 8px;
    font-weight: 500;
  }
  .cp-lab-table .val-cell .dot {
    width: 6px; height: 6px; border-radius: 50%;
  }
  .cp-lab-table .val-cell.normal .dot { background: #26A65B; }
  .cp-lab-table .val-cell.high   .dot { background: #FA8C33; }
  .cp-lab-table .val-cell.high          { color: #FA8C33; }
  .cp-lab-table .range, .cp-lab-table .date { color: #8A91A0; font-size: 12px; }

  /* ── Visit list ──────────────────────────────────────────────────────── */
  .cp-visit-list { display: flex; flex-direction: column; }
  .cp-visit {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 10px 0;
  }
  .cp-visit + .cp-visit { border-top: 1px solid #F0F1F3; }
  .cp-visit .ico {
    width: 22px; flex: 0 0 auto;
    text-align: center; font-size: 14px;
    line-height: 18px; padding-top: 1px;
  }
  .cp-visit .body { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
  .cp-visit .title { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cp-visit .sub   { font-size: 12px; color: #8A91A0; line-height: 1.3; }
</style>
</head>
<body>

<main class="cp-dash">

  <!-- Vitals -->
  <section class="cp-vitals">
    <?php foreach ($vitals as $v): ?>
      <div class="cp-card cp-vital">
        <div class="lbl"><?php echo text($v['label']); ?></div>
        <div class="row">
          <span class="val"><?php echo text($v['value']); ?></span>
          <span class="unit"><?php echo text($v['unit']); ?></span>
        </div>
        <div class="trend" style="color: <?php echo attr($v['trend_color']); ?>;">
          <span class="dot"></span>
          <span><?php echo text($v['trend']); ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- Allergies / Active Problems / Current Medications -->
  <section class="cp-three-up">

    <div class="cp-card">
      <div class="cp-card-head">
        <span class="cp-card-title"><?php echo xlt('Allergies'); ?></span>
        <a class="cp-card-link" href="#"><?php echo xlt('View all'); ?></a>
      </div>
      <div class="cp-list">
        <?php foreach ($allergies as $a): ?>
          <div class="cp-list-item">
            <span class="ico <?php echo attr($a['kind']); ?>"><?php echo $a['icon']; ?></span>
            <div class="body">
              <span class="name"><?php echo text($a['name']); ?></span>
              <span class="sub"><?php echo text($a['sub']); ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="cp-card">
      <div class="cp-card-head">
        <span class="cp-card-title"><?php echo xlt('Active Problems'); ?></span>
        <a class="cp-card-link" href="#"><?php echo xlt('View all'); ?></a>
      </div>
      <div class="cp-list">
        <?php foreach ($problems as $p): ?>
          <div class="cp-list-item">
            <span class="ico note">🩺</span>
            <div class="body">
              <span class="name"><?php echo text($p['name']); ?></span>
              <span class="sub"><?php echo text($p['sub']); ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="cp-card">
      <div class="cp-card-head">
        <span class="cp-card-title"><?php echo xlt('Current Medications'); ?></span>
        <a class="cp-card-link" href="#"><?php echo xlt('View all'); ?></a>
      </div>
      <div class="cp-list">
        <?php foreach ($medications as $m): ?>
          <div class="cp-list-item">
            <span class="ico note">💊</span>
            <div class="body">
              <span class="name"><?php echo text($m['name']); ?></span>
              <span class="sub"><?php echo text($m['sub']); ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </section>

  <!-- Recent Lab Results / Recent Visits -->
  <section class="cp-two-up">

    <div class="cp-card">
      <div class="cp-card-head">
        <span class="cp-card-title"><?php echo xlt('Recent Lab Results'); ?></span>
        <a class="cp-card-link" href="#"><?php echo xlt('View trends'); ?> →</a>
      </div>
      <table class="cp-lab-table">
        <thead>
          <tr>
            <th><?php echo xlt('Test'); ?></th>
            <th><?php echo xlt('Value'); ?></th>
            <th><?php echo xlt('Range'); ?></th>
            <th><?php echo xlt('Date'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($labs as $l): ?>
            <tr>
              <td><?php echo text($l['test']); ?></td>
              <td>
                <span class="val-cell <?php echo attr($l['status']); ?>">
                  <span class="dot"></span><?php echo text($l['value']); ?>
                </span>
              </td>
              <td><span class="range"><?php echo text($l['range']); ?></span></td>
              <td><span class="date"><?php echo text($l['date']); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="cp-card">
      <div class="cp-card-head">
        <span class="cp-card-title"><?php echo xlt('Recent Visits'); ?></span>
        <a class="cp-card-link" href="#"><?php echo xlt('View all'); ?></a>
      </div>
      <div class="cp-visit-list">
        <?php foreach ($visits as $v): ?>
          <div class="cp-visit">
            <span class="ico">📅</span>
            <div class="body">
              <span class="title"><?php echo text($v['title']); ?></span>
              <span class="sub"><?php echo text($v['sub']); ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </section>

</main>

</body>
</html>
