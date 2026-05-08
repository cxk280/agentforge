<?php

/**
 * Lab Documents landing page — Figma "Screen 40 — Lab Documents".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/lab_documents/. The PHP outer shell
 * at /interface/main/tabs/main.php still owns the navy top nav and left
 * sidebar; this file only renders inside the #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original DB-backed mock is preserved at copilot_lab_documents.php.bak
 * so a side-by-side screenshot diff remains possible. The static React
 * landing page is the only thing in scope for this Sunday-final migration;
 * the sibling PHP action handlers (copilot_documents_upload.php,
 * copilot_documents_delete.php, copilot_documents_serve.php) and the
 * child viewer at copilot_doc_viewer.php are intentionally untouched.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");

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
$entry        = $manifest['src/pages/lab_documents/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live document inbox.
//
// Pulls recent rows from `documents` (joined to categories +
// patient_data). Categorisation comes from the categories_to_documents →
// categories link with name LIKE patterns (Lab / Imaging / Discharge /
// Other). Match status is driven by foreign_id (NULL or 0 = unmatched).
//
// Demo state: the demo seed does not populate `documents` (real on-disk
// PDF binaries are out of scope). The page reads whatever has been
// uploaded via the existing /interface/patient_file/documents/ Upload
// flow and renders an empty-state otherwise — that is the honest read
// on a fresh install.
// ---------------------------------------------------------------------------

function cp_doc_category(?string $catName): string
{
    if ($catName === null || $catName === '') { return 'Other'; }
    $n = strtolower($catName);
    if (str_contains($n, 'lab'))      { return 'Lab'; }
    if (str_contains($n, 'imag'))     { return 'Imaging'; }
    if (str_contains($n, 'radiol'))   { return 'Imaging'; }
    if (str_contains($n, 'discharge')) { return 'Discharge'; }
    return 'Other';
}

function cp_doc_format_size(int $bytes): string
{
    if ($bytes <= 0)            { return '—'; }
    if ($bytes < 1024)          { return $bytes . ' B'; }
    if ($bytes < 1024 * 1024)   { return number_format($bytes / 1024, 0) . ' KB'; }
    return number_format($bytes / 1024 / 1024, 1) . ' MB';
}

function cp_doc_format_received(string $iso): string
{
    if ($iso === '') { return ''; }
    $t = strtotime($iso);
    if ($t === false) { return ''; }
    return date('m/d H:i', $t);
}

function cp_doc_format_received_long(string $iso, string $source): string
{
    $short = cp_doc_format_received($iso);
    if ($short === '' && $source === '') { return ''; }
    if ($short === '')  { return $source; }
    if ($source === '') { return $short; }
    return $short . ' · ' . $source;
}

$listSql = "
    SELECT d.id,
           d.name,
           d.size,
           d.mimetype,
           d.url,
           d.foreign_id,
           d.docdate,
           d.date,
           pd.pid AS pat_pid,
           pd.fname AS pat_fname,
           pd.lname AS pat_lname,
           pd.DOB AS pat_dob,
           pd.pubpid AS pat_mrn,
           c.name AS cat_name
      FROM documents d
 LEFT JOIN patient_data pd ON pd.pid = d.foreign_id
 LEFT JOIN categories_to_documents c2d ON c2d.document_id = d.id
 LEFT JOIN categories c ON c.id = c2d.category_id
     WHERE d.deleted = 0
  GROUP BY d.id
  ORDER BY COALESCE(d.docdate, DATE(d.date)) DESC, d.id DESC
     LIMIT 50
";

$docs = [];
$counts = ['all' => 0, 'unmatched' => 0, 'lab' => 0, 'imaging' => 0, 'discharge' => 0, 'other' => 0];

$rs = sqlStatement($listSql);
while ($r = sqlFetchArray($rs)) {
    $unmatched = empty($r['foreign_id']) || (int)$r['foreign_id'] === 0;
    $category  = cp_doc_category($r['cat_name'] ?? null);

    $patientName = null;
    $mrn = null;
    $dob = null;
    if (!$unmatched) {
        $fn = trim((string)($r['pat_fname'] ?? ''));
        $ln = trim((string)($r['pat_lname'] ?? ''));
        $patientName = trim($fn . ' ' . $ln) ?: null;
        $mrn = $r['pat_mrn'] !== null && $r['pat_mrn'] !== ''
            ? '#' . (string)$r['pat_mrn']
            : null;
        $rawDob = (string)($r['pat_dob'] ?? '');
        if ($rawDob !== '' && $rawDob !== '0000-00-00') {
            $t = strtotime($rawDob);
            if ($t !== false) { $dob = date('m/d/Y', $t); }
        }
    }

    $iso = (string)($r['docdate'] ?: $r['date'] ?: '');
    // The url column on `documents` is a host-style path. Strip the file
    // prefix and use just the trailing segment as a fallback "source"
    // label when no category gives a hint.
    $urlBase = '';
    if (!empty($r['url'])) {
        $parts = explode('/', (string)$r['url']);
        $urlBase = $parts[count($parts) - 1] ?? '';
    }
    $source = $r['cat_name'] ? (string)$r['cat_name'] : ($urlBase !== '' ? $urlBase : 'Uploaded document');

    $icon = $category === 'Imaging' ? '🩻' : '📄';
    $unmatchedSubLabel = null;
    if ($unmatched) {
        $unmatchedSubLabel = 'UNMATCHED — needs routing';
    }

    $docs[] = [
        'id'                => (int)$r['id'],
        'filename'          => (string)$r['name'],
        'icon'              => $icon,
        'patientName'       => $patientName,
        'mrn'               => $mrn,
        'dob'               => $dob,
        'category'          => $category,
        'receivedShort'     => cp_doc_format_received($iso),
        'receivedLong'      => cp_doc_format_received_long($iso, $source),
        'size'              => cp_doc_format_size((int)($r['size'] ?? 0)),
        'source'            => $source,
        'unmatched'         => $unmatched,
        'unmatchedSubLabel' => $unmatchedSubLabel,
    ];

    $counts['all']++;
    if ($unmatched) { $counts['unmatched']++; }
    if ($category === 'Lab')       { $counts['lab']++; }
    if ($category === 'Imaging')   { $counts['imaging']++; }
    if ($category === 'Discharge') { $counts['discharge']++; }
    if ($category === 'Other')     { $counts['other']++; }
}

$docsPayload = [
    'docs'   => $docs,
    'counts' => $counts,
];
$docsJson = json_encode($docsPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Lab Documents'); ?></title>
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
     data-page="lab_documents"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-docs="<?php echo attr($docsJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Lab Documents UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
