<?php

/**
 * Patient Education landing page — Figma "Screen 48 — Patient Education".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/education/. The PHP outer shell at
 * /interface/patient_file/summary/demographics.php (and its header2) still
 * owns the navy top nav, demographics banner and patient navtabs; this
 * file only renders inside the patient-tabs body iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * Live patient context (name + active medical_problem rows + most-recent
 * A1C) is queried server-side and JSON-encoded onto data-education so the
 * "Suggested for …" banner reflects the real chart, not a hardcoded demo.
 * The catalog of education handouts itself stays in the React component:
 * OpenEMR has no patient_education_catalog table (the document_templates
 * table is for fillable forms, not handouts), so it is a documented
 * TODO(real-data) stub per the External Data precedent.
 *
 * The original static-HTML mock is preserved at copilot_education.php.bak
 * so a side-by-side screenshot diff remains possible.
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
$entry        = $manifest['src/pages/education/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live patient context — preserves the queries the pre-React PHP page
// (copilot_education.php.bak) ran against patient_data, lists, and the
// procedure_result join chain. The React component renders the suggestion
// banner from this payload; the catalog itself remains client-side.
//
// If pid is empty/0 (no patient context), we ship an empty payload — React
// renders the empty-state message, no demo fallback.
// ---------------------------------------------------------------------------

$pid = (int)$patientId;

$patientName     = '';
$problems        = [];
$a1cValue        = null;     // float|null
$a1cDate         = '';       // YYYY-MM-DD when present

if ($pid > 0) {
    $pat = sqlQuery(
        "SELECT pid, fname, lname FROM patient_data WHERE pid = ?",
        [$pid],
    );
    if ($pat) {
        $patientName = trim(((string)($pat['fname'] ?? '')) . ' ' . ((string)($pat['lname'] ?? '')));
    }

    // Active medical problems (deduplicated by title — `lists` has dup rows
    // per migration in the seed DB).
    $problemRows = sqlStatement(
        "SELECT DISTINCT title, diagnosis FROM lists "
        . "WHERE pid = ? AND type = 'medical_problem' "
        . "AND COALESCE(outcome, 0) != 1 "
        . "AND COALESCE(enddate, '0000-00-00') = '0000-00-00' "
        . "ORDER BY date ASC",
        [$pid],
    );
    while ($r = sqlFetchArray($problemRows)) {
        $problems[] = [
            'title'     => (string)($r['title'] ?? ''),
            'diagnosis' => (string)($r['diagnosis'] ?? ''),
        ];
    }

    // Most-recent A1C / HbA1c from procedure_result, if any.
    $a1cRow = sqlQuery(
        "SELECT pr.result, pr.units, pr.date "
        . "FROM procedure_result pr "
        . "JOIN procedure_report rpt ON rpt.procedure_report_id = pr.procedure_report_id "
        . "JOIN procedure_order po ON po.procedure_order_id = rpt.procedure_order_id "
        . "WHERE po.patient_id = ? "
        . "AND (LOWER(pr.result_text) LIKE '%a1c%' OR LOWER(pr.result_text) LIKE '%hba1c%' "
        . "     OR pr.result_code IN ('4548-4','17856-6')) "
        . "ORDER BY pr.date DESC LIMIT 1",
        [$pid],
    );
    if ($a1cRow !== false && $a1cRow !== null && is_array($a1cRow) && isset($a1cRow['result']) && is_numeric($a1cRow['result'])) {
        $a1cValue = (float)$a1cRow['result'];
        $a1cDate  = (string)($a1cRow['date'] ?? '');
    }
}

$education = [
    'patientName' => $patientName,
    'problems'    => $problems,
    'a1c'         => $a1cValue !== null
        ? ['value' => $a1cValue, 'date' => $a1cDate]
        : null,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Education'); ?></title>
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
  #cp-root { min-height: 100%; display: flex; flex-direction: column; }
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
     data-page="education"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-education="<?php echo attr((string)json_encode($education)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Patient Education UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
