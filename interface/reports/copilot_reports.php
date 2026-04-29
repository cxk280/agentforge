<?php

/**
 * Reports landing page — implements Screen 8 of the AgentForge mockups.
 *
 * Categorised card grid (Clinical / Operations / Financial) replacing
 * the legacy Reports dropdown. Each card links to the corresponding
 * existing OpenEMR report URL so the underlying functionality is
 * preserved.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

// Section data — mirrors the Figma Screen 8 mockup exactly.
// Each entry: [icon, title, description, target_url].
$sections = [
    [
        'label' => 'CLINICAL',
        'color' => '#008C8C',
        'cards' => [
            ['📋', 'Patient List',          'Filterable cohort export with demographics + active conditions',
                '/interface/reports/patient_list.php'],
            ['💊', 'Prescription Report',   'All Rx in date range, by provider or drug class',
                '/interface/reports/prescriptions_report.php'],
            ['🧪', 'Lab Trends',            'A1C, lipids, BP across patient panel',
                '/interface/orders/orders_results.php'],
            ['📊', 'Quality Measures (CQM)', 'Standard + automated measures for MIPS reporting',
                '/interface/reports/cqm.php?type=standard'],
            ['💉', 'Immunization Registry', 'Due / overdue immunizations by age band',
                '/interface/reports/immunization_report.php'],
        ],
    ],
    [
        'label' => 'OPERATIONS',
        'color' => '#4885D9',
        'cards' => [
            ['📅', 'Daily Summary',         "Today's appointments, encounters, no-shows",
                '/interface/reports/daily_summary_report.php'],
            ['✅', 'Encounters',            'Encounter counts by provider and visit type',
                '/interface/reports/encounters_report.php'],
            ['🏥', 'Patient Flow Board',    'Live status across exam rooms',
                '/interface/reports/patient_flow_board_report.php'],
            ['📈', 'Chart Activity',        'Open vs locked notes, signing turnaround',
                '/interface/reports/chart_location_activity.php'],
        ],
    ],
    [
        'label' => 'FINANCIAL',
        'color' => '#FA8C33',
        'cards' => [
            ['💰', 'Daily Cash Reconciliation', 'Receipts by method, balanced against deposits',
                '/interface/billing/sl_receipts_report.php'],
            ['📉', 'Aging & Collections',       '30/60/90/120 buckets per insurer',
                '/interface/reports/collections_report.php'],
            ['🧾', 'Patient Ledger',            'All charges and payments for a single patient',
                '/interface/reports/pat_ledger.php?form=0'],
            ['📋', 'Insurance Distribution',    'Volume and revenue by payer',
                '/interface/reports/insurance_allocation_report.php'],
        ],
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Reports'); ?></title>
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
  .cp-rep-header {
    width: 100%;
    height: 64px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 16px;
  }
  .cp-rep-title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-rep-spacer { flex: 1; }
  .cp-rep-search {
    display: inline-flex; align-items: center; gap: 8px;
    background: #F5F7F8;
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 13px; color: #8A91A0;
    border: none;
    cursor: pointer;
    line-height: 1;
  }
  .cp-rep-search:hover { background: #ECEEF0; }

  /* ── Content ─────────────────────────────────────────────────────────── */
  .cp-rep-content {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 24px;
  }
  .cp-rep-section { display: flex; flex-direction: column; gap: 12px; }
  .cp-rep-sec-head {
    display: flex; align-items: center; gap: 8px;
    line-height: 1;
  }
  .cp-rep-sec-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex: 0 0 auto;
  }
  .cp-rep-sec-label {
    font-size: 11px; font-weight: 600;
    color: #8A91A0;
    letter-spacing: 0.6px;
  }

  .cp-rep-grid {
    display: grid;
    grid-template-columns: repeat(5, 270px);
    gap: 12px;
  }
  /* Operations + Financial sections only have 4 cards; force a 4-col grid
     for them so cards stay 270px wide and don't stretch */
  .cp-rep-grid.cols-4 {
    grid-template-columns: repeat(4, 270px);
  }

  .cp-rep-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    text-decoration: none;
    color: inherit;
    transition: border-color 0.12s, box-shadow 0.12s, transform 0.05s;
    min-height: 153px;
  }
  .cp-rep-card:hover {
    border-color: #008C8C;
    box-shadow: 0 1px 2px rgba(13, 27, 42, 0.04), 0 4px 12px rgba(13, 27, 42, 0.06);
  }
  .cp-rep-card:active { transform: translateY(1px); }

  .cp-rep-icon {
    width: 36px; height: 36px;
    border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex: 0 0 auto;
  }
  .cp-rep-icon-spacer { height: 4px; flex: 0 0 auto; }
  .cp-rep-card-title {
    font-size: 14px; font-weight: 600; color: #0D1B2A;
    line-height: 1.2;
  }
  .cp-rep-card-desc {
    font-size: 12px; color: #8A91A0;
    line-height: 1.5;
    margin: 0;
  }
</style>
</head>
<body>

<header class="cp-rep-header">
  <div class="cp-rep-title"><?php echo xlt('Reports'); ?></div>
  <div class="cp-rep-spacer"></div>
  <button class="cp-rep-search" type="button">
    <span>🔍</span><span><?php echo xlt('Search reports'); ?></span>
  </button>
</header>

<main class="cp-rep-content">
  <?php foreach ($sections as $sec): ?>
    <section class="cp-rep-section">
      <div class="cp-rep-sec-head">
        <span class="cp-rep-sec-dot" style="background-color: <?php echo attr($sec['color']); ?>;"></span>
        <span class="cp-rep-sec-label"><?php echo text($sec['label']); ?></span>
      </div>
      <?php
      // Color-tinted icon background using a 12% mix of the section color
      // over white. Encoded inline so each card uses its section's tint.
      $iconBg = $sec['color'] . '1F'; // 1F = ~12% alpha when supported
      ?>
      <div class="cp-rep-grid<?php echo (count($sec['cards']) === 4) ? ' cols-4' : ''; ?>">
        <?php foreach ($sec['cards'] as $card): ?>
          <a class="cp-rep-card" href="<?php echo attr($card[3]); ?>" target="_top">
            <span class="cp-rep-icon" style="background-color: <?php echo attr($iconBg); ?>;"><?php echo $card[0]; ?></span>
            <div class="cp-rep-icon-spacer"></div>
            <div class="cp-rep-card-title"><?php echo text($card[1]); ?></div>
            <p class="cp-rep-card-desc"><?php echo text($card[2]); ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</main>

</body>
</html>
