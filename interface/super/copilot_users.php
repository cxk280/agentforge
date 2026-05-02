<?php

/**
 * Users & Groups — Screen 52, AgentForge admin sub-page archetype.
 *
 * Admin → Users & Access → Users & Groups. Renders a left rail of admin
 * categories, a header with action buttons, the Users / Groups / Service
 * accounts / Pending invites tab strip, a filter row, and a paginated
 * user table with avatar, name, username, email, role, group memberships,
 * MFA status and last login.
 *
 * Cross-patient — no $pid scope.
 *
 * Data sources:
 *   - `users`            — primary list (one row per account).
 *   - `groups`           — legacy phpGACL denormalized table; one row per
 *                           (group_name, username) pair. We aggregate by
 *                           name to produce the "8 groups" count and the
 *                           per-user GROUPS column.
 *   - `login_mfa_registrations` — presence => MFA on.
 *   - `log` (event='login')      — most recent login per user, used for
 *                                  the LAST LOGIN column.
 *   - `list_options` (abook_type)— reserved for the role inference, but
 *                                  most accounts in the seed have an empty
 *                                  abook_type so role is derived from
 *                                  authorized + title.
 *
 * Filters (GET):
 *   - tab=users|groups|services|invites — top tab strip
 *   - q=<search>              — name / username / email LIKE
 *   - role=any|provider|nurse|fd|billing|admin
 *   - group=<group_name>      — exact group membership match
 *   - status=active|inactive|all
 *   - mfa=any|on|off
 *
 * POST handlers (CSRF skipped — internal mock page):
 *   - action=new_group         — INSERT INTO `groups` (name, user='admin')
 *
 * The chrome (top nav) is rendered by the parent shell; this file
 * renders only the body.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

// --------------------------------------------------------------------
// Service-account heuristic. Service accounts are non-human accounts
// used for integrations / system tasks — we mark them by username
// pattern (no `is_service` column exists in the OpenEMR schema).
// --------------------------------------------------------------------
const CP_USERS_SERVICE_LIKE_SQL =
    "(u.username LIKE 'svc%' "
    . "OR u.username LIKE '%\\_svc' "
    . "OR u.username LIKE '%-service' "
    . "OR u.username LIKE '%-svc' "
    . "OR u.username = 'oe-system' "
    . "OR u.username = 'portal-user' "
    . "OR u.username = 'phimail-service')";

// Synthetic accounts we never want surfaced as "active human users"
// in the header counts.
const CP_USERS_HIDDEN_FROM_ACTIVE = ['admin', 'phimail-service', 'portal-user', 'oe-system'];

/**
 * Map a users row to the role label we render in the ROLE column.
 * Ordering matters — service accounts win first, then provider-class
 * titles, then nurse, then a small set of common non-clinical roles
 * inferred from username.
 */
function cp_users_role_for(array $u): string
{
    $username = strtolower((string)($u['username'] ?? ''));
    $title    = strtoupper(trim((string)($u['title'] ?? '')));
    $auth     = (int)($u['authorized'] ?? 0);

    // Service accounts.
    if (
        str_starts_with($username, 'svc')
        || str_ends_with($username, '_svc')
        || str_ends_with($username, '-svc')
        || str_ends_with($username, '-service')
        || $username === 'oe-system'
        || $username === 'portal-user'
        || $username === 'phimail-service'
    ) {
        return 'Service account';
    }

    // Doctoral providers.
    if (in_array($title, ['MD', 'DO', 'DDS', 'DMD', 'DPM', 'DC', 'OD', 'PHD'], true)) {
        return 'Provider';
    }
    if ($title === 'NP') { return 'Provider (NP)'; }
    if ($title === 'PA') { return 'Provider (PA)'; }
    if ($title === 'RN' || $title === 'LPN') { return 'Nurse'; }

    // Authorized but no doctoral title → still a clinician.
    if ($auth === 1) { return 'Clinician'; }

    // Username heuristics for non-clinical staff.
    if (str_contains($username, 'bill')) { return 'Billing'; }
    if ($username === 'admin' || str_contains($username, 'admin')) { return 'IT Admin'; }
    if (str_contains($username, 'recept') || str_contains($username, 'desk')) { return 'Front desk'; }

    return 'Staff';
}

