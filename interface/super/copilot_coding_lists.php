<?php

/**
 * Coding & Lists landing page — Figma "Screen 63 — Coding & Lists".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/coding_lists/. The PHP outer shell
 * at /interface/main/tabs/main.php still owns the navy top nav, left
 * sidebar, and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original static-HTML mock is preserved at copilot_coding_lists.php.bak
 * so a side-by-side screenshot diff remains possible.
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
// ---------------------------------------------------------------------------

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/coding_lists/index.tsx'] ?? null;
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live in-house lists from `list_options`. The meta-list with list_id='lists'
// names every other list; we join its rows back to a per-list COUNT(*) so the
// "size" column shows real entry counts. External code systems (ICD-10, CPT,
// etc.) are kept as known-curated entries alongside the live data.
// ---------------------------------------------------------------------------

$liveLists = [];
$rs = sqlStatement(
    "SELECT lo.option_id, lo.title,
            (SELECT COUNT(*) FROM list_options sub WHERE sub.list_id = lo.option_id) AS n
       FROM list_options lo
      WHERE lo.list_id = 'lists' AND lo.activity = 1
      ORDER BY lo.seq, lo.title"
);
while ($r = sqlFetchArray($rs)) {
    $optId = (string)($r['option_id'] ?? '');
    $title = (string)($r['title'] ?? '');
    $count = (int)($r['n'] ?? 0);
    if ($optId === '' || $title === '' || $count === 0) {
        continue; // skip empty / metadata-only meta entries
    }
    $liveLists[] = [
        'id'     => 'list-' . $optId,
        'name'   => $title,
        'type'   => 'List',
        'size'   => $count . ' ' . ($count === 1 ? 'entry' : 'entries'),
        'source' => 'list_options',
        'status' => 'Local',
        'tone'   => 'info',
    ];
}

// Cap at 24 to keep the demo readable; sort by entry count desc.
usort($liveLists, function (array $a, array $b): int {
    $an = (int)preg_replace('/[^0-9]/', '', (string)$a['size']);
    $bn = (int)preg_replace('/[^0-9]/', '', (string)$b['size']);
    return $bn <=> $an;
});
$liveLists = array_slice($liveLists, 0, 24);

// External code-system rows kept as curated metadata (no DB-backed sync state
// exists for these in OpenEMR's schema).
$codeSystems = [
    ['id' => 'icd10',   'name' => 'ICD-10 (clinical)',       'type' => 'Code system', 'size' => '~70,000 entries',  'source' => '2026 release',   'status' => 'Synced', 'tone' => 'good'],
    ['id' => 'cpt',     'name' => 'CPT / HCPCS',             'type' => 'Code system', 'size' => '~10,400 entries',  'source' => '2026 release',   'status' => 'Synced', 'tone' => 'good'],
    ['id' => 'snomed',  'name' => 'SNOMED CT',               'type' => 'Code system', 'size' => 'Subset (~80k)',    'source' => '2025-09 update', 'status' => 'Synced', 'tone' => 'good'],
    ['id' => 'rxnorm',  'name' => 'RxNorm',                  'type' => 'Code system', 'size' => '~150,000 entries', 'source' => 'Daily sync',     'status' => 'Synced', 'tone' => 'good'],
    ['id' => 'loinc',   'name' => 'LOINC',                   'type' => 'Code system', 'size' => '~95,000 entries',  'source' => '2026-04 update', 'status' => 'Synced', 'tone' => 'good'],
];

$codingListsPayload = [
    'codeSystems' => $codeSystems,
    'lists'       => $liveLists,
    'totalLists'  => count($liveLists),
];
$codingListsJson = json_encode($codingListsPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Coding & Lists'); ?></title>
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
  #cp-root { height: 100%; display: flex; flex-direction: column; }
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
     data-page="coding_lists"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-coding-lists="<?php echo attr($codingListsJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Coding & Lists UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
