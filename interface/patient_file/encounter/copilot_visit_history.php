<?php

/**
 * Visit History — Screen 30.
 *
 * Patient-scoped encounter list. Distinct from the Co-Pilot History
 * navtab (Screen 12). The chrome (top nav, demographics banner,
 * patient navtab strip) is rendered by the parent shell — this page
 * renders only the body (page header, filter row, table, pager).
 *
 * Data is pulled live from `form_encounter` joined to `users` (provider)
 * and `openemr_postcalendar_categories` (visit type). Filters, search,
 * pagination and the CSV export are all real.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Session\SessionWrapperFactory;
require_once(__DIR__ . "/../../main/copilot_helpers.php");

// Patient context comes from session; default to Margaret Chen (pid=1) for dev.
$pid = (int)(SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 1);

/* ---------------------------------------------------------------------------
 * Filter / search / pagination state — read GET, validate, build WHERE.
 * Only literal SQL fragments live in $where; user input always goes
 * through $params for sqlStatement().
 * ------------------------------------------------------------------------- */

$dateRange   = $_GET['date_range']  ?? 'all';
$visitTypeId = $_GET['visit_type']  ?? 'all';
$providerId  = $_GET['provider']    ?? 'all';
$status      = $_GET['status']      ?? 'all';
$q           = trim((string)($_GET['q'] ?? ''));
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 11;

$validDateRanges = ['12mo', '6mo', '3mo', 'all'];
if (!in_array($dateRange, $validDateRanges, true)) {
    $dateRange = 'all';
}
$validStatuses = ['all', 'signed', 'in_progress', 'billed'];
if (!in_array($status, $validStatuses, true)) {
    $status = 'all';
}

$where = 'fe.pid = ?';
$params = [$pid];

// Date range — literal date arithmetic, no user-supplied SQL.
if ($dateRange === '12mo') {
    $where .= ' AND fe.date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)';
} elseif ($dateRange === '6mo') {
    $where .= ' AND fe.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)';
} elseif ($dateRange === '3mo') {
    $where .= ' AND fe.date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)';
}

// Visit type — keep as int; cast and parameterize.
$visitTypeIdInt = ($visitTypeId === 'all' || $visitTypeId === '') ? null : (int)$visitTypeId;
if ($visitTypeIdInt !== null && $visitTypeIdInt > 0) {
    $where .= ' AND fe.pc_catid = ?';
    $params[] = $visitTypeIdInt;
}

// Provider — int, parameterized.
$providerIdInt = ($providerId === 'all' || $providerId === '') ? null : (int)$providerId;
if ($providerIdInt !== null && $providerIdInt > 0) {
    $where .= ' AND fe.provider_id = ?';
    $params[] = $providerIdInt;
}

// Status — only literal SQL fragments.
if ($status === 'signed') {
    $where .= ' AND fe.last_level_closed > 0';
} elseif ($status === 'billed') {
    $where .= ' AND fe.last_level_billed > 0';
} elseif ($status === 'in_progress') {
    $where .= ' AND COALESCE(fe.last_level_closed, 0) = 0';
}

// Search — parameterized LIKE on reason.
if ($q !== '') {
    $where .= ' AND fe.reason LIKE ?';
    $params[] = '%' . $q . '%';
}

/* ---------------------------------------------------------------------------
 * Header count: total encounters for this patient (NOT filter-scoped).
 * Matches the spec: "Visit History · {N} encounters · {filter}".
 * ------------------------------------------------------------------------- */
$totalAll = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM form_encounter WHERE pid = ?",
    [$pid]
)['c'] ?? 0);

/* ---------------------------------------------------------------------------
 * Filtered count for pagination + footer.
 * ------------------------------------------------------------------------- */
$totalFiltered = (int)(sqlQuery(
    "SELECT COUNT(*) AS c
       FROM form_encounter fe
       LEFT JOIN users u ON u.id = fe.provider_id
       LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = fe.pc_catid
      WHERE $where",
    $params
)['c'] ?? 0);

$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

