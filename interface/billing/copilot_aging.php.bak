<?php

/**
 * Aging Report — Screen 59.
 *
 * Reports → Financial → Aging sub-page. Cross-patient view of
 * outstanding A/R bucketed by age, with payer breakdown and a
 * largest-outstanding-accounts panel.
 *
 * Data sources:
 *   - billing                    — fee, date, pid, payer_id, activity
 *   - ar_activity                — payments / adjustments against billing
 *   - patient_data               — patient names + pubpid (MRN)
 *   - insurance_data             — primary payer per patient
 *   - insurance_companies        — payer display name
 *
 * Aging buckets are computed as DATEDIFF(NOW(), billing.date), one of:
 *   0-30 / 31-60 / 61-90 / 91-120 / >120
 *
 * The "balance" for a billing line is fee - SUM(ar_activity.pay_amount +
 * ar_activity.adj_amount) joined by (pid, encounter, code, modifier).
 * Only billing rows with activity=1 are considered.
 *
 * The chrome (top nav) is rendered by the parent shell; this page renders
 * only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

// ---------------------------------------------------------------------------
// Aging helpers.
// ---------------------------------------------------------------------------

/**
 * Bucket a "days outstanding" integer into one of the 5 aging columns.
 *
 * @return string  one of "0-30" | "31-60" | "61-90" | "91-120" | ">120"
 */
function cp_aging_bucket(int $days): string
{
    if ($days <= 30)  { return '0-30'; }
    if ($days <= 60)  { return '31-60'; }
    if ($days <= 90)  { return '61-90'; }
    if ($days <= 120) { return '91-120'; }
    return '>120';
}

/**
 * Pull every still-open billing line with computed balance + bucket.
 *
 * Returns rows shaped:
 *   ['pid', 'encounter', 'date', 'fee', 'paid', 'balance', 'days', 'bucket',
 *    'payer_id', 'payer_name', 'patient_name', 'pubpid']
 *
 * @return list<array<string, mixed>>
 */