// --------------------------------------------------------------------
// POST: new_group  — INSERT into `groups` table.
// CSRF skipped — internal mock page.
// --------------------------------------------------------------------
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'new_group'
) {
    $newGroup = trim((string)($_POST['name'] ?? ''));
    if ($newGroup === '') {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=group_empty');
        exit;
    }
    try {
        // The legacy phpGACL `groups` table is denormalized: one row per
        // (group_name, username) pair. Creating a new empty group is
        // represented by inserting a single (name, 'admin') row — admin
        // is always present and acts as the seed member. The Edit-group
        // UI (out of scope here) is what would add other members.
        $existing = sqlQuery(
            "SELECT id FROM `groups` WHERE name = ? LIMIT 1",
            [$newGroup]
        );
        if ($existing && !empty($existing['id'])) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=groups&msg=group_exists');
            exit;
        }
        sqlStatement(
            "INSERT INTO `groups` SET name = ?, user = ?",
            [$newGroup, 'admin']
        );
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=groups&msg=group_created');
        exit;
    } catch (\Throwable $e) {
        // Don't expose the message to the user.
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=groups&msg=group_failed');
        exit;
    }
}

// --------------------------------------------------------------------
// GET filters
// --------------------------------------------------------------------
$validTabs = ['users', 'groups', 'services', 'invites'];
$tab = (string)($_GET['tab'] ?? 'users');
if (!in_array($tab, $validTabs, true)) { $tab = 'users'; }

$q = trim((string)($_GET['q'] ?? ''));

$validRoles = ['any', 'provider', 'nurse', 'fd', 'billing', 'admin'];
$roleFilter = (string)($_GET['role'] ?? 'any');
if (!in_array($roleFilter, $validRoles, true)) { $roleFilter = 'any'; }

$groupFilter = trim((string)($_GET['group'] ?? ''));

$validStatus = ['active', 'inactive', 'all'];
// Default status = "active" on the human-user tab, "all" on Services
// (OpenEMR ships service accounts with active=0 by default).
$defaultStatus = ($tab === 'services') ? 'all' : 'active';
$statusFilter = (string)($_GET['status'] ?? $defaultStatus);
if (!in_array($statusFilter, $validStatus, true)) { $statusFilter = $defaultStatus; }

$validMfa = ['any', 'on', 'off'];
$mfaFilter = (string)($_GET['mfa'] ?? 'any');
if (!in_array($mfaFilter, $validMfa, true)) { $mfaFilter = 'any'; }

$flash = (string)($_GET['msg'] ?? '');

// --------------------------------------------------------------------
// Header counts. Always reflect the full DB, not the current filter.
// --------------------------------------------------------------------
$activeCount = 0;
$groupsCount = 0;
$serviceCount = 0;
try {
    $hiddenPlaceholders = implode(',', array_fill(0, count(CP_USERS_HIDDEN_FROM_ACTIVE), '?'));
    $row = sqlQuery(
        "SELECT COUNT(*) AS n FROM users u "
        . "WHERE u.active = 1 "
        . "  AND u.username NOT IN ($hiddenPlaceholders) "
        . "  AND NOT " . CP_USERS_SERVICE_LIKE_SQL,
        CP_USERS_HIDDEN_FROM_ACTIVE
    );
    $activeCount = (int)($row['n'] ?? 0);

    $row = sqlQuery("SELECT COUNT(DISTINCT name) AS n FROM `groups`");
    $groupsCount = (int)($row['n'] ?? 0);

    $row = sqlQuery(
        "SELECT COUNT(*) AS n FROM users u WHERE " . CP_USERS_SERVICE_LIKE_SQL
    );
    $serviceCount = (int)($row['n'] ?? 0);
} catch (\Throwable $e) {
    // counts default to 0 — page still renders
}

// --------------------------------------------------------------------
// Group list (for the GROUP filter dropdown and the Groups tab body).
// --------------------------------------------------------------------
$allGroups = [];
try {
    $gr = sqlStatement(
        "SELECT name, COUNT(DISTINCT user) AS member_count "
        . "FROM `groups` "
        . "GROUP BY name "
        . "ORDER BY name ASC"
    );
    while ($row = sqlFetchArray($gr)) {
        $allGroups[] = [
            'name'    => (string)($row['name'] ?? ''),
            'members' => (int)($row['member_count'] ?? 0),
        ];
    }
} catch (\Throwable $e) {
    $allGroups = [];
}

