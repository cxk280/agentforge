<?php

/**
 * Module Installer (admin) — Screen 57.
 *
 * Distinct from the patient-context Modules navtab (Screen 21).
 * Installed modules + Updates / Marketplace / Custom uploads tabs,
 * filter pills by category, 3-column card grid with per-module
 * Active/Update available pills, Settings button, and on/off toggle.
 *
 * Backend wiring (real DB):
 *   - Source table: `modules` (mod_id, mod_name, mod_directory, mod_active,
 *     mod_ui_name, mod_nick_name, mod_description, mod_type, sql_version, …)
 *   - Augmented with `openemr_modules` rows (Postnuke-style legacy entries).
 *   - Header counts: N enabled = COUNT WHERE mod_active=1; M updates available
 *     is computed locally via cp_module_has_update() (see comment — for the
 *     demo we mark a few modules as having updates by name; a real install
 *     would compare sql_version against a remote registry).
 *   - Tabs: ?tab=installed|updates|marketplace|custom
 *   - Filter pills: ?cat=all|clinical|billing|integrations|ui|custom (counts
 *     come from the same query, post-categorize).
 *   - Search: ?q= matches mod_nick_name / mod_name / mod_description.
 *   - Toggle action: POST action=toggle_module&id=&value=0|1 → UPDATE
 *     modules.mod_active and audit-log via EventAuditLogger.
 *   - "Apply N updates" header button: POST action=apply_updates → records to
 *     extended_log + flash banner. (No remote registry to talk to in the
 *     demo, so it's a logged no-op.)
 *   - "Upload .zip module" header button: links to the upstream Zend
 *     installer at /interface/modules/zend_modules/public/Installer.
 *
 * The chrome (top nav) is rendered by the parent shell — this page renders
 * only the body. Cross-patient admin page (no $pid).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

use OpenEMR\Common\Logging\EventAuditLogger;

$selfPath = $_SERVER['PHP_SELF'];

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

/**
 * Categorize a module by name/directory into one of:
 *   clinical | billing | integrations | ui | custom
 *
 * The DB doesn't carry a category column, so we infer from the module's
 * name. This is deterministic so counts and filtering agree.
 */
function cp_module_category(string $name, string $directory = ''): string
{
    $haystack = strtolower($name . ' ' . $directory);
    // Integration cues
    $integrationCues = [
        'hl7', 'fhir', 'ccda', 'ccr', 'carecoordination', 'surescripts', 'erx',
        'epcs', 'commonwell', 'hie', 'twilio', 'sms', 'registry', 'dsh', 'directmsg',
        'oauth', 'api', 'webhook', 'zoom', 'telehealth', 'syndromic',
    ];
    foreach ($integrationCues as $cue) {
        if (str_contains($haystack, $cue)) {
            return 'integrations';
        }
    }
    // Billing cues
    $billingCues = ['billing', 'claim', 'clearinghouse', '837', 'stripe', 'payment', 'invoice', 'x12'];
    foreach ($billingCues as $cue) {
        if (str_contains($haystack, $cue)) {
            return 'billing';
        }
    }
    // UI cues
    $uiCues = ['theme', 'dashboard', 'layout', 'ui', 'portal'];
    foreach ($uiCues as $cue) {
        if (str_contains($haystack, $cue)) {
            return 'ui';
        }
    }
    // Custom cues
    $customCues = ['custom_modules', 'oe-module-', 'agentforge', 'copilot'];
    foreach ($customCues as $cue) {
        if (str_contains($haystack, $cue)) {
            return 'custom';
        }
    }
    // Default: clinical
    return 'clinical';
}

/**
 * Pick a swatch color for the icon based on module name.
 * Hash → palette, so colors are stable across reloads.
 *
 * @return array{0:string,1:string} [swatch class, glyph]
 */
