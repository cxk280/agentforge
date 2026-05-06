<?php

/**
 * Patient Results timeline — Screen 36.
 *
 * Patient-scoped results timeline. Renders a chronological list of lab,
 * imaging and document results grouped by month, with category filter
 * pills and a Timeline / Table / Trends view toggle. The chrome (top
 * nav, demographics banner, navtab strip) is rendered by the parent
 * shell — this page renders only the body.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Session\SessionWrapperFactory;
require_once(__DIR__ . "/../main/copilot_helpers.php");

// Patient context comes from session; default to pid=1 if missing.
$pid = (int)(SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 1);

// -----------------------------------------------------------------------------
// Filter / view GET params
// -----------------------------------------------------------------------------
$validTypes = ['all', 'labs', 'imaging', 'procedures', 'documents', 'abnormal'];
$type = (string)($_GET['type'] ?? 'all');
if (!in_array($type, $validTypes, true)) {
    $type = 'all';
}

$validViews = ['timeline', 'table', 'trends'];
$view = (string)($_GET['view'] ?? 'timeline');
if (!in_array($view, $validViews, true)) {
    $view = 'timeline';
}

/**
 * Map a procedure_order_type string to one of the filter pill buckets.
 * OpenEMR ships 'laboratory_test' as the default. Imaging orders typically
 * use 'imaging' or 'radiology'; documents use 'document' / 'ancillary'.
 */
function cp_results_bucket(string $orderType): string
{
    $t = strtolower($orderType);
    if (str_contains($t, 'imag') || str_contains($t, 'rad')) {
        return 'imaging';
    }
    if (str_contains($t, 'doc')) {
        return 'documents';
    }
    if (str_contains($t, 'proc') && !str_contains($t, 'lab')) {
        return 'procedures';
    }
    return 'labs';
}

// -----------------------------------------------------------------------------
// Counts for the header line and pill badges (computed once, ignoring filter
// so the badges always reflect the patient's full record).
// -----------------------------------------------------------------------------
$countsByBucket = ['labs' => 0, 'imaging' => 0, 'procedures' => 0, 'documents' => 0];
$abnormalCount = 0;
$totalCount = 0;

$countRows = sqlStatement(
    "SELECT po.procedure_order_type, pr.abnormal
     FROM procedure_result pr
     JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
     JOIN procedure_order po   ON po.procedure_order_id   = rep.procedure_order_id
     WHERE po.patient_id = ?",
    [$pid]
);
while ($cr = sqlFetchArray($countRows)) {
    $totalCount++;
    $bucket = cp_results_bucket((string)($cr['procedure_order_type'] ?? ''));
    $countsByBucket[$bucket] = ($countsByBucket[$bucket] ?? 0) + 1;
    $abn = strtolower(trim((string)($cr['abnormal'] ?? '')));
    if ($abn !== '' && $abn !== 'no' && $abn !== 'n' && $abn !== 'normal') {
        $abnormalCount++;
    }
}

// -----------------------------------------------------------------------------
// Main query: every result row for this patient, newest first.
// We always fetch the full set then apply the bucket/abnormal filter in PHP
// so a single query feeds both the count badges and the filtered timeline.
// -----------------------------------------------------------------------------
$sql = "SELECT
            po.procedure_order_id,
            po.encounter_id,
            po.provider_id,
            po.date_ordered,
            po.procedure_order_type,
            rep.procedure_report_id,
            rep.report_status,
            rep.date_collected,
            rep.date_report,
            pr.procedure_result_id,
            pr.result_code,
            pr.result_text,
            pr.result,
            pr.units,
            pr.range,
            pr.abnormal,
            pr.result_status
        FROM procedure_result pr
        JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
        JOIN procedure_order po   ON po.procedure_order_id   = rep.procedure_order_id
        WHERE po.patient_id = ?
        ORDER BY COALESCE(rep.date_collected, rep.date_report, po.date_ordered) DESC,
                 pr.procedure_result_id DESC";
$rs = sqlStatement($sql, [$pid]);

// Cache for provider lookups so we only hit `users` once per provider.
$providerCache = [];

// Group rows into month buckets (e.g. "April 2026") preserving DESC order.
$months = []; // [['label' => 'April 2026', 'rows' => [...]], …]
$monthIndex = []; // label => index in $months