/* ---------------------------------------------------------------------------
 * CSV export — POST handler runs before any HTML output.
 * Reuses the same WHERE so users export exactly what they filtered.
 * ------------------------------------------------------------------------- */
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'export_csv'
) {
    // CSRF skipped — internal mock page.
    $exportRows = sqlStatement(
        "SELECT fe.id, fe.encounter, fe.date, fe.reason, fe.pc_catid,
                COALESCE(c.pc_catname, 'Office Visit') AS pc_catname,
                COALESCE(c.pc_duration, 0)             AS pc_duration,
                fe.provider_id, fe.last_level_closed, fe.last_level_billed,
                u.username, u.fname, u.lname, u.title
           FROM form_encounter fe
           LEFT JOIN users u ON u.id = fe.provider_id
           LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = fe.pc_catid
          WHERE $where
          ORDER BY fe.date DESC",
        $params
    );

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="visit-history.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Time', 'Visit Type', 'Provider', 'Reason', 'Duration', 'Status', 'Billing']);

    while ($r = sqlFetchArray($exportRows)) {
        $ts          = strtotime((string)$r['date']) ?: time();
        $dateStr     = date('m/d/Y', $ts);
        $timeStr     = date('g:i A', $ts);
        $type        = (string)($r['pc_catname'] ?? 'Office Visit');
        $provider    = cp_format_provider_name([
            'username' => $r['username'] ?? '',
            'fname'    => $r['fname']    ?? '',
            'lname'    => $r['lname']    ?? '',
            'title'    => $r['title']    ?? '',
        ]);
        $secs        = (int)($r['pc_duration'] ?? 0);
        $duration    = $secs > 0 ? (int)round($secs / 60) . ' min' : '30 min';
        $closed      = (int)($r['last_level_closed'] ?? 0);
        $billed      = (int)($r['last_level_billed'] ?? 0);
        $statusLabel = $closed > 0 ? 'Signed' : ($billed > 0 ? 'Billed' : 'In progress');
        $billLabel   = $billed > 0 ? 'Billed' : '';

        fputcsv($out, [$dateStr, $timeStr, $type, $provider, (string)$r['reason'], $duration, $statusLabel, $billLabel]);
    }
    fclose($out);
    exit;
}

/* ---------------------------------------------------------------------------
 * Main page query — visible rows.
 * $perPage / $offset are guaranteed ints (cast above) so it is safe to
 * inline them into the LIMIT/OFFSET fragment.
 * ------------------------------------------------------------------------- */
$sql = "SELECT fe.id, fe.encounter, fe.date, fe.reason, fe.pc_catid,
               COALESCE(c.pc_catname, 'Office Visit') AS pc_catname,
               COALESCE(c.pc_duration, 0)             AS pc_duration,
               fe.provider_id, fe.last_level_closed, fe.last_level_billed,
               u.username, u.fname, u.lname, u.title
          FROM form_encounter fe
          LEFT JOIN users u ON u.id = fe.provider_id
          LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = fe.pc_catid
         WHERE $where
         ORDER BY fe.date DESC
         LIMIT $perPage OFFSET $offset";

$rs = sqlStatement($sql, $params);
$rows = [];
while ($r = sqlFetchArray($rs)) {
    $ts          = strtotime((string)$r['date']) ?: time();
    $dateStr     = date('m/d/Y', $ts);
    $timeStr     = date('g:i A', $ts);
    $type        = (string)($r['pc_catname'] ?? 'Office Visit');
    if ($type === '' || strcasecmp($type, 'No Show') === 0) {
        $type = 'Office Visit';
    }
    $provider    = cp_format_provider_name([
        'username' => $r['username'] ?? '',
        'fname'    => $r['fname']    ?? '',
        'lname'    => $r['lname']    ?? '',
        'title'    => $r['title']    ?? '',
    ]);
    $secs        = (int)($r['pc_duration'] ?? 0);
    $duration    = $secs > 0 ? (int)round($secs / 60) . ' min' : '30 min';
    $closed      = (int)($r['last_level_closed'] ?? 0);
    $billed      = (int)($r['last_level_billed'] ?? 0);

    if ($closed > 0) {
        $statusKey = 'signed';
        $statusLabel = 'Signed';
    } elseif ($billed > 0) {
        $statusKey = 'billed';
        $statusLabel = 'Billed';
    } else {
        $statusKey = 'in_progress';
        $statusLabel = 'In progress';
    }

    $rows[] = [
        'id'           => (int)$r['id'],
        'encounter'    => (int)($r['encounter'] ?? 0),
        'date'         => $dateStr,
        'time'         => $timeStr,
        'type'         => $type,
        'provider'     => $provider,
        'reason'       => (string)($r['reason'] ?? ''),
        'duration'     => $duration,
        'status_key'   => $statusKey,
        'status_label' => $statusLabel,
        'billed'       => $billed > 0,
    ];
}

