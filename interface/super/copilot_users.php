<?php

/**
 * Users & Groups landing page — Figma "Screen 52 — Users & Groups".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/users/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original DB-backed implementation is preserved at
 * copilot_users.php.bak so a side-by-side screenshot diff (and an eventual
 * port of the new-group / search / filter logic onto a real
 * /apis/copilot/users/* endpoint) remains possible.
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
$entry        = $manifest['src/pages/users/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live users + groups + service-account counts. Admin scope (no pid filter).
// We surface the full set; client-side React filters with the same role
// heuristic used by the original .bak.
// ---------------------------------------------------------------------------

function cp_users_initials(string $fname, string $lname, string $username): string
{
    $f = strtoupper(substr(trim($fname), 0, 1));
    $l = strtoupper(substr(trim($lname), 0, 1));
    if ($f !== '' && $l !== '') {
        return $f . $l;
    }
    if ($f !== '' || $l !== '') {
        return ($f . $l) ?: 'U';
    }
    return strtoupper(substr($username, 0, 2)) ?: 'U';
}

function cp_users_role_for(array $u): string
{
    $title = strtoupper((string)($u['title'] ?? ''));
    $username = strtolower((string)($u['username'] ?? ''));
    if (in_array($title, ['MD', 'DO', 'DDS', 'DMD', 'DPM', 'DC', 'OD', 'PHD', 'NP', 'PA'], true)) {
        return 'Provider';
    }
    if (in_array($title, ['RN', 'LPN'], true)) {
        return 'Nurse';
    }
    if (str_contains($username, 'recept') || str_contains($username, 'desk')) {
        return 'Front desk';
    }
    if (str_contains($username, 'bill')) {
        return 'Billing';
    }
    if ($username === 'admin' || str_contains($username, 'admin')) {
        return 'Admin';
    }
    if ((int)($u['authorized'] ?? 0) === 1) {
        return 'Provider';
    }
    return 'Staff';
}

function cp_users_role_key(string $role): string
{
    $r = strtolower($role);
    if (str_starts_with($r, 'provider')) return 'provider';
    if ($r === 'nurse') return 'nurse';
    if ($r === 'front desk') return 'fd';
    if ($r === 'billing') return 'billing';
    if ($r === 'admin') return 'admin';
    return 'any';
}

function cp_users_relative_login(?string $iso): string
{
    if ($iso === null || $iso === '' || $iso === '0000-00-00 00:00:00') {
        return 'Never';
    }
    $ts = strtotime($iso);
    if ($ts === false) { return 'Never'; }
    $delta = time() - $ts;
    if ($delta < 60)             { return 'Just now'; }
    if ($delta < 3600)           { return (int)floor($delta / 60) . ' min ago'; }
    if ($delta < 86400)          { return (int)floor($delta / 3600) . 'h ago'; }
    if ($delta < 86400 * 7) {
        $d = (int)floor($delta / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y', $ts);
}

$usersList   = [];
$groupsList  = [];
$activeCount = 0;

$rs = sqlStatement(
    "SELECT u.id, u.username, u.fname, u.mname, u.lname, u.title, u.email,
            u.authorized, u.active, u.abook_type,
            (SELECT GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ')
               FROM `groups` g WHERE g.user = u.username) AS group_names,
            (SELECT MAX(l.date) FROM log l
              WHERE l.event = 'login' AND l.user = u.username) AS last_login_at
       FROM users u
      ORDER BY u.active DESC, u.lname ASC, u.fname ASC, u.username ASC
      LIMIT 200"
);
while ($r = sqlFetchArray($rs)) {
    $username = (string)($r['username'] ?? '');
    if ($username === '') { continue; }
    $fname = (string)($r['fname'] ?? '');
    $lname = (string)($r['lname'] ?? '');
    $name  = trim($fname . ' ' . $lname);
    if ($name === '') { $name = $username; }
    if (!empty($r['title'])) { $name .= ', ' . $r['title']; }

    $role = cp_users_role_for($r);
    $isService = (int)($r['abook_type'] ?? 0) === 0
        ? false
        : false; // abook_type doesn't reliably mark service accounts; rely on naming.
    $isService = $isService || str_contains($username, '_svc') || $username === 'erx_svc' || str_contains($username, 'service');
    $active = (int)($r['active'] ?? 0) === 1;
    if ($active && !$isService) { $activeCount++; }

    $groups = [];
    $gnames = (string)($r['group_names'] ?? '');
    if ($gnames !== '') {
        foreach (explode(',', $gnames) as $g) {
            $g = trim($g);
            if ($g !== '') { $groups[] = $g; }
        }
    }

    $usersList[] = [
        'id'        => (string)(int)$r['id'],
        'name'      => $name,
        'initials'  => cp_users_initials($fname, $lname, $username),
        'username'  => $username,
        'email'     => (string)($r['email'] ?? '') !== '' ? (string)$r['email'] : '-',
        'role'      => $isService ? 'Service account' : $role,
        'roleKey'   => $isService ? 'any' : cp_users_role_key($role),
        'groups'    => $groups,
        'mfaOn'     => false,
        'active'    => $active,
        'lastLogin' => cp_users_relative_login(is_string($r['last_login_at'] ?? null) ? (string)$r['last_login_at'] : null),
        'badge'     => $isService ? 'svc' : (!$active ? 'inactive' : ''),
        'isService' => $isService,
    ];
}

$grs = sqlStatement(
    "SELECT name, COUNT(DISTINCT user) AS members
       FROM `groups`
      GROUP BY name
      ORDER BY name"
);
while ($g = sqlFetchArray($grs)) {
    $groupsList[] = [
        'name'    => (string)($g['name'] ?? ''),
        'members' => (int)($g['members'] ?? 0),
    ];
}

$serviceCount = 0;
foreach ($usersList as $u) {
    if (!empty($u['isService'])) { $serviceCount++; }
}

$usersPayload = [
    'users'        => $usersList,
    'groups'       => $groupsList,
    'activeCount'  => $activeCount,
    'serviceCount' => $serviceCount,
];
$usersJson = json_encode($usersPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Users & Groups'); ?></title>
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
     data-page="users"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-users="<?php echo attr($usersJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Users & Groups UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
