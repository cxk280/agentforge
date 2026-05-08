<?php

/**
 * Pending Review — Figma "Screen 35 — Pending Review".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/pending_review/. The PHP outer shell
 * at /interface/main/tabs/main.php still owns the navy top nav, left
 * sidebar, and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original PHP-rendered version (with real DB-backed queue, POST
 * handlers, etc.) is preserved at copilot_pending_review.php.bak so a
 * side-by-side reference remains possible.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

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
$entry        = $manifest['src/pages/pending_review/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live cross-patient queue of pending reviews.
//
// One queue row per procedure_report whose review_status is unset or not
// 'reviewed'. We keep the FIRST procedure_result on each report (smallest
// procedure_result_id) so the row's value/units summarize the whole report
// — a clinician landing on the queue wants the headline, not every
// secondary analyte. The detail pane (right side of the page) re-fetches
// the trend for the selected report's primary result.
// ---------------------------------------------------------------------------

function cp_pending_bucket(string $orderType, string $abnormal): string
{
    $abn = strtolower(trim($abnormal));
    if (str_contains($abn, 'crit') || $abn === 'critical') {
        return 'lab'; // critical readings still belong to the lab bucket;
                      // the page exposes a separate "Critical" pivot pill.
    }
    $t = strtolower(trim($orderType));
    if (str_contains($t, 'imag') || str_contains($t, 'rad')) { return 'imaging'; }
    if (str_contains($t, 'doc'))                              { return 'doc'; }
    return 'lab';
}

function cp_pending_status(string $abnormal): array
{
    $abn = strtolower(trim($abnormal));
    if (str_contains($abn, 'crit')) { return ['Critical', 'danger']; }
    if (in_array($abn, ['high', 'low', 'abn', 'abnormal'], true) || str_starts_with($abn, 'a')) {
        return ['Abnormal', 'warn'];
    }
    return ['Routine', 'info'];
}

function cp_pending_when(string $iso): string
{
    $t = strtotime($iso);
    if ($t === false) { return ''; }
    $delta = time() - $t;
    if ($delta < 60)            { return 'just now'; }
    if ($delta < 3600)          { return (int)floor($delta / 60) . 'm ago'; }
    if ($delta < 86400)         { return (int)floor($delta / 3600) . 'h ago'; }
    if ($delta < 86400 * 30)    { return (int)floor($delta / 86400) . 'd ago'; }
    return date('m/d/y', $t);
}

function cp_pending_arrow(string $abnormal): string
{
    $abn = strtolower(trim($abnormal));
    if ($abn === 'high' || $abn === 'h')                   { return ' (↑)'; }
    if ($abn === 'low' || $abn === 'l')                    { return ' (↓)'; }
    if (str_contains($abn, 'crit'))                        { return ' (↑↑)'; }
    return '';
}

$listSql = "
    SELECT
        pr.procedure_result_id,
        pr.result_code,
        pr.result_text,
        pr.result,
        pr.units,
        pr.range,
        pr.abnormal,
        rep.procedure_report_id,
        rep.review_status,
        rep.report_status,
        COALESCE(rep.date_collected, rep.date_report) AS report_date,
        po.procedure_order_id,
        po.procedure_order_type,
        po.patient_id,
        po.provider_id,
        pd.fname,
        pd.lname,
        pd.pubpid
    FROM procedure_result pr
    JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
    JOIN procedure_order po   ON po.procedure_order_id   = rep.procedure_order_id
    JOIN patient_data pd      ON pd.pid                  = po.patient_id
    WHERE (rep.review_status IS NULL OR rep.review_status <> 'reviewed')
      AND pr.procedure_result_id = (
          SELECT MIN(pr2.procedure_result_id)
            FROM procedure_result pr2
           WHERE pr2.procedure_report_id = pr.procedure_report_id
      )
    ORDER BY COALESCE(rep.date_collected, rep.date_report) DESC,
             pr.procedure_result_id DESC
";

$queue = [];
$counts = ['all' => 0, 'lab' => 0, 'imaging' => 0, 'doc' => 0, 'msg' => 0, 'critical' => 0];
$providerCache = [];
$providerHits = [];

$rs = sqlStatement($listSql);
while ($r = sqlFetchArray($rs)) {
    $bucket = cp_pending_bucket((string)$r['procedure_order_type'], (string)$r['abnormal']);
    $abn    = strtolower((string)$r['abnormal']);
    $isCrit = str_contains($abn, 'crit');

    [$statusLabel, $statusTone] = cp_pending_status((string)$r['abnormal']);

    $providerId = (int)($r['provider_id'] ?? 0);
    if ($providerId > 0 && !isset($providerCache[$providerId])) {
        $u = sqlQuery(
            "SELECT username, fname, lname, title FROM users WHERE id = ?",
            [$providerId]
        );
        $providerCache[$providerId] = cp_format_provider_name($u ?: null);
    }
    if ($providerId > 0) {
        $providerHits[$providerId] = ($providerHits[$providerId] ?? 0) + 1;
    }

    $valueRaw   = trim((string)$r['result']);
    $units      = trim((string)$r['units']);
    $valueDisp  = $valueRaw === '' ? '—'
                : ($units !== '' ? $valueRaw . ' ' . $units : $valueRaw);
    $valueDisp .= cp_pending_arrow((string)$r['abnormal']);

    $patientName = trim((string)$r['fname'] . ' ' . (string)$r['lname']);
    if ($patientName === '') { $patientName = 'Patient #' . (int)$r['patient_id']; }
    $testName = trim((string)$r['result_text']);
    if ($testName === '') { $testName = trim((string)$r['result_code']) ?: '—'; }

    $icon = $bucket === 'imaging' ? '🩻' : ($bucket === 'doc' ? '📄' : '🧪');

    $queue[] = [
        'id'            => 'rep-' . (int)$r['procedure_report_id'],
        'reportId'      => (int)$r['procedure_report_id'],
        'resultId'      => (int)$r['procedure_result_id'],
        'orderId'       => (int)$r['procedure_order_id'],
        'patientId'     => (int)$r['patient_id'],
        'providerId'    => $providerId,
        'bucket'        => $bucket,
        'icon'          => $icon,
        'title'         => $patientName . ' · ' . $testName,
        'sub'           => $valueDisp,
        'statusLabel'   => $statusLabel,
        'statusTone'    => $statusTone,
        'when'          => cp_pending_when((string)$r['report_date']),
        'preChecked'    => $statusTone !== 'plain',
        'critical'      => $isCrit,
        // Detail-pane payload (per row, no extra round trip).
        'patientName'   => $patientName,
        'pubpid'        => (string)$r['pubpid'],
        'testName'      => $testName,
        'resultCode'    => (string)$r['result_code'],
        'resultValue'   => $valueRaw,
        'units'         => $units,
        'range'         => trim((string)$r['range']),
        'abnormal'      => (string)$r['abnormal'],
        'reportDate'    => (string)$r['report_date'],
        'providerName'  => $providerId > 0 ? ($providerCache[$providerId] ?? '—') : '—',
    ];

    $counts['all']++;
    $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
    if ($isCrit) { $counts['critical']++; }
}

// Per-row Last-N trend: last 3 prior values for the same patient + result_code,
// oldest→newest, with the row's own value appended. Skipped for empty result
// codes (e.g. imaging with prose results) since trend isn't meaningful.
foreach ($queue as &$row) {
    $code = $row['resultCode'];
    if ($code === '' || !is_numeric(preg_replace('/^[<>≤≥]+/', '', trim($row['resultValue'])))) {
        $row['trend'] = [];
        $row['priorValue'] = null;
        $row['priorDate']  = null;
        continue;
    }
    $tRs = sqlStatement(
        "SELECT pr.result, COALESCE(rep.date_collected, rep.date_report, pr.date) AS d
           FROM procedure_result pr
           JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
           JOIN procedure_order  po  ON po.procedure_order_id   = rep.procedure_order_id
          WHERE po.patient_id = ?
            AND pr.result_code = ?
            AND pr.procedure_result_id <> ?
          ORDER BY COALESCE(rep.date_collected, rep.date_report, pr.date) DESC
          LIMIT 3",
        [$row['patientId'], $code, $row['resultId']]
    );
    $priors = [];
    while ($p = sqlFetchArray($tRs)) {
        $priors[] = ['v' => (string)$p['result'], 'd' => (string)$p['d']];
    }
    $trend = [];
    foreach (array_reverse($priors) as $p) { $trend[] = $p['v']; }
    $trend[] = $row['resultValue'];
    $row['trend'] = $trend;
    $row['priorValue'] = $priors !== [] ? $priors[0]['v'] : null;
    $row['priorDate']  = $priors !== [] ? $priors[0]['d'] : null;
}
unset($row);

// "Dr. Rivera · 18 results, 4 documents, 2 messages awaiting sign-off" line.
$topProvider = '';
if ($providerHits !== []) {
    arsort($providerHits);
    $topId = (int)array_key_first($providerHits);
    $topProvider = $providerCache[$topId] ?? '';
}
$resultCount   = $counts['lab'];
$imagingCount  = $counts['imaging'];
$docCount      = $counts['doc'];
$summaryParts = [];
if ($resultCount > 0)  { $summaryParts[] = $resultCount . ' result'  . ($resultCount === 1 ? '' : 's'); }
if ($imagingCount > 0) { $summaryParts[] = $imagingCount . ' imaging'; }
if ($docCount > 0)     { $summaryParts[] = $docCount . ' document'  . ($docCount === 1 ? '' : 's'); }
$summaryTail = $summaryParts === [] ? 'queue empty' : implode(', ', $summaryParts) . ' awaiting sign-off';
$headerSummary = ($topProvider !== '' ? $topProvider . ' · ' : '') . $summaryTail;

$pendingPayload = [
    'queue'         => $queue,
    'counts'        => $counts,
    'headerSummary' => $headerSummary,
];
$pendingJson = json_encode($pendingPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Pending Review'); ?></title>
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
     data-page="pending_review"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-pending="<?php echo attr($pendingJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Pending Review UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
