<?php

/**
 * Encounter Detail — Figma "Screen 23 — Encounter Detail".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/encounter/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original DB-driven mock is preserved at copilot_encounter.php.bak so
 * a side-by-side screenshot diff remains possible.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../../main/copilot_helpers.php");

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
$entry        = $manifest['src/pages/encounter/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);
$activePid   = (int)($patientId !== '' ? $patientId : 0);

// ---------------------------------------------------------------------------
// Live encounter context. The Visit History "Open →" link passes ?eid=N;
// otherwise we pick the most recent open encounter (or most recent overall).
// ---------------------------------------------------------------------------

$encId = isset($_GET['eid']) && ctype_digit((string)$_GET['eid'])
    ? (int)$_GET['eid']
    : 0;

$enc = null;
if ($activePid > 0) {
    if ($encId > 0) {
        $enc = sqlQuery(
            "SELECT id, pid, date, reason, last_level_closed, last_level_billed,
                    provider_id, facility, encounter
               FROM form_encounter
              WHERE id = ? AND pid = ?",
            [$encId, $activePid]
        );
    }
    if (!$enc) {
        $enc = sqlQuery(
            "SELECT id, pid, date, reason, last_level_closed, last_level_billed,
                    provider_id, facility, encounter
               FROM form_encounter
              WHERE pid = ?
              ORDER BY (CASE WHEN COALESCE(last_level_closed, 0) = 0 THEN 0 ELSE 1 END),
                       date DESC
              LIMIT 1",
            [$activePid]
        );
    }
}

$encRow = null;
if (is_array($enc)) {
    $encRow = [
        'id'        => (int)($enc['id'] ?? 0),
        'encounter' => (int)($enc['encounter'] ?? 0),
        'date'      => date('M j, Y', strtotime((string)($enc['date'] ?? 'now')) ?: time()),
        'reason'    => (string)($enc['reason'] ?? ''),
        'closed'    => (int)($enc['last_level_closed'] ?? 0) > 0,
        'billed'    => (int)($enc['last_level_billed'] ?? 0) > 0,
        'facility'  => (string)($enc['facility'] ?? ''),
    ];
    $providerRow = sqlQuery(
        "SELECT username, fname, lname, title FROM users WHERE id = ?",
        [(int)($enc['provider_id'] ?? 0)]
    );
    $encRow['provider'] = cp_format_provider_name(is_array($providerRow) ? $providerRow : null);
}

// Most recent vitals row for this patient (independent of the encounter).
$vitalsRow = null;
if ($activePid > 0) {
    $v = sqlQuery(
        "SELECT bps, bpd, pulse, temperature, oxygen_saturation, weight, BMI, date
           FROM form_vitals
          WHERE pid = ?
          ORDER BY date DESC
          LIMIT 1",
        [$activePid]
    );
    if (is_array($v)) {
        $vitalsRow = [
            'bp'   => ($v['bps'] && $v['bpd']) ? (int)$v['bps'] . '/' . (int)$v['bpd'] : '',
            'hr'   => $v['pulse'] !== null && (float)$v['pulse'] > 0 ? (string)(int)(float)$v['pulse'] : '',
            'temp' => $v['temperature'] !== null && (float)$v['temperature'] > 0 ? number_format((float)$v['temperature'], 1) : '',
            'spo2' => $v['oxygen_saturation'] !== null && (float)$v['oxygen_saturation'] > 0 ? (string)(int)(float)$v['oxygen_saturation'] : '',
            'wt'   => $v['weight'] !== null && (float)$v['weight'] > 0 ? (string)(int)(float)$v['weight'] : '',
            'bmi'  => $v['BMI'] !== null && (float)$v['BMI'] > 0 ? number_format((float)$v['BMI'], 1) : '',
            'date' => $v['date'] ?? '',
        ];
    }
}

$encounterPayload = [
    'encounter' => $encRow,
    'vitals'    => $vitalsRow,
];
$encounterJson = json_encode($encounterPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Encounter'); ?></title>
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
     data-page="encounter"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-encounter="<?php echo attr($encounterJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Encounter UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
