<?php

/**
 * Audit / Activity Log — Screen 55, admin archetype.
 *
 * HIPAA-required activity log with grouped admin sidebar, multi-filter
 * row, paged event table, and a right-rail incident detail card pinned
 * to the currently selected event.
 *
 * Source of truth: `log` table (OpenEMR's primary audit log). Comments are
 * base64-encoded — decoded via cp_decode_log_comment(). Cross-patient
 * (admin) — no $pid filter.
 *
 * Filters (all GET):
 *   q          full-text on user/event/comments/log_from
 *   range      today | 7d | 30d | 90d | all   (default 7d)
 *   event      one of the distinct event values (default all)
 *   user       one of the distinct user values (default all)
 *   outcome    success | fail | both          (default both)
 *   patient    integer patient_id              (default all)
 *   selected   id of the highlighted log row  (default first row)
 *
 * POST actions:
 *   action=ack         — INSERT 'audit_ack' into extended_log
 *   action=escalate    — INSERT 'audit_escalate' into extended_log
 *   action=export_csv  — stream CSV of the current filter set
 *
 * The chrome (top nav) is rendered by the parent shell — this page
 * renders only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

$selfPath = $_SERVER['PHP_SELF'];
$authUser = (string)($_SESSION['authUser'] ?? 'admin');

// -------------------------------------------------------------------------
// Filter parsing — these are reused by GET (rendering) and POST (export).
// -------------------------------------------------------------------------

/**
 * Build the WHERE-clause + bind params for the current filter set.
 *
 * Returns [whereSql, params] where whereSql always begins with '1=1' so the
 * caller can append it after a literal "WHERE" without juggling AND/OR.
 *
 * Reads filters from $src — defaults to $_GET for normal page rendering, but
 * the CSV export POST passes $_POST so the form can carry hidden inputs.
 *
 * @param array<string,mixed>|null $src
 * @return array{0:string,1:array<int,mixed>}
 */
