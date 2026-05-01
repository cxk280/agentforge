<?php

/**
 * Encounter Detail — implements Screen 23 of the AgentForge mockups.
 *
 * Active-visit view inside the patient chart. Renders Today's Visit
 * header + status, vitals strip, SOAP-tabbed left column (Subjective/
 * Objective/Assessment/Plan) and a right rail with Active Orders,
 * Diagnoses for this visit, and a Co-Pilot suggestion card.
 *
 * The chrome (top nav, demographics banner, patient navtab strip) is
 * rendered by the parent shell — this page renders only the body.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../../main/copilot_helpers.php");

// Patient context comes from session; default to Ted Shaw (pid=1) if missing.
$pid = (int)($_SESSION['pid'] ?? 1);

// Most recent encounter for this patient (preferred: still open).
$enc = sqlQuery(
    "SELECT id, date, reason, last_level_closed, last_level_billed, provider_id, facility
     FROM form_encounter WHERE pid = ?
     ORDER BY (CASE WHEN COALESCE(last_level_closed, 0) = 0 THEN 0 ELSE 1 END), date DESC LIMIT 1",
    [$pid]
);
$encId    = $enc ? (int)$enc['id'] : 0;
$encReason = $enc['reason'] ?? '—';
$encDate  = $enc ? date('M j, Y', strtotime($enc['date'])) : date('M j, Y');
$encOpen  = $enc ? (int)($enc['last_level_closed'] ?? 0) === 0 : true;
$encStatus = $encOpen ? 'OPEN' : 'SIGNED';
$provider = sqlQuery("SELECT username, fname, lname, title FROM users WHERE id = ?", [(int)($enc['provider_id'] ?? 1)]) ?: ['username' => 'admin'];
$providerName = cp_format_provider_name($provider);

// CRUD: Sign & lock the encounter (UPDATE form_encounter.last_level_closed)
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'sign_encounter') {
    $targetEnc = (int)($_POST['encounter_id'] ?? $encId);
    if ($targetEnc) {
        sqlStatement("UPDATE form_encounter SET last_level_closed = 1 WHERE id = ?", [$targetEnc]);
        $flash = 'Encounter signed & locked.';
    }
    header('Location: copilot_encounter.php?msg=' . urlencode($flash ?? 'Done'));
    exit;
}
$flash = $_GET['msg'] ?? null;

// Most recent vitals for this patient.
$v = sqlQuery(
    "SELECT bps, bpd, pulse, temperature, oxygen_saturation, weight, BMI
     FROM form_vitals WHERE pid = ? ORDER BY date DESC LIMIT 1",
    [$pid]
);
$bp   = ($v && $v['bps'] && $v['bpd']) ? (int)$v['bps'] . '/' . (int)$v['bpd'] : '—';
$hr   = ($v && $v['pulse']) ? (int)$v['pulse'] : '—';
$temp = ($v && $v['temperature']) ? number_format((float)$v['temperature'], 1) : '—';
$spo2 = ($v && $v['oxygen_saturation']) ? (int)$v['oxygen_saturation'] : '—';
$wt   = ($v && $v['weight']) ? (int)$v['weight'] : '—';
$bmi  = ($v && $v['BMI']) ? number_format((float)$v['BMI'], 1) : '—';
$bmiTone = ($v && $v['BMI'] && (float)$v['BMI'] >= 30) ? '#FA8C33' : '#0D1B2A';

$vitals = [
    ['BP',   $bp,   'mmHg',  '#0D1B2A'],
    ['HR',   $hr,   'bpm',   '#0D1B2A'],
    ['TEMP', $temp, '°F',    '#0D1B2A'],
    ['SPO₂', $spo2, '%',     '#0D1B2A'],
    ['WT',   $wt,   'lbs',   '#0D1B2A'],
    ['BMI',  $bmi,  'kg/m²', $bmiTone],
];

$soap_tabs = [
    ['Subjective', true],
    ['Objective',  false],
    ['Assessment', false],
    ['Plan',       false],
];

$ros = [
    ['Constitutional', 'ok'],
    ['Cardio',         'ok'],
    ['Pulmonary',      'ok'],
    ['GI',             'ok'],
    ['Endo',           'flag'],
    ['Neuro',          'ok'],
    ['MSK',            'ok'],
    ['Skin',           'ok'],
];

$orders = [
    ['Lab',     'HbA1c (today)',     'In transit'],
    ['Lab',     'BMP (today)',       'In transit'],
    ['Imaging', 'Foot Doppler (Apr 30)', 'Scheduled'],
];

$dxs = [
    ['E11.9', 'Type 2 Diabetes Mellitus'],
    ['I10',   'Essential hypertension'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Encounter'); ?></title>
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
  .cp-en-head {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 14px 24px;
    display: flex; align-items: center; gap: 10px;
  }
  .cp-en-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-en-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-en-meta { color: #4F5763; font-size: 12px; line-height: 1; }
  .cp-en-status {
    border-radius: 999px;
    background: rgba(250, 140, 51, 0.14);
    color: #FA8C33;
    padding: 4px 10px;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.4px;
    line-height: 1.2;
    display: inline-flex; align-items: center; gap: 5px;
  }
  .cp-en-status .dot { width: 6px; height: 6px; border-radius: 50%; background: #FA8C33; display: inline-block; }
  .cp-en-spacer { flex: 1; }
  .cp-en-btn {
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    border: 1px solid transparent;
  }
  .cp-en-btn.ghost { background: #FFFFFF; color: #4F5763; border-color: #E4E5E8; font-weight: 500; }
  .cp-en-btn.ghost:hover { background: #F5F6F7; }
  .cp-en-btn.primary { background: #008C8C; color: #FFFFFF; }
  .cp-en-btn.primary:hover { background: #00787A; }

  /* Vitals row */
  .cp-vitals {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 14px 24px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-vitals-lbl {
    font-size: 10px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.6px;
    line-height: 1;
    margin-right: 4px;
  }
  .cp-vital-card {
    background: #F5F6F7;
    border-radius: 8px;
    padding: 8px 14px;
    min-width: 88px;
  }
  .cp-vital-card .l {
    font-size: 9px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.5px;
    line-height: 1;
    margin-bottom: 5px;
  }
  .cp-vital-card .v {
    font-size: 16px; font-weight: 700;
    color: #0D1B2A;
    line-height: 1;
    display: inline-flex; align-items: baseline; gap: 4px;
  }
  .cp-vital-card .v.good { color: #33A666; }
  .cp-vital-card .v .u {
    font-size: 9px; font-weight: 500;
    color: #8A91A1;
  }
  .cp-vitals-spacer { flex: 1; }
  .cp-add-meas {
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px; font-weight: 500;
    color: #008C8C;
    line-height: 1;
  }
  .cp-add-meas:hover { background: #F5F6F7; }

  /* Main grid */
  .cp-en-body {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 16px;
    padding: 20px 24px 32px;
  }

  /* Left panel */
  .cp-soap-panel {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 20px 24px;
  }
  .cp-soap-tabs { display: flex; gap: 6px; margin-bottom: 16px; border-bottom: 1px solid #E4E5E8; }
  .cp-soap-tabs button {
    border: none; background: transparent;
    border-radius: 999px 999px 0 0;
    padding: 8px 14px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    margin-bottom: -1px;
  }
  .cp-soap-tabs button.active {
    background: #D6F0F0;
    color: #008C8C;
    font-weight: 600;
  }

  .cp-soap-section { margin-bottom: 18px; }
  .cp-soap-section h4 {
    font-size: 12px; font-weight: 600;
    color: #4F5763;
    margin: 0 0 6px;
    line-height: 1;
  }
  .cp-soap-text {
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 12px; color: #0D1B2A;
    line-height: 1.55;
  }

  /* ROS pills */
  .cp-ros { display: flex; flex-wrap: wrap; gap: 6px; }
  .cp-ros .pill {
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 4px 12px;
    font-size: 11px; font-weight: 500;
    color: #4F5763;
    line-height: 1.2;
    display: inline-flex; align-items: center; gap: 4px;
  }
  .cp-ros .pill.flag {
    background: rgba(250, 140, 51, 0.14);
    border-color: rgba(250, 140, 51, 0.25);
    color: #FA8C33;
  }
  .cp-ros .pill .ic { font-size: 10px; }

  /* Right rail */
  .cp-rail { display: flex; flex-direction: column; gap: 12px; }
  .cp-rail-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 16px 16px;
  }
  .cp-rail-head {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 10px;
  }
  .cp-rail-head h4 { font-size: 13px; font-weight: 700; color: #0D1B2A; margin: 0; line-height: 1; }
  .cp-rail-head .cnt {
    background: #F5F6F7; color: #4F5763;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 600;
  }
  .cp-rail-head .add {
    margin-left: auto;
    border: none; background: transparent;
    color: #008C8C; font-weight: 600;
    font-size: 11px;
  }

  .cp-rail-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 0;
    border-top: 1px solid #F0F1F3;
    font-size: 12px;
  }
  .cp-rail-row:first-of-type { border-top: none; padding-top: 4px; }
  .cp-rail-row .tag {
    background: #F0F4F9; color: #4785D9;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 10px; font-weight: 600;
    flex: 0 0 auto;
  }
  .cp-rail-row .tag.dx { background: #F0FAFA; color: #008C8C; }
  .cp-rail-row .name { flex: 1; color: #0D1B2A; min-width: 0; }
  .cp-rail-row .meta { color: #8A91A1; font-size: 11px; }

  /* Co-Pilot card */
  .cp-rail-card.copilot {
    background: #F0FAFA;
    border-color: #B3E0E0;
  }
  .cp-rail-card.copilot .head {
    display: flex; align-items: center; gap: 6px;
    color: #008C8C; font-weight: 700; font-size: 12px;
    margin-bottom: 8px;
  }
  .cp-rail-card.copilot p {
    margin: 0 0 12px;
    font-size: 12px; color: #0D1B2A;
    line-height: 1.55;
  }
  .cp-rail-card.copilot .actions { display: flex; gap: 8px; }
  .cp-rail-card.copilot .actions button {
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 11px; font-weight: 500;
    line-height: 1;
    border: 1px solid transparent;
  }
  .cp-rail-card.copilot .actions .insert {
    background: #008C8C; color: #FFFFFF;
    font-weight: 600;
  }
  .cp-rail-card.copilot .actions .dismiss {
    background: transparent; color: #4F5763; border-color: #E4E5E8;
  }
</style>
</head>
<body>

<header class="cp-en-head">
  <div class="cp-en-title"><?php echo $encOpen ? xlt("Today's Visit") : xlt('Encounter'); ?></div>
  <div class="cp-en-bullet">•</div>
  <div class="cp-en-meta"><?php echo text($encReason); ?> — <?php echo text($providerName); ?></div>
  <span class="cp-en-status" style="<?php echo $encOpen ? '' : 'background: rgba(51,166,102,0.14); color: #1F8C4D;'; ?>"><span class="dot" style="<?php echo $encOpen ? '' : 'background:#1F8C4D;'; ?>"></span> <?php echo text($encStatus); ?></span>
  <div class="cp-en-spacer"></div>
  <button type="button" class="cp-en-btn ghost">⎙ <?php echo xlt('Print'); ?></button>
  <button type="button" class="cp-en-btn ghost"><?php echo xlt('Save draft'); ?></button>
  <form method="post" style="display:inline;" onsubmit="return confirm('Sign & lock this encounter?');">
    <input type="hidden" name="action" value="sign_encounter">
    <button type="submit" class="cp-en-btn primary"><?php echo xlt('Sign & lock'); ?> →</button>
  </form>
</header>

<?php if ($flash): ?>
  <div style="background:#EBF8F0; border:1px solid #B6E0C5; padding:8px 24px; color:#1F8C4D; font-size:12px;"><?php echo text($flash); ?></div>
<?php endif; ?>

<section class="cp-vitals">
  <span class="cp-vitals-lbl"><?php echo xlt('VITALS'); ?></span>
  <?php foreach ($vitals as [$lbl, $v, $u, $col]): ?>
    <div class="cp-vital-card">
      <div class="l"><?php echo text($lbl); ?></div>
      <div class="v <?php echo $col === '#33A666' ? 'good' : ''; ?>">
        <?php echo text($v); ?> <span class="u"><?php echo text($u); ?></span>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="cp-vitals-spacer"></div>
  <button type="button" class="cp-add-meas">+ <?php echo xlt('Add measurement'); ?></button>
</section>

<main class="cp-en-body">

  <section class="cp-soap-panel">
    <div class="cp-soap-tabs">
      <?php foreach ($soap_tabs as [$lbl, $active]): ?>
        <button type="button" class="<?php echo $active ? 'active' : ''; ?>"><?php echo text($lbl); ?></button>
      <?php endforeach; ?>
    </div>

    <div class="cp-soap-section">
      <h4><?php echo xlt('Chief Complaint'); ?></h4>
      <div class="cp-soap-text"><?php echo text($encReason); ?></div>
    </div>

    <div class="cp-soap-section">
      <h4><?php echo xlt('History of Present Illness'); ?></h4>
      <div class="cp-soap-text">68F with Type 2 DM (E11.9) on Metformin 1000 mg BID and Lisinopril 10 mg daily. A1C trend: 7.9% (Apr) → 7.4% (Feb) → 7.2% (Nov). Adherent to medication; struggling with portion control at family meals.<br><br>Denies polyuria, polydipsia, blurred vision. No new chest pain, dyspnea on exertion, or peripheral edema. Adheres to home BP log — averages 128/82.</div>
    </div>

    <div class="cp-soap-section">
      <h4><?php echo xlt('Review of Systems'); ?></h4>
      <div class="cp-ros">
        <?php foreach ($ros as [$sys, $kind]): ?>
          <span class="pill <?php echo $kind === 'flag' ? 'flag' : ''; ?>">
            <?php echo text($sys); ?>
            <span class="ic"><?php echo $kind === 'flag' ? '⚠' : '✓'; ?></span>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <aside class="cp-rail">

    <div class="cp-rail-card">
      <div class="cp-rail-head">
        <h4><?php echo xlt('Active Orders'); ?></h4>
        <span class="cnt">3</span>
        <button type="button" class="add">+ <?php echo xlt('Add'); ?></button>
      </div>
      <?php foreach ($orders as [$type, $name, $meta]): ?>
        <div class="cp-rail-row">
          <span class="tag"><?php echo text($type); ?></span>
          <span class="name"><?php echo text($name); ?></span>
          <span class="meta"><?php echo text($meta); ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="cp-rail-card">
      <div class="cp-rail-head">
        <h4><?php echo xlt('Diagnoses for this visit'); ?></h4>
        <span class="cnt">2</span>
        <button type="button" class="add">+ <?php echo xlt('Add'); ?></button>
      </div>
      <?php foreach ($dxs as [$code, $name]): ?>
        <div class="cp-rail-row">
          <span class="tag dx"><?php echo text($code); ?></span>
          <span class="name"><?php echo text($name); ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="cp-rail-card copilot">
      <div class="head">✦ <?php echo xlt('Co-Pilot suggestion'); ?></div>
      <p>A1C trending up — consider GLP-1 agonist if no improvement at 3-month recheck. Patient counseled on portion control.</p>
      <div class="actions">
        <button type="button" class="insert"><?php echo xlt('Insert into note'); ?></button>
        <button type="button" class="dismiss"><?php echo xlt('Dismiss'); ?></button>
      </div>
    </div>

  </aside>

</main>

</body>
</html>
