<?php

/**
 * Facilities — Screen 54.
 *
 * Admin → Facilities sub-page. Left rail of admin navigation grouped
 * by Users & Access / Practice / Clinical / System, a master list of
 * practice facilities (cards), and an Edit Facility form on the right
 * with Identity / Address / Contact / Service hours / Capabilities.
 *
 * Backend wiring:
 *   - List/edit reads/writes the `facility` table.
 *   - Service hours stored as JSON in extension column `cp_service_hours`
 *     (idempotent ALTER on first run).
 *   - Capabilities stored as JSON in extension column `cp_capabilities`
 *     (idempotent ALTER on first run).
 *   - POST handlers: `save_facility` (UPDATE), `new_facility` (INSERT).
 *     POST/redirect/GET pattern. Audited via EventAuditLogger.
 *
 * The chrome (top nav) is rendered by the parent shell; this page
 * renders only the body. Page is not patient-scoped.
 *
 * CSRF skipped — internal mock page; OpenEMR's auth gate (globals.php →
 * authCheckCore()) prevents anonymous POSTs.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Logging\EventAuditLogger;

$selfPath = $_SERVER['PHP_SELF'];

// -------------------------------------------------------------------------
// Idempotent schema extension: cp_service_hours / cp_capabilities JSON.
// Runs once per page load; INFORMATION_SCHEMA check makes it a no-op
// after the first successful ALTER.
// -------------------------------------------------------------------------
$dbName = (string)(sqlQuery("SELECT DATABASE() AS d")['d'] ?? '');
$cpFacColumns = [
    'cp_service_hours' => "ALTER TABLE facility ADD COLUMN cp_service_hours TEXT NULL DEFAULT NULL",
    'cp_capabilities'  => "ALTER TABLE facility ADD COLUMN cp_capabilities TEXT NULL DEFAULT NULL",
];
foreach ($cpFacColumns as $col => $ddl) {
    $exists = sqlQuery(
        "SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'facility' AND COLUMN_NAME = ?",
        [$dbName, $col]
    );
    if (empty($exists['x'])) {
        try {
            sqlStatement($ddl);
        } catch (\Throwable $e) {
            // Defensive: another concurrent request may have added the column.
            // Ignore — the next iteration / page load will see it via the check.
        }
    }
}

// -------------------------------------------------------------------------
// Capability catalogue — order matches the Figma mock. Stored value is
// the slug (key); the label is what we render on the page.
// -------------------------------------------------------------------------
$capabilityCatalog = [
    'lab'          => 'On-site lab',
    'imaging'      => 'On-site imaging',
    'vaccinations' => 'Vaccinations',
    'procedures'   => 'Procedures',
    'telehealth'   => 'Telehealth',
    'epcs'         => 'EPCS DEA registered',
    'ada'          => 'Wheelchair accessible',
];

// -------------------------------------------------------------------------
// Default service hours used when a facility has none stored.
// -------------------------------------------------------------------------
$dayOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$defaultHours = [
    'Mon' => '7:00 AM – 6:00 PM',
    'Tue' => '7:00 AM – 6:00 PM',
    'Wed' => '7:00 AM – 6:00 PM',
    'Thu' => '7:00 AM – 6:00 PM',
    'Fri' => '7:00 AM – 5:00 PM',
    'Sat' => '8:00 AM – 12:00 PM',
    'Sun' => 'Closed',
];

/**
 * Decode a JSON column value into a string-keyed array. Returns the
 * fallback when value is null/empty/invalid JSON.
 *
 * @param array<string, mixed> $fallback
 * @return array<string, mixed>
 */
function cp_facility_json_decode(?string $raw, array $fallback): array
{
    if ($raw === null || $raw === '') {
        return $fallback;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $fallback;
    }
    return $decoded;
}