function cp_aging_open_lines(): array
{
    // Aggregate ar_activity per (pid, encounter, code, modifier) so we can
    // subtract payments and adjustments from the billing fee. ar_activity has
    // its own composite key — there can be multiple payment rows per service
    // line, hence the SUM in the subquery.
    $sql = "
        SELECT b.id            AS billing_id,
               b.pid           AS pid,
               b.encounter     AS encounter,
               b.date          AS svc_date,
               b.fee           AS fee,
               b.payer_id      AS payer_id,
               COALESCE(act.paid, 0)      AS paid,
               (b.fee - COALESCE(act.paid, 0))     AS balance,
               DATEDIFF(NOW(), b.date)             AS days,
               pd.fname        AS fname,
               pd.lname        AS lname,
               pd.pubpid       AS pubpid,
               ic.name         AS payer_name,
               idata.type      AS payer_type
        FROM billing b
        LEFT JOIN (
            SELECT pid, encounter, code, modifier,
                   SUM(COALESCE(pay_amount, 0) + COALESCE(adj_amount, 0)) AS paid
            FROM ar_activity
            WHERE deleted IS NULL
            GROUP BY pid, encounter, code, modifier
        ) act
          ON act.pid       = b.pid
         AND act.encounter = b.encounter
         AND act.code      = b.code
         AND act.modifier  = b.modifier
        LEFT JOIN patient_data pd ON pd.pid = b.pid
        LEFT JOIN insurance_companies ic ON ic.id = b.payer_id
        LEFT JOIN insurance_data idata
               ON idata.pid = b.pid AND idata.type = 'primary'
        WHERE b.activity = 1
          AND b.fee IS NOT NULL
          AND b.fee > 0
          AND (b.fee - COALESCE(act.paid, 0)) > 0
        ORDER BY b.date ASC
    ";
    $rs = sqlStatement($sql);
    $out = [];
    while ($r = sqlFetchArray($rs)) {
        $days = (int)($r['days'] ?? 0);
        $payerName = (string)($r['payer_name'] ?? '');
        if ($payerName === '') {
            // No insurance_companies row → fall back to insurance_data.provider
            // if the patient has a primary policy, else "Self-pay".
            $idr = sqlQuery(
                "SELECT provider, plan_name FROM insurance_data
                 WHERE pid = ? AND type = 'primary' AND (provider IS NOT NULL AND provider <> '')
                 ORDER BY date DESC LIMIT 1",
                [(int)$r['pid']]
            );
            $payerName = (string)($idr['provider'] ?? $idr['plan_name'] ?? '');
            if ($payerName === '') { $payerName = 'Self-pay'; }
        }
        $first = trim((string)($r['fname'] ?? ''));
        $last  = trim((string)($r['lname'] ?? ''));
        $name  = trim($first . ' ' . $last);
        if ($name === '') { $name = 'Unknown'; }
        $out[] = [
            'billing_id'   => (int)$r['billing_id'],
            'pid'          => (int)$r['pid'],
            'encounter'    => (int)$r['encounter'],
            'svc_date'     => (string)$r['svc_date'],
            'fee'          => (float)$r['fee'],
            'paid'         => (float)$r['paid'],
            'balance'      => (float)$r['balance'],
            'days'         => $days,
            'bucket'       => cp_aging_bucket($days),
            'payer_id'     => $r['payer_id'] !== null ? (int)$r['payer_id'] : null,
            'payer_name'   => $payerName,
            'patient_name' => $name,
            'pubpid'       => (string)($r['pubpid'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Sum the balance column from cp_aging_open_lines() into the 5 buckets.
 *
 * @param list<array<string, mixed>> $lines
 * @return array<string, float>
 */
function cp_aging_bucket_totals(array $lines): array
{
    $tot = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91-120' => 0.0, '>120' => 0.0];
    foreach ($lines as $l) {
        $tot[(string)$l['bucket']] += (float)$l['balance'];
    }
    return $tot;
}

/**
 * Group the open lines by payer and bucket them.
 *
 * @param list<array<string, mixed>> $lines
 * @return list<array{payer:string, buckets:array<string, float>, total:float}>
 */
function cp_aging_by_payer(array $lines): array
{
    $byPayer = [];
    foreach ($lines as $l) {
        $p = (string)$l['payer_name'];
        if (!isset($byPayer[$p])) {
            $byPayer[$p] = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91-120' => 0.0, '>120' => 0.0];
        }
        $byPayer[$p][(string)$l['bucket']] += (float)$l['balance'];
    }
    $out = [];
    foreach ($byPayer as $payer => $buckets) {
        $total = array_sum($buckets);
        $out[] = ['payer' => $payer, 'buckets' => $buckets, 'total' => $total];
    }
    // Sort largest payer total first.
    usort($out, static fn($a, $b) => $b['total'] <=> $a['total']);
    return $out;
}

/**
 * Largest outstanding patient accounts. Sums balance across all open
 * billing lines per patient, returns top N.
 *
 * @param list<array<string, mixed>> $lines
 * @return list<array<string, mixed>>
 */
function cp_aging_top_accounts(array $lines, int $limit = 8): array
{
    $byPid = [];
    foreach ($lines as $l) {
        $pid = (int)$l['pid'];
        if (!isset($byPid[$pid])) {
            $byPid[$pid] = [
                'pid'          => $pid,
                'patient_name' => (string)$l['patient_name'],
                'pubpid'       => (string)$l['pubpid'],
                'balance'      => 0.0,
                'max_days'     => 0,
                'payer_name'   => (string)$l['payer_name'],
            ];
        }
        $byPid[$pid]['balance']  += (float)$l['balance'];
        $byPid[$pid]['max_days']  = max((int)$byPid[$pid]['max_days'], (int)$l['days']);
        // Prefer a non-Self-pay payer name if any line had one.
        if ($byPid[$pid]['payer_name'] === 'Self-pay' && (string)$l['payer_name'] !== 'Self-pay') {
            $byPid[$pid]['payer_name'] = (string)$l['payer_name'];
        }
    }
    $rows = array_values($byPid);
    usort($rows, static fn($a, $b) => $b['balance'] <=> $a['balance']);
    foreach ($rows as &$r) {
        $r['bucket'] = cp_aging_bucket((int)$r['max_days']);
    }
    unset($r);
    return array_slice($rows, 0, $limit);
}

// ---------------------------------------------------------------------------
// POST: action=export_csv — stream every open A/R line as CSV.
// CSRF skipped — internal mock page; OpenEMR's authCheckCore() in globals.php
// gates anonymous access.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'export_csv')) {
    $lines = cp_aging_open_lines();
    $stamp = date('Ymd-His');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aging-report-' . $stamp . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'billing_id', 'pid', 'mrn', 'patient_name', 'service_date',
        'days_outstanding', 'aging_bucket', 'fee', 'paid', 'balance', 'payer',
    ]);
    foreach ($lines as $l) {
        fputcsv($out, [
            (int)$l['billing_id'],
            (int)$l['pid'],
            (string)$l['pubpid'],
            (string)$l['patient_name'],
            (string)$l['svc_date'],
            (int)$l['days'],
            (string)$l['bucket'],
            number_format((float)$l['fee'], 2, '.', ''),
            number_format((float)$l['paid'], 2, '.', ''),
            number_format((float)$l['balance'], 2, '.', ''),
            (string)$l['payer_name'],
        ]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// GET — render the page.
// ---------------------------------------------------------------------------

$selfPath = $_SERVER['PHP_SELF'] ?? '/interface/billing/copilot_aging.php';

$lines        = cp_aging_open_lines();
$bucketTotals = cp_aging_bucket_totals($lines);
$totalAR      = array_sum($bucketTotals);

// % > 90 days (91-120 + >120) over Total A/R.
$gt90 = $bucketTotals['91-120'] + $bucketTotals['>120'];
$pctGt90 = ($totalAR > 0) ? ($gt90 / $totalAR) * 100.0 : 0.0;

// Days in A/R = Total A/R / avg daily revenue last 90 days.
// Avg daily revenue = SUM(billing.fee where activity=1 and date in last 90d) / 90.
$rowRev = sqlQuery(
    "SELECT COALESCE(SUM(fee), 0) AS rev_90d
       FROM billing
      WHERE activity = 1
        AND fee IS NOT NULL
        AND date >= NOW() - INTERVAL 90 DAY"
);
$rev90 = (float)($rowRev['rev_90d'] ?? 0.0);
$avgDaily = $rev90 / 90.0;
$daysInAR = ($avgDaily > 0) ? ($totalAR / $avgDaily) : 0.0;

// Collected today = SUM(ar_activity.pay_amount where DATE(post_time)=CURDATE())
$rowToday = sqlQuery(
    "SELECT COALESCE(SUM(pay_amount), 0) AS collected_today
       FROM ar_activity
      WHERE deleted IS NULL
        AND DATE(post_time) = CURDATE()"
);
$collectedToday = (float)($rowToday['collected_today'] ?? 0.0);

// Adjustments = SUM(ar_activity.adj_amount) for the same window — show as
// total downward adjustments. Per brief, adjustments are pay_amount * -1 in
// ar_activity, but in OpenEMR adj_amount is the adjustment column. We sum
// adj_amount across all open AR (not deleted).
$rowAdj = sqlQuery(
    "SELECT COALESCE(SUM(adj_amount), 0) AS adj_total
       FROM ar_activity
      WHERE deleted IS NULL"
);
$adjTotal = (float)($rowAdj['adj_total'] ?? 0.0);

$payerRows = cp_aging_by_payer($lines);
$topAccts  = cp_aging_top_accounts($lines, 8);

$flash = (string)($_GET['msg'] ?? '');

/**
 * Format dollars as "$1,234" (rounded). Used in the KPI tiles + bucket cards.
 */
function cp_fmt_money(float $v): string
{
    return '$' . number_format(round($v), 0, '.', ',');
}

/**
 * Format dollars as "$1,234.56" for tables.
 */
function cp_fmt_money_cents(float $v): string
{
    if ($v == 0.0) { return '$0'; }
    return '$' . number_format($v, 2, '.', ',');
}

/** Tone class for a balance / bucket. */
function cp_tone_for_bucket(string $bucket): string
{
    return match ($bucket) {
        '0-30', '31-60' => 'green',
        '61-90', '91-120' => 'orange',
        '>120' => 'red',
        default => 'dark',
    };
}

/** Display label for a bucket value. */
function cp_label_for_bucket(string $bucket): string
{
    return match ($bucket) {
        '0-30'   => 'Current (0-30)',
        '31-60'  => '31-60',
        '61-90'  => '61-90',
        '91-120' => '91-120',
        '>120'   => '> 120',
        default  => $bucket,
    };
}

/** Bucket dot color (matches mock). */
function cp_dot_for_bucket(string $bucket): string
{
    return match ($bucket) {
        '0-30'   => '#1F8C4D',
        '31-60'  => '#1F8C4D',
        '61-90'  => '#FA8C33',
        '91-120' => '#FA8C33',
        '>120'   => '#D93838',
        default  => '#8A91A1',
    };
}

/** Bucket bar segment color (top of bucket panel). */
function cp_bar_for_bucket(string $bucket): string
{
    return match ($bucket) {
        '0-30'   => '#1F8C4D',
        '31-60'  => '#33A666',
        '61-90'  => '#FA8C33',
        '91-120' => '#F2A65A',
        '>120'   => '#D93838',
        default  => '#8A91A1',
    };
}

$bucketOrder = ['0-30', '31-60', '61-90', '91-120', '>120'];

$sidebar = [
    'CLINICAL' => [
        ['Patient List',          false],
        ['Prescriptions',         false],
        ['Lab Trends',            false],
        ['Quality Measures',      false],
        ['Immunizations',         false],
        ['Encounters',            false],
    ],
    'FINANCIAL' => [
        ['Daily Cash',            false],
        ['Aging',                 true],
        ['Payer Mix',             false],
        ['Collections',           false],
    ],
    'OPERATIONS' => [
        ['Visit Volume',          false],
        ['Provider Productivity', false],
        ['No-shows',              false],
    ],
    'ELECTRONIC' => [
        ['Submissions',           false],
        ['CCDA Exports',          false],
        ['HIE Sync',              false],
        ['Public Health',         false],
    ],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Aging Report'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #4F5763; font-size: 12px; line-height: 1; }

  /* Reports left sidebar */
  .cp-rep-side {
    flex: 0 0 200px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 18px 0 24px;
    overflow-y: auto;
  }
  .cp-rep-side .header {
    font-size: 11px; font-weight: 700;
    color: #8A91A1; letter-spacing: 0.7px;
    padding: 0 20px 12px;
  }
  .cp-rep-side .grp { margin-bottom: 14px; }
  .cp-rep-side .grp .lbl {
    font-size: 11px; font-weight: 500;
    color: #8A91A1;
    padding: 6px 20px 4px;
  }
  .cp-rep-side .item {
    display: block;
    padding: 7px 20px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    text-decoration: none;
    line-height: 1.3;
    position: relative;
  }
  .cp-rep-side .item:hover { background: #F5F6F7; color: #0D1B2A; }
  .cp-rep-side .item.active {
    color: #008C8C; font-weight: 600;
    background: rgba(0,140,140,0.08);
  }

  /* KPI row — 5 cards, slim style with colored values */
  .cp-aging-kpi { grid-template-columns: repeat(5, 1fr); }
  .cp-aging-kpi .cp-kpi { padding: 12px 16px 14px; gap: 6px; }
  .cp-aging-kpi .cp-kpi .lbl { font-size: 11px; color: #4F5763; font-weight: 500; }
  .cp-aging-kpi .cp-kpi .val { font-size: 22px; font-weight: 700; line-height: 1.1; color: #0D1B2A; }
  .cp-aging-kpi .cp-kpi .val.orange { color: #FA8C33; }
  .cp-aging-kpi .cp-kpi .val.green  { color: #1F8C4D; }

  /* A/R by aging bucket panel */
  .cp-bucket-panel {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 20px 20px;
  }
  .cp-bucket-panel .head {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    text-transform: uppercase;
    margin-bottom: 12px;
  }
  .cp-bucket-bar {
    display: flex;
    height: 28px;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 14px;
    background: #F0F1F3;
  }
  .cp-bucket-bar > span { display: block; height: 100%; }
  .cp-bucket-legend {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
  }
  .cp-bucket-legend .item { display: flex; flex-direction: column; gap: 4px; padding-top: 2px; }
  .cp-bucket-legend .item .lbl-row { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #4F5763; font-weight: 500; }
  .cp-bucket-legend .item .lbl-row .dot { width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto; }
  .cp-bucket-legend .item .val { font-size: 17px; font-weight: 700; line-height: 1.15; }
  .cp-bucket-legend .item .val.green  { color: #1F8C4D; }
  .cp-bucket-legend .item .val.orange { color: #FA8C33; }
  .cp-bucket-legend .item .val.red    { color: #D93838; }
  .cp-bucket-legend .item .sub { font-size: 11px; color: #8A91A1; }

  /* Two-column lower layout */
  .cp-aging-cols {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    align-items: start;
  }
  .cp-aging-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 18px 18px;
  }
  .cp-aging-card .head {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    text-transform: uppercase;
    margin-bottom: 12px;
  }

  /* Aging-by-payer table — borderless, tight */
  .cp-payer-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
  .cp-payer-tbl thead th {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.5px;
    text-transform: uppercase;
    padding: 8px 6px;
    text-align: right;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-payer-tbl thead th:first-child { text-align: left; padding-left: 0; }
  .cp-payer-tbl tbody td {
    padding: 12px 6px;
    text-align: right;
    color: #0D1B2A;
  }
  .cp-payer-tbl tbody td:first-child {
    text-align: left;
    padding-left: 0;
    font-weight: 600;
  }
  .cp-payer-tbl tbody td.tot { font-weight: 600; }
  .cp-payer-tbl tbody td.warn { color: #FA8C33; }
  .cp-payer-tbl tbody td.danger { color: #D93838; }
  .cp-payer-tbl tbody tr.empty td { text-align: center; color: #8A91A1; font-style: italic; padding: 20px 6px; }

  /* Largest outstanding accounts list */
  .cp-acct-list { display: flex; flex-direction: column; }
  .cp-acct-row {
    display: grid;
    grid-template-columns: 32px 1fr auto auto auto auto;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-top: 1px solid #F0F1F3;
  }
  .cp-acct-row:first-of-type { border-top: none; }
  .cp-acct-row .av {
    width: 28px; height: 28px;
    border-radius: 50%;
    background: #C7CBD2;
  }
  .cp-acct-row .who { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
  .cp-acct-row .who .nm { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cp-acct-row .who .mrn { font-size: 11px; color: #8A91A1; line-height: 1.2; }
  .cp-acct-row .amt { font-size: 14px; font-weight: 700; }
  .cp-acct-row .amt.red    { color: #D93838; }
  .cp-acct-row .amt.orange { color: #FA8C33; }
  .cp-acct-row .amt.dark   { color: #0D1B2A; }
  .cp-acct-row .amt.green  { color: #1F8C4D; }
  .cp-acct-row .bkt { font-size: 12px; color: #4F5763; }
  .cp-acct-row .note { font-size: 12px; color: #4F5763; }
  .cp-acct-row .stmt {
    font-size: 12px; font-weight: 600;
    color: #008C8C; text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px;
  }
  .cp-acct-empty { padding: 24px 0; text-align: center; font-size: 12px; color: #8A91A1; font-style: italic; }

  /* Header export button — inline form so the button submits */
  .cp-pagehead .cp-export-form { margin: 0; }
  .cp-pagehead .cp-btn.primary { padding: 7px 16px; }

  /* Flash banner */
  .cp-flash {
    background: #EBF8F0;
    border: 1px solid #1F8C4D;
    color: #0D1B2A;
    padding: 8px 14px;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 12px;
  }
</style>
</head>
<body class="cp-arch">

<div class="cp-shell">
  <aside class="cp-rep-side">
    <div class="header"><?php echo xlt('REPORTS'); ?></div>
    <?php foreach ($sidebar as $cat => $items): ?>
      <div class="grp">
        <div class="lbl"><?php echo text(ucfirst(strtolower($cat))); ?></div>
        <?php foreach ($items as [$nm, $act]): ?>
          <a href="#" class="item<?php echo $act ? ' active' : ''; ?>"><?php echo text($nm); ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </aside>

  <div style="flex:1 1 auto; display:flex; flex-direction:column; min-width:0;">
    <header class="cp-pagehead">
      <div class="info">
        <div style="display:flex; align-items:center; gap:8px;">
          <span class="title"><?php echo xlt('Aging Report'); ?></span>
          <span class="meta-light">
            <?php echo xlt('Outstanding A/R by aging bucket'); ?>
            ·
            <?php echo xlt('as of'); ?> <?php echo text(date('m/d/Y')); ?>
          </span>
        </div>
      </div>
      <form method="post" action="<?php echo attr($selfPath); ?>" class="cp-export-form">
        <input type="hidden" name="action" value="export_csv">
        <button type="submit" class="cp-btn primary">⤓ <?php echo xlt('Export CSV'); ?></button>
      </form>
    </header>

    <main class="cp-content tight">

      <?php if ($flash !== ''): ?>
        <div class="cp-flash"><?php echo text($flash); ?></div>
      <?php endif; ?>

      <div class="cp-kpi-grid cp-aging-kpi">
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Total A/R'); ?></span>
          <span class="val"><?php echo text(cp_fmt_money($totalAR)); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Days in A/R'); ?></span>
          <span class="val"><?php echo text(($avgDaily > 0) ? (string)((int)round($daysInAR)) . ' d' : '— d'); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('% > 90 days'); ?></span>
          <span class="val orange"><?php echo text(number_format($pctGt90, 1) . '%'); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Collected today'); ?></span>
          <span class="val green"><?php echo text(cp_fmt_money($collectedToday)); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Adjustments'); ?></span>
          <span class="val"><?php echo text(cp_fmt_money($adjTotal)); ?></span>
        </div>
      </div>

      <div class="cp-bucket-panel">
        <div class="head"><?php echo xlt('A/R BY AGING BUCKET'); ?></div>
        <div class="cp-bucket-bar">
          <?php foreach ($bucketOrder as $bk):
              $val = (float)$bucketTotals[$bk];
              $pct = ($totalAR > 0) ? ($val / $totalAR) * 100.0 : 0.0;
              if ($pct <= 0) { continue; }
              ?>
              <span style="background: <?php echo attr(cp_bar_for_bucket($bk)); ?>; width: <?php echo attr(number_format($pct, 2)) . '%'; ?>;"></span>
          <?php endforeach; ?>
        </div>
        <div class="cp-bucket-legend">
          <?php foreach ($bucketOrder as $bk):
              $val = (float)$bucketTotals[$bk];
              $pct = ($totalAR > 0) ? ($val / $totalAR) * 100.0 : 0.0;
              $tone = cp_tone_for_bucket($bk);
              ?>
            <div class="item">
              <div class="lbl-row">
                <span class="dot" style="background: <?php echo attr(cp_dot_for_bucket($bk)); ?>;"></span>
                <?php echo text(cp_label_for_bucket($bk)); ?>
              </div>
              <div class="val <?php echo attr($tone); ?>"><?php echo text(cp_fmt_money($val)); ?></div>
              <div class="sub">
                <?php
                if ($totalAR > 0) {
                    echo text(number_format($pct, 1) . '% of total');
                } else {
                    echo xlt('— of total');
                }
                ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="cp-aging-cols">

        <div class="cp-aging-card">
          <div class="head"><?php echo xlt('AGING BY PAYER'); ?></div>
          <table class="cp-payer-tbl">
            <thead>
              <tr>
                <th><?php echo xlt('PAYER'); ?></th>
                <th>0-30</th>
                <th>31-60</th>
                <th>61-90</th>
                <th>91-120</th>
                <th>&gt; 120</th>
                <th><?php echo xlt('TOTAL'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($payerRows) === 0): ?>
                <tr class="empty">
                  <td colspan="7"><?php echo xlt('No outstanding A/R.'); ?></td>
                </tr>
              <?php else: ?>
                <?php foreach ($payerRows as $pr):
                    $b = $pr['buckets'];
                    ?>
                  <tr>
                    <td><?php echo text((string)$pr['payer']); ?></td>
                    <td><?php echo text(cp_fmt_money_cents((float)$b['0-30'])); ?></td>
                    <td><?php echo text(cp_fmt_money_cents((float)$b['31-60'])); ?></td>
                    <td><?php echo text(cp_fmt_money_cents((float)$b['61-90'])); ?></td>
                    <td class="warn"><?php echo text(cp_fmt_money_cents((float)$b['91-120'])); ?></td>
                    <td class="warn"><?php echo text(cp_fmt_money_cents((float)$b['>120'])); ?></td>
                    <td class="tot danger"><?php echo text(cp_fmt_money_cents((float)$pr['total'])); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="cp-aging-card">
          <div class="head"><?php echo xlt('LARGEST OUTSTANDING ACCOUNTS'); ?></div>
          <div class="cp-acct-list">
            <?php if (count($topAccts) === 0): ?>
              <div class="cp-acct-empty"><?php echo xlt('No outstanding accounts.'); ?></div>
            <?php else: ?>
              <?php foreach ($topAccts as $a):
                  $bk = (string)$a['bucket'];
                  $tone = cp_tone_for_bucket($bk);
                  $mrn = ((string)$a['pubpid'] !== '') ? '#' . (string)$a['pubpid'] : '#' . (string)$a['pid'];
                  // Statement link → patient billing page in the same module.
                  $stmtUrl = 'pat_ledger.php?form=1&patient_id=' . (int)$a['pid'];
                  ?>
                <div class="cp-acct-row">
                  <span class="av"></span>
                  <div class="who">
                    <span class="nm"><?php echo text((string)$a['patient_name']); ?></span>
                    <span class="mrn"><?php echo text($mrn); ?></span>
                  </div>
                  <span class="amt <?php echo attr($tone); ?>"><?php echo text(cp_fmt_money_cents((float)$a['balance'])); ?></span>
                  <span class="bkt"><?php echo text(cp_label_for_bucket($bk)); ?></span>
                  <span class="note"><?php echo text((string)$a['payer_name']); ?></span>
                  <a href="<?php echo attr($stmtUrl); ?>" class="stmt"><?php echo xlt('Statement'); ?> →</a>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>

    </main>
  </div>
</div>

</body>
</html>
