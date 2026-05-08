<?php

/**
 * Aging Report — Figma "Screen 59 — Daily Cash / Aging".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/aging/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original PHP-rendered page is preserved at copilot_aging.php.bak
 * so a side-by-side screenshot diff remains possible.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

// ---------------------------------------------------------------------------
// Resolve built React assets via the Vite manifest.
//
// Vite emits hashed filenames + a manifest.json mapping logical entry paths
// (relative to /frontend) to the built file + its imported CSS. We read it
// at request time so a fresh build is picked up without restarting Apache.
//
// If the manifest is missing (e.g. /frontend has not been built yet), fall
// through to a clear in-page error rather than silently rendering nothing.
// ---------------------------------------------------------------------------

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/aging/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live aging buckets. Each billing row contributes (fee - paid_total) to
// the bucket determined by date-of-service age. Negative outstanding is
// clamped to 0 so over-payments don't subtract.
// ---------------------------------------------------------------------------

$buckets = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91-120' => 0.0, '>120' => 0.0];
$totalAR = 0.0;
$totalCollected = 0.0;
$accountTotals = []; // pid → outstanding $
$accountMeta   = []; // pid → ['name' => ..., 'mrn' => ..., 'bucket' => ...]

$rs = sqlStatement(
    "SELECT b.id, b.date, b.code, b.fee, b.encounter, b.pid, b.billed,
            pd.fname, pd.lname,
            (SELECT IFNULL(SUM(pay_amount + adj_amount), 0) FROM ar_activity a
              WHERE a.encounter = b.encounter AND a.code = b.code AND a.deleted IS NULL) AS paid_total
       FROM billing b
       LEFT JOIN patient_data pd ON pd.pid = b.pid
      WHERE b.activity = 1"
);
$now = time();
while ($r = sqlFetchArray($rs)) {
    $fee  = (float)($r['fee'] ?? 0);
    $paid = (float)($r['paid_total'] ?? 0);
    $totalCollected += $paid;
    $outstanding = max(0, $fee - $paid);
    if ($outstanding <= 0) { continue; }

    $totalAR += $outstanding;
    $ts = strtotime((string)($r['date'] ?? '')) ?: $now;
    $days = max(0, (int)floor(($now - $ts) / 86400));
    $bucket = $days <= 30  ? '0-30'
           : ($days <= 60  ? '31-60'
           : ($days <= 90  ? '61-90'
           : ($days <= 120 ? '91-120' : '>120')));
    $buckets[$bucket] += $outstanding;

    $pid = (int)($r['pid'] ?? 0);
    $accountTotals[$pid] = ($accountTotals[$pid] ?? 0) + $outstanding;
    if (!isset($accountMeta[$pid])) {
        $name = trim((string)($r['fname'] ?? '') . ' ' . (string)($r['lname'] ?? ''));
        if ($name === '') { $name = 'Patient #' . $pid; }
        $accountMeta[$pid] = [
            'name'   => $name,
            'mrn'    => '#' . str_pad((string)$pid, 6, '0', STR_PAD_LEFT),
            'bucket' => $bucket,
        ];
    }
}

$money = static fn (float $n): string => '$' . number_format($n, 2);
$pct   = static fn (float $n): string =>
    $totalAR > 0 ? number_format(($n / $totalAR) * 100, 1) . '%' : '0%';

$bucketTiles = [
    ['key' => '0-30',   'label' => 'Current (0-30)', 'value' => $money($buckets['0-30']),   'pctLabel' => $pct($buckets['0-30'])   . ' of total', 'tone' => 'green'],
    ['key' => '31-60',  'label' => '31-60',          'value' => $money($buckets['31-60']),  'pctLabel' => $pct($buckets['31-60'])  . ' of total', 'tone' => 'green'],
    ['key' => '61-90',  'label' => '61-90',          'value' => $money($buckets['61-90']),  'pctLabel' => $pct($buckets['61-90'])  . ' of total', 'tone' => 'orange'],
    ['key' => '91-120', 'label' => '91-120',         'value' => $money($buckets['91-120']), 'pctLabel' => $pct($buckets['91-120']) . ' of total', 'tone' => 'orange'],
    ['key' => '>120',   'label' => '> 120',          'value' => $money($buckets['>120']),   'pctLabel' => $pct($buckets['>120'])   . ' of total', 'tone' => 'red'],
];

$bucketBarPcts = [
    ['key' => '0-30',   'pct' => $totalAR > 0 ? round(($buckets['0-30']   / $totalAR) * 100, 1) : 0, 'fill' => '#33A666'],
    ['key' => '31-60',  'pct' => $totalAR > 0 ? round(($buckets['31-60']  / $totalAR) * 100, 1) : 0, 'fill' => '#33A666'],
    ['key' => '61-90',  'pct' => $totalAR > 0 ? round(($buckets['61-90']  / $totalAR) * 100, 1) : 0, 'fill' => '#FA8C33'],
    ['key' => '91-120', 'pct' => $totalAR > 0 ? round(($buckets['91-120'] / $totalAR) * 100, 1) : 0, 'fill' => '#FA8C33'],
    ['key' => '>120',   'pct' => $totalAR > 0 ? round(($buckets['>120']   / $totalAR) * 100, 1) : 0, 'fill' => '#D93838'],
];

// Top outstanding accounts.
arsort($accountTotals);
$accounts = [];
foreach ($accountTotals as $pid => $amt) {
    $meta = $accountMeta[$pid] ?? null;
    if (!$meta) { continue; }
    $tone = $meta['bucket'] === '>120' ? 'red'
          : ($meta['bucket'] === '91-120' || $meta['bucket'] === '61-90' ? 'orange' : 'dark');
    $accounts[] = [
        'name'       => $meta['name'],
        'mrn'        => $meta['mrn'],
        'amount'     => $money($amt),
        'amountTone' => $tone,
        'bucket'     => $meta['bucket'] === '>120' ? '> 120 days' : $meta['bucket'],
        'note'       => 'Pending claim',
    ];
    if (count($accounts) >= 8) { break; }
}

$pctOver90 = $totalAR > 0
    ? number_format((($buckets['91-120'] + $buckets['>120']) / $totalAR) * 100, 1) . '%'
    : '0%';
$kpis = [
    ['label' => 'Total A/R',       'value' => $money($totalAR),         'tone' => 'dark'],
    ['label' => 'Days in A/R',     'value' => '—',                       'tone' => 'green'],
    ['label' => '% > 90 days',     'value' => $pctOver90,                'tone' => 'orange'],
    ['label' => 'Collected (cum)', 'value' => $money($totalCollected),   'tone' => 'green'],
    ['label' => 'Adjustments',     'value' => '—',                       'tone' => 'dark'],
];

$agingPayload = [
    'kpis'          => $kpis,
    'bucketTiles'   => $bucketTiles,
    'bucketBarPcts' => $bucketBarPcts,
    'accounts'      => $accounts,
    'totalAR'       => $totalAR,
];
$agingJson = json_encode($agingPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Daily Cash / Aging'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo attr($webroot); ?>/public/copilot-tokens.css">
<?php foreach ($cssHrefs as $h): ?>
<link rel="stylesheet" href="<?php echo attr($webroot); ?>/public/build/<?php echo attr((string)$h); ?>">
<?php endforeach; ?>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: var(--cp-font);
    background: var(--cp-bg);
    color: var(--cp-navy);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
  }
  button { font-family: inherit; }
  #cp-root { height: 100%; }
  /* Visible-by-default error if the React bundle fails to load. Hidden by
   * the React tree on first render. */
  .cp-boot-error {
    display: none;
    padding: 24px;
    color: #4F5763;
    font-size: 13px;
  }
  #cp-root:empty + .cp-boot-error { display: block; }
</style>
</head>
<body>
<div id="cp-root"
     data-page="aging"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-aging="<?php echo attr($agingJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Aging UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
