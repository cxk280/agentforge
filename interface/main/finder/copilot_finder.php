<?php

/**
 * Patient Finder landing page — implements Figma Screen 10 with a
 * real DB-backed roster.
 *
 * Behavior:
 *   - Pulls live patients from `patient_data` with joins to `users`
 *     (provider name) and subqueries on `insurance_data`,
 *     `form_encounter`, and `lists` (allergies + active conditions).
 *   - Search box (?q=) does a substring match on first/last/full
 *     name or pubpid (MRN).
 *   - Filter chips (?my_panel, ?active, ?ins, ?recent) toggle on/off.
 *     Each chip is a link that GETs back to the same page with the
 *     adjusted querystring; "active" defaults on, the others default off.
 *   - 20 rows per page, Prev/Next at the bottom.
 *   - Clicking (or pressing Enter / Space on) a row navigates the
 *     EMR shell's main iframe to demographics.php?set_pid=N — the
 *     same idiom the legacy `dynamic_finder` uses, which makes the
 *     OpenEMR demographics banner ("header2") appear and unlocks
 *     the patient nav menu (Demographics, History, Co-Pilot ✦, …).
 *
 * Visual layout matches the Figma mock exactly; only the data source
 * is dynamic.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

// ── Query params ──────────────────────────────────────────────────────────

$q             = trim((string)($_GET['q'] ?? ''));
$page          = max(1, (int)($_GET['page'] ?? 1));
$page_size     = 20;
$f_my_panel    = !empty($_GET['my_panel']);
$f_active      = !isset($_GET['active']) || !empty($_GET['active']);  // default on
$f_ins         = !empty($_GET['ins']);
$f_recent      = !empty($_GET['recent']);
$selected_pid  = (int)($_GET['pid'] ?? 0);
$current_user  = (int)($_SESSION['authUserID'] ?? 0);

// ── Build WHERE ───────────────────────────────────────────────────────────

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $where[] = "(pd.fname LIKE ? OR pd.lname LIKE ? "
             . "OR CONCAT(pd.lname, ', ', pd.fname) LIKE ? OR pd.pubpid LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($f_my_panel && $current_user > 0) {
    $where[]  = "pd.providerID = ?";
    $params[] = $current_user;
}
if ($f_active) {
    // OpenEMR marks inactive/deceased patients via deceased_date.
    $where[] = "(pd.deceased_date IS NULL OR pd.deceased_date = '0000-00-00 00:00:00')";
}
if ($f_ins) {
    $where[] = "EXISTS (SELECT 1 FROM insurance_data id WHERE id.pid = pd.pid)";
}
if ($f_recent) {
    $where[] = "EXISTS (SELECT 1 FROM form_encounter fe "
             . "WHERE fe.pid = pd.pid AND fe.date >= DATE_SUB(NOW(), INTERVAL 12 MONTH))";
}

$where_sql = implode(' AND ', $where);

// ── Total count + page rows ───────────────────────────────────────────────

$count_row = sqlQuery("SELECT COUNT(*) AS c FROM patient_data pd WHERE $where_sql", $params);
$total     = (int)($count_row['c'] ?? 0);
$last_page = max(1, (int)ceil($total / $page_size));
$page      = min($page, $last_page);
$offset    = ($page - 1) * $page_size;

$sql = "SELECT
    pd.pid, pd.pubpid, pd.fname, pd.lname, pd.DOB, pd.providerID,
    NULLIF(TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))), '') AS provider_name,
    (SELECT plan_name FROM insurance_data
       WHERE pid = pd.pid AND type='primary' ORDER BY date DESC LIMIT 1) AS insurance,
    (SELECT MAX(date) FROM form_encounter WHERE pid = pd.pid) AS last_visit,
    (SELECT title FROM lists
       WHERE pid = pd.pid AND type='allergy' AND activity=1 ORDER BY id LIMIT 1) AS top_allergy,
    (SELECT COUNT(*) FROM lists
       WHERE pid = pd.pid AND type='allergy' AND activity=1) AS allergy_count,
    (SELECT title FROM lists
       WHERE pid = pd.pid AND type='medical_problem' AND activity=1 ORDER BY id LIMIT 1) AS top_condition,
    (SELECT COUNT(*) FROM lists
       WHERE pid = pd.pid AND type='medical_problem' AND activity=1) AS condition_count
  FROM patient_data pd
  LEFT JOIN users u ON u.id = pd.providerID
  WHERE $where_sql
  ORDER BY pd.lname, pd.fname
  LIMIT $page_size OFFSET $offset";

$result = sqlStatement($sql, $params);

// ── Helpers ───────────────────────────────────────────────────────────────

$avatar_palette = ['#5FD0D0', '#4885D9', '#FA8C33', '#8561C7', '#33A68C', '#D9668C'];

/** First letter of first + last name, uppercased. */
function cp_initials(string $first, string $last): string
{
    $a = $first !== '' ? mb_strtoupper(mb_substr($first, 0, 1)) : '';
    $b = $last  !== '' ? mb_strtoupper(mb_substr($last,  0, 1)) : '';
    $i = $a . $b;
    return $i !== '' ? $i : '?';
}