// -------------------------------------------------------------------------
// POST handlers — run before any output, redirect on success.
// -------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $audit  = EventAuditLogger::getInstance();
    $user   = (string)($_SESSION['authUser'] ?? 'admin');
    $grp    = (string)($_SESSION['authProvider'] ?? 'Default');

    if ($action === 'new_facility') {
        $newName = trim((string)($_POST['name'] ?? ''));
        if ($newName === '') {
            $newName = 'New Facility';
        }
        sqlStatement(
            "INSERT INTO facility
                (name, country_code, tax_id_type, color, oid, organization_type,
                 service_location, billing_location, accepts_assignment,
                 primary_business_entity, extra_validation, inactive)
             VALUES (?, 'US', 'EI', '#FFFFFF', '', 'prov', 1, 1, 1, 0, 1, 0)",
            [$newName]
        );
        $newId = (int)sqlQuery("SELECT LAST_INSERT_ID() AS i")['i'];
        try {
            $audit->newEvent(
                'security-administration',
                $user,
                $grp,
                1,
                'Facilities editor: created facility id=' . $newId . ' (' . $newName . ')'
            );
        } catch (\Throwable $e) {
            // Audit failures must not block the user-visible flow.
        }
        header('Location: ' . $selfPath . '?id=' . $newId . '&msg=created');
        exit;
    }

    if ($action === 'save_facility') {
        $fid = (int)($_POST['id'] ?? 0);
        if ($fid > 0) {
            // Validate the row exists before updating.
            $existing = sqlQuery("SELECT id FROM facility WHERE id = ?", [$fid]);
            if (!empty($existing['id'])) {
                $name        = trim((string)($_POST['name'] ?? ''));
                $shortName   = trim((string)($_POST['short_name'] ?? ''));
                $npi         = trim((string)($_POST['facility_npi'] ?? ''));
                $type        = (string)($_POST['type'] ?? 'service');
                $taxId       = trim((string)($_POST['federal_ein'] ?? ''));
                $street      = trim((string)($_POST['street'] ?? ''));
                $city        = trim((string)($_POST['city'] ?? ''));
                $state       = trim((string)($_POST['state'] ?? ''));
                $postal      = trim((string)($_POST['postal_code'] ?? ''));
                $country     = trim((string)($_POST['country_code'] ?? ''));
                $phone       = trim((string)($_POST['phone'] ?? ''));
                $fax         = trim((string)($_POST['fax'] ?? ''));
                $email       = trim((string)($_POST['email'] ?? ''));

                // type = service|billing|both — maps to two boolean columns.
                $service  = ($type === 'service' || $type === 'both') ? 1 : 0;
                $billing  = ($type === 'billing' || $type === 'both') ? 1 : 0;

                // Service hours — 7 day-keyed inputs, stored as JSON.
                $hours = [];
                foreach ($dayOrder as $day) {
                    $hours[$day] = trim((string)($_POST['hours'][$day] ?? ''));
                }
                $hoursJson = json_encode($hours, JSON_UNESCAPED_SLASHES);

                // Capabilities — checkbox slugs that were submitted.
                $caps = [];
                $rawCaps = $_POST['capabilities'] ?? [];
                if (is_array($rawCaps)) {
                    foreach ($rawCaps as $slug) {
                        $slugStr = (string)$slug;
                        if (isset($capabilityCatalog[$slugStr])) {
                            $caps[] = $slugStr;
                        }
                    }
                }
                $capsJson = json_encode(array_values(array_unique($caps)), JSON_UNESCAPED_SLASHES);

                // Short-name carries no native column; we stash it in
                // facility.facility_code, which is varchar(31) and otherwise
                // unused in the seed. (Documented near the form below.)
                sqlStatement(
                    "UPDATE facility SET
                        name = ?,
                        facility_code = ?,
                        facility_npi = ?,
                        federal_ein = ?,
                        service_location = ?,
                        billing_location = ?,
                        street = ?,
                        city = ?,
                        state = ?,
                        postal_code = ?,
                        country_code = ?,
                        phone = ?,
                        fax = ?,
                        email = ?,
                        cp_service_hours = ?,
                        cp_capabilities = ?
                     WHERE id = ?",
                    [
                        $name,
                        substr($shortName, 0, 31),
                        substr($npi, 0, 15),
                        substr($taxId, 0, 15),
                        $service,
                        $billing,
                        $street,
                        $city,
                        $state,
                        substr($postal, 0, 11),
                        substr($country, 0, 30),
                        substr($phone, 0, 30),
                        substr($fax, 0, 30),
                        $email,
                        $hoursJson === false ? null : $hoursJson,
                        $capsJson === false ? null : $capsJson,
                        $fid,
                    ]
                );
                try {
                    $audit->newEvent(
                        'security-administration',
                        $user,
                        $grp,
                        1,
                        'Facilities editor: saved facility id=' . $fid . ' (' . $name . ')'
                    );
                } catch (\Throwable $e) {
                    // Audit failures must not block the user-visible flow.
                }
                header('Location: ' . $selfPath . '?id=' . $fid . '&msg=saved');
                exit;
            }
        }
        header('Location: ' . $selfPath . '?msg=missing');
        exit;
    }
}