function cp_module_swatch(string $name): array
{
    $palette = ['teal', 'green', 'blue', 'violet', 'mint', 'orange', 'navy'];
    $h = 0;
    for ($i = 0, $n = strlen($name); $i < $n; $i++) {
        $h = ($h * 31 + ord($name[$i])) & 0x7fffffff;
    }
    $swatch = $palette[$h % count($palette)];
    // Glyph: first non-space char of the name, uppercased.
    $glyph = '?';
    $trim = trim($name);
    if ($trim !== '') {
        $glyph = strtoupper(mb_substr($trim, 0, 1));
    }
    // Two-letter glyph for a few well-known names so they stand out.
    $known = [
        'rx' => 'Rx', 'ccr' => 'CCR', 'ccda' => '@', 'hl7' => 'L',
        'pat' => 'P',
    ];
    $low = strtolower($trim);
    foreach ($known as $needle => $g) {
        if (str_starts_with($low, $needle)) {
            $glyph = $g;
            break;
        }
    }
    return [$swatch, $glyph];
}

/**
 * Decide whether a module has an update available.
 *
 * NOTE: For the demo we flag a fixed subset of modules by name. A real
 * implementation would compare modules.sql_version against a remote
 * package registry (Packagist, internal mirror, etc.).
 */
function cp_module_has_update(string $directory, string $name): bool
{
    $key = strtolower($directory . '|' . $name);
    $flagged = ['immunization', 'documents', 'carecoordination'];
    foreach ($flagged as $needle) {
        if (str_contains($key, $needle)) {
            return true;
        }
    }
    return false;
}

/**
 * Best-effort version string for a module row.
 * Falls back to "v1.0" if no version column populated.
 */
function cp_module_version(array $row): string
{
    foreach (['sql_version', 'acl_version'] as $col) {
        $v = trim((string)($row[$col] ?? ''));
        if ($v !== '' && $v !== '0') {
            return str_starts_with($v, 'v') ? $v : 'v' . $v;
        }
    }
    return 'v1.0';
}

// -------------------------------------------------------------------------
// POST handlers — run before any output, redirect on success.
// CSRF skipped — internal mock page; OpenEMR's auth gate (globals.php →
// authCheckCore()) prevents anonymous POSTs.
// -------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $audit  = EventAuditLogger::getInstance();
    $user   = (string)($_SESSION['authUser'] ?? 'admin');
    $grp    = (string)($_SESSION['authProvider'] ?? 'Default');

    if ($action === 'toggle_module') {
        $id    = (int)($_POST['id'] ?? 0);
        $value = (int)($_POST['value'] ?? 0);
        $value = $value === 1 ? 1 : 0;
        if ($id > 0) {
            $row = sqlQuery("SELECT mod_id, mod_name FROM modules WHERE mod_id = ?", [$id]);
            if ($row) {
                sqlStatement("UPDATE modules SET mod_active = ? WHERE mod_id = ?", [$value, $id]);
                try {
                    $audit->newEvent(
                        'security-administration',
                        $user,
                        $grp,
                        1,
                        'Module ' . $value . ': ' . (string)$row['mod_name']
                    );
                } catch (\Throwable $e) {
                    // audit failures should not block toggle
                }
                $msg = $value === 1 ? 'enabled' : 'disabled';
                header('Location: ' . $selfPath . '?msg=' . urlencode($msg));
                exit;
            }
        }
        header('Location: ' . $selfPath . '?msg=toggle_failed');
        exit;
    }

    if ($action === 'apply_updates') {
        // No remote registry in the demo. Log to extended_log + audit so the
        // action is honest about being queued rather than no-op silent.
        try {
            sqlStatement(
                "INSERT INTO extended_log (date, event, user, recipient, description)
                 VALUES (NOW(), ?, ?, ?, ?)",
                ['module-updates', $user, '', 'Apply updates queued from Module Installer']
            );
        } catch (\Throwable $e) {
            // best effort
        }
        try {
            $audit->newEvent('security-administration', $user, $grp, 1, 'Module installer: apply_updates queued');
        } catch (\Throwable $e) {
        }
        header('Location: ' . $selfPath . '?msg=updates_queued');
        exit;
    }

    if ($action === 'upload_zip') {
        // Out-of-scope POST: the real flow lives at the Zend Installer.
        // Logging it makes the click observable without faking a real upload.
        try {
            sqlStatement(
                "INSERT INTO extended_log (date, event, user, recipient, description)
                 VALUES (NOW(), ?, ?, ?, ?)",
                ['module-upload', $user, '', 'Upload .zip module clicked (delegated to Zend Installer)']
            );
        } catch (\Throwable $e) {
        }
        header('Location: ' . $selfPath . '?msg=upload_redirect');
        exit;
    }

    // Unknown action — fall through to GET render.
    header('Location: ' . $selfPath);
    exit;
}