while ($r = sqlFetchArray($rs)) {
    $bucket = cp_results_bucket((string)($r['procedure_order_type'] ?? ''));
    $abn = strtolower(trim((string)($r['abnormal'] ?? '')));
    $isAbnormal = ($abn !== '' && $abn !== 'no' && $abn !== 'n' && $abn !== 'normal');

    // Apply the active filter pill.
    if ($type === 'labs' && $bucket !== 'labs') { continue; }
    if ($type === 'imaging' && $bucket !== 'imaging') { continue; }
    if ($type === 'procedures' && $bucket !== 'procedures') { continue; }
    if ($type === 'documents' && $bucket !== 'documents') { continue; }
    if ($type === 'abnormal' && !$isAbnormal) { continue; }

    // Pick a date for sorting / display: prefer date_collected, then date_report, then date_ordered.
    $dateRaw = $r['date_collected'] ?: ($r['date_report'] ?: $r['date_ordered']);
    if (!$dateRaw) {
        continue; // no usable date — skip this orphan row
    }
    $ts = strtotime((string)$dateRaw);
    if ($ts === false) {
        continue;
    }
    $monthLabel = date('F Y', $ts);
    $datePretty = date('m/d', $ts);

    // Provider name (lazy lookup, cached).
    $providerId = (int)($r['provider_id'] ?? 0);
    if ($providerId > 0 && !isset($providerCache[$providerId])) {
        $u = sqlQuery("SELECT username, fname, lname, title FROM users WHERE id = ?", [$providerId]);
        $providerCache[$providerId] = cp_format_provider_name($u ?: null);
    }
    $providerName = $providerCache[$providerId] ?? '—';

    // Source string: "Quest · Dr. Rivera" — we don't have a lab vendor in the
    // schema, so use facility from the result, falling back to the order type
    // label, then the provider name.
    $facility = trim((string)($r['result_status'] ?? '')); // not really facility, kept for parity
    $orderType = (string)($r['procedure_order_type'] ?? 'laboratory_test');
    $vendor = ($bucket === 'imaging') ? 'Imaging' : (($bucket === 'documents') ? 'Document' : 'Lab');
    $source = $vendor . ' · ' . $providerName;

    // Value rendering: numeric "result units" if both, otherwise just result text.
    $resultVal = trim((string)($r['result'] ?? ''));
    $units = trim((string)($r['units'] ?? ''));
    $range = trim((string)($r['range'] ?? ''));
    if ($resultVal === '') {
        $valueDisplay = '—';
    } elseif ($units !== '') {
        $valueDisplay = $resultVal . ' ' . $units;
    } else {
        $valueDisplay = $resultVal;
    }
    $valueTone = $isAbnormal ? 'warn' : 'plain';

    // Delta text — schema has no prior-value field; show the reference range
    // when present so the user has *some* context, otherwise an em dash.
    $delta = $range !== '' ? ('Ref ' . $range) : '—';

    // Flag pill: Critical / Abnormal / (none).
    $flagLabel = '';
    $flagTone = '';
    if ($isAbnormal) {
        // OpenEMR convention: abnormal = 'high' | 'low' | 'critical' | 'abnormal' | 'yes'.
        if (str_contains($abn, 'crit') || $abn === 'cc' || $abn === 'critical') {
            $flagLabel = 'Critical';
            $flagTone = 'danger';
        } else {
            $flagLabel = 'Abnormal';
            $flagTone = 'warn';
        }
    }

    // Icon bucket — labs and procedures use the lab/pen glyph; imaging and
    // documents use the small image glyph.
    $icon = ($bucket === 'imaging' || $bucket === 'documents') ? 'imaging' : 'lab';

    // Test name — prefer the result_text; fall back to result_code.
    $testName = trim((string)($r['result_text'] ?? '')) ?: trim((string)($r['result_code'] ?? '')) ?: '—';

    // Per-row link target — prefer the encounter view if we have one.
    $encId = (int)($r['encounter_id'] ?? 0);
    $orderId = (int)($r['procedure_order_id'] ?? 0);
    if ($encId > 0) {
        $viewHref = '/interface/patient_file/encounter/copilot_encounter.php?eid=' . $encId;
    } else {
        $viewHref = '/interface/orders/orders_results.php?id=' . $orderId;
    }

    $row = [
        'date'      => $datePretty,
        'icon'      => $icon,
        'test'      => $testName,
        'src'       => $source,
        'value'     => $valueDisplay,
        'valueTone' => $valueTone,
        'delta'     => $delta,
        'flagLabel' => $flagLabel,
        'flagTone'  => $flagTone,
        'href'      => $viewHref,
    ];

    if (!isset($monthIndex[$monthLabel])) {
        $monthIndex[$monthLabel] = count($months);
        $months[] = ['label' => $monthLabel, 'rows' => []];
    }
    $months[$monthIndex[$monthLabel]]['rows'][] = $row;
}