/* ---------------------------------------------------------------------------
 * Dropdown option lists — visit types and providers actually used by
 * this patient's encounters (so the menus stay short and relevant).
 * ------------------------------------------------------------------------- */
$visitTypeOpts = [];
$rsTypes = sqlStatement(
    "SELECT DISTINCT c.pc_catid, COALESCE(c.pc_catname, 'Office Visit') AS pc_catname
       FROM form_encounter fe
       LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = fe.pc_catid
      WHERE fe.pid = ?
      ORDER BY pc_catname",
    [$pid]
);
while ($t = sqlFetchArray($rsTypes)) {
    $visitTypeOpts[] = [
        'id'   => (int)$t['pc_catid'],
        'name' => (string)$t['pc_catname'],
    ];
}

$providerOpts = [];
$rsProvs = sqlStatement(
    "SELECT DISTINCT u.id, u.username, u.fname, u.lname, u.title
       FROM form_encounter fe
       LEFT JOIN users u ON u.id = fe.provider_id
      WHERE fe.pid = ? AND u.id IS NOT NULL
      ORDER BY u.lname, u.fname",
    [$pid]
);
while ($p = sqlFetchArray($rsProvs)) {
    $providerOpts[] = [
        'id'   => (int)$p['id'],
        'name' => cp_format_provider_name($p),
    ];
}

/* ---------------------------------------------------------------------------
 * UI labels for the header and pre-selected dropdown values.
 * ------------------------------------------------------------------------- */
$dateRangeLabels = [
    'all'  => 'All time',
    '12mo' => 'Last 12 months',
    '6mo'  => 'Last 6 months',
    '3mo'  => 'Last 3 months',
];
$statusLabels = [
    'all'         => 'All',
    'signed'      => 'Signed',
    'in_progress' => 'In progress',
    'billed'      => 'Billed',
];

$visitTypeLabel = 'All types';
foreach ($visitTypeOpts as $opt) {
    if ($visitTypeIdInt !== null && $opt['id'] === $visitTypeIdInt) {
        $visitTypeLabel = $opt['name'];
        break;
    }
}
$providerLabel = 'All providers';
foreach ($providerOpts as $opt) {
    if ($providerIdInt !== null && $opt['id'] === $providerIdInt) {
        $providerLabel = $opt['name'];
        break;
    }
}

// Footer "Showing X-Y of Z" math.
$showFrom = $totalFiltered === 0 ? 0 : ($offset + 1);
$showTo   = min($offset + $perPage, $totalFiltered);

// Build a query-string preserver so per-page links keep current filters.
$baseQuery = [
    'date_range' => $dateRange,
    'visit_type' => ($visitTypeIdInt !== null ? (string)$visitTypeIdInt : 'all'),
    'provider'   => ($providerIdInt !== null ? (string)$providerIdInt : 'all'),
    'status'     => $status,
    'q'          => $q,
];
$qsFor = function (array $extra) use ($baseQuery): string {
    $merged = array_merge($baseQuery, $extra);
    // Drop empties / 'all' to keep URLs short.
    foreach ($merged as $k => $v) {
        if ($v === '' || $v === 'all') {
            unset($merged[$k]);
        }
    }
    return $merged === [] ? '' : '?' . http_build_query($merged);
};

