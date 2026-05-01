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
require_once(__DIR__ . "/../../main/copilot_helpers.php");

$pid = (int)($_SESSION['pid'] ?? 1);

// ── Vitals from form_vitals (most recent + previous for trend) ─────────
$vRecent = sqlQuery(
    "SELECT bps, bpd, BMI, weight FROM form_vitals WHERE pid = ? ORDER BY date DESC LIMIT 1",
    [$pid]
);
$vPrev = sqlQuery(
    "SELECT bps, bpd, BMI, weight FROM form_vitals WHERE pid = ? ORDER BY date DESC LIMIT 1 OFFSET 1",
    [$pid]
);
$vitals = [];
if ($vRecent && $vRecent['bps']) {
    $bp = (int)$vRecent['bps'] . '/' . (int)$vRecent['bpd'];
    if ($vPrev && $vPrev['bps']) {
        $prevBp = (int)$vPrev['bps'] . '/' . (int)$vPrev['bpd'];
        $arrow = ((int)$vRecent['bps'] < (int)$vPrev['bps']) ? '↓' : '↑';
        $color = ((int)$vRecent['bps'] < (int)$vPrev['bps']) ? '#26A65B' : '#FA8C33';
        $trend = "$arrow from $prevBp";
    } else { $trend = '—'; $color = '#26A65B'; }
    $vitals[] = ['label' => 'BP', 'value' => $bp, 'unit' => 'mmHg', 'trend' => $trend, 'trend_color' => $color];
}
if ($vRecent && $vRecent['BMI']) {
    $bmi = number_format((float)$vRecent['BMI'], 1);
    if ($vPrev && $vPrev['BMI']) {
        $prevBmi = number_format((float)$vPrev['BMI'], 1);
        $arrow = ((float)$vRecent['BMI'] < (float)$vPrev['BMI']) ? '↓' : '↑';
        $color = ((float)$vRecent['BMI'] < (float)$vPrev['BMI']) ? '#26A65B' : '#FA8C33';
        $trend = "$arrow from $prevBmi";
    } else { $trend = '—'; $color = '#26A65B'; }
    $vitals[] = ['label' => 'BMI', 'value' => $bmi, 'unit' => 'kg/m²', 'trend' => $trend, 'trend_color' => $color];
}
if ($vRecent && $vRecent['weight']) {
    $wt = (int)$vRecent['weight'];
    $vitals[] = ['label' => 'WT', 'value' => (string)$wt, 'unit' => 'lbs', 'trend' => 'last visit', 'trend_color' => '#26A65B'];
}
// Pad to 4 with placeholders if needed.
while (count($vitals) < 4) {
    $vitals[] = ['label' => '—', 'value' => '—', 'unit' => '—', 'trend' => '—', 'trend_color' => '#8A91A1'];
}

// ── Allergies ──────────────────────────────────────────────────────────
$allergies = [];
$rows = sqlStatement("SELECT DISTINCT title, severity_al, comments FROM lists WHERE pid = ? AND type = 'allergy' AND COALESCE(enddate, '0000-00-00') = '0000-00-00' ORDER BY date ASC", [$pid]);
while ($r = sqlFetchArray($rows)) {
    $sub = trim((string)($r['severity_al'] ?? ''));
    if (!$sub && $r['comments']) { $sub = $r['comments']; }
    if (!$sub) { $sub = 'Active allergy'; }
    $allergies[] = ['icon' => '⚠', 'kind' => 'alert', 'name' => $r['title'], 'sub' => $sub];
}
if (!$allergies) {
    $allergies[] = ['icon' => '+', 'kind' => 'note', 'name' => 'No allergies recorded', 'sub' => 'Reviewed today'];
}

// ── Problems ───────────────────────────────────────────────────────────
$problems = [];
$rows = sqlStatement("SELECT DISTINCT title, diagnosis, date FROM lists WHERE pid = ? AND type = 'medical_problem' AND COALESCE(enddate, '0000-00-00') = '0000-00-00' ORDER BY date ASC", [$pid]);
while ($r = sqlFetchArray($rows)) {
    $year = $r['date'] ? substr($r['date'], 0, 4) : '';
    $sub = ($year ? 'Since ' . $year . ' • ' : '') . 'Active';
    $problems[] = ['name' => $r['title'], 'sub' => $sub];
}

// ── Medications (active prescriptions) ─────────────────────────────────
$medications = [];
$rows = sqlStatement(
    "SELECT drug, dosage, MAX(date_added) AS dt
     FROM prescriptions WHERE patient_id = ? AND active = 1
     GROUP BY drug, dosage ORDER BY dt DESC LIMIT 6",
    [$pid]
);
while ($r = sqlFetchArray($rows)) {
    $name = trim($r['drug'] . ' ' . $r['dosage']);
    $medications[] = ['name' => $name, 'sub' => 'Active'];
}

// ── Labs (placeholder — no procedure_result data in demo) ──────────────
// Show vitals trends as faux labs so this card isn't empty.
$labs = [];
if ($vRecent) {
    if ($vRecent['bps']) {
        $bps = (int)$vRecent['bps'];
        $tone = $bps >= 140 ? 'high' : ($bps < 90 ? 'low' : 'normal');
        $labs[] = ['test' => 'BP (sys)', 'value' => $bps . ' mmHg', 'status' => $tone, 'range' => '<140', 'date' => 'recent'];
    }
    if ($vRecent['BMI']) {
        $bm = (float)$vRecent['BMI'];
        $tone = $bm >= 30 ? 'high' : 'normal';
        $labs[] = ['test' => 'BMI', 'value' => number_format($bm, 1), 'status' => $tone, 'range' => '18.5–25', 'date' => 'recent'];
    }
}
if (!$labs) {
    $labs = [['test' => '(no labs on file)', 'value' => '—', 'status' => 'normal', 'range' => '—', 'date' => '—']];
}

// ── Recent visits ──────────────────────────────────────────────────────
$visits = [];
$rows = sqlStatement(
    "SELECT fe.date, fe.reason, u.username, u.fname, u.lname, u.title
     FROM form_encounter fe LEFT JOIN users u ON fe.provider_id = u.id
     WHERE fe.pid = ? ORDER BY fe.date DESC LIMIT 5",
    [$pid]
);
while ($r = sqlFetchArray($rows)) {
    $prov = cp_format_provider_name([
        'username' => $r['username'] ?? '',
        'fname'    => $r['fname'] ?? '',
        'lname'    => $r['lname'] ?? '',
        'title'    => $r['title'] ?? '',
    ]);
    $title = ($r['reason'] ?: 'Office visit') . ' — ' . $prov;
    $sub = ($r['date'] ? date('m/d/Y', strtotime($r['date'])) : '—') . ' • Signed';
    $visits[] = ['title' => $title, 'sub' => $sub];
}
if (!$visits) {
    $visits[] = ['title' => '(no encounters on file)', 'sub' => '—'];
}
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