// -------------------------------------------------------------------------
// GET — read filters, pull modules from DB.
// -------------------------------------------------------------------------
$tab = (string)($_GET['tab'] ?? 'installed');
$validTabs = ['installed', 'updates', 'marketplace', 'custom'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'installed';
}

$cat = (string)($_GET['cat'] ?? 'all');
$validCats = ['all', 'clinical', 'billing', 'integrations', 'ui', 'custom'];
if (!in_array($cat, $validCats, true)) {
    $cat = 'all';
}

$q = trim((string)($_GET['q'] ?? ''));

// Pull every row from `modules`. Ordered so newer/most-active rows surface
// first — primarily by mod_active DESC then by mod_name.
$allRows = [];
$rs = sqlStatement(
    "SELECT mod_id, mod_name, mod_directory, mod_active, mod_type, mod_ui_name,
            mod_nick_name, mod_description, sql_version, acl_version
       FROM modules
   ORDER BY mod_active DESC, mod_name ASC"
);
while ($r = sqlFetchArray($rs)) {
    $allRows[] = [
        'id'          => (int)$r['mod_id'],
        'name'        => trim((string)($r['mod_nick_name'] ?: ($r['mod_ui_name'] ?: $r['mod_name']))),
        'directory'   => (string)$r['mod_directory'],
        'description' => trim((string)$r['mod_description']),
        'version'     => cp_module_version($r),
        'active'      => (int)$r['mod_active'] === 1,
        'category'    => cp_module_category((string)$r['mod_name'], (string)$r['mod_directory']),
        'has_update'  => cp_module_has_update((string)$r['mod_directory'], (string)$r['mod_name']),
        'source'      => 'modules',
    ];
}

// Augment with openemr_modules (legacy Postnuke-style registry). Some sites
// have entries here but not in `modules`, so include both. Dedup by directory.
try {
    $rs2 = sqlStatement(
        "SELECT pn_id, pn_name, pn_displayname, pn_description, pn_directory,
                pn_version, pn_state
           FROM openemr_modules
       ORDER BY pn_displayname ASC"
    );
    $seen = [];
    foreach ($allRows as $row) {
        $seen[strtolower($row['directory'])] = true;
    }
    while ($r2 = sqlFetchArray($rs2)) {
        $dir = (string)$r2['pn_directory'];
        if ($dir !== '' && isset($seen[strtolower($dir)])) {
            continue;
        }
        $name = trim((string)($r2['pn_displayname'] ?: $r2['pn_name']));
        $allRows[] = [
            'id'          => 'pn-' . (int)$r2['pn_id'], // distinct id space; not toggleable here
            'name'        => $name,
            'directory'   => $dir,
            'description' => trim((string)$r2['pn_description']),
            'version'     => trim((string)$r2['pn_version']) !== '' ? 'v' . $r2['pn_version'] : 'v1.0',
            // pn_state: 3 = active in PostNuke convention.
            'active'      => (int)$r2['pn_state'] === 3,
            'category'    => cp_module_category($name, $dir),
            'has_update'  => cp_module_has_update($dir, $name),
            'source'      => 'openemr_modules',
        ];
    }
} catch (\Throwable $e) {
    // openemr_modules is optional — ignore if absent.
}

