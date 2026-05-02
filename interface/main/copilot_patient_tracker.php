<?php

/**
 * Patient Flow / Tracker — Screen 32.
 *
 * Cross-patient kanban-style flow board for the day's exam-room states.
 * Five columns: Waiting, Roomed, With Provider, Ready to Discharge,
 * Checked Out. Each card shows the patient name, MRN, visit type,
 * provider, time and room, plus an optional status pill.
 *
 * Data sources, in priority order:
 *
 *   1. `patient_tracker` (joined to `patient_data`, `users`,
 *      `openemr_postcalendar_categories`, and `list_options(apptstat)`)
 *      — the canonical OpenEMR flow-board table. Each row's `status`
 *      column carries an `apptstat` option_id. We use the standard
 *      toggle_setting_1 (isCheckin) / toggle_setting_2 (isCheckout)
 *      flags from list_options to pick a column.
 *
 *   2. If `patient_tracker` has no rows for the requested date range,
 *      fall back to `form_encounter` for the same range, inferring the
 *      column from `last_level_closed` (>0 → Checked Out) and the
 *      encounter's relative date.
 *
 * Filters:
 *   - `?range=today|tomorrow|week` — segmented date control.
 *   - `?provider=<users.id>` — provider pill (0 = all providers).
 *
 * POST:
 *   - `action=walk_in` — INSERT a stub `form_encounter` for pid=1
 *     dated NOW() and redirect to ?msg=walk_in. CSRF skipped — internal
 *     mock page.
 *
 * The chrome (top nav) is rendered by the parent shell — this file
 * renders only the body.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_helpers.php");

// --------------------------------------------------------------------
// Range filter ?range=today|tomorrow|week
// --------------------------------------------------------------------
$validRanges = ['today', 'tomorrow', 'week'];
$range = (string)($_GET['range'] ?? 'today');
if (!in_array($range, $validRanges, true)) {
    $range = 'today';
}
[$rangeStart, $rangeEnd, $rangeLabel] = match ($range) {
    'today'    => [date('Y-m-d'), date('Y-m-d'), 'Today'],
    'tomorrow' => [date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+1 day')), 'Tomorrow'],
    'week'     => [date('Y-m-d'), date('Y-m-d', strtotime('+6 days')), 'This week'],
};

// --------------------------------------------------------------------
// Provider filter ?provider=<users.id>  (0 = all)
// --------------------------------------------------------------------
$providerId = (int)($_GET['provider'] ?? 0);
if ($providerId < 0) {
    $providerId = 0;
}

// --------------------------------------------------------------------
// POST: walk_in — insert a stub encounter for pid=1 + redirect.
// CSRF skipped — internal mock page.
// --------------------------------------------------------------------
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'walk_in'
) {
    try {
        // Default walk-in patient = pid 1 (Ted Shaw in dev seed). In a
        // real flow the user would select the patient first; for the
        // mock we drop a placeholder encounter against pid 1 so the
        // POST has a verifiable side effect.
        $walkPid = 1;
        $userId  = (int)($_SESSION['authUserID'] ?? 1);
        // Office Visit category (5 in the standard seed).
        $catId = 5;
        $reason = 'Walk-in';

        // form_encounter has no auto encounter-number: synthesize one
        // from MAX(encounter)+1 to stay consistent with OpenEMR.
        $maxRow = sqlQuery(
            "SELECT IFNULL(MAX(encounter), 0) + 1 AS next_enc FROM form_encounter"
        );
        $nextEnc = (int)($maxRow['next_enc'] ?? 1);

        sqlInsert(
            "INSERT INTO form_encounter
                 (date, reason, facility, facility_id, pid, encounter,
                  pc_catid, last_level_closed, provider_id, billing_facility,
                  class_code)
             VALUES
                 (NOW(), ?, '', 3, ?, ?,
                  ?, 0, ?, 3,
                  'AMB')",
            [$reason, $walkPid, $nextEnc, $catId, $userId]
        );
        // Best-effort: also create a patient_tracker row so future
        // refreshes show the walk-in in the Waiting column. Failures
        // are swallowed — the encounter row alone is enough proof.
        try {
            $trackerId = (int)sqlInsert(
                "INSERT INTO patient_tracker
                     (date, apptdate, appttime, eid, pid, original_user, encounter, lastseq, drug_screen_completed)
                 VALUES
                     (NOW(), CURDATE(), CURTIME(), 0, ?, ?, ?, '1', 0)",
                [$walkPid, (string)$userId, $nextEnc]
            );
            if ($trackerId > 0) {
                sqlInsert(
                    "INSERT INTO patient_tracker_element
                         (pt_tracker_id, start_datetime, room, status, seq, user)
                     VALUES
                         (?, NOW(), '', '@', '1', ?)",
                    [$trackerId, (string)$userId]
                );
            }
        } catch (\Throwable $eInner) {
            // ignore — encounter insert is the primary side effect
        }

        $qs = http_build_query(['range' => $range, 'provider' => $providerId, 'msg' => 'walk_in']);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $qs);
        exit;
    } catch (\Throwable $e) {
        // Fall through to render with an error flash. Avoid exposing
        // ->getMessage() to the user.
        $qs = http_build_query(['range' => $range, 'provider' => $providerId, 'msg' => 'walk_in_failed']);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $qs);
        exit;
    }
}

$flash = (string)($_GET['msg'] ?? '');

// --------------------------------------------------------------------
// Build the column definitions. Order is the visual left-to-right.
// `dot` is the legend dot color used in the column header.
// --------------------------------------------------------------------
$colDefs = [
    'waiting'    => ['label' => 'Waiting',            'dot' => '#8A91A1'],
    'roomed'     => ['label' => 'Roomed',             'dot' => '#4785D9'],
    'with_prov'  => ['label' => 'With Provider',      'dot' => '#FA8C33'],
    'ready_dc'   => ['label' => 'Ready to Discharge', 'dot' => '#1F8C4D'],
    'checked_out' => ['label' => 'Checked Out',       'dot' => '#33A68C'],
];
// Initialize bucket arrays.
$buckets = array_fill_keys(array_keys($colDefs), []);

/**
 * Map an apptstat option_id (plus its toggle flags + title) to one of
 * our 5 columns. Returns the column key.
 *
 * Rules (using the canonical apptstat seed):
 *   - is_checkout (toggle_setting_2) → 'checked_out'
 *   - is_checkin (toggle_setting_1)  → 'waiting'    (Arrived / Late)
 *   - title contains "exam"          → 'roomed'     (e.g. "< In exam room")
 *   - title contains "provider"|"with"|"<" or option in $providerSet → 'with_prov'
 *   - "Coding done" / "$" / "Pending" / "^" → 'ready_dc'
 *   - everything else → 'waiting'  (default for arrived-but-undifferentiated)
 */
