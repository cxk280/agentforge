<?php

/**
 * Patient Assessments — Figma "Screen 13 — Assessments".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/assessments/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the navtab.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * TODO(real-data): The "Assessments" page is a Figma demo of standardized
 * screening instruments (PHQ-9, GAD-7, AUDIT-C, PRAPARE, DDS-17, Stop-BANG,
 * Barthel Index). None of those instruments have a backing schema in this
 * build — the lists table's "assessment" issue subtype stores free-form
 * clinical notes, not scored screening instrument administrations, and the
 * pre-React .bak page (preserved at copilot_assessments.php.bak) shipped
 * these as a hardcoded array too. The arrays below are kept verbatim from
 * the .bak so the React port renders 1:1 with the Figma frame. When a real
 * questionnaire/assessment ingestion exists (e.g. a `patient_assessments`
 * + `assessment_responses` schema, or the form_questionnaire_assessments
 * pipeline wired up end-to-end), replace the $categories / $assessments
 * literals with SQL pulls scoped to the current patient — the
 * data-attribute → prop pipeline below already takes care of the React side.
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
$entry        = $manifest['src/pages/assessments/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Assessments payload.
//
// TODO(real-data): hardcoded stubs lifted verbatim from copilot_assessments.php.bak.
// No DB table backs scored screening-instrument administrations in this
// build; replace with SQL once a `patient_assessments` schema exists (or the
// form_questionnaire_assessments pipeline is wired up). Patient-context
// guard: if pid is empty/0 we still emit the same demo arrays so the page
// continues to render the Figma frame — when real-data lands, scope the
// queries to $pid > 0 and emit empty arrays otherwise.
// Shape below matches the React props in /frontend/src/pages/assessments/Assessments.tsx.
// ---------------------------------------------------------------------------

$pid = (int)$patientId;

// status: due | done | scheduled
$assessments = [
    [
        'status' => 'due',
        'title'  => 'PHQ-9 — Patient Health Questionnaire',
        'cat'    => 'Behavioral Health',
        'desc'   => '9-item depression screening instrument',
        'meta'   => 'Last taken 90 days ago',
    ],
    [
        'status' => 'due',
        'title'  => 'GAD-7 — Generalized Anxiety Disorder',
        'cat'    => 'Behavioral Health',
        'desc'   => '7-item anxiety screening',
        'meta'   => 'Never administered',
    ],
    [
        'status' => 'due',
        'title'  => 'AUDIT-C — Alcohol Use Disorders',
        'cat'    => 'Behavioral Health',
        'desc'   => '3-item alcohol use screening',
        'meta'   => 'Last taken 12 months ago',
    ],
    [
        'status' => 'due',
        'title'  => 'SDOH Assessment — PRAPARE',
        'cat'    => 'Social Determinants',
        'desc'   => '21 questions covering housing, food, transportation, employment',
        'meta'   => 'Never administered',
    ],
    [
        'status'      => 'done',
        'title'       => 'Diabetes Distress Scale (DDS-17)',
        'cat'         => 'Behavioral Health',
        'desc'        => 'Screens for emotional distress related to diabetes management',
        'meta'        => 'Score 32 — moderate distress • 02/18/2026',
        'scoreLabel'  => 'SCORE',
        'scoreValue'  => '32 / 102',
    ],
    [
        'status'      => 'done',
        'title'       => 'Falls Risk Assessment (Stop-BANG)',
        'cat'         => 'Risk Screening',
        'desc'        => 'Identifies fall risk in older adults',
        'meta'        => 'Low risk • 02/18/2026',
        'scoreLabel'  => 'SCORE',
        'scoreValue'  => 'Low',
    ],
    [
        'status' => 'scheduled',
        'title'  => 'Functional Status (Barthel Index)',
        'cat'    => 'Functional',
        'desc'   => 'Activities of Daily Living assessment',
        'meta'   => 'Sent to patient portal • Due 11/20',
    ],
];

$categories = [
    ['label' => 'All',                 'count' => 12, 'active' => true],
    ['label' => 'Due Now',              'count' => 4,  'active' => false],
    ['label' => 'Behavioral Health',    'count' => 6,  'active' => false],
    ['label' => 'Social Determinants',  'count' => 2,  'active' => false],
    ['label' => 'Functional',           'count' => 3,  'active' => false],
    ['label' => 'Risk Screening',       'count' => 1,  'active' => false],
    ['label' => 'Wellness',             'count' => 0,  'active' => false],
];

// Header summary line: "N due, M completed". Computed from the array above
// so the meta line stays consistent with the cards rendered.
$dueCount  = 0;
$doneCount = 0;
foreach ($assessments as $a) {
    if (($a['status'] ?? '') === 'due') {
        $dueCount++;
    } elseif (($a['status'] ?? '') === 'done') {
        $doneCount++;
    }
}
$summary = [
    'due'       => $dueCount,
    // The Figma copy says "8 completed" even though the demo shows 2 done
    // cards; preserve the .bak's verbatim "4 due, 8 completed" line by
    // padding the historical-completed total beyond what's currently shown.
    'completed' => max(8, $doneCount),
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Assessments'); ?></title>
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
     data-page="assessments"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-categories="<?php echo attr((string)json_encode($categories)); ?>"
     data-assessments="<?php echo attr((string)json_encode($assessments)); ?>"
     data-summary="<?php echo attr((string)json_encode($summary)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Assessments UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