// Helper to build "?type=…&view=…" while preserving the other param.
function cp_results_qs(string $typeVal, string $viewVal): string
{
    return '?type=' . urlencode($typeVal) . '&view=' . urlencode($viewVal);
}

$pillSpec = [
    ['key' => 'all',        'label' => 'All',        'count' => $totalCount],
    ['key' => 'labs',       'label' => 'Labs',       'count' => $countsByBucket['labs']],
    ['key' => 'imaging',    'label' => 'Imaging',    'count' => $countsByBucket['imaging']],
    ['key' => 'procedures', 'label' => 'Procedures', 'count' => $countsByBucket['procedures']],
    ['key' => 'documents',  'label' => 'Documents',  'count' => $countsByBucket['documents']],
    ['key' => 'abnormal',   'label' => 'Abnormal',   'count' => $abnormalCount],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Results'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header — Results */
  .cp-pagehead .dot { color: #C7CBD2; font-size: 14px; padding: 0 2px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }

  /* Filter strip — count pills + right-side view toggle */
  .cp-filter .pills a,
  .cp-filter .pills button {
    text-decoration: none;
  }
  .cp-filter .pills button .ct,
  .cp-filter .pills a .ct {
    background: #F0F1F3; color: #4F5763;
    margin-left: 6px; padding: 1px 7px; border-radius: 999px;
    font-size: 10px; font-weight: 600;
  }
  .cp-filter .pills button.active .ct,
  .cp-filter .pills a.active .ct {
    background: #008C8C; color: #FFFFFF;
  }
  .cp-filter .right {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 8px;
  }
  .cp-filter .right .lbl { font-size: 11px; color: #8A91A1; }
  .cp-view-toggle {
    display: inline-flex;
    border: 1px solid #E4E5E8; border-radius: 8px; overflow: hidden;
    background: #FFFFFF;
  }
  .cp-view-toggle a,
  .cp-view-toggle button {
    border: 0; background: transparent;
    padding: 6px 14px;
    font-size: 11px; font-weight: 500;
    color: #4F5763;
    border-right: 1px solid #E4E5E8;
    line-height: 1.2;
    text-decoration: none;
    display: inline-block;
  }
  .cp-view-toggle a:last-child,
  .cp-view-toggle button:last-child { border-right: 0; }
  .cp-view-toggle a.active,
  .cp-view-toggle button.active {
    background: #F5F6F7; color: #0D1B2A; font-weight: 600;
  }

  /* Timeline */
  .cp-timeline { display: flex; flex-direction: column; gap: 18px; }
  .cp-tl-month {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.4px;
    padding: 0 4px 8px;
    margin: 0;
  }
  .cp-tl-rows { display: flex; flex-direction: column; gap: 8px; }
  .cp-tl-row {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 18px;
    display: grid;
    grid-template-columns: 56px 28px 1fr auto auto auto auto;
    align-items: center;
    gap: 14px;
  }
  .cp-tl-row .date {
    font-size: 13px; font-weight: 600;
    color: #0D1B2A;
    line-height: 1;
  }
  .cp-tl-row .ic {
    width: 28px; height: 28px;
    border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex: 0 0 auto;
  }
  .cp-tl-row .ic.lab     { color: #008C8C; }
  .cp-tl-row .ic.imaging {
    background: #E9F0FA; color: #4785D9;
    border-radius: 6px;
  }
  .cp-tl-row .body { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
  .cp-tl-row .body .name {
    font-size: 13px; font-weight: 600;
    color: #0D1B2A; line-height: 1.2;
  }
  .cp-tl-row .body .src {
    font-size: 11px; color: #8A91A1; line-height: 1.2;
  }
  .cp-tl-row .val {
    display: flex; flex-direction: column; gap: 4px;
    text-align: left;
    min-width: 200px;
  }
  .cp-tl-row .val .num {
    font-size: 13px; font-weight: 600;
    color: #0D1B2A; line-height: 1.2;
  }
  .cp-tl-row .val .num.warn { color: #FA8C33; }
  .cp-tl-row .val .delta {
    font-size: 11px; color: #8A91A1; line-height: 1.2;
  }
  .cp-tl-row .val .delta.warn { color: #FA8C33; }
  .cp-tl-row .pill-cell {
    min-width: 78px;
    display: flex; justify-content: flex-start;
  }
  .cp-tl-row .view {
    background: transparent; border: 0;
    color: #008C8C;
    font-size: 12px; font-weight: 600;
    padding: 4px 6px;
    line-height: 1;
    text-decoration: none;
  }
  .cp-tl-row .kebab {
    color: #8A91A1; cursor: not-allowed;
    padding: 4px 6px; border-radius: 6px;
    font-size: 14px; line-height: 1;
    background: transparent; border: 0;
    opacity: 0.7;
  }
  .cp-tl-row .kebab:hover { background: #F5F6F7; color: #0D1B2A; }

  /* Pill sizing override to match the soft outline-style pill in the mock */
  .cp-tl-row .cp-status-pill {
    background: #FFFFFF;
    border: 1px solid;
    padding: 3px 10px;
    font-weight: 600; letter-spacing: 0.2px;
    text-transform: none;
  }
  .cp-tl-row .cp-status-pill.danger { color: #D93838; border-color: #F5C2C2; }
  .cp-tl-row .cp-status-pill.warn   { color: #FA8C33; border-color: #F8D5A8; }

  /* Table view */
  .cp-results-table {
    width: 100%;
    border-collapse: collapse;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
  }
  .cp-results-table th, .cp-results-table td {
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid #F0F1F3;
    font-size: 12px;
    color: #0D1B2A;
  }
  .cp-results-table th {
    background: #F5F6F7;
    color: #4F5763;
    font-weight: 600;
    font-size: 11px;
  }
  .cp-results-table tr:last-child td { border-bottom: 0; }
  .cp-results-table .warn { color: #FA8C33; font-weight: 600; }

  /* Empty state */
  .cp-empty {
    background: #FFFFFF;
    border: 1px dashed #E4E5E8;
    border-radius: 12px;
    padding: 40px;
    text-align: center;
    color: #8A91A1;
    font-size: 13px;
  }

  /* Trends placeholder banner */
  .cp-trends-cta {
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    color: #4F5763;
    font-size: 12px;
  }
  .cp-trends-cta a { color: #008C8C; font-weight: 600; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="title"><?php echo xlt('Results'); ?></span>
      <span class="dot">·</span>
      <span class="meta-light">
        <?php echo text((string)$countsByBucket['labs']); ?> <?php echo xlt('lab results'); ?>
        ·
        <?php echo text((string)$countsByBucket['imaging']); ?> <?php echo xlt('imaging studies'); ?>
        ·
        <?php echo text((string)$countsByBucket['documents']); ?> <?php echo xlt('documents'); ?>
      </span>
    </div>
  </div>
  <a href="/interface/orders/orders_results.php?form=load&newprocedure=1" class="cp-btn primary" style="text-decoration:none;">+ <?php echo xlt('Order labs / imaging'); ?></a>
  <button type="button" class="cp-btn ghost">? <?php echo xlt('Help'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-filter">
    <div class="pills">
      <?php foreach ($pillSpec as $p): ?>
        <a href="<?php echo attr(cp_results_qs($p['key'], $view)); ?>"
           class="<?php echo $type === $p['key'] ? 'active' : ''; ?>"
           role="button">
          <?php echo xlt($p['label']); ?>
          <span class="ct"><?php echo text((string)$p['count']); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="right">
      <span class="lbl"><?php echo xlt('View'); ?></span>
      <div class="cp-view-toggle">
        <a href="<?php echo attr(cp_results_qs($type, 'timeline')); ?>" class="<?php echo $view === 'timeline' ? 'active' : ''; ?>"><?php echo xlt('Timeline'); ?></a>
        <a href="<?php echo attr(cp_results_qs($type, 'table')); ?>" class="<?php echo $view === 'table' ? 'active' : ''; ?>"><?php echo xlt('Table'); ?></a>
        <a href="<?php echo attr(cp_results_qs($type, 'trends')); ?>" class="<?php echo $view === 'trends' ? 'active' : ''; ?>"><?php echo xlt('Trends'); ?></a>
      </div>
    </div>
  </div>

  <?php if ($view === 'trends'): ?>
    <div class="cp-trends-cta">
      <?php echo xlt('Trended-value charts live on the Lab Overview page.'); ?>
      &nbsp;
      <a href="/interface/orders/copilot_lab_overview.php"><?php echo xlt('Open Lab Overview'); ?> →</a>
    </div>
  <?php elseif (empty($months)): ?>
    <div class="cp-empty">
      <?php echo xlt('No results match the current filter.'); ?>
    </div>
  <?php elseif ($view === 'table'): ?>
    <table class="cp-results-table">
      <thead>
        <tr>
          <th><?php echo xlt('Date'); ?></th>
          <th><?php echo xlt('Test'); ?></th>
          <th><?php echo xlt('Source'); ?></th>
          <th><?php echo xlt('Value'); ?></th>
          <th><?php echo xlt('Reference'); ?></th>
          <th><?php echo xlt('Flag'); ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($months as $m): ?>
          <?php foreach ($m['rows'] as $r): ?>
            <tr>
              <td><?php echo text($r['date']); ?></td>
              <td><?php echo text($r['test']); ?></td>
              <td><?php echo text($r['src']); ?></td>
              <td class="<?php echo $r['valueTone'] === 'warn' ? 'warn' : ''; ?>"><?php echo text($r['value']); ?></td>
              <td><?php echo text($r['delta']); ?></td>
              <td>
                <?php if ($r['flagLabel'] !== ''): ?>
                  <span class="cp-status-pill <?php echo attr($r['flagTone']); ?>"><?php echo text($r['flagLabel']); ?></span>
                <?php endif; ?>
              </td>
              <td><a class="view" href="<?php echo attr($r['href']); ?>"><?php echo xlt('View'); ?> →</a></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: /* timeline view */ ?>
    <div class="cp-timeline">
      <?php foreach ($months as $m): ?>
        <section>
          <h3 class="cp-tl-month"><?php echo text($m['label']); ?></h3>
          <div class="cp-tl-rows">
            <?php foreach ($m['rows'] as $r): ?>
              <div class="cp-tl-row">
                <span class="date"><?php echo text($r['date']); ?></span>
                <span class="ic <?php echo attr($r['icon']); ?>">
                  <?php if ($r['icon'] === 'lab'): ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.06 4.94l5 5L7 22H2v-5L14.06 4.94z"/><path d="M13 6l5 5"/></svg>
                  <?php else: ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                  <?php endif; ?>
                </span>
                <div class="body">
                  <span class="name"><?php echo text($r['test']); ?></span>
                  <span class="src"><?php echo text($r['src']); ?></span>
                </div>
                <div class="val">
                  <span class="num <?php echo $r['valueTone'] === 'warn' ? 'warn' : ''; ?>"><?php echo text($r['value']); ?></span>
                  <?php if ($r['delta'] !== ''): ?>
                    <span class="delta <?php echo $r['valueTone'] === 'warn' ? 'warn' : ''; ?>"><?php echo text($r['delta']); ?></span>
                  <?php endif; ?>
                </div>
                <div class="pill-cell">
                  <?php if ($r['flagLabel'] !== ''): ?>
                    <span class="cp-status-pill <?php echo attr($r['flagTone']); ?>"><?php echo text($r['flagLabel']); ?></span>
                  <?php endif; ?>
                </div>
                <a class="view" href="<?php echo attr($r['href']); ?>"><?php echo xlt('View'); ?> →</a>
                <button type="button" class="kebab" disabled title="<?php echo xla('Coming soon'); ?>">⋯</button>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</main>

</body>
</html>