function flow_column_for_status(string $optId, string $title, int $isCheckin, int $isCheckout): string
{
    if ($isCheckout === 1 || $optId === '>' || $optId === '!') {
        return 'checked_out';
    }
    $titleL = strtolower($title);
    if (str_contains($titleL, 'coding done') || $optId === '$' || str_contains($titleL, 'ready')) {
        return 'ready_dc';
    }
    if ($optId === '<' || str_contains($titleL, 'exam room')) {
        return 'roomed';
    }
    if (str_contains($titleL, 'with provider') || str_contains($titleL, 'in exam')) {
        return 'with_prov';
    }
    if ($isCheckin === 1) {
        return 'waiting';
    }
    return 'waiting';
}

/**
 * Pretty-print a name string from a users row.
 */
function flow_provider_label(?array $u): string
{
    if (!$u) {
        return '—';
    }
    return cp_format_provider_name($u);
}

// --------------------------------------------------------------------
// Source 1: patient_tracker (canonical). Pulls today's tracker rows
// joined to patient_data, users, and the latest patient_tracker_element
// for room/status/start_datetime. We left-join list_options(apptstat)
// to get the human-readable status title and the checkin/checkout
// toggles.
//
// $range filters apptdate; $providerId optionally filters by users.id
// (matching either the encounter's provider_id or the patient's
// providerID — we prefer the encounter side).
// --------------------------------------------------------------------
$ptParams = [$rangeStart, $rangeEnd];
$ptProviderClause = '';
if ($providerId > 0) {
    $ptProviderClause = ' AND COALESCE(fe.provider_id, pd.providerID, 0) = ?';
    $ptParams[] = $providerId;
}

