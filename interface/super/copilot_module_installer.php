<?php

/**
 * Module Installer landing page — Figma "Screen 57 — Module Installer".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/module_installer/. The PHP outer
 * shell at /interface/main/tabs/main.php still owns the navy top nav,
 * left sidebar, and patient header2 banner; this file only renders inside
 * the #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original static-HTML mock is preserved at
 * copilot_module_installer.php.bak so a side-by-side screenshot diff
 * remains possible.
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

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/module_installer/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live modules — admin scope, from the `modules` table.
// ---------------------------------------------------------------------------

$installedModules = [];
$rs = sqlStatement(
    "SELECT mod_id, mod_name, mod_active, mod_directory, mod_type
       FROM modules
      ORDER BY mod_active DESC, mod_name"
);
while ($r = sqlFetchArray($rs)) {
    $name = (string)($r['mod_name'] ?? '');
    if ($name === '') { continue; }
    $type = strtolower((string)($r['mod_type'] ?? ''));
    if ($type === '') {
        // Lazy categorization based on the module name.
        $lower = strtolower($name);
        if (str_contains($lower, 'doc') || str_contains($lower, 'ccr')) {
            $cat = 'Clinical';
        } elseif (str_contains($lower, 'immun') || str_contains($lower, 'syndrom') || str_contains($lower, 'care')) {
            $cat = 'Clinical';
        } else {
            $cat = 'Integration';
        }
    } else {
        $cat = ucfirst($type);
    }

    $installedModules[] = [
        'id'          => 'mod-' . (int)($r['mod_id'] ?? 0),
        'icon'        => "\u{2728}",
        'iconBg'      => '#E6F5F5',
        'name'        => $name,
        'version'     => 'v1.0.0',
        'category'    => $cat,
        'description' => trim((string)($r['mod_directory'] ?? '')) !== ''
            ? 'Directory: ' . (string)$r['mod_directory']
            : '',
        'active'      => (int)($r['mod_active'] ?? 0) === 1,
        'hasUpdate'   => false,
    ];
}

$installedCount = 0;
foreach ($installedModules as $m) {
    if ($m['active']) { $installedCount++; }
}
$moduleInstallerPayload = [
    'modules'        => $installedModules,
    'installedCount' => $installedCount,
];
$moduleInstallerJson = json_encode($moduleInstallerPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Module Installer'); ?></title>
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
    display: flex;
    flex-direction: column;
  }
  button { font-family: inherit; }
  #cp-root { height: 100%; display: flex; flex-direction: column; }
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
     data-page="module_installer"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-modules="<?php echo attr($moduleInstallerJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Module Installer UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
