<?php

/**
 * Patient Finder landing page — Figma "Screen 10 — Patient Finder".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/finder/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original DB-backed mock is preserved at copilot_finder.php.bak so a
 * side-by-side comparison remains possible. The React port uses static demo
 * data matching the Figma frame exactly; rewiring to a live patient query
 * is a follow-up.
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
$entry        = $manifest['src/pages/finder/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live patient roster — preserves the behavior of the pre-React PHP page
// (copilot_finder.php.bak). Same JOIN to users + scalar subqueries; React
// renders the result and handles search / filter / pagination client-side.
// ---------------------------------------------------------------------------

$roster_sql = "SELECT
    pd.pid, pd.pubpid, pd.fname, pd.lname, pd.DOB, pd.providerID,
    NULLIF(TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))), '') AS provider_name,
    (SELECT plan_name FROM insurance_data
       WHERE pid = pd.pid AND type='primary' ORDER BY date DESC LIMIT 1) AS insurance,
    (SELECT MAX(date) FROM form_encounter WHERE pid = pd.pid) AS last_visit,
    (SELECT title FROM lists
       WHERE pid = pd.pid AND type='allergy' AND activity=1 ORDER BY id LIMIT 1) AS top_allergy,
    (SELECT COUNT(*) FROM lists
       WHERE pid = pd.pid AND type='allergy' AND activity=1) AS allergy_count,
    (SELECT title FROM lists
       WHERE pid = pd.pid AND type='medical_problem' AND activity=1 ORDER BY id LIMIT 1) AS top_condition,
    (SELECT COUNT(*) FROM lists
       WHERE pid = pd.pid AND type='medical_problem' AND activity=1) AS condition_count,
    (pd.deceased_date IS NULL OR pd.deceased_date = '0000-00-00 00:00:00') AS is_active
  FROM patient_data pd
  LEFT JOIN users u ON u.id = pd.providerID
  ORDER BY pd.lname, pd.fname
  LIMIT 200";

$roster = [];
$result = sqlStatement($roster_sql);
while ($row = sqlFetchArray($result)) {
    $age = null;
    $dob_str = (string)($row['DOB'] ?? '');
    if ($dob_str !== '' && $dob_str !== '0000-00-00') {
        try {
            $dob = new DateTime($dob_str);
            $age = (int)$dob->diff(new DateTime('now'))->y;
        } catch (Throwable $_) {
            $age = null;
        }
    }
    $last_visit = (string)($row['last_visit'] ?? '');
    $today = date('Y-m-d');
    $last_visit_label = '';
    $is_today = false;
    if ($last_visit !== '' && $last_visit !== '0000-00-00 00:00:00') {
        $is_today = str_starts_with($last_visit, $today);
        try {
            $last_visit_label = (new DateTime($last_visit))->format('M j');
        } catch (Throwable $_) {
            $last_visit_label = $last_visit;
        }
    }

    $roster[] = [
        'pid'              => (int)$row['pid'],
        'mrn'              => '#' . str_pad((string)($row['pubpid'] ?? $row['pid']), 6, '0', STR_PAD_LEFT),
        'fname'            => (string)($row['fname'] ?? ''),
        'lname'            => (string)($row['lname'] ?? ''),
        'name'             => trim((string)($row['lname'] ?? '') . ', ' . (string)($row['fname'] ?? '')),
        'dob'              => $dob_str !== '' && $dob_str !== '0000-00-00' ? $dob_str : '',
        'age'              => $age,
        'providerId'       => (int)($row['providerID'] ?? 0),
        'providerName'     => (string)($row['provider_name'] ?? ''),
        'insurance'        => (string)($row['insurance'] ?? ''),
        'lastVisit'        => $last_visit_label,
        'isToday'          => $is_today,
        'topAllergy'       => (string)($row['top_allergy'] ?? ''),
        'allergyCount'     => (int)($row['allergy_count'] ?? 0),
        'topCondition'     => (string)($row['top_condition'] ?? ''),
        'conditionCount'   => (int)($row['condition_count'] ?? 0),
        'isActive'         => (bool)($row['is_active'] ?? true),
    ];
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Finder'); ?></title>
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
     data-page="finder"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-roster="<?php echo attr((string)json_encode($roster)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Patient Finder UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
