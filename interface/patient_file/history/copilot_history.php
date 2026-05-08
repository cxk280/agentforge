<?php

/**
 * Patient Visit History — Figma "Screen 12 — History".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/history/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * patient header2 banner, and patient-file navtab strip; this file only
 * renders inside the History tab content area.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * Live encounter rows for the active patient are queried server-side
 * (form_encounter LEFT JOIN users) and JSON-encoded onto data-visits, so
 * PIDs the user actually opens drive what the History UI shows. Mirrors the
 * SQL the pre-React PHP page (copilot_history.php.bak) ran. If pid is
 * empty/0 (no patient context) we ship empty arrays so the page renders
 * cleanly instead of crashing.
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
$entry        = $manifest['src/pages/history/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live visit history — preserves the SQL the pre-React PHP page
// (copilot_history.php.bak) ran. We pull recent encounters for the current
// pid (joined to users for the provider name) and group by year. The React
// component renders the cards client-side and handles filter chips locally.
// ---------------------------------------------------------------------------

$pid        = (int)$patientId;
$totalCount = 0;
$yearGroups = [];

if ($pid > 0) {
    $totalCount = (int)(sqlQuery(
        "SELECT COUNT(*) AS n FROM form_encounter WHERE pid = ?",
        [$pid]
    )['n'] ?? 0);

    $rows = sqlStatement(
        "SELECT fe.id, fe.date, fe.reason, fe.last_level_closed,
                u.username, u.fname, u.lname, u.title
         FROM form_encounter fe
         LEFT JOIN users u ON fe.provider_id = u.id
         WHERE fe.pid = ?
         ORDER BY fe.date DESC
         LIMIT 30",
        [$pid]
    );

    /** @var array<string, list<array<string, mixed>>> $byYear */
    $byYear = [];

    while ($r = sqlFetchArray($rows)) {
        $rawDate = (string)($r['date'] ?? '');
        $ts      = $rawDate !== '' ? strtotime($rawDate) : false;
        if ($ts === false) {
            $ts = time();
        }
        $year    = date('Y', $ts);
        $dateLbl = date('M j', $ts);
        $dayLbl  = date('l', $ts);
        $reason  = trim((string)($r['reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Office visit';
        }

        // Heuristic visit-type classification from reason text. Mirrors the
        // .bak. The React component picks rail styling off `rail`.
        $reasonL = strtolower($reason);
        if (str_contains($reasonL, 'annual')) {
            $type = 'Annual Physical';
            $rail = 'annual-physical';
        } elseif (
            str_contains($reasonL, 'lab')
            || str_contains($reasonL, 'a1c')
            || str_contains($reasonL, 'cholesterol')
        ) {
            $type = 'Lab Review';
            $rail = 'lab-review';
        } elseif (str_contains($reasonL, 'follow')) {
            $type = 'Follow-up';
            $rail = 'follow-up';
        } elseif (str_contains($reasonL, 'tele')) {
            $type = 'Telehealth';
            $rail = 'follow-up';
        } else {
            $type = 'Office Visit';
            $rail = 'follow-up';
        }

        $providerName = cp_format_provider_name([
            'username' => (string)($r['username'] ?? ''),
            'fname'    => (string)($r['fname'] ?? ''),
            'lname'    => (string)($r['lname'] ?? ''),
            'title'    => (string)($r['title'] ?? ''),
        ]);

        $closed = (int)($r['last_level_closed'] ?? 0) > 0;

        $byYear[$year][] = [
            'date'      => $dateLbl,
            'day'       => $dayLbl,
            'rail'      => $rail,
            'typeLabel' => $type,
            'provider'  => $providerName,
            'modality'  => str_contains($reasonL, 'tele') ? 'Tele' : '',
            'duration'  => '—',
            'status'    => $closed ? 'Signed' : 'Open',
            'title'     => $reason,
            'desc'      => '',
            'tags'      => [],
        ];
    }

    foreach ($byYear as $year => $visits) {
        $yearGroups[] = [
            'year'   => (string)$year,
            'visits' => $visits,
        ];
    }
}

$historyData = [
    'totalCount' => $totalCount,
    'years'      => $yearGroups,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('History'); ?></title>
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
     data-page="history"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-history="<?php echo attr((string)json_encode($historyData)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('History UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
