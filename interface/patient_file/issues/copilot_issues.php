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

$status_tabs = [
    ['Active',   true],
    ['Inactive', false],
    ['Resolved', false],
    ['All',      false],
];

// severity tones: warn (orange) | good (green)
$groups = [
    [
        'title'    => 'Active — Medical Problems',
        'count'    => 4,
        'dot_tone' => 'warn',
        'rows'     => [
            ['icd' => 'E11.9', 'title' => 'Type 2 Diabetes Mellitus',          'sub' => 'Onset 2019 • Active',                        'sev' => 'MODERATE', 'sev_tone' => 'warn'],
            ['icd' => 'I10',   'title' => 'Essential Hypertension',            'sub' => 'Onset 2017 • Controlled w/ Lisinopril',      'sev' => 'MODERATE', 'sev_tone' => 'warn'],
            ['icd' => 'E03.9', 'title' => 'Hypothyroidism, unspecified',       'sub' => 'Onset 2021 • Levothyroxine 50mcg daily',     'sev' => 'STABLE',   'sev_tone' => 'good'],
            ['icd' => 'M17.0', 'title' => 'Bilateral knee osteoarthritis',     'sub' => 'Onset 2022 • Conservative management',       'sev' => 'MILD',     'sev_tone' => 'good'],
        ],
    ],
    [
        'title'    => 'Active — Allergies & Risk Factors',
        'count'    => 3,
        'dot_tone' => 'danger',
        'rows'     => [
            ['icd' => 'Z88.0', 'title' => 'Allergy to Penicillin',                  'sub' => 'Documented 2017 • Itching, rash',  'sev' => 'MILD',     'sev_tone' => 'good'],
            ['icd' => 'Z88.2', 'title' => 'Allergy to Sulfa drugs',                 'sub' => 'Documented 2017 • Skin reaction',  'sev' => 'MILD',     'sev_tone' => 'good'],
            ['icd' => 'Z83.3', 'title' => 'Family hx of diabetes (mother, brother)','sub' => 'Documented 2019 • Risk factor',    'sev' => 'ADVISORY', 'sev_tone' => 'good'],
        ],
    ],
];
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
  <div class="cp-iss-meta">Active 9 • Inactive 4 • Resolved 12</div>
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