// --------------------------------------------------------------------
// Build the user-list query for the active tab.
// --------------------------------------------------------------------
$where  = ['1=1'];
$params = [];

// Default: hide the synthetic non-human "admin shell" accounts unless
// the operator is on the Service-accounts tab and explicitly wants them.
if ($tab === 'services') {
    $where[] = CP_USERS_SERVICE_LIKE_SQL;
} elseif ($tab === 'users') {
    // Real human users — exclude obvious service / system stubs.
    $where[] = 'NOT ' . CP_USERS_SERVICE_LIKE_SQL;
    $hiddenPlaceholders = implode(',', array_fill(0, count(CP_USERS_HIDDEN_FROM_ACTIVE), '?'));
    $where[] = "u.username NOT IN ($hiddenPlaceholders)";
    foreach (CP_USERS_HIDDEN_FROM_ACTIVE as $hu) { $params[] = $hu; }
}

// Status filter
if ($statusFilter === 'active') {
    $where[] = 'u.active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'u.active = 0';
}

// Role filter
switch ($roleFilter) {
    case 'provider':
        $where[] = "(UPPER(IFNULL(u.title,'')) IN ('MD','DO','DDS','DMD','DPM','DC','OD','PHD','NP','PA') OR u.authorized = 1)";
        break;
    case 'nurse':
        $where[] = "UPPER(IFNULL(u.title,'')) IN ('RN','LPN')";
        break;
    case 'fd':
        $where[] = "(LOWER(u.username) LIKE '%recept%' OR LOWER(u.username) LIKE '%desk%')";
        break;
    case 'billing':
        $where[] = "LOWER(u.username) LIKE '%bill%'";
        break;
    case 'admin':
        $where[] = "(u.username = 'admin' OR LOWER(u.username) LIKE '%admin%')";
        break;
}

// Search box
if ($q !== '') {
    $where[] = '(u.fname LIKE ? OR u.lname LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR CONCAT(u.fname, " ", u.lname) LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

// Group membership filter — exact group name match.
if ($groupFilter !== '') {
    $where[] = "EXISTS (SELECT 1 FROM `groups` g WHERE g.user = u.username AND g.name = ?)";
    $params[] = $groupFilter;
}

// MFA filter
if ($mfaFilter === 'on') {
    $where[] = "EXISTS (SELECT 1 FROM login_mfa_registrations m WHERE m.user_id = u.id)";
} elseif ($mfaFilter === 'off') {
    $where[] = "NOT EXISTS (SELECT 1 FROM login_mfa_registrations m WHERE m.user_id = u.id)";
}

$whereSql = implode(' AND ', $where);

// Compose the row query. Subselects keep the result set one row per
// user no matter how many group rows / login rows exist.
$userSql = "
    SELECT u.id,
           u.username,
           u.fname, u.mname, u.lname,
           u.title,
           u.email,
           u.authorized,
           u.active,
           u.abook_type,
           (SELECT GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ', ')
              FROM `groups` g WHERE g.user = u.username)               AS group_names,
           (SELECT COUNT(*) FROM login_mfa_registrations m
             WHERE m.user_id = u.id)                                    AS mfa_count,
           (SELECT MAX(l.date) FROM log l
             WHERE l.event = 'login' AND l.user = u.username)           AS last_login_at
      FROM users u
     WHERE $whereSql
     ORDER BY u.active DESC, u.lname ASC, u.fname ASC, u.username ASC
     LIMIT 200
";

$users = [];
try {
    $rs = sqlStatement($userSql, $params);
    while ($r = sqlFetchArray($rs)) {
        $users[] = $r;
    }
} catch (\Throwable $e) {
    $users = [];
}

/**
 * Pretty-format a "last login" datetime as a human relative phrase.
 * NULL → "Never". Returns a short string suitable for the table cell.
 */