function cp_age(?string $dob): ?int
{
    if (!$dob || $dob === '0000-00-00') {
        return null;
    }
    try {
        return (int)(new DateTime($dob))->diff(new DateTime())->y;
    } catch (Exception $e) {
        return null;
    }
}

function cp_dob_display(?string $dob): string
{
    if (!$dob || $dob === '0000-00-00') {
        return '—';
    }
    $d = DateTime::createFromFormat('Y-m-d', $dob);
    if (!$d) {
        return '—';
    }
    $age = cp_age($dob);
    return $d->format('m/d/Y') . ($age !== null ? " ($age yrs)" : '');
}

/** Returns [display_string, is_today_bool]. */
function cp_last_visit_display(?string $dt): array
{
    if (!$dt || str_starts_with($dt, '0000')) {
        return ['—', false];
    }
    try {
        $d = new DateTime($dt);
        $is_today = $d->format('Y-m-d') === (new DateTime())->format('Y-m-d');
        return [$is_today ? 'Today' : $d->format('M d'), $is_today];
    } catch (Exception $e) {
        return ['—', false];
    }
}

/** Common condition shorthand for the flag pill; falls back to first word. */
function cp_short_condition(?string $title): string
{
    if (!$title) {
        return '';
    }
    $lookup = [
        'Type 2 Diabetes Mellitus'      => 'T2DM',
        'Type 1 Diabetes Mellitus'      => 'T1DM',
        'Diabetes Mellitus'             => 'DM',
        'Hypertension'                  => 'HTN',
        'Chronic Kidney Disease'        => 'CKD',
        'Hyperlipidemia'                => 'HLD',
        'Congestive Heart Failure'      => 'CHF',
        'Coronary Artery Disease'       => 'CAD',
        'Atrial Fibrillation'           => 'AFib',
        'Chronic Obstructive Pulmonary' => 'COPD',
        'Gastroesophageal Reflux'       => 'GERD',
        'Asthma'                        => 'Asthma',
        'Anxiety'                       => 'Anxiety',
        'Depression'                    => 'Depression',
        'Obesity'                       => 'Obesity',
    ];
    foreach ($lookup as $needle => $abbr) {
        if (stripos($title, $needle) !== false) {
            return $abbr;
        }
    }
    $first = strtok($title, ' ');
    return $first !== false ? mb_substr($first, 0, 12) : '';
}

/** Build a self-link that adjusts the current querystring. */
function cp_filter_url(array $set, array $unset = []): string
{
    parse_str($_SERVER['QUERY_STRING'] ?? '', $cur);
    foreach ($set as $k => $v) {
        $cur[$k] = $v;
    }
    foreach ($unset as $k) {
        unset($cur[$k]);
    }
    // any filter change resets to page 1
    unset($cur['page']);
    return '?' . http_build_query($cur);
}

// ── Materialize the rows array for rendering ──────────────────────────────