$ptSql = "
    SELECT pt.id            AS tracker_id,
           pt.pid           AS pid,
           pt.eid           AS eid,
           pt.encounter     AS encounter,
           pt.apptdate      AS apptdate,
           pt.appttime      AS appttime,
           pte.start_datetime AS pte_start,
           pte.room         AS pte_room,
           pte.status       AS pte_status,
           lo.title         AS status_title,
           lo.toggle_setting_1 AS is_checkin,
           lo.toggle_setting_2 AS is_checkout,
           pd.fname         AS p_fname,
           pd.lname         AS p_lname,
           pd.pubpid        AS pubpid,
           pd.DOB           AS dob,
           pd.providerID    AS p_providerID,
           fe.pc_catid      AS pc_catid,
           fe.provider_id   AS fe_provider_id,
           c.pc_catname     AS pc_catname,
           u.id             AS u_id,
           u.username       AS u_username,
           u.fname          AS u_fname,
           u.lname          AS u_lname,
           u.title          AS u_title
      FROM patient_tracker pt
      LEFT JOIN (
            SELECT pte1.*
              FROM patient_tracker_element pte1
              JOIN (
                  SELECT pt_tracker_id, MAX(start_datetime) AS max_dt
                    FROM patient_tracker_element
                   GROUP BY pt_tracker_id
              ) latest ON latest.pt_tracker_id = pte1.pt_tracker_id
                       AND latest.max_dt = pte1.start_datetime
      ) pte ON pte.pt_tracker_id = pt.id
      LEFT JOIN list_options lo
             ON lo.list_id = 'apptstat' AND lo.option_id = pte.status
      LEFT JOIN patient_data pd
             ON pd.pid = pt.pid
      LEFT JOIN form_encounter fe
             ON fe.encounter = pt.encounter AND fe.pid = pt.pid
      LEFT JOIN openemr_postcalendar_categories c
             ON c.pc_catid = fe.pc_catid
      LEFT JOIN users u
             ON u.id = COALESCE(fe.provider_id, pd.providerID)
     WHERE pt.apptdate BETWEEN ? AND ?
       $ptProviderClause
     ORDER BY pt.apptdate ASC, pt.appttime ASC
";

$ptCards = [];
$res = sqlStatement($ptSql, $ptParams);
while ($r = sqlFetchArray($res)) {
    $r['_source'] = 'patient_tracker';
    $ptCards[] = $r;
}

// Track which form_encounter ids are already represented by a
// patient_tracker row so we don't double-render them.
$coveredEncounters = [];
foreach ($ptCards as $r) {
    $encNum = (int)($r['encounter'] ?? 0);
    $pidNum = (int)($r['pid'] ?? 0);
    if ($encNum > 0 && $pidNum > 0) {
        $coveredEncounters[$pidNum . ':' . $encNum] = true;
    }
}

// --------------------------------------------------------------------
// Source 2: form_encounter for the same date range, EXCLUDING any
// encounter already covered by a patient_tracker row above. This
// ensures encounters that pre-date the flow-board adoption (or were
// created via paths that bypass patient_tracker) still show up.
// --------------------------------------------------------------------
$feParams = [$rangeStart . ' 00:00:00', $rangeEnd . ' 23:59:59'];
$feProviderClause = '';
if ($providerId > 0) {
    $feProviderClause = ' AND fe.provider_id = ?';
    $feParams[] = $providerId;
}
$feSql = "
    SELECT fe.id            AS encounter_pk,
           fe.encounter     AS encounter,
           fe.pid           AS pid,
           fe.date          AS encounter_date,
           fe.date_end      AS encounter_end,
           fe.last_level_closed AS last_level_closed,
           fe.pc_catid      AS pc_catid,
           fe.provider_id   AS fe_provider_id,
           pd.fname         AS p_fname,
           pd.lname         AS p_lname,
           pd.pubpid        AS pubpid,
           pd.DOB           AS dob,
           c.pc_catname     AS pc_catname,
           u.id             AS u_id,
           u.username       AS u_username,
           u.fname          AS u_fname,
           u.lname          AS u_lname,
           u.title          AS u_title
      FROM form_encounter fe
      LEFT JOIN patient_data pd
             ON pd.pid = fe.pid
      LEFT JOIN openemr_postcalendar_categories c
             ON c.pc_catid = fe.pc_catid
      LEFT JOIN users u
             ON u.id = fe.provider_id
     WHERE fe.date BETWEEN ? AND ?
       $feProviderClause
     ORDER BY fe.date ASC