// -------------------------------------------------------------------------
// Read facility list (left card column). Active facilities only, primary
// business entity floats to the top.
// -------------------------------------------------------------------------
$facilities = [];
$rs = sqlStatement(
    "SELECT id, name, street, city, state, postal_code,
            primary_business_entity, inactive
       FROM facility
      WHERE inactive = 0
      ORDER BY primary_business_entity DESC, name ASC"
);
while ($r = sqlFetchArray($rs)) {
    $facilities[] = $r;
}
$totalActive = count($facilities);

// Header count includes inactive rows (administrative view).
$totalAll = (int)(sqlQuery("SELECT COUNT(*) AS c FROM facility")['c'] ?? 0);

// -------------------------------------------------------------------------
// Selected facility — `?id=<fid>`, defaults to first row in the list.
// -------------------------------------------------------------------------
$selectedId = (int)($_GET['id'] ?? 0);
if ($selectedId <= 0 && !empty($facilities)) {
    $selectedId = (int)$facilities[0]['id'];
}

$selected = null;
if ($selectedId > 0) {
    $selected = sqlQuery("SELECT * FROM facility WHERE id = ?", [$selectedId]);
}
// Fall back to first list row if the requested id is bogus / inactive.
if (empty($selected) && !empty($facilities)) {
    $selectedId = (int)$facilities[0]['id'];
    $selected = sqlQuery("SELECT * FROM facility WHERE id = ?", [$selectedId]);
}
if (!is_array($selected)) {
    $selected = [];
}

// Decode hours / capabilities for the selected row.
$selectedHours = cp_facility_json_decode(
    $selected['cp_service_hours'] ?? null,
    $defaultHours
);
$selectedCaps = cp_facility_json_decode(
    $selected['cp_capabilities'] ?? null,
    array_keys($capabilityCatalog) // default to all-on for the seed mock
);
$selectedCapSet = array_flip(array_map('strval', $selectedCaps));

// Type select value: derived from the two boolean columns.
$selService = (int)($selected['service_location'] ?? 1) === 1;
$selBilling = (int)($selected['billing_location'] ?? 1) === 1;
$selectedType = ($selService && $selBilling) ? 'both'
              : ($selService ? 'service' : ($selBilling ? 'billing' : 'service'));

$flash = (string)($_GET['msg'] ?? '');

// -------------------------------------------------------------------------
// Build a 1-line address sub-text per card.
// -------------------------------------------------------------------------
$cardSub = function (array $f): string {
    $parts = [];
    if (!empty($f['street'])) { $parts[] = (string)$f['street']; }
    $cityState = trim(((string)($f['city'] ?? '')) . (empty($f['state']) ? '' : ' ' . (string)$f['state']));
    if ($cityState !== '') { $parts[] = $cityState; }
    $tail = implode(' · ', $parts);
    if ($tail === '') {
        return (int)($f['primary_business_entity'] ?? 0) === 1
            ? 'No address on file'
            : 'No physical address';
    }
    return ((int)($f['primary_business_entity'] ?? 0) === 1 ? 'Main · ' : 'Site · ') . $tail;
};

