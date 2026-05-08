<?php

/**
 * Visit History — Figma "Screen 30 — Visit History".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/visit_history/. The PHP outer
 * shell at /interface/main/tabs/main.php still owns the navy top nav,
 * left sidebar, patient header2 banner, and patient-file navtab strip;
 * this file only renders inside the encounter-tab content area.
 *
 * Boot context (CSRF token, current user id, current patient id, API
 * base) is passed to React via data-* attributes on the #cp-root mount
 * node and parsed in TS by readBootContext() — no global
 * window.__INITIAL_STATE__.
 *
 * The pre-React PHP mock is preserved at copilot_visit_history.php.bak
 * so a side-by-side screenshot diff remains possible.
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
$entry        = $manifest['src/pages/visit_history/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);
$activePid   = (int)($patientId !== '' ? $patientId : 0);

// ---------------------------------------------------------------------------
// Live encounter rows for this patient (newest first), with provider name
// (joined off `users`) and visit type label (joined off
// `openemr_postcalendar_categories`). All filtering, search and pagination
// is done client-side in React from this payload.
// ---------------------------------------------------------------------------

$rows = [];
$providerOpts = [];
$visitTypeOpts = [];
$totalAll = 0;

if ($activePid > 0) {
    $totalAll = (int)(sqlQuery(
        "SELECT COUNT(*) AS c FROM form_encounter WHERE pid = ?",
        [$activePid]
    )['c'] ?? 0);

    $rs = sqlStatement(
        "SELECT fe.id, fe.encounter, fe.date, fe.reason, fe.pc_catid,
                COALESCE(c.pc_catname, 'Office Visit') AS pc_catname,
                COALESCE(c.pc_duration, 0)             AS pc_duration,
                fe.provider_id, fe.last_level_closed, fe.last_level_billed,
                u.username, u.fname, u.lname, u.title
           FROM form_encounter fe
           LEFT JOIN users u ON u.id = fe.provider_id
           LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = fe.pc_catid
          WHERE fe.pid = ?
          ORDER BY fe.date DESC",
        [$activePid]
    );
    while ($r = sqlFetchArray($rs)) {
        $ts          = strtotime((string)$r['date']) ?: time();
        $type        = (string)($r['pc_catname'] ?? 'Office Visit');
        if ($type === '' || strcasecmp($type, 'No Show') === 0) {
            $type = 'Office Visit';
        }
        $provider    = cp_format_provider_name([
            'username' => $r['username'] ?? '',
            'fname'    => $r['fname']    ?? '',
            'lname'    => $r['lname']    ?? '',
            'title'    => $r['title']    ?? '',
        ]);
        $secs        = (int)($r['pc_duration'] ?? 0);
        $duration    = $secs > 0 ? (int)round($secs / 60) . ' min' : '30 min';
        $closed      = (int)($r['last_level_closed'] ?? 0);
        $billed      = (int)($r['last_level_billed'] ?? 0);

        if ($closed > 0) {
            $statusKey = 'signed';
        } elseif ($billed > 0) {
            $statusKey = 'billed';
        } else {
            $statusKey = 'in_progress';
        }

        $rows[] = [
            'id'         => (int)$r['id'],
            'encounter'  => (int)($r['encounter'] ?? 0),
            'date'       => date('m/d/Y', $ts),
            'time'       => date('g:i A', $ts),
            'type'       => $type,
            'typeId'     => (int)($r['pc_catid'] ?? 0),
            'provider'   => $provider,
            'providerId' => (int)($r['provider_id'] ?? 0),
            'reason'     => (string)($r['reason'] ?? ''),
            'duration'   => $duration,
            'status'     => $statusKey,
            'billed'     => $billed > 0,
        ];
    }

    // Visit-type options actually used by this patient.
    $rsTypes = sqlStatement(
        "SELECT DISTINCT c.pc_catid, COALESCE(c.pc_catname, 'Office Visit') AS pc_catname
           FROM form_encounter fe
           LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = fe.pc_catid
          WHERE fe.pid = ?
          ORDER BY pc_catname",
        [$activePid]
    );
    while ($t = sqlFetchArray($rsTypes)) {
        $visitTypeOpts[] = [
            'id'   => (int)$t['pc_catid'],
            'name' => (string)($t['pc_catname'] ?? 'Office Visit'),
        ];
    }

    // Provider options actually used by this patient.
    $rsProvs = sqlStatement(
        "SELECT DISTINCT u.id, u.username, u.fname, u.lname, u.title
           FROM form_encounter fe
           LEFT JOIN users u ON u.id = fe.provider_id
          WHERE fe.pid = ? AND u.id IS NOT NULL
          ORDER BY u.lname, u.fname",
        [$activePid]
    );
    while ($p = sqlFetchArray($rsProvs)) {
        $providerOpts[] = [
            'id'   => (int)$p['id'],
            'name' => cp_format_provider_name($p),
        ];
    }
}

$visitHistoryPayload = [
    'rows'          => $rows,
    'totalAll'      => $totalAll,
    'visitTypeOpts' => $visitTypeOpts,
    'providerOpts'  => $providerOpts,
];
$visitHistoryJson = json_encode($visitHistoryPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Visit History'); ?></title>
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
     data-page="visit_history"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-history="<?php echo attr($visitHistoryJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Visit History UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
