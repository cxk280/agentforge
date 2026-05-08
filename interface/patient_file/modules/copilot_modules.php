<?php

/**
 * Patient Modules landing page — Figma "Screen 21 — Patient Modules".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/patient_modules/. The PHP outer
 * shell at /interface/main/tabs/main.php still owns the navy top nav, left
 * sidebar, and patient header2 banner; this file only renders inside the
 * patient-file iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original static-HTML mock is preserved at copilot_modules.php.bak
 * so a side-by-side screenshot diff remains possible.
 *
 * Note: page slug is "patient_modules" (not "modules") to avoid colliding
 * with the admin Module Installer wrapper at copilot_module_installer.php.
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
$entry        = $manifest['src/pages/patient_modules/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Active + recommended modules for this patient.
//
// TODO(real-data): hardcoded stubs lifted verbatim from copilot_modules.php.bak.
// The pre-React .bak page also shipped these as a static mock — there has
// never been a "patient_modules" / "module_recommendations" schema in this
// build (the OpenEMR `modules` table tracks installed modules globally, not
// patient-context activations or per-patient recommendations). The arrays
// below are kept verbatim from the .bak so the React port renders 1:1 with
// the Figma frame. When a real per-patient module-activation schema exists
// (e.g. `patient_module_activations` + `module_recommendations`, or service-
// layer adapters joining `modules` to clinical context), replace the
// $active_clinical / $available literals with SQL pulls scoped to the current
// patient — the data-attribute → prop pipeline below already takes care of
// the React side.
//
// Shape below matches the React props in
// /frontend/src/pages/patient_modules/PatientModules.tsx.
// ---------------------------------------------------------------------------

$active_clinical = [
    [
        'name'        => 'Care Coordination',
        'icon'        => '🤝',
        'iconTone'    => 'teal',
        'version'     => 'v2.4.1',
        'vendor'      => 'OpenEMR Foundation',
        'description' => 'Care plan, care team roster, transitions of care. Direct messaging integrated.',
        'status'      => 'ACTIVE',
        'statusTone'  => 'good',
        'action'      => 'Open →',
        'actionTone'  => 'primary',
    ],
    [
        'name'        => 'Clinical Decision Rules',
        'icon'        => '✨',
        'iconTone'    => 'info',
        'version'     => 'v1.9.3',
        'vendor'      => 'OpenEMR Foundation',
        'description' => 'CQM rules engine: drives reminders, alerts, and quality measure calculation.',
        'status'      => 'ACTIVE',
        'statusTone'  => 'good',
        'action'      => 'Open →',
        'actionTone'  => 'primary',
    ],
    [
        'name'        => 'EasiPRO',
        'icon'        => '📊',
        'iconTone'    => 'violet',
        'version'     => 'v3.1.0',
        'vendor'      => 'Northwestern',
        'description' => 'Patient-Reported Outcome instruments delivered through the Patient Portal.',
        'status'      => 'ACTIVE',
        'statusTone'  => 'good',
        'action'      => 'Open →',
        'actionTone'  => 'primary',
    ],
    [
        'name'        => 'ClinicalTables FHIR',
        'icon'        => '🔗',
        'iconTone'    => 'mint',
        'version'     => 'v0.7.2',
        'vendor'      => 'NLM',
        'description' => 'Code-set lookups for ICD-10, SNOMED, RxNorm via the FHIR ValueSet API.',
        'status'      => 'UPDATE AVAILABLE',
        'statusTone'  => 'warn',
        'action'      => 'Update',
        'actionTone'  => 'warn',
    ],
];

$available = [
    [
        'name'        => 'Diabetes Coach',
        'icon'        => '🩸',
        'iconTone'    => 'warn',
        'version'     => 'v1.2.0',
        'vendor'      => 'RiversideHealth',
        'description' => 'Glucose log integration, A1C trending, and Co-Pilot diabetes-focused prompts.',
        'status'      => 'AVAILABLE',
        'statusTone'  => 'neutral',
        'action'      => 'Install',
        'actionTone'  => 'secondary',
    ],
    [
        'name'        => 'Care Plan Templates',
        'icon'        => '📋',
        'iconTone'    => 'info',
        'version'     => 'v0.9.1',
        'vendor'      => 'OpenEMR Foundation',
        'description' => 'Condition-specific care plan templates with order sets and patient education.',
        'status'      => 'AVAILABLE',
        'statusTone'  => 'neutral',
        'action'      => 'Install',
        'actionTone'  => 'secondary',
    ],
    [
        'name'        => 'Pharmacy Sync',
        'icon'        => '💊',
        'iconTone'    => 'pink',
        'version'     => 'v2.0.1',
        'vendor'      => 'Surescripts',
        'description' => 'Two-way sync of medication history, including external prescriptions.',
        'status'      => 'AVAILABLE',
        'statusTone'  => 'neutral',
        'action'      => 'Install',
        'actionTone'  => 'secondary',
    ],
    [
        'name'        => 'Telehealth Studio',
        'icon'        => '📹',
        'iconTone'    => 'violet',
        'version'     => 'v4.2.0',
        'vendor'      => 'OpenEMR Foundation',
        'description' => 'Embedded video visits with screen-share, captioning, and visit recording.',
        'status'      => 'AVAILABLE',
        'statusTone'  => 'neutral',
        'action'      => 'Install',
        'actionTone'  => 'secondary',
    ],
];

// Header summary line: "N active, M available". Computed from the arrays
// above so the meta line stays consistent with the rendered cards.
$activeCount    = count($active_clinical);
$availableCount = count($available);
$summary = [
    'active'    => $activeCount,
    'available' => $availableCount,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Modules'); ?></title>
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
     data-page="patient_modules"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-active-clinical="<?php echo attr((string)json_encode($active_clinical)); ?>"
     data-available="<?php echo attr((string)json_encode($available)); ?>"
     data-summary="<?php echo attr((string)json_encode($summary)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Modules UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
