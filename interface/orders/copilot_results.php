<?php

/**
 * Patient Results landing page — Figma "Screen 36 — Patient Results".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/patient_results/. The PHP outer shell
 * still owns the navy top nav, demographics banner and patient-tab strip;
 * this file only renders the page body inside the patient frame.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original static-HTML mock is preserved at copilot_results.php.bak
 * so a side-by-side screenshot diff remains possible.
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
$entry        = $manifest['src/pages/patient_results/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);
$activePid   = (int)($patientId !== '' ? $patientId : 0);

// ---------------------------------------------------------------------------
// Live procedure_result rows for the active patient. Each row is rendered
// as a card on the timeline grouped by month. Bucket logic mirrors the
// .bak (lab / imaging / documents / procedures) so filter pills align with
// the prior PHP implementation.
// ---------------------------------------------------------------------------

function cp_pr_bucket(string $orderType): string
{
    $t = strtolower($orderType);
    if (str_contains($t, 'imag') || str_contains($t, 'rad')) return 'imaging';
    if (str_contains($t, 'doc')) return 'documents';
    if (str_contains($t, 'proc') && !str_contains($t, 'lab')) return 'procedures';
    return 'labs';
}

$months = [];
$monthIndex = [];
$counts = ['labs' => 0, 'imaging' => 0, 'procedures' => 0, 'documents' => 0];
$abnormalCount = 0;
$totalCount = 0;

if ($activePid > 0) {
    $rs = sqlStatement(
        "SELECT po.procedure_order_id, po.encounter_id, po.provider_id,
                po.date_ordered, po.procedure_order_type,
                rep.procedure_report_id, rep.report_status,
                rep.date_collected, rep.date_report,
                pr.procedure_result_id, pr.result_code, pr.result_text,
                pr.result, pr.units, pr.range, pr.abnormal, pr.result_status,
                u.fname AS uf, u.lname AS ul, u.title AS ut, u.username AS uu
           FROM procedure_result pr
           JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
           JOIN procedure_order  po  ON po.procedure_order_id   = rep.procedure_order_id
           LEFT JOIN users u ON u.id = po.provider_id
          WHERE po.patient_id = ?
          ORDER BY COALESCE(rep.date_collected, rep.date_report, po.date_ordered) DESC,
                   pr.procedure_result_id DESC",
        [$activePid]
    );
    while ($r = sqlFetchArray($rs)) {
        $totalCount++;
        $bucket = cp_pr_bucket((string)($r['procedure_order_type'] ?? ''));
        $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        $abn = strtolower(trim((string)($r['abnormal'] ?? '')));
        $isAbnormal = ($abn !== '' && !in_array($abn, ['no', 'n', 'normal'], true));
        if ($isAbnormal) { $abnormalCount++; }

        $dateRaw = $r['date_collected'] ?: ($r['date_report'] ?: $r['date_ordered']);
        $ts = strtotime((string)$dateRaw);
        if ($ts === false) { continue; }
        $monthLabel = date('F Y', $ts);
        $datePretty = date('m/d', $ts);

        $providerName = cp_format_provider_name([
            'username' => $r['uu'] ?? '', 'fname' => $r['uf'] ?? '',
            'lname'    => $r['ul'] ?? '', 'title' => $r['ut'] ?? '',
        ]);
        $vendor = $bucket === 'imaging' ? 'Imaging'
                : ($bucket === 'documents' ? 'Document' : 'Lab');
        $source = $vendor . ' · ' . ($providerName !== '' ? $providerName : '—');

        $resultVal = trim((string)($r['result'] ?? ''));
        $units     = trim((string)($r['units'] ?? ''));
        $rangeRef  = trim((string)($r['range'] ?? ''));
        $valueDisplay = $resultVal === ''
            ? '—'
            : ($units !== '' ? $resultVal . ' ' . $units : $resultVal);
        $valueTone = $isAbnormal
            ? (str_contains($abn, 'crit') || $abn === 'critical' ? 'danger' : 'warn')
            : 'plain';
        $delta = $rangeRef !== '' ? ('Ref ' . $rangeRef) : '—';

        $flagLabel = '';
        $flagTone  = null;
        if ($isAbnormal) {
            if (str_contains($abn, 'crit') || $abn === 'critical') {
                $flagLabel = 'Critical'; $flagTone = 'danger';
            } else {
                $flagLabel = 'Abnormal'; $flagTone = 'warn';
            }
        }

        $icon = ($bucket === 'imaging' || $bucket === 'documents') ? '🩻' : '🧪';
        $testName = trim((string)($r['result_text'] ?? '')) ?: trim((string)($r['result_code'] ?? '')) ?: '—';
        $orderId = (int)($r['procedure_order_id'] ?? 0);
        $encId   = (int)($r['encounter_id'] ?? 0);

        $row = [
            'date'      => $datePretty,
            'bucket'    => $bucket,
            'icon'      => $icon,
            'test'      => $testName,
            'src'       => $source,
            'value'     => $valueDisplay,
            'valueTone' => $valueTone,
            'delta'     => $delta,
            'isAbnormal'=> $isAbnormal,
            'flagLabel' => $flagLabel,
            'flagTone'  => $flagTone,
            'href'      => $encId > 0
                ? '/interface/patient_file/encounter/copilot_encounter.php?eid=' . $encId
                : '/interface/orders/orders_results.php?id=' . $orderId,
        ];

        if (!isset($monthIndex[$monthLabel])) {
            $monthIndex[$monthLabel] = count($months);
            $months[] = ['label' => $monthLabel, 'rows' => []];
        }
        $months[$monthIndex[$monthLabel]]['rows'][] = $row;
    }
}

$resultsPayload = [
    'months'  => $months,
    'counts'  => [
        'all'        => $totalCount,
        'labs'       => $counts['labs'],
        'imaging'    => $counts['imaging'],
        'procedures' => $counts['procedures'],
        'documents'  => $counts['documents'],
        'abnormal'   => $abnormalCount,
    ],
];
$resultsJson = json_encode($resultsPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Results'); ?></title>
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
    background: #F5F6F7;
    color: var(--cp-navy);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
  }
  button { font-family: inherit; }
  #cp-root { min-height: 100%; }
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
     data-page="patient_results"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-results="<?php echo attr($resultsJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Patient Results UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