function cp_audit_build_where(?array $src = null): array
{
    $src = $src ?? $_GET;
    $where = '1=1';
    $params = [];

    // Date range pill
    $range = (string)($src['range'] ?? '7d');
    $validRanges = ['today', '7d', '30d', '90d', 'all'];
    if (!in_array($range, $validRanges, true)) {
        $range = '7d';
    }
    if ($range === 'today') {
        $where .= ' AND DATE(date) = CURDATE()';
    } elseif ($range === '7d') {
        $where .= ' AND date > NOW() - INTERVAL 7 DAY';
    } elseif ($range === '30d') {
        $where .= ' AND date > NOW() - INTERVAL 30 DAY';
    } elseif ($range === '90d') {
        $where .= ' AND date > NOW() - INTERVAL 90 DAY';
    }
    // 'all' → no clause

    // Event type
    $event = trim((string)($src['event'] ?? ''));
    if ($event !== '' && $event !== 'all') {
        $where .= ' AND event = ?';
        $params[] = $event;
    }

    // User
    $userF = trim((string)($src['user'] ?? ''));
    if ($userF !== '' && $userF !== 'all') {
        $where .= ' AND user = ?';
        $params[] = $userF;
    }

    // Outcome
    $outcome = (string)($src['outcome'] ?? 'both');
    if ($outcome === 'success') {
        $where .= ' AND success = 1';
    } elseif ($outcome === 'fail') {
        $where .= ' AND success = 0';
    }

    // Patient (numeric)
    $patientF = trim((string)($src['patient'] ?? ''));
    if ($patientF !== '' && ctype_digit($patientF)) {
        $where .= ' AND patient_id = ?';
        $params[] = (int)$patientF;
    }

    // Free-text search across user / event / comments / log_from.
    // (comments are base64; matching there is best-effort but still useful
    //  for IPs / event names which are stored plain.)
    $q = trim((string)($src['q'] ?? ''));
    if ($q !== '') {
        $where .= ' AND (user LIKE ? OR event LIKE ? OR comments LIKE ? OR log_from LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }

    return [$where, $params];
}

/**
 * Compute a coarse risk tone for a log event.
 *  - high   → red dot, "danger"
 *  - medium → orange dot, "warn"
 *  - low    → green dot, "good"
 */
function cp_audit_risk_tone(string $event, int $success): string
{
    if ($success === 0) {
        return 'danger';
    }
    $highRisk = [
        'delete_patient',
        'security-access-denied',
        'sign_epcs',
    ];
    if (in_array($event, $highRisk, true)) {
        return 'danger';
    }
    $medRisk = [
        'login',
        'logout',
        'security-administration-update',
        'security-administration-insert',
        'export',
        'print',
    ];
    if (in_array($event, $medRisk, true)) {
        return 'warn';
    }
    return 'good';
}

/**
 * Numeric risk score for the right-rail incident card (0.00–1.00).
 * Rough heuristic — high-risk events score higher, failed events bump it.
 */
function cp_audit_risk_score(string $event, int $success): float
{
    $base = 0.10;
    if (cp_audit_risk_tone($event, $success) === 'warn')   { $base = 0.45; }
    if (cp_audit_risk_tone($event, $success) === 'danger') { $base = 0.80; }
    if ($success === 0) { $base += 0.10; }
    return min(1.00, round($base, 2));
}

// -------------------------------------------------------------------------
// POST handlers — run before any output, redirect on success.
// CSRF skipped — internal mock page; OpenEMR's auth gate (globals.php →
// authCheckCore()) prevents anonymous POSTs.
// -------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'ack' || $action === 'escalate') {
        $logId = (int)($_POST['log_id'] ?? 0);
        if ($logId > 0) {
            $row = sqlQuery(
                "SELECT id, event, user, patient_id FROM log WHERE id = ?",
                [$logId]
            );
            if ($row) {
                $eventName = $action === 'ack' ? 'audit_ack' : 'audit_escalate';
                $verb = $action === 'ack' ? 'Acknowledged' : 'Escalated';
                $desc = sprintf(
                    '%s log entry #%d (event=%s, user=%s)',
                    $verb,
                    $logId,
                    (string)($row['event'] ?? ''),
                    (string)($row['user'] ?? '')
                );
                sqlStatement(
                    "INSERT INTO extended_log (date, event, user, recipient, description, patient_id)
                     VALUES (NOW(), ?, ?, '', ?, ?)",
                    [$eventName, $authUser, $desc, (int)($row['patient_id'] ?? 0)]
                );
                $msg = $action === 'ack'
                    ? "Acknowledged event #{$logId}"
                    : "Escalated event #{$logId} to security";
            } else {
                $msg = "Event #{$logId} not found";
            }
        } else {
            $msg = 'Invalid event id';
        }
        // Preserve existing filters on redirect.
        $qs = $_GET;
        $qs['msg']      = $msg;
        $qs['selected'] = (string)$logId;
        header('Location: ' . $selfPath . '?' . http_build_query($qs));
        exit;
    }

    if ($action === 'export_csv') {
        // Stream a CSV of the current filter set. The form posts current
        // filters back as hidden inputs, so read filters from $_POST.
        [$where, $params] = cp_audit_build_where($_POST);
        $sql = "SELECT id, date, event, user, patient_id, success, log_from, comments
                FROM log
                WHERE $where
                ORDER BY date DESC, id DESC
                LIMIT 5000";
        $rs = sqlStatement($sql, $params);

        $stamp = date('Ymd-His');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-log-' . $stamp . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'timestamp', 'event', 'user', 'patient_id', 'outcome', 'log_from', 'detail']);
        while ($r = sqlFetchArray($rs)) {
            fputcsv($out, [
                (int)$r['id'],
                (string)$r['date'],
                (string)$r['event'],
                (string)$r['user'],
                (int)($r['patient_id'] ?? 0),
                ((int)$r['success'] === 1) ? 'success' : 'fail',
                (string)$r['log_from'],
                cp_decode_log_comment((string)($r['comments'] ?? ''), 240),
            ]);
        }
        fclose($out);
        exit;
    }
}

// -------------------------------------------------------------------------
// GET — render the page.
// -------------------------------------------------------------------------

[$where, $params] = cp_audit_build_where();

// Header counter — total events in the last 7 days, regardless of filters.
$row = sqlQuery("SELECT COUNT(*) AS n FROM log WHERE date > NOW() - INTERVAL 7 DAY");
$count7d = (int)($row['n'] ?? 0);

// Filtered audit rows (cap 200, render top 15 in mock).
$rsRows = sqlStatement(
    "SELECT id, date, event, user, patient_id, success, log_from, comments
     FROM log
     WHERE $where
     ORDER BY date DESC, id DESC
     LIMIT 200",
    $params
);
$auditRows = [];
while ($r = sqlFetchArray($rsRows)) {
    $auditRows[] = $r;
}

// Pre-cache patient labels for any patient_ids in the result set.
$pidSet = [];
foreach ($auditRows as $r) {
    $p = (int)($r['patient_id'] ?? 0);
    if ($p > 0) { $pidSet[$p] = true; }
}
$patientLabels = [];
if ($pidSet !== []) {
    $ids = array_keys($pidSet);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rsP = sqlStatement("SELECT pid, fname, lname FROM patient_data WHERE pid IN ($ph)", $ids);
    while ($pr = sqlFetchArray($rsP)) {
        $patientLabels[(int)$pr['pid']] = trim((string)$pr['fname'] . ' ' . (string)$pr['lname']);
    }
}