function cp_users_relative_login(?string $iso): string
{
    if ($iso === null || $iso === '' || $iso === '0000-00-00 00:00:00') {
        return 'Never';
    }
    $ts = strtotime($iso);
    if ($ts === false) { return 'Never'; }
    $delta = time() - $ts;
    if ($delta < 60)             { return 'Just now'; }
    if ($delta < 60 * 60)        { return (int)floor($delta / 60) . ' min ago'; }
    if ($delta < 60 * 60 * 24)   { return (int)floor($delta / 3600) . 'h ago'; }
    if ($delta < 60 * 60 * 24 * 7) {
        $d = (int)floor($delta / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y', $ts);
}

/**
 * Decide which mini-pill (if any) belongs on the LAST LOGIN cell.
 * Returns 'svc' | 'inactive' | 'warn' | ''.
 */
function cp_users_badge_for(array $u, string $role): string
{
    if ($role === 'Service account') { return 'svc'; }
    if ((int)($u['active'] ?? 1) === 0) { return 'inactive'; }
    // "warn" — MFA off + a clinician role. Mock convention.
    $isClinician = in_array($role, ['Provider', 'Provider (NP)', 'Provider (PA)', 'Nurse', 'Clinician'], true);
    if ($isClinician && (int)($u['mfa_count'] ?? 0) === 0) { return 'warn'; }
    return '';
}

// Build a list of groups for the GROUP dropdown — distinct by name,
// already loaded above as $allGroups.

// Left rail — admin nav.
$sidebar = [
    'Users & Access' => [
        ['Users & Groups',     true],
        ['ACL Editor',         false],
        ['Active Sessions',    false],
        ['Password Policy',    false],
    ],
    'Practice' => [
        ['Facilities',         false],
        ['Providers',          false],
        ['Schedule Templates', false],
        ['Pricing',            false],
    ],
    'Clinical' => [
        ['Forms',              false],
        ['Lists',              false],
        ['Templates',          false],
        ['Issue Types',        false],
        ['Layouts',            false],
    ],
    'System' => [
        ['Audit Log',          false],
        ['Backup',             false],
        ['Globals',            false],
        ['Database',           false],
        ['Modules',            false],
    ],
];

// Tab definitions.
$tabs = [
    'users'    => ['Users',            null],
    'groups'   => ['Groups',           null],
    'services' => ['Service accounts', null],
    'invites'  => ['Pending invites',  null],
];

// Helper to build a query string for tab/filter links — preserve
// orthogonal filter state when switching tabs, drop the filters that
// don't apply to the new tab.
$buildLink = function (array $overrides) use ($tab, $q, $roleFilter, $groupFilter, $statusFilter, $mfaFilter): string {
    $base = [
        'tab'    => $tab,
        'q'      => $q,
        'role'   => $roleFilter,
        'group'  => $groupFilter,
        'status' => $statusFilter,
        'mfa'    => $mfaFilter,
    ];
    foreach ($overrides as $k => $v) { $base[$k] = $v; }
    // Strip empties to keep URLs clean.
    // Each filter has a "default" we strip from the URL to keep it tidy.
    // The default for "status" depends on which tab we're switching TO,
    // not which tab we're on — so peek at the override.
    $newTab = $overrides['tab'] ?? $tab;
    $statusDefault = ($newTab === 'services') ? 'all' : 'active';
    foreach ($base as $k => $v) {
        if (
            $v === '' || $v === null
            || ($k === 'role'   && $v === 'any')
            || ($k === 'mfa'    && $v === 'any')
            || ($k === 'status' && $v === $statusDefault)
        ) {
            unset($base[$k]);
        }
    }
    return $_SERVER['PHP_SELF'] . '?' . http_build_query($base);
};

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Users & Groups'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Admin left rail — grouped categories like the reports sidebar */
  .cp-adm-side {
    flex: 0 0 200px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 16px 0 24px;
    overflow-y: auto;
  }
  .cp-adm-side .header {
    font-size: 10px; font-weight: 700;
    color: #8A91A1; letter-spacing: 0.7px;
    padding: 6px 20px 10px;
  }
  .cp-adm-side .grp { margin-bottom: 12px; }
  .cp-adm-side .grp .lbl {
    font-size: 11px; font-weight: 600;
    color: #4F5763;
    padding: 8px 20px 4px;
    line-height: 1.2;
  }
  .cp-adm-side .item {
    display: block;
    padding: 7px 20px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    text-decoration: none;
    line-height: 1.3;
    position: relative;
  }
  .cp-adm-side .item:hover { background: #F5F6F7; color: #0D1B2A; }
  .cp-adm-side .item.active {
    color: #008C8C; font-weight: 600;
    background: rgba(0,140,140,0.08);
  }
  .cp-adm-side .item.active::before {
    content: ''; position: absolute;
    left: 0; top: 0; bottom: 0; width: 3px; background: #008C8C;
  }

  /* Page head — meta dot separator */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 2px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }

  /* Header buttons — reset native button defaults, link styles for forms */
  .cp-pagehead form { display: inline-flex; margin: 0; }
  .cp-pagehead .cp-btn { font-family: inherit; }
  .cp-pagehead a.cp-btn { text-decoration: none; }

  /* Flash banner */
  .cp-flash {
    margin: 12px 24px 0;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 12px; line-height: 1.4;
    border: 1px solid #C6E5C8;
    background: #E7F5E8; color: #1F8C4D;
  }
  .cp-flash.err { background: #FCE7E7; color: #D93838; border-color: #F5C2C2; }
  .cp-flash.warn { background: #FFF4EA; color: #B25E11; border-color: #FAD0A6; }

  /* Tab strip below page header */
  .cp-tabs {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 0 24px;
    display: flex;
    gap: 24px;
    flex: 0 0 auto;
  }
  .cp-tabs a {
    display: inline-block;
    padding: 12px 0 14px;
    font-size: 13px; font-weight: 500;
    color: #4F5763;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    line-height: 1.2;
  }
  .cp-tabs a:hover { color: #0D1B2A; }
  .cp-tabs a.active {
    color: #008C8C; font-weight: 600;
    border-bottom-color: #008C8C;
  }

  /* Filter row — search input + 4 dropdowns */
  .cp-users-filter {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 12px;
  }
  .cp-users-filter .search {
    flex: 1 1 360px;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 34px;
    padding: 0 12px;
    display: flex; align-items: center; gap: 8px;
  }
  .cp-users-filter .search .ic { color: #8A91A1; font-size: 12px; }
  .cp-users-filter .search input {
    border: none; background: transparent; outline: none;
    flex: 1; font-size: 12px; color: #0D1B2A;
  }
  .cp-users-filter .search input::placeholder { color: #8A91A1; }
  .cp-users-filter .dd {
    flex: 0 0 130px;
    height: 34px;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 0 28px 0 12px;
    font-size: 12px; color: #0D1B2A;
    line-height: 32px;
    appearance: none; -webkit-appearance: none;
    background-image:
      linear-gradient(45deg, transparent 50%, #8A91A1 50%),
      linear-gradient(135deg, #8A91A1 50%, transparent 50%);
    background-position:
      calc(100% - 14px) calc(50% - 1px),
      calc(100% - 9px) calc(50% - 1px);
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat;
  }
  .cp-users-filter .reset {
    font-size: 11px; color: #4F5763; text-decoration: none;
    padding: 0 4px;
  }
  .cp-users-filter .reset:hover { color: #0D1B2A; }

  /* User table tweaks */
  .cp-users-tbl table { font-size: 12px; }
  .cp-users-tbl th { padding: 12px 14px; }
  .cp-users-tbl td { padding: 11px 14px; vertical-align: middle; }
  .cp-users-tbl td.chk { width: 28px; padding-left: 18px; padding-right: 0; }
  .cp-users-tbl th.chk  { width: 28px; padding-left: 18px; padding-right: 0; }
  .cp-users-tbl td.chk input,
  .cp-users-tbl th.chk input {
    width: 14px; height: 14px;
    accent-color: #008C8C;
    margin: 0; vertical-align: middle;
  }

  .cp-user-cell { display: inline-flex; align-items: center; gap: 10px; }
  .cp-user-cell .name { font-weight: 600; color: #0D1B2A; font-size: 12px; }
  .cp-avatar.neutral {
    background: #C7CBD2;
    color: #FFFFFF;
    font-size: 10px;
    width: 28px; height: 28px;
  }

  .cp-mfa-on  { color: #1F8C4D; font-weight: 700; font-size: 14px; }
  .cp-mfa-off { color: #8A91A1; font-weight: 400; }
  .cp-mfa-warn { color: #FA8C33; font-size: 14px; line-height: 1; }

  .cp-mini-pill {
    display: inline-block;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.3px;
    line-height: 1.4;
    margin-left: 6px;
  }
  .cp-mini-pill.svc      { background: #F0EBFA; color: #8561C7; }
  .cp-mini-pill.inactive { background: #F5F6F7; color: #8A91A1; }

  .cp-tbl td .lastlogin-row { display: inline-flex; align-items: center; gap: 4px; }

  /* Empty state */
  .cp-users-empty {
    background: #FFFFFF;
    border: 1px dashed #E4E5E8;
    border-radius: 8px;
    padding: 32px 24px;
    text-align: center;
    color: #4F5763;
    font-size: 13px;
  }
  .cp-users-empty .lbl {
    color: #8A91A1; font-size: 11px; font-weight: 600;
    letter-spacing: 0.4px;
    margin-bottom: 6px;
  }

  /* Groups tab table */
  .cp-groups-tbl table { font-size: 13px; }
  .cp-groups-tbl th { padding: 12px 14px; }
  .cp-groups-tbl td { padding: 11px 14px; vertical-align: middle; }
  .cp-groups-tbl .name { font-weight: 600; color: #0D1B2A; }

  /* New-group inline form (visible on Groups tab) */
  .cp-newgrp {
    display: flex; gap: 8px; align-items: center;
    background: #FFFFFF; border: 1px solid #E4E5E8;
    border-radius: 8px; padding: 10px 12px;
    margin-bottom: 12px;
  }
  .cp-newgrp input[type="text"] {
    flex: 1; height: 30px;
    padding: 0 10px;
    border: 1px solid #E4E5E8;
    border-radius: 6px;
    font-size: 12px; color: #0D1B2A;
    outline: none;
  }
  .cp-newgrp input[type="text"]:focus { border-color: #008C8C; }
</style>
</head>
<body class="cp-arch">

<div class="cp-shell">
  <aside class="cp-adm-side">
    <div class="header"><?php echo xlt('ADMIN'); ?></div>
    <?php foreach ($sidebar as $cat => $items): ?>
      <div class="grp">
        <div class="lbl"><?php echo text($cat); ?></div>
        <?php foreach ($items as [$nm, $act]): ?>
          <a href="#" class="item<?php echo $act ? ' active' : ''; ?>"><?php echo text($nm); ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </aside>

  <div style="flex:1 1 auto; display:flex; flex-direction:column; min-width:0;">
    <header class="cp-pagehead">
      <div class="info">
        <div style="display:flex; align-items:center; gap:8px;">
          <span class="title"><?php echo xlt('Users & Groups'); ?></span>
          <span class="dot">·</span>
          <span class="meta-light">
            <?php
              echo text(sprintf(
                  '%d active users · %d groups · %d service accounts',
                  $activeCount,
                  $groupsCount,
                  $serviceCount
              ));
            ?>
          </span>
        </div>
      </div>
      <a class="cp-btn ghost" href="<?php echo attr($GLOBALS['webroot']); ?>/interface/super/edit_globals.php">
        ⤓ <?php echo xlt('Import CSV'); ?>
      </a>
      <a class="cp-btn ghost" href="<?php echo attr($buildLink(['tab' => 'groups'])); ?>#new-group">
        + <?php echo xlt('New group'); ?>
      </a>
      <a class="cp-btn primary" href="<?php echo attr($GLOBALS['webroot']); ?>/interface/usergroup/usergroup_admin_add.php">
        + <?php echo xlt('New user'); ?>
      </a>
    </header>

    <?php if ($flash !== ''): ?>
      <?php
        // Map msg codes to a (toneClass, label) pair.
        $flashMap = [
            'group_created' => ['',     xl('Group created.')],
            'group_exists'  => ['warn', xl('A group with that name already exists.')],
            'group_empty'   => ['err',  xl('Group name is required.')],
            'group_failed'  => ['err',  xl('Could not create group — try again.')],
        ];
        [$flashClass, $flashLabel] = $flashMap[$flash] ?? ['', ''];
      ?>
      <?php if ($flashLabel !== ''): ?>
        <div class="cp-flash <?php echo attr($flashClass); ?>"><?php echo text($flashLabel); ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <nav class="cp-tabs">
      <?php foreach ($tabs as $tabKey => [$tabLabel, $_]):
          $isActive = $tab === $tabKey;
      ?>
        <a href="<?php echo attr($buildLink(['tab' => $tabKey])); ?>"
           class="<?php echo $isActive ? 'active' : ''; ?>"><?php echo text($tabLabel); ?></a>
      <?php endforeach; ?>
    </nav>

    <main class="cp-content tight">

      <?php if ($tab === 'users' || $tab === 'services'): ?>

        <form method="get" action="<?php echo attr($_SERVER['PHP_SELF']); ?>" class="cp-users-filter">
          <input type="hidden" name="tab" value="<?php echo attr($tab); ?>">
          <div class="search">
            <span class="ic">&#128269;</span>
            <input type="text" name="q"
                   value="<?php echo attr($q); ?>"
                   placeholder="<?php echo xla('Search by name, email, or username'); ?>"
                   onchange="this.form.submit()">
          </div>

          <select class="dd" name="role" onchange="this.form.submit()">
            <option value="any"      <?php echo $roleFilter === 'any'      ? 'selected' : ''; ?>><?php echo xlt('All roles'); ?></option>
            <option value="provider" <?php echo $roleFilter === 'provider' ? 'selected' : ''; ?>><?php echo xlt('Provider'); ?></option>
            <option value="nurse"    <?php echo $roleFilter === 'nurse'    ? 'selected' : ''; ?>><?php echo xlt('Nurse'); ?></option>
            <option value="fd"       <?php echo $roleFilter === 'fd'       ? 'selected' : ''; ?>><?php echo xlt('Front desk'); ?></option>
            <option value="billing"  <?php echo $roleFilter === 'billing'  ? 'selected' : ''; ?>><?php echo xlt('Billing'); ?></option>
            <option value="admin"    <?php echo $roleFilter === 'admin'    ? 'selected' : ''; ?>><?php echo xlt('IT Admin'); ?></option>
          </select>

          <select class="dd" name="group" onchange="this.form.submit()">
            <option value=""><?php echo xlt('All groups'); ?></option>
            <?php foreach ($allGroups as $g): ?>
              <option value="<?php echo attr($g['name']); ?>"
                      <?php echo $groupFilter === $g['name'] ? 'selected' : ''; ?>>
                <?php echo text($g['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select class="dd" name="status" onchange="this.form.submit()">
            <option value="active"   <?php echo $statusFilter === 'active'   ? 'selected' : ''; ?>><?php echo xlt('Active'); ?></option>
            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>><?php echo xlt('Inactive'); ?></option>
            <option value="all"      <?php echo $statusFilter === 'all'      ? 'selected' : ''; ?>><?php echo xlt('All'); ?></option>
          </select>

          <select class="dd" name="mfa" onchange="this.form.submit()">
            <option value="any" <?php echo $mfaFilter === 'any' ? 'selected' : ''; ?>><?php echo xlt('Any MFA'); ?></option>
            <option value="on"  <?php echo $mfaFilter === 'on'  ? 'selected' : ''; ?>><?php echo xlt('MFA on'); ?></option>
            <option value="off" <?php echo $mfaFilter === 'off' ? 'selected' : ''; ?>><?php echo xlt('MFA off'); ?></option>
          </select>

          <?php
            $hasFilter = $q !== '' || $roleFilter !== 'any' || $groupFilter !== ''
                         || $statusFilter !== $defaultStatus || $mfaFilter !== 'any';
          ?>
          <?php if ($hasFilter): ?>
            <a class="reset" href="<?php echo attr($_SERVER['PHP_SELF'] . '?tab=' . urlencode($tab)); ?>">
              <?php echo xlt('Reset'); ?>
            </a>
          <?php endif; ?>
        </form>

        <?php if (count($users) === 0): ?>
          <div class="cp-users-empty">
            <div class="lbl"><?php echo xlt('NO MATCHING USERS'); ?></div>
            <?php echo xlt('Adjust your filters or'); ?>
            <a href="<?php echo attr($GLOBALS['webroot']); ?>/interface/usergroup/usergroup_admin_add.php">
              <?php echo xlt('add a new user'); ?>
            </a>.
          </div>
        <?php else: ?>
        <div class="cp-tbl cp-users-tbl">
          <table>
            <thead>
              <tr>
                <th class="chk"><input type="checkbox"></th>
                <th><?php echo xlt('NAME'); ?></th>
                <th><?php echo xlt('USERNAME'); ?></th>
                <th><?php echo xlt('EMAIL'); ?></th>
                <th><?php echo xlt('ROLE'); ?></th>
                <th><?php echo xlt('GROUPS'); ?></th>
                <th><?php echo xlt('MFA'); ?></th>
                <th><?php echo xlt('LAST LOGIN'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                  $name      = cp_format_provider_name($u);
                  $initials  = cp_initials($u);
                  $role      = cp_users_role_for($u);
                  $groupStr  = (string)($u['group_names'] ?? '');
                  $hasMfa    = (int)($u['mfa_count'] ?? 0) > 0;
                  $loginRel  = cp_users_relative_login($u['last_login_at'] ?? null);
                  $badge     = cp_users_badge_for($u, $role);
                  $emailStr  = trim((string)($u['email'] ?? ''));
                  if ($emailStr === '') { $emailStr = '—'; }
                ?>
                <tr>
                  <td class="chk"><input type="checkbox" name="user_ids[]" value="<?php echo attr((string)$u['id']); ?>"></td>
                  <td>
                    <span class="cp-user-cell">
                      <span class="cp-avatar neutral"><?php echo text($initials); ?></span>
                      <span class="name"><?php echo text($name); ?></span>
                    </span>
                  </td>
                  <td class="muted"><?php echo text((string)($u['username'] ?? '')); ?></td>
                  <td class="muted"><?php echo text($emailStr); ?></td>
                  <td class="muted"><?php echo text($role); ?></td>
                  <td class="muted"><?php echo text($groupStr !== '' ? $groupStr : '—'); ?></td>
                  <td>
                    <?php if ($hasMfa): ?>
                      <span class="cp-mfa-on" title="<?php echo xla('MFA registered'); ?>">&#10003;</span>
                    <?php else: ?>
                      <span class="cp-mfa-off">&mdash;</span>
                    <?php endif; ?>
                  </td>
                  <td class="muted">
                    <span class="lastlogin-row">
                      <?php echo text($loginRel); ?>
                      <?php if ($badge === 'svc'): ?>
                        <span class="cp-mini-pill svc"><?php echo xlt('svc'); ?></span>
                      <?php elseif ($badge === 'inactive'): ?>
                        <span class="cp-mini-pill inactive"><?php echo xlt('inactive'); ?></span>
                      <?php elseif ($badge === 'warn'): ?>
                        <span class="cp-mfa-warn" title="<?php echo xla('MFA disabled'); ?>">&#9888;</span>
                      <?php endif; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

      <?php elseif ($tab === 'groups'): ?>

        <form id="new-group" method="post"
              action="<?php echo attr($_SERVER['PHP_SELF']); ?>"
              class="cp-newgrp">
          <input type="hidden" name="action" value="new_group">
          <input type="text" name="name" required
                 placeholder="<?php echo xla('New group name (e.g. Clinical, Billing, Reception)'); ?>"
                 maxlength="100">
          <button type="submit" class="cp-btn primary">+ <?php echo xlt('Create group'); ?></button>
        </form>

        <?php if (count($allGroups) === 0): ?>
          <div class="cp-users-empty">
            <div class="lbl"><?php echo xlt('NO GROUPS'); ?></div>
            <?php echo xlt('Use the form above to create your first group.'); ?>
          </div>
        <?php else: ?>
        <div class="cp-tbl cp-groups-tbl">
          <table>
            <thead>
              <tr>
                <th><?php echo xlt('GROUP'); ?></th>
                <th><?php echo xlt('MEMBERS'); ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allGroups as $g): ?>
                <tr>
                  <td class="name"><?php echo text($g['name']); ?></td>
                  <td class="muted"><?php echo text((string)$g['members']); ?></td>
                  <td class="muted">
                    <a href="<?php echo attr($buildLink(['tab' => 'users', 'group' => $g['name']])); ?>">
                      <?php echo xlt('View members'); ?>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

      <?php elseif ($tab === 'invites'): ?>

        <div class="cp-users-empty">
          <div class="lbl"><?php echo xlt('NO PENDING INVITES'); ?></div>
          <?php echo xlt('OpenEMR creates accounts directly — there is no invite queue.'); ?><br>
          <a href="<?php echo attr($GLOBALS['webroot']); ?>/interface/usergroup/usergroup_admin_add.php">
            <?php echo xlt('Add a new user'); ?>
          </a>
        </div>

      <?php endif; ?>

    </main>
  </div>
</div>

</body>
</html>
