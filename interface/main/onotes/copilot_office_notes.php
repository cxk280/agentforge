<?php

/**
 * Office Notes landing page — Figma "Screen 49 — Office Notes".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/office_notes/. The PHP outer shell
 * at /interface/main/tabs/main.php still owns the navy top nav, left
 * sidebar, and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * Live notes from the `onotes` table (with cp_pinned / cp_category / cp_parent_id
 * extension columns) are queried server-side and JSON-encoded onto data-notes,
 * mirroring the pattern used by the Finder + Issues pages. The original
 * DB-driven mock with full POST handlers is preserved at copilot_office_notes.php.bak.
 *
 * NOTE: Office Notes is practice-wide (NOT pid-scoped). All active top-level
 * notes are returned regardless of patient context.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../copilot_helpers.php");

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
$entry        = $manifest['src/pages/office_notes/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Category taxonomy (slug => [Display label, sticky tone, emoji icon]).
// Mirrors the metadata previously rendered by the PHP page (see .bak).
// ---------------------------------------------------------------------------
$catMeta = [
    'pharmacy'    => ['Pharmacy',    'yellow', "\u{1F48A}"],   // pill
    'clinical'    => ['Clinical',    'blue',   "\u{1FA7A}"],   // stethoscope
    'front_desk'  => ['Front desk',  'violet', "\u{1F6AA}"],   // door
    'billing'     => ['Billing',     'pink',   "\u{1F4B5}"],   // dollar
    'maintenance' => ['Maintenance', 'violet', "\u{1F6E0}"],   // hammer & wrench
    'general'     => ['General',     'yellow', "\u{1F4CC}"],   // pushpin
];

// Avatar tone palette — cycle by author's user id so the same person always
// gets the same coloured chip across the board.
$avatarTones = ['teal', 'blue', 'orange', 'green', 'pink', 'mint', 'purple'];

// ---------------------------------------------------------------------------
// Counts per category for filter pill badges. Always reflect the full active
// dataset, not whatever filter the React state happens to apply.
// ---------------------------------------------------------------------------
$counts = ['all' => 0, 'pinned' => 0];
foreach (array_keys($catMeta) as $slug) {
    $counts[$slug] = 0;
}
$rsCount = sqlStatement(
    "SELECT cp_category, cp_pinned, COUNT(*) AS c
     FROM onotes
     WHERE activity = 1 AND cp_parent_id IS NULL
     GROUP BY cp_category, cp_pinned"
);
while ($cr = sqlFetchArray($rsCount)) {
    $cSlug = (string)($cr['cp_category'] ?? 'general');
    $cN    = (int)($cr['c'] ?? 0);
    $counts['all'] += $cN;
    if ((int)$cr['cp_pinned'] === 1) {
        $counts['pinned'] += $cN;
    }
    if (isset($counts[$cSlug])) {
        $counts[$cSlug] += $cN;
    }
}

// ---------------------------------------------------------------------------
// Fetch active top-level notes joined to author (users.username) and a
// reply-count subquery. Practice-wide; not patient-scoped.
// ---------------------------------------------------------------------------
$sql = "SELECT o.id, o.date, o.body, o.user AS author_username,
               o.cp_pinned, o.cp_category,
               u.id AS user_id, u.fname, u.lname, u.title, u.username,
               (SELECT COUNT(*) FROM onotes c
                 WHERE c.cp_parent_id = o.id AND c.activity = 1) AS reply_count
        FROM onotes o
        LEFT JOIN users u ON u.username = o.user
        WHERE o.activity = 1 AND o.cp_parent_id IS NULL
        ORDER BY o.cp_pinned DESC, o.date DESC
        LIMIT 50";
$rs = sqlStatement($sql);

$notes = [];
while ($r = sqlFetchArray($rs)) {
    $catKey = (string)($r['cp_category'] ?? 'general');
    [$catLabel, $catTone, $catIcon] = $catMeta[$catKey] ?? $catMeta['general'];

    $authorRow = [
        'fname'    => (string)($r['fname'] ?? ''),
        'lname'    => (string)($r['lname'] ?? ''),
        'title'    => (string)($r['title'] ?? ''),
        'username' => (string)($r['username'] ?? $r['author_username'] ?? ''),
    ];
    // If users join missed (e.g. legacy free-text user), fall back to the raw value.
    $authorName = $authorRow['username'] !== ''
        ? cp_format_provider_name($authorRow)
        : ((string)($r['author_username'] ?? '') !== '' ? (string)$r['author_username'] : 'Unknown');

    $initialsStr = cp_initials($authorRow);
    $userId      = (int)($r['user_id'] ?? 0);
    $tone        = $userId > 0
        ? $avatarTones[$userId % count($avatarTones)]
        : $avatarTones[0];

    $when        = (string)($r['date'] ?? '');
    $whenDisplay = $when !== '' ? date('m/d H:i', strtotime($when)) : '';

    $notes[] = [
        'id'       => (int)$r['id'],
        'category' => strtoupper((string)$catLabel),
        'catSlug'  => $catKey,
        'icon'     => $catIcon,
        'tone'     => $catTone,
        'pinned'   => (int)$r['cp_pinned'] === 1,
        'body'     => (string)($r['body'] ?? ''),
        'author'   => $authorName,
        'avatar'   => $tone,
        'initials' => $initialsStr,
        'when'     => $whenDisplay,
        'comments' => (int)($r['reply_count'] ?? 0),
    ];
}

$payload = [
    'notes'  => $notes,
    'counts' => [
        'all'         => (int)$counts['all'],
        'pinned'      => (int)$counts['pinned'],
        'pharmacy'    => (int)$counts['pharmacy'],
        'clinical'    => (int)$counts['clinical'],
        'front_desk'  => (int)$counts['front_desk'],
        'billing'     => (int)$counts['billing'],
        'maintenance' => (int)$counts['maintenance'],
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Office Notes'); ?></title>
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
     data-page="office_notes"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-notes="<?php echo attr((string)json_encode($payload)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Office Notes UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
