<?php

/**
 * Patient Issues — Figma "Screen 17 — Issues".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/issues/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * Live patient issues (medical_problem + allergy rows from the lists table)
 * are queried server-side and JSON-encoded onto data-issues, mirroring the
 * pattern used by the Finder page. The original DB-driven mock is preserved
 * at copilot_issues.php.bak so a side-by-side screenshot diff remains
 * possible.
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
$entry        = $manifest['src/pages/issues/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live issues — preserves the SQL the pre-React PHP page (copilot_issues.php
// .bak) ran against the lists table. We pull medical_problem + allergy rows
// for the current pid and let React render the grouped cards client-side.
// If pid is empty/0 (no patient context) we render an empty array so the
// page renders cleanly instead of crashing.
// ---------------------------------------------------------------------------

/**
 * Extract an ICD code from a diagnosis field. Mirrors cp_extract_icd from
 * the .bak file — supports the "ICD10:X##.#" / "ICD9:###" formats OpenEMR
 * stores in lists.diagnosis.
 */
function cp_issues_extract_icd(string $diagnosis): string
{
    if (preg_match('#ICD10:([A-Z][0-9.]+)#i', $diagnosis, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('#ICD9:([0-9.]+)#i', $diagnosis, $m)) {
        return $m[1];
    }
    return '—';
}

$pid = (int)$patientId;

$problems   = [];
$allergies  = [];

if ($pid > 0) {
    $problemRows = sqlStatement(
        "SELECT DISTINCT title, diagnosis, date FROM lists "
        . "WHERE pid = ? AND type = 'medical_problem' "
        . "AND COALESCE(enddate, '0000-00-00') = '0000-00-00' "
        . "ORDER BY date ASC",
        [$pid],
    );
    while ($r = sqlFetchArray($problemRows)) {
        $dateStr = (string)($r['date'] ?? '');
        $year    = $dateStr !== '' ? substr($dateStr, 0, 4) : '';
        $problems[] = [
            'icd'     => cp_issues_extract_icd((string)($r['diagnosis'] ?? '')),
            'title'   => (string)($r['title'] ?? ''),
            'sub'     => ($year !== '' ? "Onset $year" : 'Onset unknown') . ' • Active',
            'sev'     => 'ACTIVE',
            'sevTone' => 'warn',
        ];
    }

    $allergyRows = sqlStatement(
        "SELECT DISTINCT title, severity_al, comments, date FROM lists "
        . "WHERE pid = ? AND type = 'allergy' "
        . "AND COALESCE(enddate, '0000-00-00') = '0000-00-00' "
        . "ORDER BY date ASC",
        [$pid],
    );
    while ($r = sqlFetchArray($allergyRows)) {
        $dateStr  = (string)($r['date'] ?? '');
        $year     = $dateStr !== '' ? substr($dateStr, 0, 4) : '';
        $comments = (string)($r['comments'] ?? '');
        $sub      = ($year !== '' ? "Documented $year" : 'Documented')
                  . ($comments !== '' ? ' • ' . $comments : '');
        $sevRaw   = strtoupper((string)($r['severity_al'] ?? ''));
        $allergies[] = [
            'icd'     => 'Z88',
            'title'   => 'Allergy to ' . (string)($r['title'] ?? ''),
            'sub'     => $sub,
            'sev'     => $sevRaw !== '' ? $sevRaw : 'MILD',
            'sevTone' => 'good',
        ];
    }
}

$issues = [
    'problems'  => $problems,
    'allergies' => $allergies,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Issues'); ?></title>
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
     data-page="issues"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-issues="<?php echo attr((string)json_encode($issues)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Issues UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