$rows = [];
while ($p = sqlFetchArray($result)) {
    $first = trim((string)$p['fname']);
    $last  = trim((string)$p['lname']);
    [$visit_label, $is_today] = cp_last_visit_display($p['last_visit'] ?? null);

    $top_allergy   = $p['top_allergy']   ?? null;
    $top_condition = $p['top_condition'] ?? null;
    $allergy_count = (int)($p['allergy_count']   ?? 0);

    $flags = [];
    if ($top_allergy) {
        $extra   = $allergy_count > 1 ? ' +' . ($allergy_count - 1) : '';
        $flags[] = ['warn', '⚠ ' . $top_allergy . $extra];
    }
    if ($top_condition) {
        $flags[] = ['cond', '🩺 ' . cp_short_condition($top_condition)];
    }

    $provider  = trim((string)($p['provider_name'] ?? ''));
    $insurance = trim((string)($p['insurance']     ?? ''));

    // Pre-compute every field `left_nav.setPatient` needs so the click
    // handler can update header2 without waiting for demographics.php to
    // finish rendering. setPatient signature:
    //   setPatient(pname, pid, pubpid, frname, str_dob, provider, insurance, allergies)
    // pname uses the same "First Last" form OpenEMR's other call sites
    // produce; str_dob mirrors the format demographics.php builds.
    $allergy_titles = [];
    $allergy_res = sqlStatement(
        "SELECT title FROM lists WHERE pid = ? AND type = 'allergy' AND activity = 1 "
      . "AND (enddate IS NULL OR enddate = '0000-00-00' OR enddate > CURDATE()) ORDER BY id",
        [(int)$p['pid']]
    );
    while ($a = sqlFetchArray($allergy_res)) {
        if (!empty($a['title'])) {
            $allergy_titles[] = $a['title'];
        }
    }

    $age_yrs   = cp_age($p['DOB'] ?? null);
    $dob_full  = cp_dob_display($p['DOB'] ?? null);
    $str_dob   = $dob_full === '—'
        ? 'DOB: —'
        : 'DOB: ' . $dob_full;

    $set_patient_payload = [
        'pname'     => trim("$first $last"),
        'pid'       => (int)$p['pid'],
        'pubpid'    => (string)$p['pubpid'],
        'str_dob'   => $str_dob,
        'provider'  => $provider,
        'insurance' => $insurance,
        'allergies' => $allergy_titles,
    ];

    $rows[] = [
        'pid'          => (int)$p['pid'],
        'initials'     => cp_initials($first, $last),
        'avatar_color' => $avatar_palette[((int)$p['pid']) % count($avatar_palette)],
        'name'         => $last !== '' ? "$last, $first" : $first,
        'mrn'          => $p['pubpid'] !== '' ? '#' . $p['pubpid'] : '—',
        'dob'          => $dob_full,
        'provider'     => $provider  !== '' ? $provider  : '—',
        'insurance'    => $insurance !== '' ? $insurance : '—',
        'last_visit'   => $visit_label,
        'today'        => $is_today,
        'selected'     => $selected_pid > 0 && (int)$p['pid'] === $selected_pid,
        'flags'        => $flags,
        'set_patient_payload' => $set_patient_payload,
    ];
}

// ── Filter-chip definitions (each chip is a link that toggles its state) ─

$filters = [
    [
        'label'  => 'My panel',
        'active' => $f_my_panel,
        'href'   => $f_my_panel
            ? cp_filter_url([], ['my_panel'])
            : cp_filter_url(['my_panel' => 1]),
    ],
    [
        'label'  => 'All providers',
        'active' => !$f_my_panel,
        'href'   => cp_filter_url([], ['my_panel']),
    ],
    [
        'label'  => 'Active only',
        'active' => $f_active,
        'href'   => $f_active
            ? cp_filter_url(['active' => 0])
            : cp_filter_url([], ['active']),
    ],
    [
        'label'  => 'Insurance: ' . ($f_ins ? 'Yes' : 'Any'),
        'active' => $f_ins,
        'href'   => $f_ins
            ? cp_filter_url([], ['ins'])
            : cp_filter_url(['ins' => 1]),
    ],
    [
        'label'  => 'Last visit ≤ 12 mo',
        'active' => $f_recent,
        'href'   => $f_recent
            ? cp_filter_url([], ['recent'])
            : cp_filter_url(['recent' => 1]),
    ],
];

$cols = [
    ['NAME',       'col-name'],
    ['MRN',        'col-mrn'],
    ['DOB / AGE',  'col-dob'],
    ['PROVIDER',   'col-provider'],
    ['INSURANCE',  'col-insurance'],
    ['LAST VISIT', 'col-visit'],
    ['FLAGS',      'col-flags'],
];