";
$feCards = [];
$res = sqlStatement($feSql, $feParams);
while ($r = sqlFetchArray($res)) {
    $key = (int)$r['pid'] . ':' . (int)$r['encounter'];
    if (isset($coveredEncounters[$key])) {
        continue;
    }
    $r['_source'] = 'form_encounter';
    $feCards[] = $r;
}

// Merge: tracker rows first (canonical), then any encounter rows that
// aren't covered by a tracker entry.
$cards = array_merge($ptCards, $feCards);

// $dataSource is now per-row via $r['_source'] — the legacy single
// $dataSource var is preserved as the dominant source for KPI / pill
// heuristics in code paths that pre-date the merge. Default to
// patient_tracker if any tracker rows exist, else form_encounter.
$dataSource = $ptCards !== [] ? 'patient_tracker' : 'form_encounter';

// --------------------------------------------------------------------
// Map each card → a column + render struct.
// --------------------------------------------------------------------
$nowTs = time();
$totalToday = 0;          // total encounters today (for the KPI bar)
$waitMinutes = [];        // per-row wait (start_datetime → now or status change)
$behindCount = 0;
foreach ($cards as $r) {
    $totalToday++;
    $rowSource = (string)($r['_source'] ?? $dataSource);

    if ($rowSource === 'patient_tracker') {
        $optId = (string)($r['pte_status'] ?? '');
        $title = (string)($r['status_title'] ?? '');
        $isCheckin = (int)($r['is_checkin'] ?? 0);
        $isCheckout = (int)($r['is_checkout'] ?? 0);
        $colKey = flow_column_for_status($optId, $title, $isCheckin, $isCheckout);

        $startTs = !empty($r['pte_start']) ? strtotime((string)$r['pte_start']) : null;
        $apptTs = !empty($r['apptdate']) && !empty($r['appttime'])
            ? strtotime((string)$r['apptdate'] . ' ' . (string)$r['appttime'])
            : null;
        $time = $apptTs ? date('g:i A', $apptTs) : '—';
        $room = (string)($r['pte_room'] ?? '');
        if ($room === '') {
            $room = $startTs ? floor(($nowTs - $startTs) / 60) . 'm' : '—';
        }
    } else {
        // form_encounter fallback. Infer column from last_level_closed,
        // visit duration vs. typical 30min, etc.
        $closed = (int)($r['last_level_closed'] ?? 0);
        $encStartTs = !empty($r['encounter_date']) ? strtotime((string)$r['encounter_date']) : null;
        $encEndTs = !empty($r['encounter_end']) ? strtotime((string)$r['encounter_end']) : null;
        $startTs = $encStartTs;

        if ($closed > 0) {
            $colKey = 'checked_out';
        } elseif ($encEndTs !== null) {
            $colKey = 'ready_dc';
        } elseif ($encStartTs !== null && ($nowTs - $encStartTs) >= 600) {
            // 10+ min in: probably with provider
            $colKey = 'with_prov';
        } elseif ($encStartTs !== null && $encStartTs <= $nowTs) {
            $colKey = 'roomed';
        } else {
            $colKey = 'waiting';
        }

        $time = $encStartTs ? date('g:i A', $encStartTs) : '—';
        $room = '—';
        if ($colKey === 'checked_out' && $encStartTs && $encEndTs) {
            $totalMin = max(1, (int)round(($encEndTs - $encStartTs) / 60));
            $room = $totalMin . 'm total';
        } elseif ($encStartTs !== null) {
            $diffMin = max(0, (int)floor(($nowTs - $encStartTs) / 60));
            $room = $diffMin . 'm';
        }
    }

    // Wait time computation for KPI: only count rows still in Waiting/Roomed.
    if ($startTs !== null && in_array($colKey, ['waiting', 'roomed'], true)) {
        $waitMinutes[] = max(0, (int)floor(($nowTs - $startTs) / 60));
    }
    // "Behind" = waiting > 15 min OR with-provider > 30 min.
    if ($startTs !== null) {
        $diffMin = (int)floor(($nowTs - $startTs) / 60);
        if (($colKey === 'waiting' && $diffMin > 15) || ($colKey === 'with_prov' && $diffMin > 30)) {
            $behindCount++;
        }
    }

    // Patient name + MRN
    $pFn = trim((string)($r['p_fname'] ?? ''));
    $pLn = trim((string)($r['p_lname'] ?? ''));
    $name = trim($pFn . ' ' . $pLn);
    if ($name === '') {
        $name = 'Patient #' . (int)($r['pid'] ?? 0);
    }
    $mrn = (string)($r['pubpid'] ?? '');
    if ($mrn === '') {
        $mrn = '#' . (int)($r['pid'] ?? 0);
    } else {
        $mrn = '#' . $mrn;
    }

    // Visit type from category
    $visit = (string)($r['pc_catname'] ?? '');
    if ($visit === '') {
        $visit = 'Visit';
    }

    // Provider
    $providerLabel = flow_provider_label([
        'username' => (string)($r['u_username'] ?? ''),
        'fname'    => (string)($r['u_fname'] ?? ''),
        'lname'    => (string)($r['u_lname'] ?? ''),
        'title'    => (string)($r['u_title'] ?? ''),
    ]);

    // Status pill text/tone — only when something interesting to show.
    $pillText = '';
    $pillTone = '';
    if ($rowSource === 'patient_tracker') {
        $optId = (string)($r['pte_status'] ?? '');
        $title = (string)($r['status_title'] ?? '');
        $titleL = strtolower($title);
        $isCheckin = (int)($r['is_checkin'] ?? 0);
        $isCheckout = (int)($r['is_checkout'] ?? 0);
        if ($isCheckout === 1) {
            $pillText = 'Billed';
            $pillTone = 'neutral';
        } elseif (str_contains($titleL, 'coding done')) {
            $pillText = 'Notes signed';
            $pillTone = 'good';
        } elseif (str_contains($titleL, 'exam room')) {
            $pillText = 'In exam';
            $pillTone = 'violet';
        } elseif ($isCheckin === 1 && $startTs !== null && ($nowTs - $startTs) > 15 * 60) {
            $mins = (int)floor(($nowTs - $startTs) / 60);
            $pillText = 'Behind ' . $mins . 'm';
            $pillTone = 'danger-soft';
        }
    } else {
        // form_encounter fallback heuristics
        if ($colKey === 'checked_out') {
            $pillText = 'Billed';
            $pillTone = 'neutral';
        } elseif ($colKey === 'ready_dc') {
            $pillText = 'Notes signed';
            $pillTone = 'good';
        } elseif ($colKey === 'with_prov') {
            $pillText = 'In exam';
            $pillTone = 'violet';
        }
    }

    $buckets[$colKey][] = [
        'name'      => $name,
        'mrn'       => $mrn,
        'visit'     => $visit,
        'provider'  => $providerLabel,
        'time'      => $time,
        'room'      => $room,
        'pillText'  => $pillText,
        'pillTone'  => $pillTone,
    ];
}