// Counts (header) — only `modules` rows count toward enabled because
// openemr_modules is a parallel registry.
$enabledCount = 0;
foreach ($allRows as $row) {
    if ($row['active'] && $row['source'] === 'modules') {
        $enabledCount++;
    }
}
$updatesAvailableCount = 0;
foreach ($allRows as $row) {
    if ($row['has_update']) {
        $updatesAvailableCount++;
    }
}

// Per-category counts for the pill row (computed across the *tab*-filtered
// set so the pill counts agree with the visible cards).
$tabFiltered = array_values(array_filter($allRows, function (array $row) use ($tab) {
    return match ($tab) {
        'updates'     => $row['has_update'],
        'custom'      => $row['category'] === 'custom' || $row['source'] === 'openemr_modules',
        'marketplace' => false, // no remote catalog wired
        default       => true,  // installed shows everything
    };
}));

$catCounts = [
    'all'          => count($tabFiltered),
    'clinical'     => 0,
    'billing'      => 0,
    'integrations' => 0,
    'ui'           => 0,
    'custom'       => 0,
];
foreach ($tabFiltered as $row) {
    if (isset($catCounts[$row['category']])) {
        $catCounts[$row['category']]++;
    }
}

// Apply category + search filters to produce the visible card list.
$visible = array_values(array_filter($tabFiltered, function (array $row) use ($cat, $q) {
    if ($cat !== 'all' && $row['category'] !== $cat) {
        return false;
    }
    if ($q !== '') {
        $hay = strtolower($row['name'] . ' ' . $row['directory'] . ' ' . $row['description']);
        if (!str_contains($hay, strtolower($q))) {
            return false;
        }
    }
    return true;
}));

// Tab counts.
$tabCounts = [
    'installed' => count($allRows),
    'updates'   => $updatesAvailableCount,
    'marketplace' => null, // no count for marketplace (remote)
    'custom'    => 0,
];
foreach ($allRows as $row) {
    if ($row['category'] === 'custom' || $row['source'] === 'openemr_modules') {
        $tabCounts['custom']++;
    }
}

$tabs = [
    ['installed',   xl('Installed'),      $tabCounts['installed']],
    ['updates',     xl('Updates'),        $tabCounts['updates']],
    ['marketplace', xl('Marketplace'),    $tabCounts['marketplace']],
    ['custom',      xl('Custom uploads'), $tabCounts['custom']],
];

$filters = [
    ['all',          xl('All'),          $catCounts['all']],
    ['clinical',     xl('Clinical'),     $catCounts['clinical']],
    ['billing',      xl('Billing'),      $catCounts['billing']],
    ['integrations', xl('Integrations'), $catCounts['integrations']],
    ['ui',           xl('UI'),           $catCounts['ui']],
    ['custom',       xl('Custom'),       $catCounts['custom']],
];

// Flash banner.
$msg = (string)($_GET['msg'] ?? '');
$flash = '';
$flashClass = '';
switch ($msg) {
    case 'enabled':
        $flash = xl('Module enabled');
        break;
    case 'disabled':
        $flash = xl('Module disabled');
        break;
    case 'toggle_failed':
        $flash = xl('Could not toggle module');
        $flashClass = 'err';
        break;
    case 'updates_queued':
        $flash = xl('Updates queued · check the audit log for status');
        break;
    case 'upload_redirect':
        $flash = xl('Opening Module Installer …');
        break;
    default:
        // no-op
}

