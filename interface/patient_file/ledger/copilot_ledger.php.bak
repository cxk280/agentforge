<?php

/**
 * Patient Ledger — implements Screen 18 of the AgentForge mockups.
 *
 * Renders the "Ledger" navtab content: dark navy summary banner with
 * outstanding balance, patient aging buckets and a stacked bar, plus
 * Collect Payment / Generate Statement actions; below is the line-item
 * ledger table (debit / credit / insurance / running balance).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$aging = [
    ['0–30',  '$0.00',     0],
    ['31–60', '$0.00',     0],
    ['61–90', '$98.00',    98.00],
    ['91+',   '$1,205.60', 1205.60],
];
$aging_total = array_sum(array_column($aging, 2));

$rows = [
    ['date'=>'04/12/2026','txn'=>'TX-9402','desc'=>'Office visit (99213)','debit'=>'$152.00','credit'=>'—','ins'=>'BCBS PPO • Pending','bal'=>'$1,303.60'],
    ['date'=>'04/12/2026','txn'=>'TX-9403','desc'=>'BCBS payment — claim BC-99281','debit'=>'—','credit'=>'$152.00','ins'=>'BCBS PPO • Paid','bal'=>'$1,303.60'],
    ['date'=>'02/18/2026','txn'=>'TX-9311','desc'=>'Annual wellness visit (99396)','debit'=>'$280.00','credit'=>'—','ins'=>'BCBS PPO • Paid','bal'=>'$1,303.60'],
    ['date'=>'02/18/2026','txn'=>'TX-9312','desc'=>'BCBS payment — claim BC-99020','debit'=>'—','credit'=>'$280.00','ins'=>'—','bal'=>'$1,303.60'],
    ['date'=>'02/18/2026','txn'=>'TX-9313','desc'=>'Lab panel (80050)','debit'=>'$184.00','credit'=>'—','ins'=>'BCBS PPO • Paid','bal'=>'$1,303.60'],
    ['date'=>'02/18/2026','txn'=>'TX-9314','desc'=>'BCBS payment — claim BC-99021','debit'=>'—','credit'=>'$139.00','ins'=>'—','bal'=>'$1,348.60'],
    ['date'=>'02/18/2026','txn'=>'TX-9315','desc'=>'Patient copay collected — visa','debit'=>'—','credit'=>'$45.00','ins'=>'—','bal'=>'$1,303.60'],
    ['date'=>'11/15/2025','txn'=>'TX-9112','desc'=>'Office visit (99212)','debit'=>'$98.00','credit'=>'—','ins'=>'BCBS PPO • Pending','bal'=>'$1,303.60','out'=>true],
    ['date'=>'08/22/2025','txn'=>'TX-8821','desc'=>'Telehealth visit (99214)','debit'=>'$156.00','credit'=>'—','ins'=>'BCBS PPO • Paid','bal'=>'$1,205.60'],
    ['date'=>'08/22/2025','txn'=>'TX-8822','desc'=>'BCBS payment — claim BC-90422','debit'=>'—','credit'=>'$108.00','ins'=>'—','bal'=>'$1,205.60'],
    ['date'=>'08/22/2025','txn'=>'TX-8823','desc'=>'Contractual write-off (BCBS)','debit'=>'—','credit'=>'$48.00','ins'=>'—','bal'=>'$1,157.60'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Ledger'); ?></title>
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

  /* ── Summary banner ──────────────────────────────────────────────────── */
  .cp-led-banner {
    background: #0D1B2A;
    color: #FFFFFF;
    padding: 18px 24px;
    display: flex; align-items: center; gap: 32px;
  }
  .cp-led-balance { display: flex; flex-direction: column; gap: 4px; }
  .cp-led-balance .lbl {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.55);
    letter-spacing: 0.6px;
    line-height: 1;
  }
  .cp-led-balance .val {
    font-size: 30px; font-weight: 700;
    line-height: 1.1;
    display: flex; align-items: baseline; gap: 6px;
  }
  .cp-led-balance .val .usd {
    font-size: 12px; font-weight: 500;
    color: rgba(255, 255, 255, 0.55);
  }
  .cp-led-balance .sub {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.55);
    line-height: 1;
    margin-top: 4px;
  }

  .cp-led-aging { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
  .cp-led-aging .lbl {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.55);
    letter-spacing: 0.6px;
    line-height: 1;
  }
  .cp-led-aging .buckets {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    column-gap: 12px;
    max-width: 520px;
  }
  .cp-led-aging .buckets .col {
    display: flex; flex-direction: column; gap: 2px;
  }
  .cp-led-aging .buckets .top {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.6);
    line-height: 1;
  }
  .cp-led-aging .buckets .amt {
    font-size: 12px; font-weight: 600;
    color: #FFFFFF;
    line-height: 1;
  }
  .cp-led-aging .bar {
    height: 6px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.10);
    overflow: hidden;
    display: flex;
    max-width: 520px;
    margin-top: 4px;
  }
  .cp-led-aging .bar .seg {
    height: 100%;
    background: #FA8C33;
  }
  .cp-led-aging .bar .seg.b3 { background: #FA8C33; }
  .cp-led-aging .bar .seg.b4 { background: #D93838; }

  .cp-led-actions { display: flex; align-items: center; gap: 10px; flex: 0 0 auto; }
  .cp-led-pri {
    background: #008C8C;
    color: #FFFFFF;
    border: none;
    border-radius: 999px;
    padding: 8px 18px;
    font-size: 13px; font-weight: 600;
    line-height: 1;
  }
  .cp-led-pri:hover { background: #00787A; }
  .cp-led-sec {
    background: rgba(255, 255, 255, 0.06);
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    padding: 8px 18px;
    font-size: 13px; font-weight: 500;
    line-height: 1;
  }
  .cp-led-sec:hover { background: rgba(255, 255, 255, 0.10); }

  /* ── Ledger table ────────────────────────────────────────────────────── */
  .cp-led-table-wrap { padding: 20px 24px 32px; }
  .cp-led-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
  }
  .cp-led-table thead th {
    background: #F5F6F7;
    color: #8A91A1;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.6px;
    text-align: left;
    padding: 10px 14px;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-led-table tbody td {
    padding: 14px 14px;
    border-bottom: 1px solid #E4E5E8;
    font-size: 12px;
    color: #0D1B2A;
    vertical-align: middle;
  }
  .cp-led-table tbody tr:last-child td { border-bottom: none; }
  .cp-led-table tbody tr.out td { background: #FFF8EE; }
  .cp-col-date { width: 96px; color: #4F5763; font-weight: 500; }
  .cp-col-tx { width: 80px; color: #8A91A1; font-size: 11px; font-weight: 500; letter-spacing: 0.2px; }
  .cp-col-amt { width: 100px; text-align: right; font-variant-numeric: tabular-nums; }
  .cp-col-amt.credit { color: #1F8C4D; font-weight: 600; }
  .cp-col-bal { width: 110px; text-align: right; font-weight: 700; color: #0D1B2A; font-variant-numeric: tabular-nums; }
  .cp-col-ins { width: 180px; color: #4F5763; }

  .cp-out-pill {
    display: inline-flex; align-items: center;
    margin-left: 10px;
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

<header class="cp-led-banner">
  <div class="cp-led-balance">
    <div class="lbl"><?php echo xlt('OUTSTANDING BALANCE'); ?></div>
    <div class="val">$1,303.60 <span class="usd">USD</span></div>
    <div class="sub">Last activity 04/12/2026 • Patient responsibility</div>
  </div>

  <div class="cp-led-aging">
    <div class="lbl"><?php echo xlt('AGING (PATIENT)'); ?></div>
    <div class="buckets">
      <?php foreach ($aging as [$range, $amt, $val]): ?>
        <div class="col">
          <div class="top"><?php echo text($range); ?></div>
          <div class="amt"><?php echo text($amt); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="bar">
      <?php
      $i = 1;
      foreach ($aging as [$_r, $_a, $val]):
          if ($val > 0 && $aging_total > 0):
              $pct = ($val / $aging_total) * 100;
              $cls = 'b' . $i;
              ?>
              <div class="seg <?php echo attr($cls); ?>" style="width: <?php echo number_format($pct, 2); ?>%;"></div>
          <?php endif; ?>
          <?php $i++; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="cp-led-actions">
    <button type="button" class="cp-led-pri"><?php echo xlt('Collect Payment'); ?></button>
    <button type="button" class="cp-led-sec"><?php echo xlt('Generate Statement'); ?></button>
  </div>
</header>

<section class="cp-led-table-wrap">
  <table class="cp-led-table">
    <thead>
      <tr>
        <th class="cp-col-date"><?php echo xlt('DATE'); ?></th>
        <th class="cp-col-tx"><?php echo xlt('#'); ?></th>
        <th><?php echo xlt('DESCRIPTION'); ?></th>
        <th class="cp-col-amt"><?php echo xlt('DEBIT'); ?></th>
        <th class="cp-col-amt"><?php echo xlt('CREDIT'); ?></th>
        <th class="cp-col-ins"><?php echo xlt('INSURANCE'); ?></th>
        <th class="cp-col-bal"><?php echo xlt('BALANCE'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?php echo !empty($r['out']) ? 'out' : ''; ?>">
          <td class="cp-col-date"><?php echo text($r['date']); ?></td>
          <td class="cp-col-tx"><?php echo text($r['txn']); ?></td>
          <td>
            <?php echo text($r['desc']); ?>
            <?php if (!empty($r['out'])): ?>
              <span class="cp-out-pill"><?php echo xlt('OUTSTANDING'); ?></span>
            <?php endif; ?>
          </td>
          <td class="cp-col-amt"><?php echo text($r['debit']); ?></td>
          <td class="cp-col-amt<?php echo $r['credit'] !== '—' ? ' credit' : ''; ?>"><?php echo text($r['credit']); ?></td>
          <td class="cp-col-ins"><?php echo text($r['ins']); ?></td>
          <td class="cp-col-bal"><?php echo text($r['bal']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

</body>
</html>
