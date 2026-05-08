<?php

/**
 * Patient List Report — Figma "Screen 43 — Patient List Report".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/patient_list/. The PHP outer shell
 * (navy top nav, left sidebar, patient header2 banner) is owned elsewhere;
 * this file only renders inside the #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original DB-backed implementation is preserved at
 * copilot_patient_list.php.bak so a side-by-side screenshot diff and
 * data-shape reference remain available.
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
$entry        = $manifest['src/pages/patient_list/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live patient cohort. Joins patient_data -> insurance_data (primary) ->
// insurance_companies, plus subqueries for last-visit (form_encounter) and
// primary diagnosis (lists.activity=1, type=medical_problem). HbA1c not
// seeded — column shows "—". 7 filter dropdowns stay demo-mode.
// ---------------------------------------------------------------------------

function cp_pl_age_from_dob(string $dob): int
{
    if ($dob === '' || $dob === '0000-00-00') return 0;
    $ts = strtotime($dob);
    if ($ts === false) return 0;
    return (int)floor((time() - $ts) / 86400 / 365.25);
}

$rows = [];
$total = 0;

$total = (int)(sqlQuery("SELECT COUNT(*) AS c FROM patient_data")['c'] ?? 0);

$rs = sqlStatement(
    "SELECT pd.pid, pd.fname, pd.lname, pd.DOB, pd.sex, pd.pubpid, pd.providerID,
            u.fname AS uf, u.lname AS ul, u.title AS ut, u.username AS uu,
            ic.name AS insurance_name,
            (SELECT MAX(date) FROM form_encounter fe WHERE fe.pid = pd.pid) AS last_visit,
            (SELECT diagnosis FROM lists l
              WHERE l.pid = pd.pid AND l.type = 'medical_problem' AND l.activity = 1
              ORDER BY l.id ASC LIMIT 1) AS dx
       FROM patient_data pd
       LEFT JOIN insurance_data id_pri
              ON id_pri.pid = pd.pid AND id_pri.type = 'primary'
       LEFT JOIN insurance_companies ic ON ic.id = id_pri.provider
       LEFT JOIN users u ON u.id = pd.providerID
      ORDER BY pd.lname, pd.fname
      LIMIT 50"
);
while ($r = sqlFetchArray($rs)) {
    $dob = (string)($r['DOB'] ?? '');
    $age = cp_pl_age_from_dob($dob);
    $sexRaw = strtolower(substr((string)($r['sex'] ?? ''), 0, 1));
    $sex = $sexRaw === 'f' ? 'F' : 'M';

    $lastVisitTs = strtotime((string)($r['last_visit'] ?? '')) ?: null;
    $lastVisit   = $lastVisitTs !== null ? date('m/d/Y', $lastVisitTs) : '—';

    $dxRaw = (string)($r['dx'] ?? '');
    $dx = $dxRaw !== '' ? str_replace('ICD10:', '', $dxRaw) : '—';

    $rows[] = [
        'name'      => trim((string)($r['fname'] ?? '') . ' ' . (string)($r['lname'] ?? '')),
        'mrn'       => '#' . str_pad((string)((int)($r['pid'] ?? 0)), 6, '0', STR_PAD_LEFT),
        'dob'       => $dob !== '' && $dob !== '0000-00-00' ? date('m/d/Y', strtotime($dob) ?: time()) : '—',
        'age'       => $age,
        'sex'       => $sex,
        'lastVisit' => $lastVisit,
        'provider'  => cp_format_provider_name([
            'username' => $r['uu'] ?? '', 'fname' => $r['uf'] ?? '',
            'lname'    => $r['ul'] ?? '', 'title' => $r['ut'] ?? '',
        ]),
        'insurance' => (string)($r['insurance_name'] ?? '') !== '' ? (string)$r['insurance_name'] : '—',
        'dx'        => $dx,
        'hba1c'     => 0,
        'tone'      => 'normal',
    ];
}

$patientListPayload = [
    'rows'  => $rows,
    'total' => $total,
];
$patientListJson = json_encode($patientListPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient List Report'); ?></title>
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
     data-page="patient_list"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-patient-list="<?php echo attr($patientListJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Patient List UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