// --------------------------------------------------------------------
// KPI computations.
// --------------------------------------------------------------------
$avgWait = $waitMinutes !== [] ? (int)round(array_sum($waitMinutes) / count($waitMinutes)) : 0;
$inRooms = count($buckets['roomed']) + count($buckets['with_prov']);
// "Total rooms" = number of distinct rooms in the practice (from list_options).
$roomCountRow = sqlQuery(
    "SELECT COUNT(*) AS n FROM list_options WHERE list_id = 'patient_flow_board_rooms' AND activity = 1"
);
$totalRooms = (int)($roomCountRow['n'] ?? 0);
if ($totalRooms === 0) {
    $totalRooms = 8; // sensible fallback if no rooms list configured
}

// --------------------------------------------------------------------
// Provider pills — derived from users + form_encounter (active providers
// who actually have an encounter in the visible range).
// --------------------------------------------------------------------
$provRows = sqlStatement(
    "SELECT DISTINCT u.id, u.username, u.fname, u.lname, u.title
       FROM users u
       JOIN form_encounter fe ON fe.provider_id = u.id
      WHERE u.active = 1
        AND u.authorized = 1
        AND DATE(fe.date) BETWEEN ? AND ?
      ORDER BY u.lname ASC, u.fname ASC",
    [$rangeStart, $rangeEnd]
);
$providers = [];
while ($pr = sqlFetchArray($provRows)) {
    $providers[] = $pr;
}
// If no providers have encounters in the range, show all authorized
// users so the filter strip still has options.
if ($providers === []) {
    $provRows = sqlStatement(
        "SELECT id, username, fname, lname, title
           FROM users
          WHERE active = 1 AND authorized = 1 AND username IS NOT NULL AND username <> ''
          ORDER BY lname ASC, fname ASC
          LIMIT 6"
    );
    while ($pr = sqlFetchArray($provRows)) {
        $providers[] = $pr;
    }
}

