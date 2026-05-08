<?php

/**
 * Login screen — Figma "Screen 28 — Login".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/login/.
 *
 * PRE-AUTH PAGE. Unlike the rest of the AgentForge React wrappers, this file
 * is intentionally chrome-less and does NOT require globals.php — the login
 * page must be reachable without an authenticated session, and pulling in
 * globals.php triggers DB lookups + auth bootstrapping that defeats that.
 *
 * Because globals.php is absent, the helpers normally used to escape output
 * (attr(), text(), xlt()) are unavailable. We use htmlspecialchars() with
 * ENT_QUOTES throughout instead. The original mock at this path emitted no
 * CSRF token (it was a static screenshot fixture); we preserve that behavior
 * — the React bundle will only render the hidden csrf input when a non-empty
 * token is provided, so flipping CSRF on later is a one-line change here.
 *
 * The form POST target is passed in via data-form-action so it can be tuned
 * without rebuilding the JS. The original PHP posted to "login.php" (the
 * upstream OpenEMR Twig-rendered login page); we keep that endpoint to match
 * existing behavior.
 *
 * The original static-HTML mock is preserved at copilot_login.php.bak so a
 * side-by-side screenshot diff remains possible.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// prevent UI redressing — same headers the upstream OpenEMR login.php emits.
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");

// ---------------------------------------------------------------------------
// Resolve built React assets via the Vite manifest.
//
// The manifest path is resolved relative to __DIR__ since $GLOBALS['fileroot']
// is not populated without globals.php. Apache serves the repo root as
// DocumentRoot in the OpenEMR container, so /public is part of the URL.
// ---------------------------------------------------------------------------

$fileroot     = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
$webroot      = ''; // no globals.php → assume root webroot; matches docker dev + Railway prod.
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/login/index.tsx'] ?? null;
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

// Boot context for the React tree. The original PHP shipped no CSRF token,
// so we surface an empty string here — the React component skips the hidden
// input when csrf is empty.
$csrfToken  = '';
$formAction = 'login.php';

/**
 * Local escaper — globals.php's attr() is unavailable on this pre-auth page.
 *
 * @param string $value
 * @return string
 */
function cp_login_attr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Clinical Co-Pilot</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php foreach ($cssHrefs as $h): ?>
<link rel="stylesheet" href="<?php echo cp_login_attr($webroot); ?>/public/build/<?php echo cp_login_attr((string)$h); ?>">
<?php endforeach; ?>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
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
     data-page="login"
     data-csrf="<?php echo cp_login_attr($csrfToken); ?>"
     data-form-action="<?php echo cp_login_attr($formAction); ?>"
     data-api-base="<?php echo cp_login_attr($webroot); ?>/apis"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo cp_login_attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  Login UI bundle not found. Run "npm run build" in the /frontend directory to generate it.
</div>
<?php endif; ?>
</body>
</html>