// -------------------------------------------------------------------------
// Sidebar (left rail) groupings — preserved verbatim from the mock.
// "Facilities" row is the active one.
// -------------------------------------------------------------------------
$adminNav = [
    'Users & Access' => [
        ['Users & Groups',    false],
        ['ACL Editor',        false],
        ['Active Sessions',   false],
        ['Password Policy',   false],
    ],
    'Practice' => [
        ['Facilities',        true],
        ['Providers',         false],
        ['Schedule Templates', false],
        ['Pricing',           false],
    ],
    'Clinical' => [
        ['Forms',             false],
        ['Lists',             false],
        ['Templates',         false],
        ['Issue Types',       false],
        ['Layouts',           false],
    ],
    'System' => [
        ['Audit Log',         false],
        ['Backup',            false],
        ['Globals',           false],
        ['Database',          false],
        ['Modules',           false],
    ],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Facilities'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Dot separator + meta in page head */
  .cp-pagehead .dot { color: #8A91A1; font-size: 16px; padding: 0 4px; line-height: 1; }
  .cp-pagehead .meta-light { color: #4F5763; font-size: 13px; line-height: 1; }

  /* Admin left rail (matches Figma SubNav) */
  .cp-admin-side {
    flex: 0 0 200px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 12px 0 24px;
    overflow-y: auto;
  }
  .cp-admin-side .header {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.4px;
    padding: 4px 16px 8px;
  }
  .cp-admin-side .grp { margin-top: 4px; }
  .cp-admin-side .grp .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1;
    padding: 8px 16px 4px;
    line-height: 1.2;
  }
  .cp-admin-side .item {
    display: block;
    margin: 0 8px;
    padding: 8px 16px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    text-decoration: none;
    line-height: 1.2;
    border-radius: 6px;
  }
  .cp-admin-side .item:hover { background: #F5F6F7; color: #0D1B2A; }
  .cp-admin-side .item.active {
    background: #E6F5F5;
    color: #008C8C;
    font-weight: 600;
  }

  /* Page head right-aligned new button */
  .cp-pagehead .spacer { flex: 1; }

  /* Body wrapper: 2 columns (list + form) on F5F6F7 background */
  .cp-fac-body {
    flex: 1 1 auto;
    padding: 16px 24px 24px;
    overflow-y: auto;
    display: grid;
    grid-template-columns: 400px 1fr;
    gap: 16px;
    align-content: start;
  }

  /* Facility list card panel */
  .cp-fac-list {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 12px 8px;
  }
  .cp-fac-list .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.4px;
    padding: 4px 8px 8px;
    line-height: 1.2;
  }
  .cp-fac-card {
    display: flex; gap: 12px;
    padding: 14px 12px;
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    border-radius: 8px;
    margin-top: 8px;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
  }
  .cp-fac-card:first-of-type { margin-top: 0; }
  .cp-fac-card.active {
    background: #E6F5F5;
    border-color: #E6F5F5;
  }
  .cp-fac-card .ico {
    font-size: 18px; line-height: 1;
    flex: 0 0 24px;
    color: #181D26;
  }
  .cp-fac-card .body { flex: 1; min-width: 0; }
  .cp-fac-card .name {
    font-size: 13px; font-weight: 600;
    color: #181D26;
    line-height: 1.2;
    margin-bottom: 4px;
  }
  .cp-fac-card .sub {
    font-size: 11px; color: #4F5763;
    line-height: 1.2;
    margin-bottom: 8px;
  }
  .cp-fac-pill {
    display: inline-block;
    border-radius: 9px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 600;
    line-height: 1.4;
    letter-spacing: 0.2px;
  }
  .cp-fac-pill.primary { background: #FFFFFF; color: #008C8C; border: 1px solid transparent; }
  .cp-fac-pill.good    { background: #E8F7ED; color: #33A666; }
  .cp-fac-pill.neutral { background: #F5F6F7; color: #8A91A1; }

  /* Edit form panel */
  .cp-fac-form {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    overflow: hidden;
  }
  .cp-fac-form .head {
    padding: 14px 20px;
    border-bottom: 1px solid #E4E5E8;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-fac-form .head .info { display: flex; flex-direction: column; gap: 4px; flex: 0 0 auto; }
  .cp-fac-form .head .kicker {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.4px;
    line-height: 1;
  }
  .cp-fac-form .head .title {
    font-size: 15px; font-weight: 600;
    color: #181D26; line-height: 1;
  }
  .cp-fac-form .head .pill {
    align-self: end;
    background: #FFF4EA; color: #FA8C33;
    border-radius: 9px;
    padding: 2px 10px;
    font-size: 10px; font-weight: 600;
    line-height: 1.4;
    letter-spacing: 0.2px;
    display: none;
  }
  .cp-fac-form.is-dirty .head .pill { display: inline-block; }
  .cp-fac-form .head .spacer { flex: 1; }
  .cp-fac-form .head .cp-btn { padding: 8px 16px; font-size: 13px; }
  .cp-fac-form .head .cp-btn.primary { padding: 8px 18px; }

  /* Saved-flash banner */
  .cp-flash {
    margin: 0 0 12px;
    padding: 10px 14px;
    border-radius: 6px;
    font-size: 12px; font-weight: 500;
    background: #E8F7ED; color: #1F7A3F;
    border: 1px solid #C5EBD3;
  }
  .cp-flash.warn {
    background: #FFF4EA; color: #B85C00;
    border-color: #FAD2A5;
  }

  .cp-fac-body-inner { padding: 16px 20px 20px; }
  .cp-fac-section {
    margin-top: 18px;
  }
  .cp-fac-section:first-child { margin-top: 0; }
  .cp-fac-section .sec-lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.4px;
    line-height: 1;
    margin-bottom: 12px;
  }
  .cp-fac-grid {
    display: grid;
    gap: 12px 16px;
  }
  .cp-fac-grid.identity-r1 { grid-template-columns: 1fr 1fr; }
  .cp-fac-grid.identity-r2 { grid-template-columns: 1fr 176px 156px; }
  .cp-fac-grid.address-r1  { grid-template-columns: 1fr; }
  .cp-fac-grid.address-r2  { grid-template-columns: 300px 140px 140px 120px; }
  .cp-fac-grid.contact     { grid-template-columns: 1fr 1fr 1fr; }

  .cp-fld { display: flex; flex-direction: column; gap: 6px; }
  .cp-fld label {
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    line-height: 1.2;
  }
  .cp-fld .cp-input,
  .cp-fld .cp-select {
    height: 36px;
    padding: 0 12px;
    border: 1px solid #E4E5E8;
    border-radius: 6px;
    background: #FFFFFF;
    font-size: 14px;
    color: #181D26;
    line-height: 1;
    width: 100%;
  }

  /* Service hours + Capabilities row */
  .cp-fac-grid.svc { grid-template-columns: 1fr 1fr; gap: 18px 32px; align-items: start; }

  .cp-svc-rows { display: flex; flex-direction: column; gap: 4px; }
  .cp-svc-row {
    display: grid;
    grid-template-columns: 56px 1fr;
    align-items: center;
    gap: 8px;
  }
  .cp-svc-row .day {
    font-size: 12px; font-weight: 600;
    color: #181D26;
    line-height: 1;
  }
  .cp-svc-row .val {
    height: 24px;
    padding: 0 8px;
    border: 1px solid #E4E5E8;
    border-radius: 4px;
    background: #FFFFFF;
    font-size: 11px;
    color: #181D26;
    line-height: 1;
    width: 100%;
  }
  .cp-svc-row .val.muted { color: #8A91A1; }

  .cp-cap-list { display: flex; flex-direction: column; gap: 12px; padding-top: 0; }
  .cp-cap-row { display: flex; align-items: center; gap: 8px; cursor: pointer; }
  .cp-cap-row input[type="checkbox"] { display: none; }
  .cp-cb-sm {
    width: 16px; height: 16px;
    background: #FFFFFF; border: 1px solid #C8CDD3; border-radius: 4px;
    display: inline-flex; align-items: center; justify-content: center;
    color: transparent; font-size: 11px; font-weight: 700;
    line-height: 1;
    flex: 0 0 auto;
  }
  .cp-cap-row input[type="checkbox"]:checked + .cp-cb-sm {
    background: #008C8C; border-color: #008C8C;
    color: #FFFFFF;
  }
  .cp-cap-row .lbl {
    font-size: 12px; font-weight: 400;
    color: #181D26; line-height: 1;
  }

  /* Cancel button (white/border) overrides for header */
  .cp-fac-form .head .cp-btn.ghost {
    border-radius: 16px;
    padding: 7px 18px;
    font-size: 13px; font-weight: 500;
    color: #4F5763;
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    text-decoration: none;
    display: inline-flex; align-items: center;
  }
  .cp-fac-form .head .cp-btn.primary {
    border-radius: 16px;
    padding: 7px 18px;
    font-size: 12px; font-weight: 600;
    cursor: pointer;
  }

  /* Page head New button */
  .cp-pagehead .cp-btn.primary {
    border-radius: 16px;
    padding: 7px 14px;
    font-size: 13px; font-weight: 600;
    cursor: pointer;
  }
</style>
</head>
<body class="cp-arch">

<div class="cp-shell">
  <aside class="cp-admin-side">
    <div class="header"><?php echo xlt('ADMIN'); ?></div>
    <?php foreach ($adminNav as $groupLbl => $items): ?>
      <div class="grp">
        <div class="lbl"><?php echo text($groupLbl); ?></div>
        <?php foreach ($items as [$name, $active]): ?>
          <a href="#" class="item<?php echo $active ? ' active' : ''; ?>"><?php echo text($name); ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </aside>

  <div style="flex:1 1 auto; display:flex; flex-direction:column; min-width:0;">
    <header class="cp-pagehead">
      <div class="info">
        <div style="display:flex; align-items:center; gap:8px;">
          <span class="title"><?php echo xlt('Facilities'); ?></span>
          <span class="dot">•</span>
          <span class="meta-light">
            <?php echo text($totalAll); ?> <?php echo xlt('facilities'); ?>
            ·
            <?php echo $selectedId > 0 ? xlt('1 selected for edit') : xlt('none selected'); ?>
          </span>
        </div>
      </div>
      <span class="spacer"></span>
      <form method="POST" action="<?php echo attr($selfPath); ?>" style="display:inline;">
        <input type="hidden" name="action" value="new_facility">
        <input type="hidden" name="name" value="New Facility">
        <button type="submit" class="cp-btn primary">+ <?php echo xlt('New facility'); ?></button>
      </form>
    </header>

    <div class="cp-fac-body">

      <div class="cp-fac-list">
        <div class="lbl"><?php echo xlt('PRACTICE FACILITIES'); ?></div>
        <?php if (empty($facilities)): ?>
          <div style="padding:16px; font-size:12px; color:#8A91A1;">
            <?php echo xlt('No active facilities yet. Use “+ New facility” to create one.'); ?>
          </div>
        <?php endif; ?>
        <?php foreach ($facilities as $f): ?>
          <?php
            $fid     = (int)$f['id'];
            $isPri   = (int)($f['primary_business_entity'] ?? 0) === 1;
            $isActive = $fid === $selectedId;
            $pillTone = $isPri ? 'primary' : ((int)($f['inactive'] ?? 0) === 1 ? 'neutral' : 'good');
            $pillLbl  = $isPri ? '⭐ Primary' : ((int)($f['inactive'] ?? 0) === 1 ? 'Inactive' : 'Active');
          ?>
          <a class="cp-fac-card<?php echo $isActive ? ' active' : ''; ?>"
             href="<?php echo attr($selfPath . '?id=' . $fid); ?>">
            <span class="ico">🏥</span>
            <div class="body">
              <div class="name"><?php echo text((string)$f['name']); ?></div>
              <div class="sub"><?php echo text($cardSub($f)); ?></div>
              <span class="cp-fac-pill <?php echo attr($pillTone); ?>"><?php echo text($pillLbl); ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <form class="cp-fac-form" method="POST" action="<?php echo attr($selfPath); ?>"
            data-initial-dirty="0"
            id="cp-fac-form">
        <input type="hidden" name="action" value="save_facility">
        <input type="hidden" name="id" value="<?php echo attr((string)$selectedId); ?>">
        <!-- Hidden dirty flag — JS flips this to '1' on first input change. -->
        <input type="hidden" name="dirty" id="cp-fac-dirty" value="0">

        <div class="head">
          <div class="info">
            <span class="kicker"><?php echo xlt('EDIT FACILITY'); ?></span>
            <span class="title"><?php echo text((string)($selected['name'] ?? '—')); ?></span>
          </div>
          <span class="pill" id="cp-fac-pill-dirty"><?php echo xlt('Unsaved changes'); ?></span>
          <span class="spacer"></span>
          <a class="cp-btn ghost"
             href="<?php echo attr($selfPath . ($selectedId > 0 ? '?id=' . $selectedId : '')); ?>">
            <?php echo xlt('Cancel'); ?>
          </a>
          <button type="submit" class="cp-btn primary"><?php echo xlt('Save changes'); ?></button>
        </div>

        <div class="cp-fac-body-inner">

          <?php if ($flash !== ''): ?>
            <?php
              $flashLabels = [
                'saved'   => ['ok',   'Facility saved.'],
                'created' => ['ok',   'New facility created.'],
                'missing' => ['warn', 'That facility could not be found.'],
              ];
              [$flashTone, $flashMsg] = $flashLabels[$flash] ?? ['ok', 'OK.'];
            ?>
            <div class="cp-flash <?php echo attr($flashTone === 'warn' ? 'warn' : ''); ?>">
              <?php echo text($flashMsg); ?>
            </div>
          <?php endif; ?>

          <div class="cp-fac-section">
            <div class="sec-lbl"><?php echo xlt('IDENTITY'); ?></div>
            <div class="cp-fac-grid identity-r1">
              <div class="cp-fld">
                <label><?php echo xlt('Facility name'); ?></label>
                <input class="cp-input" type="text" name="name"
                       value="<?php echo attr((string)($selected['name'] ?? '')); ?>">
              </div>
              <div class="cp-fld">
                <label><?php echo xlt('Short name / code'); ?></label>
                <!-- short_name persists to facility.facility_code (varchar(31)) -->
                <input class="cp-input" type="text" name="short_name"
                       value="<?php echo attr((string)($selected['facility_code'] ?? '')); ?>">
              </div>
            </div>
            <div class="cp-fac-grid identity-r2" style="margin-top:12px;">
              <div class="cp-fld">
                <label><?php echo xlt('NPI (organization)'); ?></label>
                <input class="cp-input" type="text" name="facility_npi"
                       value="<?php echo attr((string)($selected['facility_npi'] ?? '')); ?>">
              </div>
              <div class="cp-fld">
                <label><?php echo xlt('Type'); ?></label>
                <select class="cp-input cp-select" name="type">
                  <option value="service" <?php echo $selectedType === 'service' ? 'selected' : ''; ?>><?php echo xlt('Service location'); ?></option>
                  <option value="billing" <?php echo $selectedType === 'billing' ? 'selected' : ''; ?>><?php echo xlt('Billing location'); ?></option>
                  <option value="both"    <?php echo $selectedType === 'both'    ? 'selected' : ''; ?>><?php echo xlt('Service + Billing'); ?></option>
                </select>
              </div>
              <div class="cp-fld">
                <label><?php echo xlt('Tax ID (EIN)'); ?></label>
                <input class="cp-input" type="text" name="federal_ein"
                       value="<?php echo attr((string)($selected['federal_ein'] ?? '')); ?>">
              </div>
            </div>
          </div>

          <div class="cp-fac-section">
            <div class="sec-lbl"><?php echo xlt('ADDRESS'); ?></div>
            <div class="cp-fac-grid address-r1">
              <div class="cp-fld">
                <label><?php echo xlt('Street'); ?></label>
                <input class="cp-input" type="text" name="street"
                       value="<?php echo attr((string)($selected['street'] ?? '')); ?>">
              </div>
            </div>
            <div class="cp-fac-grid address-r2" style="margin-top:12px;">
              <div class="cp-fld">
                <label><?php echo xlt('City'); ?></label>
                <input class="cp-input" type="text" name="city"
                       value="<?php echo attr((string)($selected['city'] ?? '')); ?>">
              </div>
              <div class="cp-fld">
                <label><?php echo xlt('State'); ?></label>
                <input class="cp-input" type="text" name="state"
                       value="<?php echo attr((string)($selected['state'] ?? '')); ?>">
              </div>
              <div class="cp-fld">
                <label><?php echo xlt('ZIP'); ?></label>
                <input class="cp-input" type="text" name="postal_code"
                       value="<?php echo attr((string)($selected['postal_code'] ?? '')); ?>">
              </div>
              <div class="cp-fld">
                <label><?php echo xlt('Country'); ?></label>
                <input class="cp-input" type="text" name="country_code"
                       value="<?php echo attr((string)($selected['country_code'] ?? '')); ?>">
              </div>
            </div>
          </div>

          <div class="cp-fac-section">
            <div class="sec-lbl"><?php echo xlt('CONTACT'); ?></div>
            <div class="cp-fac-grid contact">
              <div class="cp-fld">
                <label><?php echo xlt('Phone'); ?></label>
                <input class="cp-input" type="text" name="phone"
                       value="<?php echo attr((string)($selected['phone'] ?? '')); ?>">
              </div>
              <div class="cp-fld">
                <label><?php echo xlt('Fax'); ?></label>
                <input class="cp-input" type="text" name="fax"
                       value="<?php echo attr((string)($selected['fax'] ?? '')); ?>">
              </div>
              <div class="cp-fld">
                <label><?php echo xlt('Email'); ?></label>
                <input class="cp-input" type="text" name="email"
                       value="<?php echo attr((string)($selected['email'] ?? '')); ?>">
              </div>
            </div>
          </div>

          <div class="cp-fac-grid svc cp-fac-section">
            <div>
              <div class="sec-lbl"><?php echo xlt('SERVICE HOURS'); ?></div>
              <div class="cp-svc-rows">
                <?php foreach ($dayOrder as $day): ?>
                  <?php
                    $val = (string)($selectedHours[$day] ?? $defaultHours[$day] ?? '');
                    $muted = (strcasecmp($val, 'Closed') === 0) ? ' muted' : '';
                  ?>
                  <div class="cp-svc-row">
                    <span class="day"><?php echo text($day); ?></span>
                    <input class="val<?php echo $muted; ?>" type="text"
                           name="hours[<?php echo attr($day); ?>]"
                           value="<?php echo attr($val); ?>">
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div>
              <div class="sec-lbl"><?php echo xlt('CAPABILITIES'); ?></div>
              <div class="cp-cap-list">
                <?php foreach ($capabilityCatalog as $slug => $label): ?>
                  <?php $checked = isset($selectedCapSet[$slug]); ?>
                  <label class="cp-cap-row">
                    <input type="checkbox" name="capabilities[]"
                           value="<?php echo attr($slug); ?>"
                           <?php echo $checked ? 'checked' : ''; ?>>
                    <span class="cp-cb-sm">✓</span>
                    <span class="lbl"><?php echo text($label); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

        </div>
      </form>

    </div>
  </div>
</div>

<script>
  // "Unsaved changes" pill — show whenever any form input changes.
  (function () {
    var form = document.getElementById('cp-fac-form');
    if (!form) return;
    var dirty = document.getElementById('cp-fac-dirty');
    function markDirty() {
      form.classList.add('is-dirty');
      if (dirty) dirty.value = '1';
    }
    form.addEventListener('input', markDirty, { once: true });
    form.addEventListener('change', markDirty, { once: true });
  })();
</script>

</body>
</html>