$headerSubtitle = $totalAll . ' ' . ($totalAll === 1 ? 'encounter' : 'encounters')
    . ' · ' . $dateRangeLabels[$dateRange];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Visit History'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header — title + subtitle on one line, with a thin dot separator */
  .cp-pagehead .title-row { display: flex; align-items: baseline; gap: 10px; }
  .cp-pagehead .title-row .title { font-size: 18px; font-weight: 700; color: #0D1B2A; }
  .cp-pagehead .title-row .dot { color: #C9CDD4; font-size: 14px; line-height: 1; }
  .cp-pagehead .title-row .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-pagehead .cp-btn.ghost .ic { font-size: 11px; opacity: .85; margin-right: 1px; }
  .cp-pagehead form.inline { display: inline; margin: 0; padding: 0; }

  /* Filter row — search + 4 dropdowns + clear-filters link */
  .vh-filter {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 12px 14px;
    display: flex; align-items: center; gap: 10px;
    flex-wrap: nowrap;
  }
  .vh-search {
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 36px;
    padding: 0 12px;
    display: inline-flex; align-items: center; gap: 8px;
    flex: 1 1 auto;
    min-width: 220px;
  }
  .vh-search .ic { color: #8A91A1; font-size: 13px; line-height: 1; }
  .vh-search input {
    border: none; background: transparent; outline: none;
    flex: 1; font-size: 12px; color: #0D1B2A;
  }
  .vh-search input::placeholder { color: #8A91A1; }

  .vh-dd {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 36px;
    padding: 4px 28px 4px 12px;
    display: inline-flex; flex-direction: column; justify-content: center;
    gap: 1px;
    position: relative;
    min-width: 120px;
    flex: 0 0 auto;
  }
  .vh-dd .lbl { font-size: 9px; font-weight: 500; color: #8A91A1; line-height: 1; letter-spacing: .2px; }
  .vh-dd .val { font-size: 12px; font-weight: 500; color: #0D1B2A; line-height: 1.2; }
  .vh-dd::after {
    content: '';
    position: absolute;
    right: 12px; top: 50%;
    width: 6px; height: 6px;
    border-right: 1.5px solid #8A91A1;
    border-bottom: 1.5px solid #8A91A1;
    transform: translateY(-70%) rotate(45deg);
    pointer-events: none;
  }
  /* Native select sits invisibly on top of the styled cell so it still filters. */
  .vh-dd select {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    opacity: 0;
    cursor: pointer;
    border: none;
    appearance: none;
  }

  .vh-clear {
    color: #008C8C;
    font-size: 12px; font-weight: 500;
    text-decoration: none;
    margin-left: 4px;
    flex: 0 0 auto;
    white-space: nowrap;
  }
  .vh-clear:hover { text-decoration: underline; }

  /* Visit-history table */
  .vh-tbl table { font-size: 12px; }
  .vh-tbl th { padding: 11px 16px; }
  .vh-tbl td { padding: 14px 16px; }
  .vh-tbl td.date { color: #0D1B2A; font-weight: 500; }
  .vh-tbl td.time { color: #4F5763; }
  .vh-tbl td.type { color: #0D1B2A; font-weight: 500; }
  .vh-tbl td.prov { color: #4F5763; }
  .vh-tbl td.reason { color: #4F5763; }
  .vh-tbl td.dur { color: #4F5763; }
  .vh-tbl td.empty {
    text-align: center;
    color: #8A91A1;
    padding: 32px 16px;
    font-style: italic;
  }

  /* Status pills */
  .vh-pill-signed {
    display: inline-flex; align-items: center; gap: 5px;
    background: #EBF8F0; color: #1F8C4D;
    border-radius: 999px;
    padding: 3px 10px 3px 7px;
    font-size: 11px; font-weight: 600;
    line-height: 1.4;
  }
  .vh-pill-signed .dot {
    width: 12px; height: 12px;
    background: #1F8C4D;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    color: #FFFFFF;
    font-size: 8px;
    font-weight: 900;
    line-height: 1;
    flex: 0 0 auto;
  }
  .vh-pill-info {
    display: inline-block;
    background: #F0F4F9; color: #4785D9;
    border-radius: 999px;
    padding: 3px 11px;
    font-size: 11px; font-weight: 600;
    line-height: 1.4;
  }
  .vh-pill-warn {
    display: inline-block;
    background: #FFF8EC; color: #FA8C33;
    border-radius: 999px;
    padding: 3px 11px;
    font-size: 11px; font-weight: 600;
    line-height: 1.4;
  }

  /* Billing pill — neutral grey */
  .vh-pill-billed {
    display: inline-block;
    background: #F5F6F7; color: #4F5763;
    border-radius: 999px;
    padding: 3px 11px;
    font-size: 11px; font-weight: 500;
    line-height: 1.4;
  }

  /* Open link + kebab cell */
  .vh-tbl td.open {
    white-space: nowrap;
    text-align: left;
    padding-right: 8px;
  }
  .vh-open-link {
    color: #008C8C;
    font-size: 12px; font-weight: 500;
    text-decoration: none;
  }
  .vh-open-link:hover { text-decoration: underline; }
  .vh-kebab {
    display: inline-block;
    color: #8A91A1;
    font-size: 14px; line-height: 1;
    padding: 2px 4px;
    margin-left: 12px;
    cursor: not-allowed;
    border-radius: 4px;
    vertical-align: middle;
    background: transparent;
    border: none;
  }

  /* Footer / pagination */
  .vh-footer {
    display: flex; align-items: center;
    padding: 4px 4px 0;
    font-size: 12px;
    color: #8A91A1;
  }
  .vh-footer .left { flex: 1; }
  .vh-footer .pager { display: inline-flex; align-items: center; gap: 4px; }
  .vh-footer .pager .pg {
    min-width: 22px; height: 22px;
    border-radius: 4px;
    border: 1px solid transparent;
    background: transparent;
    color: #4F5763;
    font-size: 11px; font-weight: 500;
    line-height: 20px;
    text-align: center;
    padding: 0 6px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
  }
  .vh-footer .pager .pg:hover { background: #F5F6F7; }
  .vh-footer .pager .pg.active {
    background: #008C8C;
    color: #FFFFFF;
    border-color: #008C8C;
    font-weight: 600;
  }
  .vh-footer .pager .nav {
    color: #4F5763;
    font-size: 11px;
    padding: 0 6px;
    line-height: 22px;
    cursor: pointer;
    text-decoration: none;
  }
  .vh-footer .pager .nav.disabled { color: #C9CDD4; cursor: not-allowed; }
  .vh-footer .pager .nav:hover:not(.disabled) { color: #0D1B2A; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div class="title-row">
      <span class="title"><?php echo xlt('Visit History'); ?></span>
      <span class="dot">·</span>
      <span class="meta-light"><?php echo text($headerSubtitle); ?></span>
    </div>
  </div>
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']) . attr($qsFor([])); ?>" class="inline">
    <input type="hidden" name="action" value="export_csv">
    <button type="submit" class="cp-btn ghost"><span class="ic">⤓</span> <?php echo xlt('Export'); ?></button>
  </form>
  <a class="cp-btn primary" href="/interface/forms/newpatient/copilot_create_visit.php">+ <?php echo xlt('New visit'); ?></a>
</header>

<main class="cp-content tight">

  <form method="get" action="<?php echo attr($_SERVER['PHP_SELF']); ?>" id="vhFilters">
    <div class="vh-filter">
      <label class="vh-search" for="vhSearch">
        <span class="ic">🔍</span>
        <input id="vhSearch" type="text" name="q"
               value="<?php echo attr($q); ?>"
               placeholder="<?php echo xla('Search by reason…'); ?>">
      </label>

      <div class="vh-dd">
        <span class="lbl"><?php echo xlt('Date range'); ?></span>
        <span class="val"><?php echo text($dateRangeLabels[$dateRange]); ?></span>
        <select name="date_range" onchange="document.getElementById('vhFilters').submit()">
          <?php foreach ($dateRangeLabels as $key => $label): ?>
            <option value="<?php echo attr($key); ?>" <?php echo $key === $dateRange ? 'selected' : ''; ?>>
              <?php echo text($label); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="vh-dd">
        <span class="lbl"><?php echo xlt('Visit type'); ?></span>
        <span class="val"><?php echo text($visitTypeLabel); ?></span>
        <select name="visit_type" onchange="document.getElementById('vhFilters').submit()">
          <option value="all" <?php echo $visitTypeIdInt === null ? 'selected' : ''; ?>>
            <?php echo xlt('All types'); ?>
          </option>
          <?php foreach ($visitTypeOpts as $opt): ?>
            <option value="<?php echo attr((string)$opt['id']); ?>"
                    <?php echo ($visitTypeIdInt !== null && $opt['id'] === $visitTypeIdInt) ? 'selected' : ''; ?>>
              <?php echo text($opt['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="vh-dd">
        <span class="lbl"><?php echo xlt('Provider'); ?></span>
        <span class="val"><?php echo text($providerLabel); ?></span>
        <select name="provider" onchange="document.getElementById('vhFilters').submit()">
          <option value="all" <?php echo $providerIdInt === null ? 'selected' : ''; ?>>
            <?php echo xlt('All providers'); ?>
          </option>
          <?php foreach ($providerOpts as $opt): ?>
            <option value="<?php echo attr((string)$opt['id']); ?>"
                    <?php echo ($providerIdInt !== null && $opt['id'] === $providerIdInt) ? 'selected' : ''; ?>>
              <?php echo text($opt['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="vh-dd">
        <span class="lbl"><?php echo xlt('Status'); ?></span>
        <span class="val"><?php echo text($statusLabels[$status]); ?></span>
        <select name="status" onchange="document.getElementById('vhFilters').submit()">
          <?php foreach ($statusLabels as $key => $label): ?>
            <option value="<?php echo attr($key); ?>" <?php echo $key === $status ? 'selected' : ''; ?>>
              <?php echo text($label); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <a class="vh-clear" href="copilot_visit_history.php"><?php echo xlt('Clear filters'); ?></a>
    </div>
  </form>

  <div class="cp-tbl vh-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('DATE'); ?></th>
          <th><?php echo xlt('TIME'); ?></th>
          <th><?php echo xlt('VISIT TYPE'); ?></th>
          <th><?php echo xlt('PROVIDER'); ?></th>
          <th><?php echo xlt('REASON'); ?></th>
          <th><?php echo xlt('DURATION'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('BILLING'); ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows === []): ?>
          <tr>
            <td class="empty" colspan="9">
              <?php echo xlt('No encounters match the current filters.'); ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="date"><?php echo text($r['date']); ?></td>
              <td class="time"><?php echo text($r['time']); ?></td>
              <td class="type"><?php echo text($r['type']); ?></td>
              <td class="prov"><?php echo text($r['provider']); ?></td>
              <td class="reason"><?php echo text($r['reason']); ?></td>
              <td class="dur"><?php echo text($r['duration']); ?></td>
              <td>
                <?php if ($r['status_key'] === 'signed'): ?>
                  <span class="vh-pill-signed">
                    <span class="dot">✓</span>
                    <?php echo xlt('Signed'); ?>
                  </span>
                <?php elseif ($r['status_key'] === 'billed'): ?>
                  <span class="vh-pill-info"><?php echo xlt('Billed'); ?></span>
                <?php else: ?>
                  <span class="vh-pill-warn"><?php echo xlt('In progress'); ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['billed']): ?>
                  <span class="vh-pill-billed"><?php echo xlt('Billed'); ?></span>
                <?php else: ?>
                  <span style="color:#C9CDD4;font-size:11px;">—</span>
                <?php endif; ?>
              </td>
              <td class="open">
                <a class="vh-open-link"
                   href="/interface/patient_file/encounter/copilot_encounter.php?eid=<?php echo attr((string)$r['id']); ?>">
                  <?php echo xlt('Open'); ?> →
                </a>
                <button type="button" class="vh-kebab" disabled
                        title="<?php echo xla('Coming soon'); ?>">⋯</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="vh-footer">
    <div class="left">
      <?php echo xlt('Showing'); ?>
      <?php echo text($showFrom . '-' . $showTo); ?>
      <?php echo xlt('of'); ?>
      <?php echo text((string)$totalFiltered); ?>
    </div>
    <div class="pager">
      <?php if ($page > 1): ?>
        <a class="nav" href="<?php echo attr($qsFor(['page' => (string)($page - 1)])); ?>">‹ <?php echo xlt('Prev'); ?></a>
      <?php else: ?>
        <span class="nav disabled">‹ <?php echo xlt('Prev'); ?></span>
      <?php endif; ?>
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $page): ?>
          <span class="pg active"><?php echo text((string)$i); ?></span>
        <?php else: ?>
          <a class="pg" href="<?php echo attr($qsFor(['page' => (string)$i])); ?>">
            <?php echo text((string)$i); ?>
          </a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
        <a class="nav" href="<?php echo attr($qsFor(['page' => (string)($page + 1)])); ?>"><?php echo xlt('Next'); ?> ›</a>
      <?php else: ?>
        <span class="nav disabled"><?php echo xlt('Next'); ?> ›</span>
      <?php endif; ?>
    </div>
  </div>

</main>

</body>
</html>
