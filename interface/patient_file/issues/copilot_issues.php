<?php

/**
 * Patient Issues — implements Screen 17 of the AgentForge mockups.
 *
 * Renders the "Issues" navtab content: header with summary counts,
 * status segmented control, "Add Issue" CTA, and grouped cards of
 * issue rows with ICD code chip, severity pill, and overflow menu.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$pid = (int)($_SESSION['pid'] ?? 1);

$status_tabs = [
    ['Active',   true],
    ['Inactive', false],
    ['Resolved', false],
    ['All',      false],
];

function cp_extract_icd(string $diagnosis): string
{
    if (preg_match('#ICD10:([A-Z][0-9.]+)#i', $diagnosis, $m)) { return strtoupper($m[1]); }
    if (preg_match('#ICD9:([0-9.]+)#i', $diagnosis, $m)) { return $m[1]; }
    return '—';
}

$problems = [];
$rows = sqlStatement("SELECT DISTINCT title, diagnosis, date FROM lists WHERE pid = ? AND type = 'medical_problem' AND COALESCE(enddate, '0000-00-00') = '0000-00-00' ORDER BY date ASC", [$pid]);
while ($r = sqlFetchArray($rows)) {
    $year = $r['date'] ? substr($r['date'], 0, 4) : '';
    $problems[] = [
        'icd' => cp_extract_icd((string)$r['diagnosis']),
        'title' => $r['title'],
        'sub' => ($year ? "Onset $year" : 'Onset unknown') . ' • Active',
        'sev' => 'ACTIVE',
        'sev_tone' => 'warn',
    ];
}

$allergies = [];
$rows = sqlStatement("SELECT DISTINCT title, severity_al, comments, date FROM lists WHERE pid = ? AND type = 'allergy' AND COALESCE(enddate, '0000-00-00') = '0000-00-00' ORDER BY date ASC", [$pid]);
while ($r = sqlFetchArray($rows)) {
    $year = $r['date'] ? substr($r['date'], 0, 4) : '';
    $sub = ($year ? "Documented $year" : 'Documented') . ($r['comments'] ? ' • ' . $r['comments'] : '');
    $sev = strtoupper((string)($r['severity_al'] ?? 'MILD'));
    $allergies[] = [
        'icd'   => 'Z88',
        'title' => 'Allergy to ' . $r['title'],
        'sub'   => $sub,
        'sev'   => $sev ?: 'MILD',
        'sev_tone' => 'good',
    ];
}

$groups = [];
if ($problems) {
    $groups[] = ['title' => 'Active — Medical Problems', 'count' => count($problems), 'dot_tone' => 'warn', 'rows' => $problems];
}
if ($allergies) {
    $groups[] = ['title' => 'Active — Allergies & Risk Factors', 'count' => count($allergies), 'dot_tone' => 'danger', 'rows' => $allergies];
}
if (!$groups) {
    $groups[] = ['title' => 'No active issues', 'count' => 0, 'dot_tone' => 'good', 'rows' => []];
}
$activeCount = array_sum(array_map(fn($g) => $g['count'], $groups));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Issues'); ?></title>
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

  /* ── Header bar ──────────────────────────────────────────────────────── */
  .cp-iss-head {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    height: 60px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-iss-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-iss-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-iss-meta { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-iss-spacer { flex: 1; }

  .cp-seg {
    background: #F5F6F7;
    border-radius: 999px;
    padding: 4px;
    display: inline-flex; align-items: center;
  }
  .cp-seg .opt {
    border-radius: 999px;
    padding: 4px 14px;
    font-size: 12px; font-weight: 500;
    line-height: 1;
    color: #4F5763;
    background: transparent;
    border: none;
  }
  .cp-seg .opt.active {
    background: #FFFFFF;
    color: #008C8C;
  }

  .cp-add-btn {
    background: #008C8C;
    color: #FFFFFF;
    border: none;
    border-radius: 999px;
    padding: 7px 16px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-add-btn:hover { background: #00787A; }
  .cp-add-btn .plus { font-size: 14px; font-weight: 700; line-height: 1; }

  /* ── Body ────────────────────────────────────────────────────────────── */
  .cp-iss-body { padding: 20px 24px 32px; display: flex; flex-direction: column; gap: 16px; }
  .cp-grp-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
    display: flex; flex-direction: column;
  }
  .cp-grp-head {
    height: 52px;
    padding: 0 22px;
    display: flex; align-items: center; gap: 12px;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    flex: 0 0 auto;
  }
  .cp-dot.warn   { background: #FA8C33; }
  .cp-dot.danger { background: #D93838; }
  .cp-dot.good   { background: #33A666; }
  .cp-grp-title { font-size: 14px; font-weight: 600; color: #0D1B2A; line-height: 1; }
  .cp-grp-count {
    background: #F5F6F7;
    border-radius: 999px;
    padding: 1px 8px;
    font-size: 11px; font-weight: 600;
    color: #4F5763;
    line-height: 1.4;
  }
  .cp-grp-row {
    height: 56px;
    padding: 0 22px;
    border-bottom: 0.5px solid #E4E5E8;
    display: flex; align-items: center; gap: 16px;
  }
  .cp-grp-row:last-child { border-bottom: none; }
  .cp-icd {
    background: #F5F6F7;
    border-radius: 4px;
    height: 22px;
    padding: 0 8px;
    font-size: 11px; font-weight: 600;
    letter-spacing: 0.4px;
    color: #4F5763;
    display: inline-flex; align-items: center;
    flex: 0 0 auto;
  }
  .cp-row-info { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
  .cp-row-title { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cp-row-sub   { font-size: 11px; color: #8A91A1; line-height: 1.2; }
  .cp-row-spacer { flex: 1; }
  .cp-sev-pill {
    border-radius: 4px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 700;
    letter-spacing: 0.4px;
    line-height: 1.2;
  }
  .cp-sev-pill.warn { background: rgba(250, 140, 51, 0.14); color: #FA8C33; }
  .cp-sev-pill.good { background: rgba(51, 166, 102, 0.14); color: #33A666; }
  .cp-kebab {
    color: #8A91A1; font-weight: 700; font-size: 18px;
    line-height: 1;
    padding: 4px 8px;
    cursor: pointer;
    user-select: none;
  }
  .cp-kebab:hover { color: #4F5763; }
</style>
</head>
<body>

<header class="cp-iss-head">
  <div class="cp-iss-title"><?php echo xlt('Issues'); ?></div>
  <div class="cp-iss-bullet">•</div>
  <div class="cp-iss-meta">Active <?php echo text($activeCount); ?></div>
  <div class="cp-iss-spacer"></div>
  <div class="cp-seg">
    <?php foreach ($status_tabs as [$label, $active]): ?>
      <button type="button" class="opt<?php echo $active ? ' active' : ''; ?>"><?php echo text($label); ?></button>
    <?php endforeach; ?>
  </div>
  <button type="button" class="cp-add-btn"><span class="plus">+</span><span><?php echo xlt('Add Issue'); ?></span></button>
</header>

<main class="cp-iss-body">
  <?php foreach ($groups as $grp): ?>
    <section class="cp-grp-card">
      <header class="cp-grp-head">
        <span class="cp-dot <?php echo attr($grp['dot_tone']); ?>"></span>
        <span class="cp-grp-title"><?php echo text($grp['title']); ?></span>
        <span class="cp-grp-count"><?php echo text((string)$grp['count']); ?></span>
      </header>
      <?php foreach ($grp['rows'] as $r): ?>
        <div class="cp-grp-row">
          <span class="cp-icd"><?php echo text($r['icd']); ?></span>
          <div class="cp-row-info">
            <div class="cp-row-title"><?php echo text($r['title']); ?></div>
            <div class="cp-row-sub"><?php echo text($r['sub']); ?></div>
          </div>
          <span class="cp-row-spacer"></span>
          <span class="cp-sev-pill <?php echo attr($r['sev_tone']); ?>"><?php echo text($r['sev']); ?></span>
          <span class="cp-kebab">⋯</span>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
</main>

</body>
</html>