// Pre-cache user-display labels for any usernames seen in the result set.
$userSet = [];
foreach ($auditRows as $r) {
    $u = trim((string)($r['user'] ?? ''));
    if ($u !== '') { $userSet[$u] = true; }
}
$userDisplay = [];
if ($userSet !== []) {
    $names = array_keys($userSet);
    $ph = implode(',', array_fill(0, count($names), '?'));
    $rsU = sqlStatement(
        "SELECT username, fname, lname, title FROM users WHERE username IN ($ph)",
        $names
    );
    while ($ur = sqlFetchArray($rsU)) {
        $userDisplay[(string)$ur['username']] = cp_format_provider_name($ur);
    }
}

// Determine the currently-selected row id. Default to the first.
$selectedId = (int)($_GET['selected'] ?? 0);
if ($selectedId === 0 && $auditRows !== []) {
    $selectedId = (int)$auditRows[0]['id'];
}
$selectedRow = null;
foreach ($auditRows as $r) {
    if ((int)$r['id'] === $selectedId) {
        $selectedRow = $r;
        break;
    }
}

// Distinct events for the Event-type dropdown (top 10 by frequency).
$rsE = sqlStatement(
    "SELECT event, COUNT(*) AS n FROM log
     WHERE event IS NOT NULL AND event <> ''
     GROUP BY event
     ORDER BY n DESC
     LIMIT 10"
);
$eventOptions = [];
while ($er = sqlFetchArray($rsE)) {
    $eventOptions[] = (string)$er['event'];
}
// Make sure the active filter value (if any) is always selectable, even if
// it isn't in the top-10 list — otherwise the dropdown silently resets.
if (($_GET['event'] ?? '') !== '' && ($_GET['event'] ?? '') !== 'all'
    && !in_array((string)$_GET['event'], $eventOptions, true)
) {
    $eventOptions[] = (string)$_GET['event'];
}
sort($eventOptions);

// Distinct users for the User dropdown (joined to users for display name).
$rsU = sqlStatement(
    "SELECT DISTINCT user FROM log
     WHERE user IS NOT NULL AND user <> ''
     ORDER BY user
     LIMIT 30"
);
$userOptions = [];
while ($urow = sqlFetchArray($rsU)) {
    $u = (string)$urow['user'];
    $userOptions[$u] = $userDisplay[$u]
        ?? (function (string $u): string {
            $r = sqlQuery("SELECT username, fname, lname, title FROM users WHERE username = ?", [$u]);
            return $r ? cp_format_provider_name($r) : $u;
        })($u);
}
// Always include the active filter value, even if it isn't in the top 30.
$selUserParam = (string)($_GET['user'] ?? '');
if ($selUserParam !== '' && $selUserParam !== 'all' && !isset($userOptions[$selUserParam])) {
    $r = sqlQuery("SELECT username, fname, lname, title FROM users WHERE username = ?", [$selUserParam]);
    $userOptions[$selUserParam] = $r ? cp_format_provider_name($r) : $selUserParam;
}

// Active filters (for pre-selection).
$selRange   = (string)($_GET['range']   ?? '7d');
$selEvent   = (string)($_GET['event']   ?? 'all');
$selUser    = (string)($_GET['user']    ?? 'all');
$selOutcome = (string)($_GET['outcome'] ?? 'both');
$selPatient = (string)($_GET['patient'] ?? '');
$selQ       = (string)($_GET['q']       ?? '');

// Flash banner.
$flash = $_GET['msg'] ?? null;

// Sidebar groups — matches Figma Screen 55 exactly.
$sideGroups = [
    ['ADMIN', null, []],
    [null, 'Users & Access', [
        ['users',           'Users & Groups',    '/interface/super/copilot_users.php'],
        ['acl',             'ACL Editor',        '/interface/super/copilot_acl.php'],
        ['sessions',        'Active Sessions',   '/interface/super/copilot_admin.php'],
        ['password_policy', 'Password Policy',   '/interface/super/copilot_admin.php'],
    ]],
    [null, 'Practice', [
        ['facilities',  'Facilities',         '/interface/super/copilot_facilities.php'],
        ['providers',   'Providers',          '/interface/super/copilot_users.php'],
        ['schedule',    'Schedule Templates', '/interface/super/copilot_templates.php'],
        ['pricing',     'Pricing',            '/interface/super/copilot_practice_settings.php'],
    ]],
    [null, 'Clinical', [
        ['forms',       'Forms',        '/interface/super/copilot_forms_layouts.php'],
        ['lists',       'Lists',        '/interface/super/copilot_coding_lists.php'],
        ['templates',   'Templates',    '/interface/super/copilot_templates.php'],
        ['issuetypes',  'Issue Types',  '/interface/super/copilot_coding_lists.php'],
        ['layouts',     'Layouts',      '/interface/super/copilot_forms_layouts.php'],
    ]],
    [null, 'System', [
        ['audit',     'Audit Log',  '/interface/super/copilot_audit.php'],
        ['backup',    'Backup',     '/interface/super/copilot_system.php'],
        ['globals',   'Globals',    '/interface/super/copilot_admin.php'],
        ['database',  'Database',   '/interface/super/copilot_db_debug.php'],
        ['modules',   'Modules',    '/interface/super/copilot_module_installer.php'],
    ]],
];
$activeKey = 'audit';