/**
 * Build a query string that preserves the current filter state with a
 * single key swapped out.
 *
 * @param array<string, scalar> $overrides
 */
function flow_qs(array $overrides, string $rangeCur, int $providerCur): string
{
    $params = ['range' => $rangeCur, 'provider' => $providerCur];
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    if ((int)($params['provider'] ?? 0) === 0) {
        unset($params['provider']);
    }
    if (($params['range'] ?? 'today') === 'today') {
        unset($params['range']);
    }
    $qs = http_build_query($params);
    return $qs === '' ? '' : '?' . $qs;
}

// Sub-meta line for the page header.
$facilityRow = sqlQuery("SELECT name FROM facility WHERE primary_business_entity = 1 ORDER BY id ASC LIMIT 1")
    ?: sqlQuery("SELECT name FROM facility ORDER BY id ASC LIMIT 1");
$facilityName = (string)($facilityRow['name'] ?? 'Clinic');
$todayLabel = date('M j');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Flow'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header tweaks */
  .cp-pagehead .title { font-size: 20px; font-weight: 700; }
  .cp-pagehead .bullet { color: #8A91A1; font-size: 14px; padding: 0 2px; }
  .cp-pagehead .sub-meta { font-size: 12px; color: #4F5763; line-height: 1; }

  /* Today / Tomorrow / Week segmented control */
  .cp-seg {
    display: inline-flex; align-items: center;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 3px;
    gap: 2px;
  }
  .cp-seg a {
    border: 0; background: transparent;
    padding: 5px 14px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    border-radius: 999px;
    line-height: 1;
    text-decoration: none;
  }
  .cp-seg a.active {
    background: #F5F6F7; color: #0D1B2A; font-weight: 600;
  }

  /* Filter strip — provider pills + right-side mini stats */
  .cp-flow-filter {
    background: transparent;
    padding: 14px 24px 8px;
    display: flex; align-items: center; gap: 10px;
  }
  .cp-flow-pills { display: flex; gap: 8px; flex: 1; flex-wrap: wrap; }
  .cp-flow-pills a {
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 12px; font-weight: 500;
    line-height: 1.2;
    background: #FFFFFF;
    color: #4F5763;
    border: 1px solid #E4E5E8;
    text-decoration: none;
    display: inline-block;
  }
  .cp-flow-pills a.active {
    background: #FFFFFF; color: #008C8C;
    border-color: #008C8C; font-weight: 600;
  }
  .cp-flow-stats {
    display: inline-flex; align-items: flex-end; gap: 24px;
    padding-left: 10px;
  }
  .cp-flow-stat { display: flex; flex-direction: column; gap: 2px; }
  .cp-flow-stat .lbl { font-size: 10px; color: #8A91A1; font-weight: 500; line-height: 1; }
  .cp-flow-stat .val { font-size: 13px; color: #0D1B2A; font-weight: 700; line-height: 1.1; }

  /* Flash banner */
  .cp-flash {
    margin: 8px 24px 0;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 12px; font-weight: 500;
    background: #EBF8F0; color: #1F8C4D;
    border: 1px solid #C6E8D2;
  }
  .cp-flash.err { background: #FCE7E7; color: #D93838; border-color: #F5C2C2; }

  /* Kanban board */
  .cp-kb {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    padding: 4px 24px 32px;
  }
  .cp-kb-col { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
  .cp-kb-col-head {
    display: flex; align-items: center; gap: 8px;
    padding: 4px 4px 6px;
  }
  .cp-kb-col-head .dot {
    width: 8px; height: 8px; border-radius: 50%;
    flex: 0 0 auto;
  }
  .cp-kb-col-head .lbl {
    font-size: 13px; font-weight: 600; color: #0D1B2A;
    line-height: 1;
  }
  .cp-kb-col-head .cnt {
    margin-left: auto;
    font-size: 12px; font-weight: 600; color: #FA8C33;
    line-height: 1;
  }
  .cp-kb-col-empty {
    border: 1px dashed #E4E5E8;
    border-radius: 10px;
    padding: 18px 8px;
    text-align: center;
    font-size: 11px;
    color: #8A91A1;
  }

  /* Card */
  .cp-kb-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    padding: 12px 14px;
    display: grid;
    grid-template-columns: 28px 1fr;
    grid-template-rows: auto auto auto;
    column-gap: 10px;
    row-gap: 2px;
  }
  .cp-kb-card .av {
    grid-column: 1; grid-row: 1 / span 2;
    width: 28px; height: 28px; border-radius: 50%;
    background: #C9CDD4;
  }
  .cp-kb-card .nm {
    grid-column: 2; grid-row: 1;
    font-size: 13px; font-weight: 700; color: #0D1B2A;
    line-height: 1.2;
  }
  .cp-kb-card .mrn {
    grid-column: 2; grid-row: 2;
    font-size: 11px; color: #8A91A1; font-weight: 500;
    line-height: 1.2;
  }
  .cp-kb-card .visit {
    grid-column: 1 / span 2; grid-row: 3;
    font-size: 12px; font-weight: 600; color: #0D1B2A;
    line-height: 1.3;
    margin-top: 6px;
  }
  .cp-kb-card .prov {
    grid-column: 1 / span 2;
    font-size: 11px; color: #4F5763; font-weight: 500;
    line-height: 1.3;
  }
  .cp-kb-card .foot {
    grid-column: 1 / span 2;
    display: flex; align-items: center; gap: 8px;
    margin-top: 8px;
  }
  .cp-kb-card .foot .when {
    font-size: 11px; color: #8A91A1; font-weight: 500;
    line-height: 1;
  }
  .cp-kb-card .foot .room {
    font-size: 11px; color: #4F5763; font-weight: 500;
    line-height: 1;
  }
  .cp-kb-card .foot .pill { margin-left: auto; }

  /* Tone variants for the small per-card pill */
  .kb-pill {
    border-radius: 999px;
    padding: 3px 9px;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.2px;
    line-height: 1.4;
    display: inline-block;
    white-space: nowrap;
  }
  .kb-pill.good       { background: #EBF8F0; color: #1F8C4D; }
  .kb-pill.info       { background: #E8F1F1; color: #008C8C; }
  .kb-pill.warn       { background: #FFF8EC; color: #FA8C33; }
  .kb-pill.danger-soft{ background: #FCE7E7; color: #D93838; }
  .kb-pill.violet     { background: #F0EBFA; color: #8561C7; }
  .kb-pill.neutral    { background: #F5F6F7; color: #4F5763; }

  /* Add patient drop slot */
  .cp-kb-add {
    border: 1px dashed #D9DCE2;
    background: transparent;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 11px;
    color: #8A91A1;
    text-align: center;
    line-height: 1.2;
    cursor: not-allowed;
  }

  /* Walk-in form: inline button styling */
  .cp-flow-walkin {
    display: inline-block;
    background: #008C8C;
    color: #FFFFFF;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    border: 0;
    cursor: pointer;
  }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="title"><?php echo xlt('Patient Flow'); ?></span>
      <span class="bullet">&middot;</span>
      <span class="sub-meta">
        <?php echo text($facilityName); ?>
        &middot;
        <?php echo text($rangeLabel); ?>, <?php echo text($todayLabel); ?>
      </span>
    </div>
  </div>
  <div class="cp-seg" role="tablist">
    <a href="<?php echo attr(flow_qs(['range' => 'today'], $range, $providerId)); ?>" class="<?php echo $range === 'today' ? 'active' : ''; ?>"><?php echo xlt('Today'); ?></a>
    <a href="<?php echo attr(flow_qs(['range' => 'tomorrow'], $range, $providerId)); ?>" class="<?php echo $range === 'tomorrow' ? 'active' : ''; ?>"><?php echo xlt('Tomorrow'); ?></a>
    <a href="<?php echo attr(flow_qs(['range' => 'week'], $range, $providerId)); ?>" class="<?php echo $range === 'week' ? 'active' : ''; ?>"><?php echo xlt('Week'); ?></a>
  </div>
  <a href="<?php echo attr(flow_qs([], $range, $providerId)); ?>" class="cp-btn ghost">&#x21bb; <?php echo xlt('Refresh'); ?></a>
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?><?php echo attr(flow_qs([], $range, $providerId)); ?>" style="margin:0; display:inline;">
    <input type="hidden" name="action" value="walk_in">
    <button type="submit" class="cp-flow-walkin">+ <?php echo xlt('Walk-in patient'); ?></button>
  </form>
  <button type="button" class="cp-btn ghost" disabled title="Coming soon">? <?php echo xlt('Help'); ?></button>
</header>

<?php if ($flash === 'walk_in'): ?>
  <div class="cp-flash"><?php echo xlt('Walk-in patient added — encounter created'); ?></div>
<?php elseif ($flash === 'walk_in_failed'): ?>
  <div class="cp-flash err"><?php echo xlt('Could not add walk-in — try again'); ?></div>
<?php endif; ?>

<div class="cp-flow-filter">
  <div class="cp-flow-pills">
    <a href="<?php echo attr(flow_qs(['provider' => 0], $range, $providerId)); ?>" class="<?php echo $providerId === 0 ? 'active' : ''; ?>"><?php echo xlt('All providers'); ?></a>
    <?php foreach ($providers as $pr): ?>
      <?php
        $prLabel = cp_format_provider_name([
            'username' => (string)($pr['username'] ?? ''),
            'fname'    => (string)($pr['fname'] ?? ''),
            'lname'    => (string)($pr['lname'] ?? ''),
            'title'    => (string)($pr['title'] ?? ''),
        ]);
        $prId = (int)($pr['id'] ?? 0);
      ?>
      <a href="<?php echo attr(flow_qs(['provider' => $prId], $range, $providerId)); ?>" class="<?php echo $providerId === $prId ? 'active' : ''; ?>"><?php echo text($prLabel); ?></a>
    <?php endforeach; ?>
  </div>
  <div class="cp-flow-stats">
    <div class="cp-flow-stat">
      <span class="lbl"><?php echo xlt('Avg wait'); ?></span>
      <span class="val"><?php echo text($waitMinutes !== [] ? ($avgWait . ' min') : '—'); ?></span>
    </div>
    <div class="cp-flow-stat">
      <span class="lbl"><?php echo xlt('In rooms'); ?></span>
      <span class="val"><?php echo text($inRooms . ' / ' . $totalRooms); ?></span>
    </div>
    <div class="cp-flow-stat">
      <span class="lbl"><?php echo xlt('Behind'); ?></span>
      <span class="val"><?php echo text((string)$behindCount); ?></span>
    </div>
    <div class="cp-flow-stat">
      <span class="lbl"><?php echo xlt('Today'); ?></span>
      <span class="val"><?php echo text($totalToday . ' visits'); ?></span>
    </div>
  </div>
</div>

<div class="cp-kb">
  <?php foreach ($colDefs as $key => $def): ?>
    <?php $colCards = $buckets[$key] ?? []; ?>
    <div class="cp-kb-col">
      <div class="cp-kb-col-head">
        <span class="dot" style="background: <?php echo attr($def['dot']); ?>;"></span>
        <span class="lbl"><?php echo text($def['label']); ?></span>
        <span class="cnt"><?php echo text((string) count($colCards)); ?></span>
      </div>
      <?php if ($colCards === []): ?>
        <div class="cp-kb-col-empty"><?php echo xlt('No patients'); ?></div>
      <?php endif; ?>
      <?php foreach ($colCards as $c): ?>
        <div class="cp-kb-card">
          <span class="av"></span>
          <span class="nm"><?php echo text($c['name']); ?></span>
          <span class="mrn"><?php echo text($c['mrn']); ?></span>
          <span class="visit"><?php echo text($c['visit']); ?></span>
          <span class="prov"><?php echo text($c['provider']); ?></span>
          <div class="foot">
            <span class="when"><?php echo text($c['time']); ?></span>
            <span class="room"><?php echo text($c['room']); ?></span>
            <?php if ($c['pillText'] !== ''): ?>
              <span class="pill kb-pill <?php echo attr($c['pillTone']); ?>"><?php echo text($c['pillText']); ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="cp-kb-add" title="<?php echo xla('Coming soon — drag-drop'); ?>">+ <?php echo xlt('Add patient'); ?></div>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
