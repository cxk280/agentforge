<?php

/**
 * Patient Visit History — implements Screen 12 of the AgentForge mockups.
 *
 * Renders the "History" navtab content: filter chips, year-grouped visit
 * cards each showing date rail (date / day / type pill) and visit body
 * (provider, modality, signature, title, narrative, tags, "Open visit").
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../../main/copilot_helpers.php");

$pid = (int)($_SESSION['pid'] ?? 1);

$filters = [
    ['All',         true],
    ['Office Visit', false],
    ['Telehealth',   false],
    ['Lab Review',   false],
    ['Acute',        false],
];

$totalCount = (int)(sqlQuery("SELECT COUNT(*) AS n FROM form_encounter WHERE pid = ?", [$pid])['n'] ?? 0);

$visits = [];
$rows = sqlStatement(
    "SELECT fe.id, fe.date, fe.reason, fe.last_level_closed,
            u.username, u.fname, u.lname, u.title
     FROM form_encounter fe LEFT JOIN users u ON fe.provider_id = u.id
     WHERE fe.pid = ? ORDER BY fe.date DESC LIMIT 30",
    [$pid]
);
while ($r = sqlFetchArray($rows)) {
    $ts = $r['date'] ? strtotime($r['date']) : time();
    $year = date('Y', $ts);
    $dateLbl = date('M j', $ts);
    $dayLbl = date('l', $ts);
    $reason = $r['reason'] ?: 'Office visit';
    // Heuristic type from reason text
    $reasonL = strtolower($reason);
    if (str_contains($reasonL, 'annual')) { $type = 'Annual Physical'; $color = '#008C8C'; }
    elseif (str_contains($reasonL, 'lab') || str_contains($reasonL, 'a1c') || str_contains($reasonL, 'cholesterol')) { $type = 'Lab Review'; $color = '#4885D9'; }
    elseif (str_contains($reasonL, 'follow')) { $type = 'Follow-up'; $color = '#4885D9'; }
    elseif (str_contains($reasonL, 'tele')) { $type = 'Telehealth'; $color = '#8561C7'; }
    else { $type = 'Office Visit'; $color = '#33A68C'; }
    $prov = cp_format_provider_name([
        'username' => $r['username'] ?? '', 'fname' => $r['fname'] ?? '',
        'lname' => $r['lname'] ?? '', 'title' => $r['title'] ?? '',
    ]);
    $closed = (int)($r['last_level_closed'] ?? 0) > 0;
    $visits[] = [
        'year' => $year,
        'date' => $dateLbl,
        'day'  => $dayLbl,
        'type' => $type,
        'type_color' => $color,
        'provider' => $prov,
        'modality' => null,
        'duration' => '—',
        'status'   => $closed ? 'Signed' : 'Open',
        'title'    => $reason,
        'desc'     => '',
        'tags'     => [],
    ];
}

// group by year preserving order
$by_year = [];
foreach ($visits as $v) { $by_year[$v['year']][] = $v; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Visit History'); ?></title>
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
  button, input { font-family: inherit; }

  /* ── Page header ─────────────────────────────────────────────────────── */
  .cp-hist-head {
    padding: 20px 24px 14px;
    display: flex; align-items: center; gap: 12px;
    background: #F5F7F8;
  }
  .cp-hist-title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-hist-meta  { font-size: 12px; color: #8A91A0; line-height: 1; }
  .cp-hist-meta::before {
    content: ''; display: inline-block;
    width: 4px; height: 4px; border-radius: 50%;
    background: #C7CBD2; margin-right: 8px; vertical-align: middle;
  }
  .cp-hist-spacer { flex: 1; }

  .cp-filters { display: inline-flex; align-items: center; gap: 8px; }
  .cp-chip {
    height: 28px;
    padding: 0 12px;
    border-radius: 999px;
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    color: #4F5662;
    font-size: 12px;
    font-weight: 500;
    line-height: 1;
    display: inline-flex; align-items: center;
    cursor: pointer;
    transition: border-color 0.12s, color 0.12s, background 0.12s;
  }
  .cp-chip:hover { border-color: #C7CBD2; }
  .cp-chip.active {
    border-color: #008C8C;
    background: #E6F4F4;
    color: #008C8C;
  }
  .cp-new-btn {
    height: 32px;
    padding: 0 14px;
    border-radius: 999px;
    background: #008C8C;
    color: #FFFFFF;
    border: none;
    font-size: 13px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 6px;
    cursor: pointer;
    margin-left: 8px;
  }
  .cp-new-btn:hover { background: #00787A; }

  /* ── Year + cards body ───────────────────────────────────────────────── */
  .cp-hist-body { padding: 0 24px 32px; display: flex; flex-direction: column; gap: 12px; }
  .cp-year-row {
    display: flex; align-items: center; gap: 12px;
    margin: 8px 0 4px;
  }
  .cp-year { font-size: 14px; font-weight: 600; color: #0D1B2A; line-height: 1; }
  .cp-year-rule { flex: 1; height: 1px; background: #E4E5E8; }

  .cp-visit-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px 20px;
    display: flex;
    gap: 24px;
    position: relative;
  }

  .cp-rail {
    width: 110px;
    flex: 0 0 auto;
    display: flex; flex-direction: column; align-items: flex-start; gap: 6px;
  }
  .cp-rail .date {
    font-size: 22px; font-weight: 700;
    color: #008C8C;
    line-height: 1.1;
  }
  .cp-rail .day {
    font-size: 12px; color: #8A91A0;
  }
  .cp-rail .pip {
    margin-top: 6px;
    width: 8px; height: 8px;
    border-radius: 50%;
  }
  .cp-rail .type-pill {
    margin-top: 2px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #ECEEF0;
    color: #4F5662;
    font-size: 11px; font-weight: 500;
    line-height: 1;
  }

  .cp-rail.lab-review     .pip { background: #4885D9; }
  .cp-rail.lab-review     .type-pill { background: #E8F0F8; color: #4885D9; }
  .cp-rail.annual-physical .pip { background: #008C8C; }
  .cp-rail.annual-physical .type-pill { background: #E6F4F4; color: #008C8C; }
  .cp-rail.followup       .pip { background: #4885D9; }
  .cp-rail.followup       .type-pill { background: #E8F0F8; color: #4885D9; }

  .cp-body { flex: 1; min-width: 0; padding-right: 90px; display: flex; flex-direction: column; gap: 8px; }
  .cp-body .meta-row {
    display: flex; align-items: center; gap: 12px;
    font-size: 12px; color: #4F5662;
    line-height: 1;
  }
  .cp-body .meta-row .sep::before {
    content: '•';
    color: #C7CBD2;
    margin: 0 4px;
  }
  .cp-body .meta-row .signed {
    padding: 3px 8px;
    border-radius: 4px;
    background: #E6F5EC;
    color: #1F8C4D;
    font-size: 11px; font-weight: 600;
    line-height: 1;
  }
  .cp-body .v-title {
    font-size: 15px; font-weight: 700; color: #0D1B2A;
    line-height: 1.3;
  }
  .cp-body .v-desc {
    font-size: 13px; color: #4F5662;
    line-height: 1.5;
    margin: 0;
  }
  .cp-tags { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 2px; }
  .cp-tag {
    height: 22px;
    padding: 0 10px;
    border-radius: 999px;
    background: #F5F7F8;
    border: 1px solid #E4E5E8;
    color: #4F5662;
    font-size: 11px; font-weight: 500;
    line-height: 1;
    display: inline-flex; align-items: center;
  }
  .cp-tag.success { background: #E6F4F4; border-color: #B6E1E1; color: #008C8C; }
  .cp-tag.copilot { background: #E6F4F4; border-color: #B6E1E1; color: #008C8C; }

  .cp-open {
    position: absolute;
    top: 18px; right: 20px;
    font-size: 13px; font-weight: 500;
    color: #008C8C;
    text-decoration: none;
  }
  .cp-open:hover { text-decoration: underline; }
</style>
</head>
<body>

<header class="cp-hist-head">
  <div class="cp-hist-title"><?php echo xlt('Visit History'); ?></div>
  <div class="cp-hist-meta"><?php echo text($totalCount); ?> <?php echo xlt('encounters'); ?></div>
  <div class="cp-hist-spacer"></div>
  <div class="cp-filters">
    <?php foreach ($filters as [$label, $active]): ?>
      <button class="cp-chip<?php echo $active ? ' active' : ''; ?>" type="button"><?php echo text($label); ?></button>
    <?php endforeach; ?>
    <button class="cp-new-btn" type="button">+ <?php echo xlt('New Visit'); ?></button>
  </div>
</header>

<main class="cp-hist-body">
  <?php foreach ($by_year as $year => $year_visits): ?>
    <div class="cp-year-row">
      <span class="cp-year"><?php echo text($year); ?></span>
      <span class="cp-year-rule"></span>
    </div>
    <?php foreach ($year_visits as $v):
        $rail_class = strtolower(str_replace([' ', '-'], ['-', '-'], $v['type']));
        $rail_class = preg_replace('/[^a-z0-9-]/', '', $rail_class);
    ?>
      <article class="cp-visit-card">
        <div class="cp-rail <?php echo attr($rail_class); ?>">
          <span class="date"><?php echo text($v['date']); ?></span>
          <span class="day"><?php echo text($v['day']); ?></span>
          <span class="pip"></span>
          <span class="type-pill"><?php echo text($v['type']); ?></span>
        </div>
        <div class="cp-body">
          <div class="meta-row">
            <span><?php echo text($v['provider']); ?></span>
            <?php if (!empty($v['modality'])): ?><span class="sep"></span><span><?php echo text($v['modality']); ?></span><?php endif; ?>
            <span class="sep"></span><span><?php echo text($v['duration']); ?></span>
            <span class="signed"><?php echo text($v['status']); ?></span>
          </div>
          <div class="v-title"><?php echo text($v['title']); ?></div>
          <p class="v-desc"><?php echo text($v['desc']); ?></p>
          <?php if (!empty($v['tags'])): ?>
            <div class="cp-tags">
              <?php foreach ($v['tags'] as [$tag, $kind]): ?>
                <span class="cp-tag <?php echo attr($kind); ?>"><?php echo text($tag); ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <a class="cp-open" href="#"><?php echo xlt('Open visit'); ?> →</a>
      </article>
    <?php endforeach; ?>
  <?php endforeach; ?>
</main>

</body>
</html>
