<?php

/**
 * Billing Manager — implements Screen 27 of the AgentForge mockups.
 *
 * Billing-financial archetype page. Renders 4 KPI cards (Open claims,
 * Submitted 30d, Paid 30d, Outstanding A/R), a status filter strip with
 * search/date-range, and a claims table with status pills and per-row
 * actions (View / Submit / Resolve).
 *
 * This page is the canonical "billing/financial" archetype — copy this
 * as a starting point for any other claim/payment/financial-table page.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$kpis = [
    ['Open claims',       '128',       'Avg age 18 days',         '#0D1B2A'],
    ['Submitted (30d)',   '$48,920',   '94 claims to payers',     '#4785D9'],
    ['Paid (30d)',        '$41,180',   '84% first-pass acceptance','#1F8C4D'],
    ['Outstanding A/R',   '$132,840',  '$28k in 90+ aging',       '#FA8C33'],
];

$status_pills = [
    ['All',       true],
    ['Open',      false],
    ['Submitted', false],
    ['Paid',      false],
    ['Denied',    false],
    ['Voided',    false],
];

// status: submitted | paid | denied | pending | outstanding
$claims = [
    ['CLM-9402', 'Margaret Chen',  'Apr 12', '99213', 'BCBS PPO',   '$152.00', 'Submitted',  '2h ago',     'view'],
    ['CLM-9401', 'Ted Shaw',       'Apr 12', '99214', 'Aetna HMO',  '$215.00', 'Paid',       '5h ago',     'view'],
    ['CLM-9399', 'Linda Martinez', 'Apr 11', '99215', 'United HC',  '$310.00', 'Denied',     'Yesterday',  'resolve'],
    ['CLM-9398', 'David Kim',      'Apr 11', '99213', 'Cigna PPO',  '$152.00', 'Pending',    'Yesterday',  'submit'],
    ['CLM-9397', 'Allison Park',   'Apr 11', '99396', 'Medicare',   '$280.00', 'Paid',       'Yesterday',  'view'],
    ['CLM-9395', 'Robert Hayes',   'Apr 10', '80050', 'BCBS PPO',   '$184.00', 'Submitted',  '2 days ago', 'view'],
    ['CLM-9394', 'Carol Bennett',  'Apr 10', '99213', 'Self-pay',   '$152.00', 'Outstanding','2 days ago', 'submit'],
    ['CLM-9392', 'James Wong',     'Apr 9',  '99214', 'United HC',  '$215.00', 'Paid',       '3 days ago', 'view'],
];

$status_tone = [
    'Submitted'   => ['#F0F4F9', '#4785D9'],
    'Paid'        => ['#EBF8F0', '#1F8C4D'],
    'Denied'      => ['#FCE7E7', '#D93838'],
    'Pending'     => ['#FFF8EC', '#FA8C33'],
    'Outstanding' => ['#FFF1E5', '#D9701A'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Billing Manager'); ?></title>
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
  .cp-bm-head {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 14px 24px;
    display: flex; align-items: center; gap: 10px;
  }
  .cp-bm-title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-bm-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-bm-meta { color: #4F5763; font-size: 12px; line-height: 1; }
  .cp-bm-spacer { flex: 1; }
  .cp-bm-btn {
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    border: 1px solid transparent;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-bm-btn.ghost { background: #FFFFFF; color: #4F5763; border-color: #E4E5E8; font-weight: 500; }
  .cp-bm-btn.ghost:hover { background: #F5F6F7; }
  .cp-bm-btn.primary { background: #008C8C; color: #FFFFFF; }
  .cp-bm-btn.primary:hover { background: #00787A; }

  /* Body */
  .cp-bm-body { padding: 20px 24px 32px; display: flex; flex-direction: column; gap: 16px; }

  /* KPI cards */
  .cp-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
  }
  .cp-kpi {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 18px 16px;
    display: flex; flex-direction: column; gap: 4px;
  }
  .cp-kpi .lbl {
    font-size: 11px; color: #8A91A1;
    font-weight: 500;
    line-height: 1.2;
  }
  .cp-kpi .val {
    font-size: 24px; font-weight: 700;
    line-height: 1.2;
  }
  .cp-kpi .sub {
    font-size: 11px; color: #8A91A1;
    line-height: 1.2;
  }

  /* Filter strip */
  .cp-filter {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 12px 16px;
    display: flex; align-items: center; gap: 8px;
  }
  .cp-filter .pills { display: flex; gap: 6px; flex: 1; flex-wrap: wrap; }
  .cp-filter .pills button {
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 11px; font-weight: 500;
    line-height: 1.2;
    background: #FFFFFF;
    color: #4F5763;
    border: 1px solid #E4E5E8;
  }
  .cp-filter .pills button.active {
    background: #FFFFFF;
    color: #008C8C;
    border-color: #008C8C;
  }
  .cp-filter .util {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 6px 12px;
    font-size: 11px; font-weight: 500;
    color: #4F5763;
    line-height: 1.2;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-filter .bulk {
    background: #0D1B2A;
    color: #FFFFFF;
    border: none;
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 11px; font-weight: 600;
    line-height: 1.2;
  }

  /* Table */
  .cp-table {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
  }
  .cp-table thead {
    background: #F5F6F7;
  }
  .cp-table table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .cp-table th {
    padding: 12px 18px;
    text-align: left;
    font-size: 10px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.5px;
    text-transform: uppercase;
  }
  .cp-table td {
    padding: 14px 18px;
    border-top: 1px solid #F0F1F3;
    color: #0D1B2A;
  }
  .cp-table td.patient { font-weight: 600; }
  .cp-table td.muted { color: #4F5763; }
  .cp-table td.amount { font-weight: 600; }
  .cp-status-pill {
    border-radius: 999px;
    padding: 3px 11px;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.4px;
    line-height: 1.4;
    display: inline-block;
  }

  .cp-row-actions { display: inline-flex; align-items: center; gap: 6px; }
  .cp-row-act {
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 11px; font-weight: 500;
    line-height: 1.2;
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    color: #4F5763;
  }
  .cp-row-act.primary {
    background: #008C8C; color: #FFFFFF;
    border-color: #008C8C; font-weight: 600;
    border-radius: 999px; padding: 5px 12px;
  }
  .cp-row-act.warn {
    background: #FA8C33; color: #FFFFFF;
    border-color: #FA8C33; font-weight: 600;
    border-radius: 999px; padding: 5px 12px;
  }
  .cp-row-dots { color: #8A91A1; font-size: 16px; padding: 0 6px; }
</style>
</head>
<body>

<header class="cp-bm-head">
  <div class="cp-bm-title"><?php echo xlt('Billing Manager'); ?></div>
  <div class="cp-bm-bullet">•</div>
  <div class="cp-bm-meta">128 <?php echo xlt('open claims'); ?> • 14 <?php echo xlt('awaiting submission'); ?></div>
  <div class="cp-bm-spacer"></div>
  <button type="button" class="cp-bm-btn ghost">⤓ <?php echo xlt('Export'); ?></button>
  <button type="button" class="cp-bm-btn primary">+ <?php echo xlt('New claim'); ?></button>
</header>

<main class="cp-bm-body">

  <div class="cp-kpi-grid">
    <?php foreach ($kpis as [$lbl, $val, $sub, $color]): ?>
      <div class="cp-kpi">
        <div class="lbl"><?php echo text($lbl); ?></div>
        <div class="val" style="color: <?php echo attr($color); ?>;"><?php echo text($val); ?></div>
        <div class="sub"><?php echo text($sub); ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cp-filter">
    <div class="pills">
      <?php foreach ($status_pills as [$lbl, $active]): ?>
        <button type="button" class="<?php echo $active ? 'active' : ''; ?>"><?php echo text($lbl); ?></button>
      <?php endforeach; ?>
    </div>
    <span class="util">📅 <?php echo xlt('Last 30 days'); ?></span>
    <span class="util">🔍 <?php echo xlt('Search claims'); ?></span>
    <button type="button" class="bulk"><?php echo xlt('Bulk: Submit (4)'); ?></button>
  </div>

  <div class="cp-table">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('CLAIM #'); ?></th>
          <th><?php echo xlt('PATIENT'); ?></th>
          <th><?php echo xlt('DOS'); ?></th>
          <th><?php echo xlt('CPT'); ?></th>
          <th><?php echo xlt('INSURER'); ?></th>
          <th><?php echo xlt('AMOUNT'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('UPDATED'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($claims as [$num, $pat, $dos, $cpt, $ins, $amt, $st, $upd, $act]):
          $tone = $status_tone[$st] ?? ['#F5F6F7', '#4F5763'];
        ?>
          <tr>
            <td class="muted"><?php echo text($num); ?></td>
            <td class="patient"><?php echo text($pat); ?></td>
            <td class="muted"><?php echo text($dos); ?></td>
            <td class="muted"><?php echo text($cpt); ?></td>
            <td class="muted"><?php echo text($ins); ?></td>
            <td class="amount"><?php echo text($amt); ?></td>
            <td>
              <span class="cp-status-pill" style="background: <?php echo attr($tone[0]); ?>; color: <?php echo attr($tone[1]); ?>;">
                <?php echo text($st); ?>
              </span>
            </td>
            <td class="muted"><?php echo text($upd); ?></td>
            <td>
              <span class="cp-row-actions">
                <button type="button" class="cp-row-act"><?php echo xlt('View'); ?></button>
                <?php if ($act === 'submit'): ?>
                  <button type="button" class="cp-row-act primary"><?php echo xlt('Submit'); ?> →</button>
                <?php elseif ($act === 'resolve'): ?>
                  <button type="button" class="cp-row-act warn"><?php echo xlt('Resolve'); ?> →</button>
                <?php else: ?>
                  <span class="cp-row-dots">⋯</span>
                <?php endif; ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
