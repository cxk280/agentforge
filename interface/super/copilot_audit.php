<?php

/**
 * Audit Log landing page — Figma "Screen 55 — Audit Log".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/audit/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav and patient
 * header2 banner; this file only renders inside the #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original static-HTML mock is preserved at copilot_audit.php.bak so a
 * side-by-side screenshot diff remains possible.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

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
$entry        = $manifest['src/pages/audit/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live audit data from the `log` table. Page is admin-scope (no pid filter).
// We surface the most recent 50 events; filtering / paging / CSV stay
// demo-mode until a follow-up wires the GET params end-to-end.
// ---------------------------------------------------------------------------

$auditRows = [];
$total7d   = 0;

$total7d = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM log WHERE date > NOW() - INTERVAL 7 DAY"
)['c'] ?? 0);

$rs = sqlStatement(
    "SELECT id, date, event, user, patient_id, success, log_from, comments
       FROM log
      ORDER BY date DESC, id DESC
      LIMIT 50"
);

while ($r = sqlFetchArray($rs)) {
    $eventName = (string)($r['event'] ?? '');
    $success   = (int)($r['success'] ?? 1);
    // Coarse risk tone (mirrors the .bak heuristic).
    $tone = 'good';
    if ($success === 0) {
        $tone = 'danger';
    } elseif (in_array($eventName, ['delete_patient', 'security-access-denied', 'sign_epcs'], true)) {
        $tone = 'danger';
    } elseif (in_array($eventName, [
        'login', 'logout', 'security-administration-update',
        'security-administration-insert', 'export', 'print',
    ], true)) {
        $tone = 'warn';
    }

    $patientId = (int)($r['patient_id'] ?? 0);
    $logFrom   = (string)($r['log_from'] ?? '');
    $target    = $patientId > 0
        ? "Patient #{$patientId}"
        : ($logFrom !== '' ? $logFrom : '—');

    $ts = strtotime((string)($r['date'] ?? '')) ?: time();
    $auditRows[] = [
        'id'         => (int)$r['id'],
        'ts'         => date('m/d H:i:s', $ts),
        'user'       => (string)($r['user'] ?? '—'),
        'event'      => $eventName,
        'target'     => $target,
        'patient_id' => $patientId,
        'success'    => $success === 1,
        'tone'       => $tone,
    ];
}

$auditPayload = [
    'rows'    => $auditRows,
    'total7d' => $total7d,
];
$auditJson = json_encode($auditPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Audit Log'); ?></title>
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
  #cp-root {
    height: 100%;
    display: flex;
    flex-direction: column;
  }
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
     data-page="audit"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-audit="<?php echo attr($auditJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Audit Log UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