$zendInstallerUrl = $GLOBALS['webroot'] . '/interface/modules/zend_modules/public/Installer';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Modules'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header — dot separator + secondary meta tone */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .info .meta { color: #4F5763; }

  /* Flash banner */
  .cp-flash {
    margin: 12px 24px 0;
    padding: 8px 14px;
    background: #E6F4EE;
    border: 1px solid #B7DCC4;
    border-radius: 8px;
    color: #1F8C4D;
    font-size: 12px; font-weight: 500;
  }
  .cp-flash.err { background: #FCE7E7; color: #D93838; border-color: #F5C2C2; }

  /* Tabs strip */
  .mod-tabs {
    display: flex; gap: 28px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 0 24px;
  }
  .mod-tabs a, .mod-tabs button {
    appearance: none; background: transparent; border: 0;
    padding: 14px 0;
    font-size: 13px; font-weight: 500;
    color: #4F5763;
    border-bottom: 2px solid transparent;
    line-height: 1.2;
    cursor: pointer;
    text-decoration: none;
  }
  .mod-tabs a.active, .mod-tabs button.active {
    color: #008C8C; font-weight: 600;
    border-bottom-color: #008C8C;
  }
  .mod-tabs a .ct, .mod-tabs button .ct {
    color: inherit;
    margin-left: 4px;
  }

  /* Filter strip — search left, pills with count badges */
  .mod-filter {
    display: flex; align-items: center; gap: 12px;
    flex-wrap: wrap;
  }
  .mod-filter .search {
    flex: 0 0 280px;
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 7px 14px;
    display: inline-flex; align-items: center; gap: 8px;
    height: 32px;
  }
  .mod-filter .search .ic { color: #8A91A1; font-size: 12px; }
  .mod-filter .search input {
    border: none; background: transparent; outline: none;
    flex: 1; font-size: 12px; color: #0D1B2A;
  }
  .mod-filter .search input::placeholder { color: #8A91A1; }
  .mod-filter .pills { display: flex; gap: 8px; flex-wrap: wrap; }
  .mod-filter .pills a, .mod-filter .pills button {
    appearance: none; cursor: pointer;
    border-radius: 999px;
    height: 32px;
    padding: 0 14px;
    font-size: 12px; font-weight: 500;
    line-height: 1;
    background: #FFFFFF;
    color: #4F5763;
    border: 1px solid #E4E5E8;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none;
  }
  .mod-filter .pills a.active, .mod-filter .pills button.active {
    background: #FFFFFF; color: #008C8C;
    border-color: #008C8C; font-weight: 600;
  }
  .mod-filter .pills a .ct, .mod-filter .pills button .ct {
    background: #F5F6F7; color: #4F5763;
    padding: 2px 8px; border-radius: 999px;
    font-size: 10px; font-weight: 600;
  }
  .mod-filter .pills a.active .ct, .mod-filter .pills button.active .ct {
    background: #008C8C; color: #FFFFFF;
  }

  /* Empty state */
  .mod-empty {
    background: #FFFFFF;
    border: 1px dashed #E4E5E8;
    border-radius: 12px;
    padding: 36px;
    text-align: center;
    color: #4F5763;
    font-size: 13px;
  }
  .mod-empty .h { font-weight: 600; color: #0D1B2A; margin-bottom: 6px; }

  /* Card grid */
  .mod-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
  }
  .mod-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 18px 18px;
    display: flex; flex-direction: column; gap: 10px;
  }
  .mod-card .head {
    display: flex; align-items: flex-start; gap: 12px;
  }
  .mod-card .ic {
    width: 40px; height: 40px;
    border-radius: 10px;
    flex: 0 0 auto;
    display: inline-flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-weight: 700; font-size: 14px;
    letter-spacing: 0.2px;
  }
  .mod-card .ic.teal   { background: #008C8C; }
  .mod-card .ic.green  { background: #33A666; }
  .mod-card .ic.blue   { background: #4785D9; }
  .mod-card .ic.violet { background: #8561C7; }
  .mod-card .ic.mint   { background: #33A68C; }
  .mod-card .ic.orange { background: #FA8C33; }
  .mod-card .ic.navy   { background: #0D1B2A; }
  .mod-card .meta { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
  .mod-card .meta .name {
    font-size: 14px; font-weight: 700;
    color: #0D1B2A; line-height: 1.2;
  }
  .mod-card .meta .sub {
    font-size: 11px; color: #8A91A1; line-height: 1.2;
  }
  .mod-card .desc {
    font-size: 12px; color: #4F5763;
    line-height: 1.45;
    margin: 2px 0 4px;
  }
  .mod-card .foot {
    display: flex; align-items: center; gap: 8px;
    margin-top: 2px;
  }
  .mod-card .foot .pillrow { display: inline-flex; gap: 6px; align-items: center; }
  .mod-card .foot .spacer { flex: 1; }
  .mod-card .foot .cp-btn.ghost {
    height: 28px; padding: 0 12px; font-size: 11px;
  }

  /* Status pill with leading check glyph */
  .cp-status-pill.good::before {
    content: '\2713';
    margin-right: 4px;
    font-weight: 700;
  }

  /* Toggle button (form submit, but styled as a pill switch) */
  .mod-toggle-form { display: inline; margin: 0; padding: 0; }
  .mod-toggle {
    width: 38px; height: 22px;
    background: #008C8C;
    border-radius: 999px;
    position: relative;
    flex: 0 0 auto;
    cursor: pointer;
    border: 0;
    padding: 0;
    display: inline-block;
  }
  .mod-toggle.off { background: #C7CBD2; }
  .mod-toggle::after {
    content: '';
    position: absolute;
    top: 2px; left: 18px;
    width: 18px; height: 18px;
    background: #FFFFFF;
    border-radius: 50%;
    box-shadow: 0 1px 2px rgba(0,0,0,0.18);
  }
  .mod-toggle.off::after { left: 2px; }
  .mod-toggle:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:baseline; gap:10px;">
      <span class="title"><?php echo xlt('Modules'); ?></span>
      <span class="meta">
        <?php echo xlt('Manage installed modules and integrations'); ?>
        &middot;
        <?php echo text(sprintf(xl('%d enabled, %d updates available'), $enabledCount, $updatesAvailableCount)); ?>
      </span>
    </div>
  </div>
  <form method="post" action="<?php echo attr($selfPath); ?>" style="display:inline; margin:0;">
    <input type="hidden" name="action" value="upload_zip">
    <button type="submit" class="cp-btn ghost"
            onclick="window.open(<?php echo "'" . attr($zendInstallerUrl) . "'"; ?>, '_blank'); return true;">
      <?php echo xlt('Upload .zip module'); ?>
    </button>
  </form>
  <form method="post" action="<?php echo attr($selfPath); ?>" style="display:inline; margin:0;">
    <input type="hidden" name="action" value="apply_updates">
    <button type="submit" class="cp-btn primary"
            <?php echo $updatesAvailableCount === 0 ? 'disabled title="' . attr(xl('No updates pending')) . '"' : ''; ?>>
      <?php echo text(sprintf(xl('Apply %d updates'), $updatesAvailableCount)); ?>
    </button>
  </form>
</header>

<?php if ($flash !== ''): ?>
  <div class="cp-flash <?php echo attr($flashClass); ?>"><?php echo text($flash); ?></div>
<?php endif; ?>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('module_installer'); ?>

  <main class="cp-content tight" style="padding:0; gap:0;">

    <nav class="mod-tabs">
      <?php foreach ($tabs as [$key, $label, $count]): ?>
        <?php $isActive = ($key === $tab); ?>
        <a href="<?php echo attr($selfPath . '?tab=' . urlencode($key)); ?>"
           class="<?php echo $isActive ? 'active' : ''; ?>">
          <?php echo text($label); ?><?php if ($count !== null): ?> <span class="ct">(<?php echo (int) $count; ?>)</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div style="padding: 16px 24px 24px; display:flex; flex-direction:column; gap:16px;">

      <form class="mod-filter" method="get" action="<?php echo attr($selfPath); ?>">
        <input type="hidden" name="tab" value="<?php echo attr($tab); ?>">
        <input type="hidden" name="cat" value="<?php echo attr($cat); ?>">
        <div class="search">
          <span class="ic">&#128269;</span>
          <input type="text" name="q" value="<?php echo attr($q); ?>"
                 placeholder="<?php echo attr(xl('Search modules')); ?>"
                 onchange="this.form.submit()">
        </div>
        <div class="pills">
          <?php foreach ($filters as [$key, $label, $count]): ?>
            <?php
            $isActive = ($key === $cat);
            $params = ['tab' => $tab, 'cat' => $key];
            if ($q !== '') {
                $params['q'] = $q;
            }
            $href = $selfPath . '?' . http_build_query($params);
            ?>
            <a href="<?php echo attr($href); ?>"
               class="<?php echo $isActive ? 'active' : ''; ?>">
              <?php echo text($label); ?>
              <span class="ct"><?php echo (int) $count; ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </form>

      <?php if ($tab === 'marketplace'): ?>
        <div class="mod-empty">
          <div class="h"><?php echo xlt('Marketplace coming soon'); ?></div>
          <?php echo xlt('A curated catalog of community modules will appear here. For now, install via .zip upload.'); ?>
        </div>
      <?php elseif (count($visible) === 0): ?>
        <div class="mod-empty">
          <div class="h"><?php echo xlt('No modules match these filters'); ?></div>
          <?php echo xlt('Try a different category, clear the search, or switch tabs.'); ?>
        </div>
      <?php else: ?>
        <div class="mod-grid">
          <?php foreach ($visible as $m): ?>
            <?php
            [$swatch, $glyph] = cp_module_swatch($m['name']);
            $catLabel = ucfirst($m['category']);
            $desc = $m['description'] !== ''
                ? $m['description']
                // Static fallback when DB has no description.
                : sprintf(xl('%s module — installed in %s.'), $m['name'], $m['directory']);
            $isToggleable = is_int($m['id']);
            $nextValue = $m['active'] ? 0 : 1;
            ?>
            <div class="mod-card">
              <div class="head">
                <span class="ic <?php echo attr($swatch); ?>"><?php echo text($glyph); ?></span>
                <div class="meta">
                  <span class="name"><?php echo text($m['name']); ?></span>
                  <span class="sub">
                    <?php echo text($m['version']); ?>
                    &middot;
                    <?php echo text($catLabel); ?>
                  </span>
                </div>
              </div>
              <p class="desc"><?php echo text($desc); ?></p>
              <div class="foot">
                <div class="pillrow">
                  <?php if ($m['active']): ?>
                    <span class="cp-status-pill good"><?php echo xlt('Active'); ?></span>
                  <?php else: ?>
                    <span class="cp-status-pill neutral"><?php echo xlt('Disabled'); ?></span>
                  <?php endif; ?>
                  <?php if ($m['has_update']): ?>
                    <span class="cp-status-pill warn"><?php echo xlt('Update available'); ?></span>
                  <?php endif; ?>
                </div>
                <span class="spacer"></span>
                <a class="cp-btn ghost" href="<?php echo attr($zendInstallerUrl); ?>" target="_blank" rel="noopener">
                  <?php echo xlt('Settings'); ?>
                </a>
                <?php if ($isToggleable): ?>
                  <form method="post" action="<?php echo attr($selfPath); ?>" class="mod-toggle-form">
                    <input type="hidden" name="action" value="toggle_module">
                    <input type="hidden" name="id" value="<?php echo attr((string)$m['id']); ?>">
                    <input type="hidden" name="value" value="<?php echo attr((string)$nextValue); ?>">
                    <button type="submit"
                            class="mod-toggle<?php echo $m['active'] ? '' : ' off'; ?>"
                            aria-label="<?php echo attr(xl('Toggle module')); ?>"
                            title="<?php echo attr($m['active'] ? xl('Disable') : xl('Enable')); ?>"></button>
                  </form>
                <?php else: ?>
                  <span class="mod-toggle<?php echo $m['active'] ? '' : ' off'; ?>"
                        aria-label="<?php echo attr(xl('Legacy module — toggle in Zend Installer')); ?>"
                        title="<?php echo attr(xl('Legacy module — toggle in Zend Installer')); ?>"></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>

  </main>
</div>

</body>
</html>
