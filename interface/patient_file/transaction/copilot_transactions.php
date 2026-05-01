<?php

/**
 * Patient Transactions — implements Screen 16 of the AgentForge mockups.
 *
 * Renders the "Transactions" navtab content: 4 KPI cards (Total Charges
 * / Insurance Paid / Patient Paid / Outstanding), filter chip row, and
 * a transactions table with type pills (CHARGE / PAYMENT / ADJUSTMENT)
 * and balance / OUTSTANDING markers.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$kpis = [
    ['label' => 'Total Charges',  'value' => '$8,420.00', 'sub' => 'Last 12 months', 'tone' => 'neutral'],
    ['label' => 'Insurance Paid', 'value' => '$5,932.40', 'sub' => '70% of charges',  'tone' => 'good'],
    ['label' => 'Patient Paid',   'value' => '$1,184.00', 'sub' => '14% of charges',  'tone' => 'info'],
    ['label' => 'Outstanding',    'value' => '$1,303.60', 'sub' => '16% — 60+ days',  'tone' => 'warn'],
];

$filters = [
    ['All',         true],
    ['Charges',     false],
    ['Payments',    false],
    ['Adjustments', false],
    ['Refunds',     false],
];

// type: charge | payment | adjustment
$rows = [
    ['date'=>'04/12/2026','type'=>'charge',     'desc'=>'Office visit, established, level 3','cpt'=>'99213','prov'=>'Dr. Rivera','ins'=>'$152.00','pt'=>'$25.00','bal'=>'$0.00','out'=>false],
    ['date'=>'04/12/2026','type'=>'payment',    'desc'=>'BCBS — claim #BC-99281',            'cpt'=>'—',     'prov'=>'—',         'ins'=>'$152.00','pt'=>'—',     'bal'=>'—',     'out'=>false, 'amount_green'=>true],
    ['date'=>'02/18/2026','type'=>'charge',     'desc'=>'Annual wellness visit (preventive)','cpt'=>'99396','prov'=>'Dr. Rivera','ins'=>'$280.00','pt'=>'$0.00','bal'=>'$0.00','out'=>false],
    ['date'=>'02/18/2026','type'=>'payment',    'desc'=>'BCBS — claim #BC-99020',            'cpt'=>'—',     'prov'=>'—',         'ins'=>'$280.00','pt'=>'—',     'bal'=>'—',     'out'=>false, 'amount_green'=>true],
    ['date'=>'02/18/2026','type'=>'charge',     'desc'=>'CMP + CBC + HbA1c lab panel',       'cpt'=>'80050','prov'=>'Dr. Rivera','ins'=>'$184.00','pt'=>'$45.00','bal'=>'$0.00','out'=>false],
    ['date'=>'11/15/2025','type'=>'charge',     'desc'=>'Office visit, established, level 2','cpt'=>'99212','prov'=>'Dr. Rivera','ins'=>'$98.00','pt'=>'$25.00','bal'=>'$98.00','out'=>true],
    ['date'=>'08/22/2025','type'=>'charge',     'desc'=>'Telehealth lab review',             'cpt'=>'99214','prov'=>'Dr. Chen',  'ins'=>'$156.00','pt'=>'$25.00','bal'=>'$0.00','out'=>false],
    ['date'=>'08/22/2025','type'=>'adjustment', 'desc'=>'Contract write-off (BCBS)',         'cpt'=>'—',     'prov'=>'—',         'ins'=>'—',     'pt'=>'—',     'bal'=>'−$48.00','out'=>false, 'amount_red'=>true],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Transactions'); ?></title>
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

  /* ── KPI strip ───────────────────────────────────────────────────────── */
  .cp-tx-kpis {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 16px 24px;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
  }
  .cp-kpi {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    padding: 14px 18px;
    display: flex; flex-direction: column; gap: 4px;
  }
  .cp-kpi .lbl {
    font-size: 11px; color: #8A91A1; font-weight: 500;
    line-height: 1;
  }
  .cp-kpi .val {
    font-size: 22px; font-weight: 700;
    line-height: 1.2;
  }
  .cp-kpi.neutral .val { color: #0D1B2A; }
  .cp-kpi.good    .val { color: #1F8C4D; }
  .cp-kpi.info    .val { color: #4785D9; }
  .cp-kpi.warn    .val { color: #FA8C33; }
  .cp-kpi .sub { font-size: 11px; color: #8A91A1; line-height: 1.2; }

  /* ── Filter row ──────────────────────────────────────────────────────── */
  .cp-tx-filters {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 12px 24px;
    display: flex; align-items: center; gap: 8px;
  }
  .cp-tx-flabel { font-size: 12px; color: #8A91A1; line-height: 1; margin-right: 4px; }
  .cp-chip {
    height: 28px;
    padding: 0 12px;
    border-radius: 999px;
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    color: #4F5763;
    font-size: 12px; font-weight: 500;
    line-height: 1;
    display: inline-flex; align-items: center;
    cursor: pointer;
  }
  .cp-chip:hover { border-color: #C7CBD2; }
  .cp-chip.active {
    border-color: #008C8C;
    background: #E6F4F4;
    color: #008C8C;
  }
  .cp-tx-spacer { flex: 1; }
  .cp-tx-pill {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 12px; color: #4F5763;
    line-height: 1;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-tx-pill:hover { background: #F5F6F7; }

  /* ── Table ───────────────────────────────────────────────────────────── */
  .cp-tx-table-wrap { padding: 16px 24px; }
  .cp-tx-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    overflow: hidden;
  }
  .cp-tx-table thead th {
    background: #F5F6F7;
    color: #8A91A1;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.6px;
    text-align: left;
    padding: 10px 12px;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-tx-table tbody td {
    padding: 14px 12px;
    border-bottom: 1px solid #E4E5E8;
    font-size: 12px;
    color: #0D1B2A;
    vertical-align: middle;
  }
  .cp-tx-table tbody tr:last-child td { border-bottom: none; }
  .cp-tx-table .col-date { width: 96px; color: #4F5763; font-weight: 500; }
  .cp-tx-table .col-type { width: 110px; }
  .cp-tx-table .col-cpt  { width: 64px; color: #4F5763; }
  .cp-tx-table .col-prov { width: 110px; color: #4F5763; }
  .cp-tx-table .col-num  { width: 96px; text-align: right; }

  .cp-type-pill {
    display: inline-flex; align-items: center;
    border-radius: 4px;
    padding: 3px 8px;
    font-size: 10px; font-weight: 700;
    letter-spacing: 0.5px;
    line-height: 1;
  }
  .cp-type-pill.charge     { background: #ECEEF0; color: #4F5763; }
  .cp-type-pill.payment    { background: #E6F5EC; color: #1F8C4D; }
  .cp-type-pill.adjustment { background: #FFF1E3; color: #FA8C33; }

  .cp-amt-green { color: #1F8C4D; font-weight: 600; }
  .cp-amt-red   { color: #D93838; font-weight: 600; }

  .cp-out-pill {
    display: inline-flex; align-items: center;
    margin-left: 8px;
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 9px; font-weight: 700;
    letter-spacing: 0.5px;
    background: rgba(250, 140, 51, 0.14);
    color: #FA8C33;
    border: 1px solid rgba(250, 140, 51, 0.4);
    line-height: 1;
  }
</style>
</head>
<body>

<section class="cp-tx-kpis">
  <?php foreach ($kpis as $k): ?>
    <div class="cp-kpi <?php echo attr($k['tone']); ?>">
      <div class="lbl"><?php echo text($k['label']); ?></div>
      <div class="val"><?php echo text($k['value']); ?></div>
      <div class="sub"><?php echo text($k['sub']); ?></div>
    </div>
  <?php endforeach; ?>
</section>

<section class="cp-tx-filters">
  <span class="cp-tx-flabel"><?php echo xlt('Filter'); ?>:</span>
  <?php foreach ($filters as [$label, $active]): ?>
    <button type="button" class="cp-chip<?php echo $active ? ' active' : ''; ?>"><?php echo text($label); ?></button>
  <?php endforeach; ?>
  <div class="cp-tx-spacer"></div>
  <button type="button" class="cp-tx-pill"><span>📅</span><span><?php echo xlt('Last 12 months'); ?></span></button>
  <button type="button" class="cp-tx-pill"><span>⬇</span><span><?php echo xlt('Export CSV'); ?></span></button>
</section>

<section class="cp-tx-table-wrap">
  <table class="cp-tx-table">
    <thead>
      <tr>
        <th class="col-date"><?php echo xlt('DATE'); ?></th>
        <th class="col-type"><?php echo xlt('TYPE'); ?></th>
        <th><?php echo xlt('DESCRIPTION'); ?></th>
        <th class="col-cpt"><?php echo xlt('CPT'); ?></th>
        <th class="col-prov"><?php echo xlt('PROVIDER'); ?></th>
        <th class="col-num"><?php echo xlt('INS PAID'); ?></th>
        <th class="col-num"><?php echo xlt('PT PAID'); ?></th>
        <th class="col-num"><?php echo xlt('BALANCE'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="col-date"><?php echo text($r['date']); ?></td>
          <td class="col-type">
            <span class="cp-type-pill <?php echo attr($r['type']); ?>">
              <?php echo text(strtoupper($r['type'])); ?>
            </span>
          </td>
          <td>
            <?php echo text($r['desc']); ?>
            <?php if (!empty($r['out'])): ?>
              <span class="cp-out-pill"><?php echo xlt('OUTSTANDING'); ?></span>
            <?php endif; ?>
          </td>
          <td class="col-cpt"><?php echo text($r['cpt']); ?></td>
          <td class="col-prov"><?php echo text($r['prov']); ?></td>
          <td class="col-num<?php echo !empty($r['amount_green']) ? ' cp-amt-green' : ''; ?>"><?php echo text($r['ins']); ?></td>
          <td class="col-num"><?php echo text($r['pt']); ?></td>
          <td class="col-num<?php echo !empty($r['amount_red']) ? ' cp-amt-red' : ''; ?>"><?php echo text($r['bal']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

</body>
</html>
