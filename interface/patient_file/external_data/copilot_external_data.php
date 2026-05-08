<?php

/**
 * External Data — Figma "Screen 19 — External Data".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/external_data/. The PHP outer shell
 * (navy top nav + patient demographics banner) is rendered by the
 * surrounding OpenEMR patient-context frame; this file only renders inside
 * the chart iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original static-HTML mock is preserved at copilot_external_data.php.bak
 * so a side-by-side screenshot diff remains possible.
 *
 * TODO(real-data): The "External Data" page is a Figma demo of HIE / lab
 * network / imaging API / Surescripts integrations. None of those external
 * networks are wired in this build (no CommonWell client, no LabCorp Direct
 * Connect, no Surescripts Rx History, no Riverside Imaging API), and the
 * pre-React .bak page also shipped these as a hardcoded mock — there has
 * never been a backing DB table for "external data sources" in this repo.
 * The arrays below are kept verbatim from the .bak so the React port renders
 * 1:1 with the Figma frame. When a real CCDA/lab-network ingestion exists
 * (e.g. an `external_data_sources` + `external_data_imports` schema, or
 * service-layer adapters), replace the $sources / $imports literals with
 * SQL pulls scoped to the current patient — the data-attribute → prop
 * pipeline below already takes care of the React side.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");

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

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/external_data/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// External-data sources + recent imports.
//
// TODO(real-data): hardcoded stubs lifted verbatim from copilot_external_data.php.bak.
// No DB table backs these in this build; replace with SQL once an
// external_data_sources / external_data_imports schema exists. Shape below
// matches the React props in /frontend/src/pages/external_data/ExternalData.tsx.
// ---------------------------------------------------------------------------

$sources = [
    [
        'name'       => 'Texas HIE — CommonWell',
        'icon'       => '🌐',
        'tone'       => 'good',
        'status'     => 'Connected',
        'statusTone' => 'good',
        'sub'        => 'Today 08:14 AM',
        'count'      => 24,
    ],
    [
        'name'       => 'LabCorp Direct Connect',
        'icon'       => '🧪',
        'tone'       => 'info',
        'status'     => 'Connected',
        'statusTone' => 'good',
        'sub'        => 'Today 08:14 AM',
        'count'      => 18,
    ],
    [
        'name'       => 'Riverside Imaging API',
        'icon'       => '🩻',
        'tone'       => 'violet',
        'status'     => 'Connected',
        'statusTone' => 'good',
        'sub'        => 'Yesterday',
        'count'      => 5,
    ],
    [
        'name'       => 'Surescripts Rx History',
        'icon'       => '💊',
        'tone'       => 'warn',
        'status'     => 'Needs review',
        'statusTone' => 'warn',
        'sub'        => '1 record awaiting reconcile',
        'count'      => 1,
    ],
];

$imports = [
    [
        'icon'          => '🌐',
        'tone'          => 'good',
        'title'         => 'ED Visit — Riverside General Hospital',
        'type'          => 'Encounter Summary',
        'src'           => 'Texas HIE',
        'date'          => 'Apr 9, 2026',
        'fields'        => 12,
        'status'        => 'Reconciled',
        'statusTone'    => 'good',
        'action'        => 'View',
        'actionVariant' => 'secondary',
    ],
    [
        'icon'          => '🧪',
        'tone'          => 'info',
        'title'         => 'Comprehensive Metabolic Panel + CBC',
        'type'          => 'Lab Result',
        'src'           => 'LabCorp Direct',
        'date'          => 'Apr 12, 2026',
        'fields'        => 18,
        'status'        => 'Reconciled',
        'statusTone'    => 'good',
        'action'        => 'View',
        'actionVariant' => 'secondary',
    ],
    [
        'icon'          => '💊',
        'tone'          => 'warn',
        'title'         => 'External Rx: Atorvastatin 20mg → 40mg (Walgreens)',
        'type'          => 'Medication History',
        'src'           => 'Surescripts',
        'date'          => 'Apr 8, 2026',
        'fields'        => 1,
        'status'        => 'Needs review',
        'statusTone'    => 'warn',
        'action'        => 'Reconcile',
        'actionVariant' => 'primary',
    ],
    [
        'icon'          => '🌐',
        'tone'          => 'good',
        'title'         => 'DEXA scan — South Austin Imaging',
        'type'          => 'Imaging Report',
        'src'           => 'Texas HIE',
        'date'          => 'Mar 22, 2026',
        'fields'        => 4,
        'status'        => 'Reconciled',
        'statusTone'    => 'good',
        'action'        => 'View',
        'actionVariant' => 'secondary',
    ],
    [
        'icon'          => '🩻',
        'tone'          => 'violet',
        'title'         => 'Bilateral knee X-Ray report',
        'type'          => 'Radiology',
        'src'           => 'Riverside Imaging',
        'date'          => 'Feb 18, 2026',
        'fields'        => 6,
        'status'        => 'Reconciled',
        'statusTone'    => 'good',
        'action'        => 'View',
        'actionVariant' => 'secondary',
    ],
    [
        'icon'          => '🌐',
        'tone'          => 'good',
        'title'         => 'Influenza vaccine — Riverside Pharmacy',
        'type'          => 'Immunization',
        'src'           => 'Texas HIE',
        'date'          => 'Oct 15, 2025',
        'fields'        => 3,
        'status'        => 'Reconciled',
        'statusTone'    => 'good',
        'action'        => 'View',
        'actionVariant' => 'secondary',
    ],
];

// Header summary line: "N connected • M pending review". Computed from the
// arrays above so the meta line stays consistent with the source cards.
$connectedCount = 0;
$pendingCount   = 0;
foreach ($sources as $s) {
    if (($s['statusTone'] ?? '') === 'good') {
        $connectedCount++;
    } elseif (($s['statusTone'] ?? '') === 'warn') {
        $pendingCount++;
    }
}
$summary = [
    'connected' => $connectedCount,
    'pending'   => $pendingCount,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('External Data'); ?></title>
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
     data-page="external_data"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-sources="<?php echo attr((string)json_encode($sources)); ?>"
     data-imports="<?php echo attr((string)json_encode($imports)); ?>"
     data-summary="<?php echo attr((string)json_encode($summary)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('External Data UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
