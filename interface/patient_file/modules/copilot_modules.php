<?php

/**
 * Patient Modules — implements Screen 21 of the AgentForge mockups.
 *
 * Renders the "Modules" navtab content: header with module summary
 * and Active/Available/All segmented control, then two grouped grids
 * (ACTIVE — CLINICAL and AVAILABLE — RECOMMENDED FOR THIS PATIENT)
 * of module cards with icon bubble, version/vendor, description,
 * status pill, and action button.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$status_tabs = [
    ['Active',    true],
    ['Available', false],
    ['All',       false],
];

// status: active | update | available
$active_clinical = [
    [
        'name' => 'Care Coordination',
        'icon' => '🤝', 'icon_tone' => 'teal',
        'ver'  => 'v2.4.1', 'vendor' => 'OpenEMR Foundation',
        'desc' => 'Care plan, care team roster, transitions of care. Direct messaging integrated.',
        'status' => 'ACTIVE', 'status_tone' => 'good',
        'action' => 'Open →', 'action_tone' => 'primary',
    ],
    [
        'name' => 'Clinical Decision Rules',
        'icon' => '✨', 'icon_tone' => 'info',
        'ver'  => 'v1.9.3', 'vendor' => 'OpenEMR Foundation',
        'desc' => 'CQM rules engine: drives reminders, alerts, and quality measure calculation.',
        'status' => 'ACTIVE', 'status_tone' => 'good',
        'action' => 'Open →', 'action_tone' => 'primary',
    ],
    [
        'name' => 'EasiPRO',
        'icon' => '📊', 'icon_tone' => 'violet',
        'ver'  => 'v3.1.0', 'vendor' => 'Northwestern',
        'desc' => 'Patient-Reported Outcome instruments delivered through the Patient Portal.',
        'status' => 'ACTIVE', 'status_tone' => 'good',
        'action' => 'Open →', 'action_tone' => 'primary',
    ],
    [
        'name' => 'ClinicalTables FHIR',
        'icon' => '🔗', 'icon_tone' => 'mint',
        'ver'  => 'v0.7.2', 'vendor' => 'NLM',
        'desc' => 'Code-set lookups for ICD-10, SNOMED, RxNorm via the FHIR ValueSet API.',
        'status' => 'UPDATE AVAILABLE', 'status_tone' => 'warn',
        'action' => 'Update', 'action_tone' => 'warn',
    ],
];

$available = [
    [
        'name' => 'Diabetes Coach',
        'icon' => '🩸', 'icon_tone' => 'warn',
        'ver'  => 'v1.2.0', 'vendor' => 'RiversideHealth',
        'desc' => 'Glucose log integration, A1C trending, and Co-Pilot diabetes-focused prompts.',
        'status' => 'AVAILABLE', 'status_tone' => 'neutral',
        'action' => 'Install', 'action_tone' => 'secondary',
    ],
    [
        'name' => 'Care Plan Templates',
        'icon' => '📋', 'icon_tone' => 'info',
        'ver'  => 'v0.9.1', 'vendor' => 'OpenEMR Foundation',
        'desc' => 'Condition-specific care plan templates with order sets and patient education.',
        'status' => 'AVAILABLE', 'status_tone' => 'neutral',
        'action' => 'Install', 'action_tone' => 'secondary',
    ],
    [
        'name' => 'Pharmacy Sync',
        'icon' => '💊', 'icon_tone' => 'pink',
        'ver'  => 'v2.0.1', 'vendor' => 'Surescripts',
        'desc' => 'Two-way sync of medication history, including external prescriptions.',
        'status' => 'AVAILABLE', 'status_tone' => 'neutral',
        'action' => 'Install', 'action_tone' => 'secondary',
    ],
    [
        'name' => 'Telehealth Studio',
        'icon' => '📹', 'icon_tone' => 'violet',
        'ver'  => 'v4.2.0', 'vendor' => 'OpenEMR Foundation',
        'desc' => 'Embedded video visits with screen-share, captioning, and visit recording.',
        'status' => 'AVAILABLE', 'status_tone' => 'neutral',
        'action' => 'Install', 'action_tone' => 'secondary',
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Modules'); ?></title>
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

  /* ── Header ──────────────────────────────────────────────────────────── */
  .cp-mod-head {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    height: 60px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-mod-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-mod-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-mod-meta { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-mod-spacer { flex: 1; }

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
  .cp-seg .opt.active { background: #FFFFFF; color: #008C8C; }

  .cp-marketplace {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px; font-weight: 500;
    color: #0D1B2A;
    line-height: 1;
  }
  .cp-marketplace:hover { background: #F5F6F7; }

  /* ── Body ────────────────────────────────────────────────────────────── */
  .cp-mod-body { padding: 20px 24px 32px; display: flex; flex-direction: column; gap: 16px; }

  .cp-section { display: flex; flex-direction: column; gap: 12px; }
  .cp-section-head {
    display: flex; align-items: center; gap: 8px;
  }
  .cp-section-head .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.6px;
    line-height: 1;
    flex: 0 0 auto;
  }
  .cp-section-head .rule {
    height: 1px;
    background: #E4E5E8;
    flex: 1;
  }

  .cp-mod-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }
  .cp-mod-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px;
    display: flex; flex-direction: column; gap: 10px;
  }
  .cp-mod-top { display: flex; align-items: flex-start; gap: 12px; }
  .cp-mod-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 22px;
    flex: 0 0 auto;
  }
  .cp-mod-icon.teal   { background: rgba(0, 140, 140, 0.15); }
  .cp-mod-icon.info   { background: rgba(71, 133, 217, 0.15); }
  .cp-mod-icon.violet { background: rgba(133, 97, 199, 0.15); }
  .cp-mod-icon.mint   { background: rgba(51, 166, 140, 0.15); }
  .cp-mod-icon.warn   { background: rgba(250, 140, 51, 0.15); }
  .cp-mod-icon.pink   { background: rgba(217, 102, 140, 0.15); }

  .cp-mod-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
  .cp-mod-name { font-size: 14px; font-weight: 700; color: #0D1B2A; line-height: 1.2; }
  .cp-mod-sub  {
    font-size: 10px; color: #8A91A1;
    display: flex; align-items: center; gap: 6px;
    line-height: 1;
  }
  .cp-mod-sub .ver { font-weight: 500; }
  .cp-mod-sub .b   { color: #C7CBD2; }

  .cp-mod-desc {
    font-size: 12px; color: #4F5763;
    line-height: 1.5;
  }

  .cp-mod-foot { display: flex; align-items: center; gap: 8px; }
  .cp-status-pill {
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.4px;
    line-height: 1.2;
    display: inline-flex; align-items: center; gap: 4px;
  }
  .cp-status-pill .dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    display: inline-block;
  }
  .cp-status-pill.good {
    background: rgba(51, 166, 102, 0.14);
    color: #33A666;
  }
  .cp-status-pill.good .dot { background: #33A666; }
  .cp-status-pill.warn {
    background: rgba(250, 140, 51, 0.14);
    color: #FA8C33;
  }
  .cp-status-pill.warn .dot { background: #FA8C33; }
  .cp-status-pill.neutral {
    background: rgba(138, 145, 161, 0.14);
    color: #8A91A1;
  }
  .cp-status-pill.neutral .dot { background: #8A91A1; }

  .cp-mod-spacer-row { flex: 1; }

  .cp-action {
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 11px; font-weight: 600;
    line-height: 1;
    border: 1px solid transparent;
    display: inline-flex; align-items: center;
  }
  .cp-action.primary {
    background: #008C8C; color: #FFFFFF;
  }
  .cp-action.primary:hover { background: #00787A; }
  .cp-action.warn {
    background: #FA8C33; color: #FFFFFF;
  }
  .cp-action.warn:hover { background: #E97D24; }
  .cp-action.secondary {
    background: #FFFFFF; color: #0D1B2A; border-color: #E4E5E8;
    font-weight: 500;
  }
  .cp-action.secondary:hover { background: #F5F6F7; }
</style>
</head>
<body>

<header class="cp-mod-head">
  <div class="cp-mod-title"><?php echo xlt('Modules'); ?></div>
  <div class="cp-mod-bullet">•</div>
  <div class="cp-mod-meta">Patient-context modules • 4 active, 3 available</div>
  <div class="cp-mod-spacer"></div>
  <div class="cp-seg">
    <?php foreach ($status_tabs as [$label, $active]): ?>
      <button type="button" class="opt<?php echo $active ? ' active' : ''; ?>"><?php echo text($label); ?></button>
    <?php endforeach; ?>
  </div>
  <button type="button" class="cp-marketplace"><?php echo xlt('Browse Marketplace'); ?> →</button>
</header>

<main class="cp-mod-body">
  <?php
  $sections = [
      ['ACTIVE — CLINICAL',                           $active_clinical],
      ['AVAILABLE — RECOMMENDED FOR THIS PATIENT',   $available],
  ];
  foreach ($sections as [$head, $items]):
  ?>
    <section class="cp-section">
      <header class="cp-section-head">
        <span class="lbl"><?php echo text($head); ?></span>
        <span class="rule"></span>
      </header>
      <div class="cp-mod-grid">
        <?php foreach ($items as $m): ?>
          <article class="cp-mod-card">
            <div class="cp-mod-top">
              <div class="cp-mod-icon <?php echo attr($m['icon_tone']); ?>"><?php echo text($m['icon']); ?></div>
              <div class="cp-mod-info">
                <div class="cp-mod-name"><?php echo text($m['name']); ?></div>
                <div class="cp-mod-sub">
                  <span class="ver"><?php echo text($m['ver']); ?></span>
                  <span class="b">•</span>
                  <span><?php echo text($m['vendor']); ?></span>
                </div>
              </div>
            </div>
            <div class="cp-mod-desc"><?php echo text($m['desc']); ?></div>
            <div class="cp-mod-foot">
              <span class="cp-status-pill <?php echo attr($m['status_tone']); ?>">
                <span class="dot"></span>
                <span><?php echo text($m['status']); ?></span>
              </span>
              <span class="cp-mod-spacer-row"></span>
              <button type="button" class="cp-action <?php echo attr($m['action_tone']); ?>"><?php echo text($m['action']); ?></button>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</main>

</body>
</html>