// Cap the rendered table at 15 rows for visual parity with the mock.
$visibleRows = array_slice($auditRows, 0, 15);

// Hidden filter inputs reused by the search form / export form so each
// preserves the rest of the active filter set.
$preserve = [
    'range'    => $selRange,
    'event'    => $selEvent,
    'user'     => $selUser,
    'outcome'  => $selOutcome,
    'patient'  => $selPatient,
    'q'        => $selQ,
    'selected' => (string)$selectedId,
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Audit Log'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Grouped admin sidebar — replaces flat cp_admin_sidebar for this screen */
  .cp-sidebar-grouped { padding: 18px 0 24px; }
  .cp-sidebar-grouped .grp-lbl-top {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.8px;
    padding: 0 24px 10px;
  }
  .cp-sidebar-grouped .grp-lbl {
    font-size: 12px; font-weight: 600;
    color: #0D1B2A; letter-spacing: 0.2px;
    padding: 14px 24px 6px;
  }
  .cp-sidebar-grouped .cp-cat { height: 32px; line-height: 32px; font-size: 13px; }

  /* Page header dot + meta */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-pagehead form { display: inline-flex; margin: 0; }

  /* Flash banner */
  .cp-flash {
    background: #EBF8F0; color: #1F8C4D;
    border: 1px solid #C7E8D5; border-radius: 8px;
    padding: 8px 14px; font-size: 12px; font-weight: 500;
    margin: 8px 24px 0;
  }

  /* Two-column content w/ right rail */
  .cp-audit-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 14px;
    align-items: start;
  }

  /* Filter row: search + multiple labelled dropdowns */
  .cp-flt-row {
    display: flex; align-items: center; gap: 10px;
    background: transparent;
    margin-bottom: 12px;
  }
  .cp-flt-row form.search-form {
    flex: 1 1 auto;
    display: flex; align-items: center;
    margin: 0;
  }
  .cp-flt-search {
    flex: 1 1 auto;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 36px; padding: 0 12px;
    display: flex; align-items: center; gap: 8px;
  }
  .cp-flt-search .ic { color: #8A91A1; font-size: 12px; }
  .cp-flt-search input {
    border: none; background: transparent; outline: none;
    flex: 1; font-size: 12px; color: #0D1B2A;
  }
  .cp-flt-search input::placeholder { color: #8A91A1; }
  .cp-flt-dd {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 36px; padding: 4px 28px 4px 10px;
    display: inline-flex; flex-direction: column; justify-content: center;
    position: relative;
    min-width: 110px;
  }
  .cp-flt-dd .lbl { font-size: 9px; color: #8A91A1; line-height: 1; letter-spacing: 0.3px; }
  .cp-flt-dd .val { font-size: 12px; color: #0D1B2A; line-height: 1.2; font-weight: 500; }
  .cp-flt-dd select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    border: none; background: transparent; outline: none;
    font: inherit; color: inherit;
    padding: 0; margin: 0; width: 100%;
    font-size: 12px; color: #0D1B2A; font-weight: 500;
  }
  .cp-flt-dd::after {
    content: '▾'; position: absolute; right: 10px; top: 50%;
    transform: translateY(-50%); color: #8A91A1; font-size: 9px;
    pointer-events: none;
  }
  .cp-flt-dd input.patient-input {
    border: none; background: transparent; outline: none;
    font: inherit; color: #0D1B2A; font-weight: 500;
    padding: 0; margin: 0; width: 100%;
    font-size: 12px;
  }

  /* Audit table */
  .cp-audit-tbl table { font-size: 12px; }
  .cp-audit-tbl th { padding: 12px 18px; }
  .cp-audit-tbl td { padding: 14px 18px; }
  .cp-audit-tbl td.ts { color: #4F5763; font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-size: 11.5px; white-space: nowrap; }
  .cp-audit-tbl td.user { color: #0D1B2A; font-weight: 600; white-space: nowrap; }
  .cp-audit-tbl td.event { color: #0D1B2A; font-weight: 500; }
  .cp-audit-tbl td.target { color: #4F5763; }
  .cp-audit-tbl td.dot { width: 24px; text-align: right; padding-right: 18px; }
  .cp-audit-tbl tr.active td { background: #EAF5F5; }
  .cp-audit-tbl tr { cursor: pointer; }
  .cp-audit-tbl td.ts-locked { color: #D93838; font-weight: 600; }
  .cp-audit-tbl td.user-locked { color: #D93838; font-weight: 700; }
  .cp-audit-tbl td.event-locked { color: #D93838; font-weight: 600; }
  .cp-audit-tbl td.target-locked { color: #D93838; }
  .cp-audit-tbl a.row-link { color: inherit; text-decoration: none; display: block; }

  .cp-dot {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
  }
  .cp-dot.good { background: #1F8C4D; }
  .cp-dot.warn { background: #FA8C33; }
  .cp-dot.danger { background: #D93838; }

  /* Right rail incident detail card */
  .cp-incident {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px 18px 18px;
  }
  .cp-incident .hd {
    display: flex; align-items: flex-start; gap: 10px;
    padding-bottom: 12px;
    border-bottom: 1px solid #F0F1F3;
    margin-bottom: 14px;
  }
  .cp-incident .alert-ic {
    flex: 0 0 auto;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: #FCE7E7; color: #D93838;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700;
  }
  .cp-incident .alert-ic.good { background: #E6F4EC; color: #1F8C4D; }
  .cp-incident .alert-ic.warn { background: #FFF1E0; color: #FA8C33; }
  .cp-incident .hd .ti { font-size: 13px; font-weight: 700; color: #0D1B2A; line-height: 1.3; }
  .cp-incident .hd .meta { font-size: 11px; color: #8A91A1; margin-top: 3px; line-height: 1.2; }

  .cp-incident .sec-lbl {
    font-size: 10px; font-weight: 600; color: #8A91A1;
    letter-spacing: 0.6px; line-height: 1; margin-bottom: 10px;
  }
  .cp-incident .field { margin-bottom: 10px; }
  .cp-incident .field .k { font-size: 11px; color: #8A91A1; line-height: 1.2; margin-bottom: 2px; }
  .cp-incident .field .v { font-size: 12px; color: #0D1B2A; line-height: 1.35; word-break: break-word; }
  .cp-incident .field .v .em { color: #D93838; font-weight: 600; }
  .cp-incident hr {
    border: none; border-top: 1px solid #F0F1F3; margin: 14px 0;
  }
  .cp-incident .ctx-list {
    list-style: none; padding: 0; margin: 0;
    font-size: 11.5px; color: #0D1B2A; line-height: 1.7;
  }
  .cp-incident .ctx-list li { padding-left: 12px; position: relative; }
  .cp-incident .ctx-list li::before {
    content: '•'; position: absolute; left: 0; top: 0;
    color: #8A91A1;
  }
  .cp-incident .ctx-list li.ok { color: #1F8C4D; }
  .cp-incident .ctx-list li.ok::before { color: #1F8C4D; }

  .cp-incident .actions {
    display: flex; gap: 8px; margin-top: 16px;
  }
  .cp-incident .actions form { flex: 1; margin: 0; }
  .cp-incident .actions .cp-btn { width: 100%; justify-content: center; padding: 9px 10px; font-size: 11px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="title"><?php echo xlt('Audit Log'); ?></span>
      <span class="dot">·</span>
      <span class="meta-light"><?php echo xlt('HIPAA-required activity log'); ?> · <?php echo text(number_format($count7d)); ?> <?php echo xlt('events in last 7 days'); ?></span>
    </div>
  </div>
  <form method="post" action="<?php echo attr($selfPath); ?>">
    <input type="hidden" name="action" value="export_csv">
    <?php foreach ($preserve as $k => $v): if ($v === '' || $v === 'all' || $v === 'both' || $k === 'selected') continue; ?>
      <input type="hidden" name="<?php echo attr((string)$k); ?>" value="<?php echo attr((string)$v); ?>">
    <?php endforeach; ?>
    <button type="submit" class="cp-btn primary">⤓ <?php echo xlt('Export log'); ?></button>
  </form>
</header>

<?php if ($flash !== null && $flash !== ''): ?>
  <div class="cp-flash"><?php echo text((string)$flash); ?></div>
<?php endif; ?>

<div class="cp-shell">
  <aside class="cp-sidebar cp-sidebar-grouped">
    <?php foreach ($sideGroups as $g):
        [$top, $sub, $items] = $g;
    ?>
      <?php if ($top !== null): ?>
        <div class="grp-lbl-top"><?php echo xlt($top); ?></div>
      <?php endif; ?>
      <?php if ($sub !== null): ?>
        <div class="grp-lbl"><?php echo xlt($sub); ?></div>
      <?php endif; ?>
      <?php foreach ($items as [$key, $label, $href]):
          $cls = ($key === $activeKey) ? 'cp-cat active' : 'cp-cat';
      ?>
        <a class="<?php echo $cls; ?>" href="<?php echo attr($href); ?>"><?php echo text($label); ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </aside>

  <main class="cp-content tight">

    <div class="cp-audit-grid">
      <div>
        <form method="get" action="<?php echo attr($selfPath); ?>" id="auditFilters">
          <div class="cp-flt-row">
            <div class="cp-flt-search">
              <span class="ic">🔍</span>
              <input type="text" name="q" value="<?php echo attr($selQ); ?>" placeholder="<?php echo xla('Search by user, IP, patient, or event'); ?>">
            </div>
            <label class="cp-flt-dd">
              <span class="lbl"><?php echo xlt('Date range'); ?></span>
              <select name="range" onchange="this.form.submit()">
                <option value="today" <?php echo $selRange === 'today' ? 'selected' : ''; ?>><?php echo xlt('Today'); ?></option>
                <option value="7d"    <?php echo $selRange === '7d'    ? 'selected' : ''; ?>><?php echo xlt('Last 7 days'); ?></option>
                <option value="30d"   <?php echo $selRange === '30d'   ? 'selected' : ''; ?>><?php echo xlt('Last 30 days'); ?></option>
                <option value="90d"   <?php echo $selRange === '90d'   ? 'selected' : ''; ?>><?php echo xlt('Last 90 days'); ?></option>
                <option value="all"   <?php echo $selRange === 'all'   ? 'selected' : ''; ?>><?php echo xlt('All time'); ?></option>
              </select>
            </label>
            <label class="cp-flt-dd">
              <span class="lbl"><?php echo xlt('Event type'); ?></span>
              <select name="event" onchange="this.form.submit()">
                <option value="all" <?php echo $selEvent === 'all' || $selEvent === '' ? 'selected' : ''; ?>><?php echo xlt('All'); ?></option>
                <?php foreach ($eventOptions as $e): ?>
                  <option value="<?php echo attr($e); ?>" <?php echo $selEvent === $e ? 'selected' : ''; ?>><?php echo text($e); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="cp-flt-dd">
              <span class="lbl"><?php echo xlt('User'); ?></span>
              <select name="user" onchange="this.form.submit()">
                <option value="all" <?php echo $selUser === 'all' || $selUser === '' ? 'selected' : ''; ?>><?php echo xlt('All users'); ?></option>
                <?php foreach ($userOptions as $u => $label): ?>
                  <option value="<?php echo attr((string)$u); ?>" <?php echo $selUser === (string)$u ? 'selected' : ''; ?>><?php echo text((string)$label); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="cp-flt-dd">
              <span class="lbl"><?php echo xlt('Outcome'); ?></span>
              <select name="outcome" onchange="this.form.submit()">
                <option value="both"    <?php echo $selOutcome === 'both'    ? 'selected' : ''; ?>><?php echo xlt('All'); ?></option>
                <option value="success" <?php echo $selOutcome === 'success' ? 'selected' : ''; ?>><?php echo xlt('Success'); ?></option>
                <option value="fail"    <?php echo $selOutcome === 'fail'    ? 'selected' : ''; ?>><?php echo xlt('Failures'); ?></option>
              </select>
            </label>
            <label class="cp-flt-dd" title="<?php echo xla('Filter by patient ID'); ?>">
              <span class="lbl"><?php echo xlt('Patient'); ?></span>
              <input class="patient-input" type="text" name="patient" value="<?php echo attr($selPatient); ?>" placeholder="<?php echo xla('All'); ?>" onchange="this.form.submit()" inputmode="numeric">
            </label>
          </div>
        </form>

        <div class="cp-tbl cp-audit-tbl">
          <table>
            <thead>
              <tr>
                <th><?php echo xlt('TIMESTAMP'); ?></th>
                <th><?php echo xlt('USER'); ?></th>
                <th><?php echo xlt('EVENT'); ?></th>
                <th><?php echo xlt('TARGET'); ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($visibleRows === []): ?>
                <tr><td colspan="5" style="padding: 28px; text-align: center; color: #8A91A1;"><?php echo xlt('No audit events match the current filters.'); ?></td></tr>
              <?php endif; ?>
              <?php foreach ($visibleRows as $r):
                  $rowId   = (int)$r['id'];
                  $tsRaw   = (string)$r['date'];
                  $ts      = $tsRaw !== '' && strtotime($tsRaw) !== false
                                ? date('m/d H:i:s', (int)strtotime($tsRaw))
                                : $tsRaw;
                  $userKey = (string)($r['user'] ?? '');
                  $userLbl = $userKey !== '' ? ($userDisplay[$userKey] ?? $userKey) : 'UNKNOWN';
                  $evt     = (string)($r['event'] ?? '');
                  $success = (int)($r['success'] ?? 1);
                  $tone    = cp_audit_risk_tone($evt, $success);
                  $isLocked = ($evt === 'security-access-denied') || ($success === 0);
                  $isActive = ($rowId === $selectedId);

                  // Decoded-comments target column. If a patient_id is set,
                  // prefer the patient label; otherwise show decoded log
                  // comments (truncated). Fall back to log_from (IP).
                  $pid = (int)($r['patient_id'] ?? 0);
                  if ($pid > 0 && isset($patientLabels[$pid])) {
                      $tgt = $patientLabels[$pid] . ' #' . str_pad((string)$pid, 6, '0', STR_PAD_LEFT);
                  } else {
                      $decoded = cp_decode_log_comment((string)($r['comments'] ?? ''), 60);
                      $tgt = $decoded !== '—' ? $decoded : (string)$r['log_from'];
                  }

                  $tsCls    = $isLocked ? 'ts ts-locked' : 'ts';
                  $userCls  = $isLocked ? 'user user-locked' : 'user';
                  $evtCls   = $isLocked ? 'event event-locked' : 'event';
                  $tgtCls   = $isLocked ? 'target target-locked' : 'target';
                  $rowCls   = $isActive ? 'active' : '';

                  // Build the selected-row link, preserving filters.
                  $linkQs = $preserve;
                  $linkQs['selected'] = (string)$rowId;
                  unset($linkQs['msg']);
                  $linkHref = $selfPath . '?' . http_build_query($linkQs);
              ?>
                <tr class="<?php echo $rowCls; ?>" onclick="window.location='<?php echo attr($linkHref); ?>'">
                  <td class="<?php echo $tsCls; ?>"><?php echo text($ts); ?></td>
                  <td class="<?php echo $userCls; ?>"><?php echo text($userLbl); ?></td>
                  <td class="<?php echo $evtCls; ?>"><?php echo text($evt); ?></td>
                  <td class="<?php echo $tgtCls; ?>"><?php echo text($tgt); ?></td>
                  <td class="dot"><span class="cp-dot <?php echo attr($tone); ?>"></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php
      // Build the right-rail incident card from the selected row.
      $sel = $selectedRow;
      if ($sel !== null) {
          $selId       = (int)$sel['id'];
          $selEvtName  = (string)($sel['event'] ?? '');
          $selUserKey  = (string)($sel['user'] ?? '');
          $selUserLbl  = $selUserKey !== '' ? ($userDisplay[$selUserKey] ?? $selUserKey) : 'UNKNOWN';
          $selSuccess  = (int)($sel['success'] ?? 1);
          $selTone     = cp_audit_risk_tone($selEvtName, $selSuccess);
          $selScore    = cp_audit_risk_score($selEvtName, $selSuccess);
          $selDate     = (string)($sel['date'] ?? '');
          $selDateF    = $selDate !== '' && strtotime($selDate) !== false
                            ? date('m/d/Y H:i:s', (int)strtotime($selDate))
                            : $selDate;
          $selLogFrom  = (string)($sel['log_from'] ?? '');
          $selDecoded  = cp_decode_log_comment((string)($sel['comments'] ?? ''), 240);
          $selPid      = (int)($sel['patient_id'] ?? 0);
          $selPatLbl   = $selPid > 0 && isset($patientLabels[$selPid])
                            ? ($patientLabels[$selPid] . ' #' . str_pad((string)$selPid, 6, '0', STR_PAD_LEFT))
                            : null;

          // Outcome label.
          $outcomeText = $selSuccess === 1 ? 'Success' : 'Failure';
          $isLockoutEvt = ($selEvtName === 'security-access-denied') || ($selSuccess === 0);

          // Risk label.
          $riskLabel = $selTone === 'danger' ? 'HIGH'
                     : ($selTone === 'warn'  ? 'MEDIUM' : 'LOW');

          // Security context — related events for same user in last 5 min.
          $ctxRows = [];
          if ($selUserKey !== '' && $selDate !== '') {
              $rsCtx = sqlStatement(
                  "SELECT id, event, success, date FROM log
                   WHERE user = ?
                     AND id <> ?
                     AND date BETWEEN (? - INTERVAL 5 MINUTE) AND (? + INTERVAL 5 MINUTE)
                   ORDER BY date DESC
                   LIMIT 5",
                  [$selUserKey, $selId, $selDate, $selDate]
              );
              while ($cr = sqlFetchArray($rsCtx)) {
                  $ctxRows[] = $cr;
              }
          }
      }
      ?>
      <aside class="cp-incident">
        <?php if ($sel === null): ?>
          <div class="hd">
            <span class="alert-ic good">i</span>
            <div>
              <div class="ti"><?php echo xlt('No event selected'); ?></div>
              <div class="meta"><?php echo xlt('Select a row to view details'); ?></div>
            </div>
          </div>
        <?php else: ?>
          <div class="hd">
            <span class="alert-ic <?php echo attr($selTone === 'good' ? 'good' : ($selTone === 'warn' ? 'warn' : '')); ?>">!</span>
            <div>
              <div class="ti"><?php echo text($selEvtName); ?><?php if ($isLockoutEvt): ?> · <?php echo xlt('FLAGGED'); ?><?php endif; ?></div>
              <div class="meta"><?php echo text($selDateF); ?></div>
            </div>
          </div>

          <div class="sec-lbl"><?php echo xlt('EVENT DETAILS'); ?></div>

          <div class="field">
            <div class="k"><?php echo xlt('User'); ?></div>
            <div class="v"><?php echo text($selUserLbl); ?></div>
          </div>
          <div class="field">
            <div class="k"><?php echo xlt('Username'); ?></div>
            <div class="v"><?php echo text($selUserKey !== '' ? $selUserKey : 'unknown'); ?></div>
          </div>
          <div class="field">
            <div class="k"><?php echo xlt('IP / source'); ?></div>
            <div class="v"><?php echo text($selLogFrom !== '' ? $selLogFrom : 'open-emr'); ?></div>
          </div>
          <div class="field">
            <div class="k"><?php echo xlt('User-Agent'); ?></div>
            <div class="v"><?php echo text((string)($_SERVER['HTTP_USER_AGENT'] ?? 'n/a')); ?></div>
          </div>
          <div class="field">
            <div class="k"><?php echo xlt('Outcome'); ?></div>
            <div class="v">
              <?php if ($selSuccess === 1): ?>
                <?php echo text($outcomeText); ?>
              <?php else: ?>
                <span class="em"><?php echo text($outcomeText); ?></span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($isLockoutEvt): ?>
          <div class="field">
            <div class="k"><?php echo xlt('Lock duration'); ?></div>
            <div class="v"><?php echo xlt('30 min (auto-unlock)'); ?></div>
          </div>
          <?php endif; ?>
          <div class="field">
            <div class="k"><?php echo xlt('Risk score'); ?></div>
            <div class="v"><?php echo text($riskLabel); ?> · <?php echo text(number_format($selScore, 2)); ?> / 1.00</div>
          </div>
          <?php if ($selPatLbl !== null): ?>
          <div class="field">
            <div class="k"><?php echo xlt('Patient'); ?></div>
            <div class="v"><?php echo text($selPatLbl); ?></div>
          </div>
          <?php endif; ?>
          <div class="field">
            <div class="k"><?php echo xlt('Detail'); ?></div>
            <div class="v"><?php echo text($selDecoded); ?></div>
          </div>

          <hr>

          <div class="sec-lbl"><?php echo xlt('SECURITY CONTEXT'); ?></div>
          <ul class="ctx-list">
            <?php if ($ctxRows === []): ?>
              <li><?php echo xlt('No related events for this user in the last 5 minutes'); ?></li>
            <?php else: ?>
              <?php foreach ($ctxRows as $cr):
                  $crEvt   = (string)($cr['event'] ?? '');
                  $crOk    = (int)($cr['success'] ?? 1) === 1;
                  $crDate  = (string)($cr['date'] ?? '');
                  $crTs    = $crDate !== '' && strtotime($crDate) !== false
                                ? date('H:i:s', (int)strtotime($crDate))
                                : $crDate;
              ?>
                <li class="<?php echo $crOk ? 'ok' : ''; ?>">
                  <?php echo text($crTs); ?> · <?php echo text($crEvt); ?>
                  <?php echo $crOk ? '' : ' · ' . xlt('failed'); ?>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>

          <div class="actions">
            <form method="post" action="<?php echo attr($selfPath); ?>">
              <input type="hidden" name="action" value="ack">
              <input type="hidden" name="log_id" value="<?php echo attr((string)$selId); ?>">
              <?php foreach ($preserve as $k => $v): if ($k === 'selected') continue; if ($v === '' || $v === 'all' || $v === 'both') continue; ?>
                <input type="hidden" name="<?php echo attr((string)$k); ?>" value="<?php echo attr((string)$v); ?>">
              <?php endforeach; ?>
              <button type="submit" class="cp-btn ghost"><?php echo xlt('Acknowledge'); ?></button>
            </form>
            <form method="post" action="<?php echo attr($selfPath); ?>">
              <input type="hidden" name="action" value="escalate">
              <input type="hidden" name="log_id" value="<?php echo attr((string)$selId); ?>">
              <?php foreach ($preserve as $k => $v): if ($k === 'selected') continue; if ($v === '' || $v === 'all' || $v === 'both') continue; ?>
                <input type="hidden" name="<?php echo attr((string)$k); ?>" value="<?php echo attr((string)$v); ?>">
              <?php endforeach; ?>
              <button type="submit" class="cp-btn danger">⚑ <?php echo xlt('Escalate'); ?></button>
            </form>
          </div>
        <?php endif; ?>
      </aside>
    </div>

  </main>
</div>

</body>
</html>