// Build the prev/next page links carrying all current query params.
$prev_url = $page > 1          ? cp_filter_url(['page' => $page - 1]) : null;
$next_url = $page < $last_page ? cp_filter_url(['page' => $page + 1]) : null;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Finder'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F7F8;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
  }
  button, input { font-family: inherit; }
  a { color: inherit; text-decoration: none; }

  /* ── Page header ─────────────────────────────────────────────────────── */
  .cp-pf-header {
    width: 100%;
    height: 64px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 16px;
  }
  .cp-pf-title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-pf-spacer { flex: 1; }

  .cp-pf-search {
    display: inline-flex; align-items: center; gap: 10px;
    background: #F5F7F8;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 8px 18px;
    height: 40px;
    min-width: 200px;
  }
  .cp-pf-search-icon { font-size: 14px; color: #8A91A0; line-height: 1; }
  .cp-pf-search-input {
    flex: 1;
    border: 0;
    background: transparent;
    color: #0D1B2A;
    font-size: 14px; font-weight: 500;
    line-height: 1;
    outline: none;
    padding: 0;
    min-width: 60px;
  }

  .cp-pf-new {
    display: inline-flex; align-items: center; gap: 6px;
    background: #008C8C;
    color: #FFFFFF;
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 13px; font-weight: 600;
    border: none;
    cursor: pointer;
    line-height: 1;
  }
  .cp-pf-new:hover { background: #006F6F; }
  .cp-pf-new-plus { font-size: 16px; font-weight: 700; line-height: 1; }

  /* ── Filter row ──────────────────────────────────────────────────────── */
  .cp-pf-filters {
    width: 100%;
    height: 52px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 8px;
  }
  .cp-pf-filter-label {
    font-size: 12px; font-weight: 500; color: #8A91A0;
    margin-right: 4px;
    line-height: 1;
  }
  .cp-pf-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: #F5F7F8;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 12px; font-weight: 500;
    color: #4F5662;
    cursor: pointer;
    line-height: 1.2;
  }
  .cp-pf-chip:hover { background: #ECEFF1; }
  .cp-pf-chip.active {
    background: rgba(0, 140, 140, 0.10);
    border-color: #008C8C;
    color: #008C8C;
  }
  .cp-pf-chip.active:hover { background: rgba(0, 140, 140, 0.16); }
  .cp-pf-chip-x {
    font-size: 12px; font-weight: 700;
    color: #008C8C;
    line-height: 1;
  }
  .cp-pf-results-count {
    margin-left: auto;
    font-size: 12px; font-weight: 500; color: #8A91A0;
    line-height: 1;
  }

  /* ── Results table ───────────────────────────────────────────────────── */
  .cp-pf-table-wrap {
    padding: 20px 24px;
  }
  .cp-pf-table {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
    width: 100%;
  }
  .cp-pf-thead {
    display: grid;
    grid-template-columns:
      280px 120px 180px 180px 200px 140px 1fr;
    height: 44px;
    background: #F5F7F8;
    align-items: center;
    padding: 0 18px;
  }
  .cp-pf-th {
    font-size: 11px; font-weight: 600;
    color: #8A91A0;
    letter-spacing: 0.5px;
    line-height: 1;
  }
  .cp-pf-row {
    display: grid;
    grid-template-columns:
      280px 120px 180px 180px 200px 140px 1fr;
    height: 84px;
    align-items: center;
    padding: 0 18px;
    border-top: 1px solid #E4E5E8;
    background: #FFFFFF;
  }
  .cp-pf-row.selected { background: rgba(0, 140, 140, 0.04); }
  .cp-pf-row:hover { background: #FAFBFB; cursor: pointer; }
  .cp-pf-row.selected:hover { background: rgba(0, 140, 140, 0.06); cursor: pointer; }
  .cp-pf-row:focus-visible { outline: 2px solid #008C8C; outline-offset: -2px; }

  .cp-pf-cell-name {
    display: inline-flex; align-items: center; gap: 12px;
  }
  .cp-pf-avatar {
    width: 36px; height: 36px;
    border-radius: 18px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #FFFFFF;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    flex: 0 0 auto;
  }
  .cp-pf-name { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }

  .cp-pf-mrn   { font-size: 12px; font-weight: 500; color: #4F5662; }
  .cp-pf-dob   { font-size: 12px; color: #0D1B2A; }
  .cp-pf-prov  { font-size: 12px; color: #0D1B2A; }
  .cp-pf-ins   { font-size: 12px; color: #0D1B2A; }
  .cp-pf-visit { font-size: 12px; color: #0D1B2A; }
  .cp-pf-visit.today { color: #008C8C; font-weight: 600; }

  .cp-pf-flags { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .cp-pf-flag {
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 10px; font-weight: 500;
    line-height: 1.2;
  }
  .cp-pf-flag.warn { background: rgba(217, 56, 56, 0.12); color: #D93838; }
  .cp-pf-flag.cond { background: rgba(0, 140, 140, 0.12); color: #008C8C; }
  .cp-pf-flag.rx   { background: rgba(0, 140, 140, 0.12); color: #008C8C; }

  /* ── Empty state + pagination ────────────────────────────────────────── */
  .cp-pf-empty {
    padding: 48px 24px;
    text-align: center;
    color: #8A91A0;
    font-size: 13px;
  }
  .cp-pf-pager {
    display: flex; align-items: center; justify-content: flex-end;
    gap: 8px;
    padding: 12px 24px 24px;
    font-size: 12px;
    color: #4F5662;
  }
  .cp-pf-pager a, .cp-pf-pager span.disabled {
    display: inline-flex; align-items: center;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 5px 12px;
    background: #FFFFFF;
    color: #4F5662;
    font-weight: 500;
  }
  .cp-pf-pager span.disabled { color: #C0C5CC; cursor: not-allowed; }
  .cp-pf-pager a:hover { background: #F5F7F8; }
  .cp-pf-pager .cp-pf-pager-status { margin-right: 8px; color: #8A91A0; }
</style>
</head>
<body>

<header class="cp-pf-header">
  <div class="cp-pf-title"><?php echo xlt('Patient Finder'); ?></div>
  <div class="cp-pf-spacer"></div>
  <form method="GET" action="" style="display:inline-flex; align-items:center;">
    <?php // preserve any active filter params across a search submit ?>
    <?php foreach (['my_panel', 'active', 'ins', 'recent', 'pid'] as $persist): ?>
      <?php if (isset($_GET[$persist])): ?>
        <input type="hidden" name="<?php echo attr($persist); ?>" value="<?php echo attr($_GET[$persist]); ?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <label class="cp-pf-search">
      <span class="cp-pf-search-icon">🔍</span>
      <input class="cp-pf-search-input" type="text" name="q"
             value="<?php echo attr($q); ?>"
             placeholder="<?php echo xla('Search patients'); ?>"
             autocomplete="off">
    </label>
  </form>
  <a class="cp-pf-new" href="/interface/new/copilot_new_patient.php" target="_self" role="button">
    <span class="cp-pf-new-plus">+</span>
    <span><?php echo xlt('New Patient'); ?></span>
  </a>
</header>

<div class="cp-pf-filters">
  <span class="cp-pf-filter-label"><?php echo xlt('Filters:'); ?></span>
  <?php foreach ($filters as $f): ?>
    <a class="cp-pf-chip<?php echo $f['active'] ? ' active' : ''; ?>"
       href="<?php echo attr($f['href']); ?>">
      <span><?php echo text($f['label']); ?></span>
      <?php if ($f['active']): ?>
        <span class="cp-pf-chip-x">×</span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  <span class="cp-pf-results-count">
    <?php echo text($total); ?> <?php echo xlt('results'); ?>
  </span>
</div>

<div class="cp-pf-table-wrap">
  <div class="cp-pf-table">
    <div class="cp-pf-thead">
      <?php foreach ($cols as $c): ?>
        <div class="cp-pf-th"><?php echo text($c[0]); ?></div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($rows)): ?>
      <div class="cp-pf-empty">
        <?php if ($q !== ''): ?>
          <?php echo xlt('No patients match your search.'); ?>
        <?php else: ?>
          <?php echo xlt('No patients match the current filters.'); ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <?php
          // Each row carries the data left_nav.setPatient needs so the
          // click handler can paint header2 immediately, in parallel with
          // demographics.php loading. Stored as a JSON-encoded data
          // attribute and decoded in cpSelectPatient.
          $pdata_json = json_encode($r['set_patient_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>
        <div class="cp-pf-row<?php echo $r['selected'] ? ' selected' : ''; ?>"
             data-pid="<?php echo attr($r['pid']); ?>"
             data-pdata="<?php echo attr($pdata_json); ?>"
             onclick="cpSelectPatient(this)"
             role="button"
             tabindex="0"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();cpSelectPatient(this);}"
             title="Open <?php echo attr($r['name']); ?>">
          <div class="cp-pf-cell-name">
            <span class="cp-pf-avatar"
                  style="background-color: <?php echo attr($r['avatar_color']); ?>;">
              <?php echo text($r['initials']); ?>
            </span>
            <span class="cp-pf-name"><?php echo text($r['name']); ?></span>
          </div>
          <div class="cp-pf-mrn"><?php echo text($r['mrn']); ?></div>
          <div class="cp-pf-dob"><?php echo text($r['dob']); ?></div>
          <div class="cp-pf-prov"><?php echo text($r['provider']); ?></div>
          <div class="cp-pf-ins"><?php echo text($r['insurance']); ?></div>
          <div class="cp-pf-visit<?php echo $r['today'] ? ' today' : ''; ?>"><?php echo text($r['last_visit']); ?></div>
          <div class="cp-pf-flags">
            <?php foreach ($r['flags'] as $fl): ?>
              <span class="cp-pf-flag <?php echo attr($fl[0]); ?>"><?php echo text($fl[1]); ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($last_page > 1): ?>
    <div class="cp-pf-pager">
      <span class="cp-pf-pager-status">
        <?php echo xlt('Page'); ?> <?php echo text($page); ?>
        <?php echo xlt('of'); ?>   <?php echo text($last_page); ?>
      </span>
      <?php if ($prev_url): ?>
        <a href="<?php echo attr($prev_url); ?>">&larr; <?php echo xlt('Prev'); ?></a>
      <?php else: ?>
        <span class="disabled">&larr; <?php echo xlt('Prev'); ?></span>
      <?php endif; ?>
      <?php if ($next_url): ?>
        <a href="<?php echo attr($next_url); ?>"><?php echo xlt('Next'); ?> &rarr;</a>
      <?php else: ?>
        <span class="disabled"><?php echo xlt('Next'); ?> &rarr;</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
// Clear any prior patient context the moment the finder loads. Without
// this, header2 (the demographics banner) keeps showing the previously
// selected patient even after the user has navigated back to the
// finder to look for someone else — which makes the EMR look stuck on
// the wrong patient. `top.clearPatient(false)` is OpenEMR's canonical
// "drop the active patient" hook (see tabs_view_model.js); the `false`
// arg tells it not to re-navigate to the finder (we're already here).
try {
    if (typeof top !== 'undefined' && typeof top.clearPatient === 'function') {
        top.clearPatient(false);
    }
} catch (e) { /* finder loaded outside EMR shell — nothing to clear */ }

// Clicking a patient row needs to (a) paint header2 with the new patient
// instantly — no waiting for demographics.php to round-trip — and
// (b) navigate the main iframe to demographics so the chart loads.
//
// We achieve (a) by calling left_nav.setPatient directly from the
// finder using data we've already rendered server-side (name, pid,
// pubpid, str_dob, provider, insurance, allergies — see the row's
// data-pdata attribute). That repaints header2 in the parent frame
// immediately. demographics.php's own setMyPatient onload will later
// call setPatient again with the same pid; left_nav.setPatient
// short-circuits when the pid hasn't changed (frame_proxies.js:29),
// so the second call only refreshes already-current data.
//
// We achieve (b) by also navigating top.RTop to demographics.php with
// set_pid=N — the same idiom the legacy dynamic_finder uses. This
// also tells the OpenEMR session middleware to switch the active
// patient on the server side.
function cpSelectPatient(rowEl) {
    if (!rowEl) return;
    var pid = parseInt(rowEl.getAttribute('data-pid') || '0', 10);
    if (!pid) return;

    // (a) Update header2 immediately with the patient data we already have.
    try {
        var pdata = JSON.parse(rowEl.getAttribute('data-pdata') || '{}');
        if (top && top.left_nav && typeof top.left_nav.setPatient === 'function') {
            top.left_nav.setPatient(
                pdata.pname || '',
                pdata.pid,
                pdata.pubpid || '',
                '',
                pdata.str_dob || '',
                pdata.provider || '',
                pdata.insurance || '',
                Array.isArray(pdata.allergies) ? pdata.allergies : []
            );
        }
    } catch (e) {
        // setPatient call is the optimization path; if it fails we
        // still navigate, demographics.php will paint header2 normally.
    }

    // (b) Navigate to demographics so the chart loads + the server-side
    //     session active-patient gets set via set_pid.
    var target = "../../patient_file/summary/demographics.php?set_pid=" + encodeURIComponent(pid);
    if (top && top.RTop) {
        top.RTop.location = target;
    } else if (window.top && window.top !== window) {
        window.top.location.href = target;
    } else {
        window.location.href = target;
    }
}
</script>

</body>
</html>
