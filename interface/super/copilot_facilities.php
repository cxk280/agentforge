<?php

/**
 * Facilities landing page — Figma "Screen 54 — Facilities".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/facilities/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original static-HTML mock is preserved at copilot_facilities.php.bak
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
$entry        = $manifest['src/pages/facilities/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live facilities — admin scope. Each row becomes a sidebar card; the first
// active row is auto-selected. The right-rail edit form / hours / capabilities
// stay demo-mode for now (those tables aren't part of OpenEMR's `facility`).
// ---------------------------------------------------------------------------

$facilityList = [];
$facilityCount = 0;
$activeFacilityCount = 0;

$rs = sqlStatement(
    "SELECT id, name, street, city, state, postal_code, country_code, phone,
            COALESCE(billing_location, 0) AS billing_location,
            COALESCE(accepts_assignment, 0) AS accepts_assignment,
            COALESCE(primary_business_entity, 0) AS primary_business_entity,
            COALESCE(service_location, 0) AS service_location,
            COALESCE(inactive, 0) AS inactive,
            COALESCE(facility_npi, '') AS facility_npi
       FROM facility
      ORDER BY (primary_business_entity = 1) DESC, inactive ASC, name ASC"
);
while ($r = sqlFetchArray($rs)) {
    $name = (string)($r['name'] ?? '');
    if ($name === '') { continue; }

    $isPrimary  = (int)($r['primary_business_entity'] ?? 0) === 1;
    $isInactive = (int)($r['inactive'] ?? 0) === 1;

    // Build a "Sub" line: prefer street + city/state, fall back to "Virtual"
    // if both are empty. Service-location/billing tags are appended.
    $street = trim((string)($r['street'] ?? ''));
    $city   = trim((string)($r['city'] ?? ''));
    $state  = trim((string)($r['state'] ?? ''));
    $cityState = $city !== '' ? ($city . ($state !== '' ? ', ' . $state : '')) : $state;
    if ($street !== '' && $cityState !== '') {
        $address = $street . ', ' . $cityState;
    } elseif ($street !== '') {
        $address = $street;
    } elseif ($cityState !== '') {
        $address = $cityState;
    } else {
        $address = '';
    }

    $tags = [];
    if ($isPrimary) {
        $tags[] = 'Main';
    } elseif ((int)($r['service_location'] ?? 0) === 1) {
        $tags[] = 'Service';
    } else {
        $tags[] = 'Satellite';
    }
    if ((int)($r['billing_location'] ?? 0) === 1) {
        $tags[] = 'Billing';
    }
    $sub = implode(' · ', array_filter([
        implode(' · ', $tags),
        $address !== '' ? $address : ($isInactive ? 'Inactive' : 'No address on file'),
    ]));

    if ($isInactive) {
        $pillLabel = 'Inactive';
        $pillTone  = 'neutral';
    } elseif ($isPrimary) {
        $pillLabel = "\u{2B50} Primary";
        $pillTone  = 'primary';
    } else {
        $pillLabel = 'Active';
        $pillTone  = 'good';
    }

    $facilityList[] = [
        'id'        => 'fac-' . (int)$r['id'],
        'dbId'      => (int)$r['id'],
        'name'      => $name,
        'sub'       => $sub,
        'pillLabel' => $pillLabel,
        'pillTone'  => $pillTone,
        'phone'     => (string)($r['phone'] ?? ''),
        'npi'       => (string)($r['facility_npi'] ?? ''),
        'inactive'  => $isInactive,
    ];
    $facilityCount++;
    if (!$isInactive) { $activeFacilityCount++; }
}

$facilitiesPayload = [
    'facilities'          => $facilityList,
    'facilityCount'       => $facilityCount,
    'activeFacilityCount' => $activeFacilityCount,
];
$facilitiesJson = json_encode($facilitiesPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Facilities'); ?></title>
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
     data-page="facilities"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-facilities="<?php echo attr($facilitiesJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Facilities UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
