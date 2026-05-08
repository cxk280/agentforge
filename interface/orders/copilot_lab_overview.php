<?php

/**
 * Lab Overview / Trends per patient — Figma "Screen 37 — Lab Overview".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/lab_overview/. The PHP outer shell
 * at /interface/main/tabs/main.php still owns the navy top nav, left
 * sidebar, and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original PHP-rendered mock is preserved at copilot_lab_overview.php.bak
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
$entry        = $manifest['src/pages/lab_overview/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);
$activePid   = (int)($patientId !== '' ? $patientId : 0);

// ---------------------------------------------------------------------------
// Live trended-lab panels for the active patient.
//
// Each panel is one logical lab concept (HbA1c, LDL, Microalbumin,
// Creatinine). We list every LOINC code variant we know about for that lab
// so the query picks up data regardless of which the lab system used. The
// query targets procedure_result (joined to procedure_report/order) — that
// is the table our clinical-data seed populates.
//
// Reference ranges below are static clinical thresholds; the .range column
// on procedure_result varies per lab vendor and is too noisy to drive the
// pill text consistently.
// ---------------------------------------------------------------------------

$panelDefs = [
    [
        'name'     => 'HbA1c',
        'codes'    => ['4548-4', '17856-6'],
        'unit'     => '%',
        'ref'      => '<7.0',
        'good_dir' => 'down',
    ],
    [
        'name'     => 'LDL',
        'codes'    => ['13457-7', '2089-1', '18262-6'],
        'unit'     => 'mg/dL',
        'ref'      => '<100',
        'good_dir' => 'down',
    ],
    [
        'name'     => 'Microalbumin',
        'codes'    => ['14959-1', '14957-5'],
        'unit'     => 'mg/g',
        'ref'      => '<30',
        'good_dir' => 'down',
    ],
    [
        'name'     => 'Creatinine',
        'codes'    => ['2160-0', '38483-4'],
        'unit'     => 'mg/dL',
        'ref'      => '0.6-1.2',
        'good_dir' => 'stable',
    ],
];

function cp_lab_overview_format(float $v): string
{
    if (abs($v) < 10) {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
    return (string)(int)round($v);
}

/**
 * Compute trend tag + tone from a chronological numeric series.
 *
 * @param list<float> $series  Oldest → newest values.
 * @return array{tag:string, tone:'good'|'warn'|'danger'}
 */
function cp_lab_overview_trend(array $series, string $goodDir): array
{
    $n = count($series);
    if ($n < 2) {
        return ['tag' => '— single reading', 'tone' => 'good'];
    }
    $last = $series[$n - 1];
    $prev = $series[$n - 2];
    $denom = $prev != 0.0 ? abs($prev) : 1.0;
    $rel = ($last - $prev) / $denom;

    if (abs($rel) < 0.02) {
        return ['tag' => '→ stable', 'tone' => 'good'];
    }
    if ($rel > 0) {
        if ($goodDir === 'down')   { return ['tag' => '↑ rising',     'tone' => 'danger']; }
        if ($goodDir === 'stable') { return ['tag' => '↑ trending up', 'tone' => 'warn']; }
        return ['tag' => '↑ rising', 'tone' => 'good'];
    }
    if ($goodDir === 'down')   { return ['tag' => '↓ improving', 'tone' => 'good']; }
    if ($goodDir === 'stable') { return ['tag' => '↓ trending down', 'tone' => 'warn']; }
    return ['tag' => '↓ falling', 'tone' => 'danger'];
}

$panels = [];
$globalDates = [];
$patientName = '';

if ($activePid > 0) {
    $pdRow = sqlQuery(
        "SELECT fname, lname FROM patient_data WHERE pid = ?",
        [$activePid]
    );
    $patientName = trim((string)($pdRow['fname'] ?? '') . ' ' . (string)($pdRow['lname'] ?? ''));

    foreach ($panelDefs as $def) {
        $codes = $def['codes'];
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $params = array_merge([$activePid], $codes);
        $rs = sqlStatement(
            "SELECT pr.result, COALESCE(rep.date_collected, rep.date_report, pr.date) AS d
               FROM procedure_result pr
               JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
               JOIN procedure_order  po  ON po.procedure_order_id   = rep.procedure_order_id
              WHERE po.patient_id = ?
                AND pr.result_code IN ($placeholders)
                AND pr.result IS NOT NULL AND pr.result <> ''
              ORDER BY COALESCE(rep.date_collected, rep.date_report, pr.date) ASC,
                       pr.procedure_result_id ASC",
            $params
        );
        $series = [];
        $dates  = [];
        while ($r = sqlFetchArray($rs)) {
            $clean = preg_replace('/^[<>≤≥]+/', '', trim((string)$r['result']));
            if (!is_numeric($clean)) { continue; }
            $series[] = (float)$clean;
            $d = (string)$r['d'];
            $dates[] = $d;
            $globalDates[] = $d;
        }
        $latest = $series === [] ? null : $series[count($series) - 1];
        $trend  = cp_lab_overview_trend($series, $def['good_dir']);
        $panels[] = [
            'name'   => $def['name'],
            'unit'   => $def['unit'],
            'ref'    => $def['ref'],
            'tag'    => $trend['tag'],
            'tone'   => $trend['tone'],
            'latest' => $latest === null ? '—' : cp_lab_overview_format($latest),
            'series' => $series,
            'dates'  => $dates,
        ];
    }
}

// Build 9 evenly-spaced X-axis ticks from min/max date across all panels.
$xLabels = [];
if ($globalDates !== []) {
    $tsList = [];
    foreach ($globalDates as $d) {
        $t = strtotime($d);
        if ($t !== false) { $tsList[] = $t; }
    }
    if ($tsList !== []) {
        $tMin = min($tsList);
        $tMax = max($tsList);
        if ($tMax === $tMin) { $tMin -= 86400 * 30; $tMax += 86400 * 30; }
        for ($i = 0; $i < 9; $i++) {
            $tt = $tMin + (int)round(($tMax - $tMin) * ($i / 8));
            $xLabels[] = date('m/y', $tt);
        }
    }
}
if ($xLabels === []) {
    $xLabels = array_fill(0, 9, '—');
}

$overviewPayload = [
    'patientName' => $patientName,
    'panels'      => $panels,
    'xLabels'     => $xLabels,
];
$overviewJson = json_encode($overviewPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Lab Overview'); ?></title>
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
     data-page="lab_overview"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-overview="<?php echo attr($overviewJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Lab Overview UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
