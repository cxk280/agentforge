<?php

/**
 * Inventory landing page — Figma "Screen 60 — Inventory".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/inventory/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original DB-backed PHP mock is preserved at copilot_inventory.php.bak
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
$entry        = $manifest['src/pages/inventory/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live drug_inventory rows. Joined to drugs for the display name +
// ndc_number + form. Past-expiration / low-stock tones are derived in PHP
// so the React side renders straight from the typed payload.
// ---------------------------------------------------------------------------

$inventoryRows = [];
$rs = sqlStatement(
    "SELECT di.inventory_id, di.drug_id, di.lot_number, di.expiration,
            di.manufacturer, di.on_hand, di.warehouse_id,
            d.name, d.ndc_number, d.form, d.size, d.unit, d.reorder_point,
            d.related_code
       FROM drug_inventory di
       LEFT JOIN drugs d ON d.drug_id = di.drug_id
      WHERE di.destroy_date IS NULL
      ORDER BY d.name ASC, di.lot_number ASC"
);
$now = time();
$soon = strtotime('+30 days', $now) ?: $now;
while ($r = sqlFetchArray($rs)) {
    $name = trim((string)($r['name'] ?? ''));
    if ($name === '') { continue; }

    $expRaw = (string)($r['expiration'] ?? '');
    $expTs  = $expRaw !== '' ? strtotime($expRaw) : false;
    $expDisplay = $expTs !== false ? date('m/d/Y', $expTs) : '—';
    $expTone = 'plain';
    if ($expTs !== false) {
        if ($expTs < $now) {
            $expTone = 'past';
        } elseif ($expTs < $soon) {
            $expTone = 'warn';
        }
    }

    $onHand = (int)($r['on_hand'] ?? 0);
    $reorder = (int)($r['reorder_point'] ?? 0);
    $onHandTone = $onHand <= $reorder ? 'warn' : 'plain';

    $form = (string)($r['form'] ?? '');
    if ($form === '0' || $form === '') { $form = 'Tablet / Capsule'; }
    $location = (string)($r['warehouse_id'] ?? '');
    if ($location === '' || $location === 'main') { $location = 'Med room A'; }

    $inventoryRows[] = [
        'id'         => (int)$r['inventory_id'],
        'drug'       => $name,
        'form'       => $form,
        'ndc'        => (string)($r['ndc_number'] ?? '') !== '' ? (string)$r['ndc_number'] : '—',
        'schedule'   => '',
        'lot'        => (string)($r['lot_number'] ?? '') !== '' ? (string)$r['lot_number'] : '—',
        'exp'        => $expDisplay,
        'expTone'    => $expTone,
        'onHand'     => (string)$onHand,
        'onHandTone' => $onHandTone,
        'reorder'    => $reorder > 0 ? (string)$reorder : '—',
        'location'   => $location,
        'lastDispensed' => '—',
    ];
}

$inventoryPayload = [
    'rows' => $inventoryRows,
];
$inventoryJson = json_encode($inventoryPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Inventory'); ?></title>
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
     data-page="inventory"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-inventory="<?php echo attr($inventoryJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Inventory UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
