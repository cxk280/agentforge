<?php

/**
 * Chart Tracker — Screen 50.
 *
 * Cross-patient kanban view of the encounter completion workflow:
 * Open Encounter → Notes Drafting → Pending Sign → Signed → Coded →
 * Billed. Each card shows the patient, encounter type, provider,
 * age-of-state, and an optional state-specific badge (overdue
 * indicator, attestation pending, E&M code, billed amount).
 *
 * Data sources:
 *   - `form_encounter` joined to `patient_data`, `users`,
 *     `openemr_postcalendar_categories`. Each row is bucketed into
 *     one of six columns based on the lifecycle below.
 *   - Per-encounter aggregates (note count, billing rows, billing
 *     code, billing fee) computed via subqueries against `forms` and
 *     `billing`.
 *
 * Bucketing rules (top-down — first match wins):
 *   1. Billed         — last_level_billed > 0
 *   2. Coded          — has billing rows AND last_level_billed = 0
 *   3. Signed         — last_level_closed > 0 AND last_level_billed = 0
 *                       (no billing rows yet)
 *   4. Pending Sign   — last_level_closed = 0 AND has note forms
 *                       (formdir != 'newpatient')
 *   5. Notes Drafting — last_level_closed = 0 AND has note forms
 *                       (split from Pending Sign by encounter age:
 *                       same-day, no end-date → still drafting)
 *   6. Open Encounter — no note forms at all
 *
 * Filters:
 *   - `?provider=<users.id>` — provider pill (0 = all providers).
 *
 * POST:
 *   - `action=export_csv` — stream a CSV of the visible tracker rows.
 *     CSRF skipped — internal mock page.
 *
 * Card click navigates to
 *   /interface/patient_file/encounter/copilot_encounter.php?eid=<id>
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../interface/globals.php");
require_once(__DIR__ . "/../interface/main/copilot_helpers.php");

// --------------------------------------------------------------------
// Filter: ?provider=<users.id>  (0 = all)
// --------------------------------------------------------------------
$providerId = (int)($_GET['provider'] ?? 0);
if ($providerId < 0) {
    $providerId = 0;
}

// --------------------------------------------------------------------
// Helper: build an array of column-bucketed rows from form_encounter.
// Returns [columns => [...rows], kpi => [...]].
// --------------------------------------------------------------------
/**
 * @return array{columns: array<string, array<int, array<string, mixed>>>, kpi: array<string, mixed>, rows: array<int, array<string, mixed>>}
 */
function ct_load_tracker(int $providerId): array
{
    $params = [];
    $providerClause = '';
    if ($providerId > 0) {
        $providerClause = ' AND fe.provider_id = ?';
        $params[] = $providerId;
    }

    // Pull the most recent 200 encounters across all patients, joined
    // to patient/provider/category. Per-encounter aggregates are
    // computed via correlated subqueries so each row has its own
    // note_count / billing_count / first_code / total_fee.
    $sql = "
        SELECT
            fe.id                  AS encounter_pk,
            fe.encounter           AS encounter,
            fe.pid                 AS pid,
            fe.date                AS encounter_date,
            fe.date_end            AS encounter_end,
            fe.last_level_closed   AS last_level_closed,
            fe.last_level_billed   AS last_level_billed,
            fe.last_update         AS last_update,
            fe.provider_id         AS fe_provider_id,
            fe.pc_catid            AS pc_catid,
            fe.reason              AS reason,
            pd.fname               AS p_fname,
            pd.lname               AS p_lname,
            pd.pubpid              AS pubpid,
            c.pc_catname           AS pc_catname,
            u.id                   AS u_id,
            u.username             AS u_username,
            u.fname                AS u_fname,
            u.lname                AS u_lname,
            u.title                AS u_title,
            (SELECT COUNT(*) FROM forms f
              WHERE f.encounter = fe.encounter
                AND f.pid = fe.pid
                AND f.deleted = 0
                AND f.formdir <> 'newpatient') AS note_count,
            (SELECT COUNT(*) FROM billing b
              WHERE b.encounter = fe.encounter
                AND b.pid = fe.pid
                AND COALESCE(b.activity, 1) = 1) AS bill_count,
            (SELECT b.code FROM billing b
              WHERE b.encounter = fe.encounter
                AND b.pid = fe.pid
                AND COALESCE(b.activity, 1) = 1
                AND b.code_type IN ('CPT4', 'HCPCS')
              ORDER BY b.id ASC LIMIT 1) AS first_code,
            (SELECT b.code FROM billing b
              WHERE b.encounter = fe.encounter
                AND b.pid = fe.pid
                AND COALESCE(b.activity, 1) = 1
              ORDER BY b.id ASC LIMIT 1) AS any_code,
            (SELECT SUM(COALESCE(b.fee, 0)) FROM billing b
              WHERE b.encounter = fe.encounter
                AND b.pid = fe.pid
                AND COALESCE(b.activity, 1) = 1) AS total_fee
        FROM form_encounter fe
        LEFT JOIN patient_data pd
               ON pd.pid = fe.pid
        LEFT JOIN openemr_postcalendar_categories c
               ON c.pc_catid = fe.pc_catid
        LEFT JOIN users u
               ON u.id = fe.provider_id
        WHERE 1 = 1
          $providerClause
        ORDER BY fe.date DESC
        LIMIT 200
    ";

    $rows = [];
    $res = sqlStatement($sql, $params);
    while ($r = sqlFetchArray($res)) {
        $rows[] = $r;
    }

    // Bucket the rows.
    $columns = [
        'open'     => [],
        'drafting' => [],
        'pending'  => [],
        'signed'   => [],
        'coded'    => [],
        'billed'   => [],
    ];

    $nowTs = time();
    $kpi = [
        'sign_hours'    => [],   // closed-date age in hours, for avg-time-to-sign
        'overdue_48h'   => 0,    // pending sign rows older than 48h
        'locked_today'  => 0,    // signed today (last_level_closed > 0 and last_update today)
    ];
    $todayDate = date('Y-m-d');

    foreach ($rows as $r) {
        $closed     = (int)($r['last_level_closed'] ?? 0);
        $billedLvl  = (int)($r['last_level_billed'] ?? 0);
        $noteCount  = (int)($r['note_count'] ?? 0);
        $billCount  = (int)($r['bill_count'] ?? 0);
        $encStartTs = !empty($r['encounter_date']) ? strtotime((string)$r['encounter_date']) : null;
        $lastUpdTs  = !empty($r['last_update']) ? strtotime((string)$r['last_update']) : null;
        $ageHours   = $encStartTs !== null ? max(0, ($nowTs - $encStartTs) / 3600.0) : null;

        // Bucket assignment (top-down).
        if ($billedLvl > 0) {
            $colKey = 'billed';
        } elseif ($billCount > 0) {
            $colKey = 'coded';
        } elseif ($closed > 0) {
            $colKey = 'signed';
        } elseif ($noteCount > 0 && $ageHours !== null && $ageHours > 4) {
            $colKey = 'pending';
        } elseif ($noteCount > 0) {
            $colKey = 'drafting';
        } else {
            $colKey = 'open';
        }

        // KPI: avg time-to-sign — encounter age at time it was signed.
        // We don't have an explicit "signed_at" column; use last_update
        // for closed encounters as a proxy.
        if ($closed > 0 && $encStartTs !== null && $lastUpdTs !== null && $lastUpdTs >= $encStartTs) {
            $kpi['sign_hours'][] = ($lastUpdTs - $encStartTs) / 3600.0;
        }
        // KPI: overdue (>48h) — pending sign older than 48h.
        if ($colKey === 'pending' && $ageHours !== null && $ageHours > 48) {
            $kpi['overdue_48h']++;
        }
        // KPI: locked today — signed (closed>0) with last_update today.
        if ($closed > 0 && $lastUpdTs !== null && date('Y-m-d', $lastUpdTs) === $todayDate) {
            $kpi['locked_today']++;
        }

        // ----- Render fields -----
        $pFn = trim((string)($r['p_fname'] ?? ''));
        $pLn = trim((string)($r['p_lname'] ?? ''));
        $name = trim($pFn . ' ' . $pLn);
        if ($name === '') {
            $name = 'Patient #' . (int)($r['pid'] ?? 0);
        }
        $pubpid = (string)($r['pubpid'] ?? '');
        $mrn = $pubpid !== '' ? '#' . $pubpid : '#' . (int)($r['pid'] ?? 0);

        $catName = trim((string)($r['pc_catname'] ?? ''));
        if ($catName === '') {
            $catName = 'Visit';
        }
        // Short visit-type for the card (Office Visit → Office, etc.).
        $catShort = $catName;
        if (mb_strlen($catShort) > 18) {
            $catShort = mb_substr($catShort, 0, 17) . '…';
        }
        $encDateLabel = $encStartTs ? date('m/d', $encStartTs) : '—';
        $encLine = $encDateLabel . ' · ' . $catShort;

        $providerLabel = cp_format_provider_name([
            'username' => (string)($r['u_username'] ?? ''),
            'fname'    => (string)($r['u_fname'] ?? ''),
            'lname'    => (string)($r['u_lname'] ?? ''),
            'title'    => (string)($r['u_title'] ?? ''),
        ]);

        // "ago" — relative to encounter_date (the lifecycle clock).
        $ago = ct_format_ago($nowTs, $encStartTs);

        // Conditional badge.
        $badge = null;
        if ($colKey === 'open' && $ageHours !== null && $ageHours > 4) {
            $badge = ['text' => 'Overdue 4h', 'kind' => 'warn'];
        } elseif ($colKey === 'pending') {
            if ($ageHours !== null && $ageHours > 48) {
                $badge = ['text' => 'Overdue 48h', 'kind' => 'danger'];
            } else {
                $badge = ['text' => 'Awaiting attest', 'kind' => 'warn'];
            }
        } elseif ($colKey === 'coded') {
            $code = (string)($r['first_code'] ?? $r['any_code'] ?? '');
            if ($code !== '') {
                $badge = ['text' => $code, 'kind' => 'info'];
            }
        } elseif ($colKey === 'billed') {
            $fee = (float)($r['total_fee'] ?? 0);
            if ($fee > 0) {
                $badge = ['text' => '$' . number_format($fee, 0), 'kind' => 'good'];
            }
        }

        $card = [
            'encounter_pk' => (int)($r['encounter_pk'] ?? 0),
            'encounter'    => (int)($r['encounter'] ?? 0),
            'pid'          => (int)($r['pid'] ?? 0),
            'name'         => $name,
            'mrn'          => $mrn,
            'enc'          => $encLine,
            'prov'         => $providerLabel,
            'ago'          => $ago,
            'badge'        => $badge,
            'col'          => $colKey,
            'encounter_date' => (string)($r['encounter_date'] ?? ''),
            'visit_type'   => $catName,
        ];
        $columns[$colKey][] = $card;
    }

    return ['columns' => $columns, 'kpi' => $kpi, 'rows' => $rows];
}

/**
 * Render a duration as a short relative-time string.
 */
function ct_format_ago(int $nowTs, ?int $thenTs): string
{
    if ($thenTs === null) {
        return '—';
    }
    $delta = $nowTs - $thenTs;
    if ($delta < 0) {
        return 'just now';
    }
    if ($delta < 60) {
        return $delta . ' sec ago';
    }
    if ($delta < 3600) {
        return ((int)floor($delta / 60)) . ' min ago';
    }
    if ($delta < 86400) {
        return ((int)floor($delta / 3600)) . 'h ago';
    }
    $days = (int)floor($delta / 86400);
    return $days . ($days === 1 ? ' day ago' : ' days ago');
}

// --------------------------------------------------------------------
// POST: action=export_csv — stream a CSV of all visible rows.
// CSRF skipped — internal mock page.
// --------------------------------------------------------------------
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'export_csv'
) {
    try {
        $tracker = ct_load_tracker($providerId);
        $colLabels = [
            'open'     => 'Open Encounter',
            'drafting' => 'Notes Drafting',
            'pending'  => 'Pending Sign',
            'signed'   => 'Signed',
            'coded'    => 'Coded',
            'billed'   => 'Billed',
        ];
        $stamp = date('Y-m-d_Hi');
        $filename = 'chart-tracker_' . $stamp . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $fh = fopen('php://output', 'w');
        fputcsv($fh, ['Status', 'Encounter ID', 'Patient', 'MRN', 'Encounter Date', 'Visit Type', 'Provider', 'Badge']);
        foreach ($colLabels as $key => $label) {
            foreach ($tracker['columns'][$key] as $card) {
                $badge = $card['badge'];
                $badgeText = is_array($badge) ? (string)$badge['text'] : '';
                fputcsv($fh, [
                    $label,
                    (string)$card['encounter'],
                    $card['name'],
                    $card['mrn'],
                    $card['encounter_date'],
                    $card['visit_type'],
                    $card['prov'],
                    $badgeText,
                ]);
            }
        }
        fclose($fh);
        exit;
    } catch (\Throwable $e) {
        // Fall back to redirect with error flash.
        $qs = http_build_query(['provider' => $providerId, 'msg' => 'export_failed']);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . $qs);
        exit;
    }
}

$flash = (string)($_GET['msg'] ?? '');

// --------------------------------------------------------------------
// Load tracker data for rendering.
// --------------------------------------------------------------------
$tracker = ct_load_tracker($providerId);
$colData = $tracker['columns'];
$kpi = $tracker['kpi'];

// KPI: avg time to sign.
$signHours = $kpi['sign_hours'];
$avgSignHrs = $signHours !== [] ? array_sum($signHours) / count($signHours) : null;
$avgSignLabel = $avgSignHrs === null ? '—' : (number_format($avgSignHrs, 1));

// KPI: re-opened — count audit_master rows where the comment indicates
// re-opening of a closed encounter. We probe for the column safely; if
// nothing matches, show "—" rather than fabricate a number.
$reopenedCount = null;
try {
    $reopenedRow = sqlQuery(
        "SELECT COUNT(*) AS n
           FROM audit_master
          WHERE created_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND (
                  comments LIKE '%reopen%'
               OR comments LIKE '%re-open%'
               OR comments LIKE '%unsigned%'
            )"
    );
    $reopenedCount = (int)($reopenedRow['n'] ?? 0);
} catch (\Throwable $e) {
    // audit_master not present or schema differs — leave as null.
    $reopenedCount = null;
}

// Total in-progress = everything except Signed + Billed (the work
// queue). Used in the page sub-meta.
$inProgress = count($colData['open'])
    + count($colData['drafting'])
    + count($colData['pending'])
    + count($colData['coded']);

// --------------------------------------------------------------------
// Provider pills — distinct providers who actually authored encounters.
// --------------------------------------------------------------------
$providers = [];
$provRows = sqlStatement(
    "SELECT DISTINCT u.id, u.username, u.fname, u.lname, u.title
       FROM users u
       JOIN form_encounter fe ON fe.provider_id = u.id
      WHERE u.active = 1
        AND fe.provider_id > 0
      ORDER BY u.lname ASC, u.fname ASC"
);
while ($pr = sqlFetchArray($provRows)) {
    $providers[] = $pr;
}

/**
 * Build a query string preserving filter state with overrides.
 *
 * @param array<string, scalar> $overrides
 */
function ct_qs(array $overrides, int $providerCur): string
{
    $params = ['provider' => $providerCur];
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    if ((int)($params['provider'] ?? 0) === 0) {
        unset($params['provider']);
    }
    $qs = http_build_query($params);
    return $qs === '' ? '' : '?' . $qs;
}

// --------------------------------------------------------------------
// Column display config (label, dot color, count-pill bg/fg).
// Order is the visual left-to-right flow.
// --------------------------------------------------------------------
$colDefs = [
    'open'     => ['label' => 'Open Encounter', 'dotColor' => '#4785D9', 'cntBg' => '#EAF1FC', 'cntFg' => '#4785D9'],
    'drafting' => ['label' => 'Notes Drafting', 'dotColor' => '#FA8C33', 'cntBg' => '#FFF4EA', 'cntFg' => '#FA8C33'],
    'pending'  => ['label' => 'Pending Sign',   'dotColor' => '#8561C7', 'cntBg' => '#F2EDFB', 'cntFg' => '#8561C7'],
    'signed'   => ['label' => 'Signed',         'dotColor' => '#33A68C', 'cntBg' => '#E8F7F3', 'cntFg' => '#33A68C'],
    'coded'    => ['label' => 'Coded',          'dotColor' => '#008C8C', 'cntBg' => '#E6F5F5', 'cntFg' => '#008C8C'],
    'billed'   => ['label' => 'Billed',         'dotColor' => '#33A666', 'cntBg' => '#E8F7ED', 'cntFg' => '#33A666'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Chart Tracker'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header overrides — Screen 50 layout */
  .cp-pagehead .help {
    background: #F5F6F7; color: #4F5763;
    border-radius: 12px;
    padding: 4px 12px;
    font-size: 12px; font-weight: 500;
    line-height: 1.2;
    border: 0;
    display: inline-flex; align-items: center; gap: 4px;
  }

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

  /* Stats bar — provider pills + KPI metrics on one row */
  .ct-statsbar {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 12px 24px;
    display: flex; align-items: center; gap: 8px;
  }
  .ct-statsbar .pills { display: flex; gap: 6px; flex-wrap: wrap; }
  .ct-statsbar .pills a {
    border-radius: 12px;
    padding: 4px 12px;
    font-size: 12px; font-weight: 500;
    line-height: 1.2;
    background: #FFFFFF; color: #4F5763;
    border: 1px solid #E4E5E8;
    height: 24px;
    display: inline-flex; align-items: center;
    text-decoration: none;
  }
  .ct-statsbar .pills a.active {
    background: #E6F5F5; color: #008C8C;
    border-color: #008C8C;
  }
  .ct-statsbar .spacer { flex: 1; }
  .ct-statsbar .kpi {
    display: flex; flex-direction: column; gap: 2px;
    margin-left: 24px;
  }
  .ct-statsbar .kpi .lbl {
    font-size: 11px; color: #8A91A1; font-weight: 500; line-height: 1.2;
  }
  .ct-statsbar .kpi .val {
    font-size: 14px; font-weight: 700; line-height: 1.2; color: #181D26;
  }
  .ct-statsbar .kpi .val.danger { color: #D93838; }
  .ct-statsbar .kpi .val.green  { color: #33A666; }
  .ct-statsbar .kpi .val.warn   { color: #FA8C33; }

  /* Advanced filter panel (toggled) */
  .ct-advanced {
    display: none;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 12px 24px;
    font-size: 12px; color: #4F5763;
  }
  .ct-advanced.open { display: block; }
  .ct-advanced .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
  .ct-advanced label { font-weight: 600; color: #181D26; }

  /* Kanban board */
  .ct-board {
    flex: 1 1 auto;
    display: flex;
    gap: 8px;
    padding: 12px 8px 24px;
    overflow-x: auto;
    overflow-y: hidden;
    background: #F5F6F7;
  }
  .ct-col {
    flex: 1 1 0;
    min-width: 232px;
    background: #F5F6F7;
    border-radius: 8px;
    padding: 12px;
    display: flex; flex-direction: column; gap: 8px;
  }
  .ct-col-hdr {
    height: 28px;
    display: flex; align-items: center; gap: 6px;
    padding: 0;
  }
  .ct-col-hdr .dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    flex: 0 0 auto;
  }
  .ct-col-hdr .lbl {
    font-size: 12px; font-weight: 600; color: #181D26;
    line-height: 1.2;
  }
  .ct-col-hdr .ct {
    margin-left: auto;
    border-radius: 10px;
    height: 20px;
    min-width: 32px;
    padding: 0 8px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600;
    line-height: 1;
  }
  .ct-col-empty {
    border: 1px dashed #D9DCE2;
    border-radius: 8px;
    padding: 16px 8px;
    text-align: center;
    font-size: 11px;
    color: #8A91A1;
  }

  /* Kanban card */
  .ct-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 11px;
    display: grid;
    grid-template-columns: 24px 1fr;
    grid-template-rows: auto auto;
    column-gap: 6px;
    row-gap: 0;
    position: relative;
    text-decoration: none;
    color: inherit;
  }
  .ct-card:hover {
    border-color: #008C8C;
    box-shadow: 0 0 0 2px rgba(0, 140, 140, 0.10);
  }
  .ct-card .av {
    grid-column: 1 / 2;
    grid-row: 1 / 3;
    width: 24px; height: 24px;
    border-radius: 50%;
    background: #C7CBD2;
    flex: 0 0 auto;
  }
  .ct-card .name {
    grid-column: 2 / 3;
    grid-row: 1 / 2;
    font-size: 12px; font-weight: 600; color: #181D26;
    line-height: 1.2;
  }
  .ct-card .mrn {
    grid-column: 2 / 3;
    grid-row: 2 / 3;
    font-size: 10px; color: #8A91A1;
    line-height: 1.4;
    margin-top: 2px;
  }
  .ct-card .enc {
    grid-column: 1 / 3;
    margin-top: 8px;
    font-size: 11px; font-weight: 500; color: #181D26;
    line-height: 1.2;
  }
  .ct-card .meta {
    grid-column: 1 / 3;
    margin-top: 3px;
    font-size: 10px; color: #4F5763;
    line-height: 1.2;
  }
  .ct-card .footer {
    grid-column: 1 / 3;
    margin-top: 3px;
    display: flex; align-items: center;
    line-height: 1.2;
  }
  .ct-card .footer .ago {
    font-size: 10px; color: #8A91A1;
  }
  .ct-card .footer .badge {
    margin-left: auto;
    border-radius: 9px;
    height: 18px;
    padding: 0 8px;
    display: inline-flex; align-items: center;
    font-size: 10px; font-weight: 600;
    line-height: 1;
    letter-spacing: 0.2px;
  }
  .ct-card .footer .badge.warn   { background: #FFF4EA; color: #FA8C33; }
  .ct-card .footer .badge.danger { background: #FCEAEA; color: #D93838; }
  .ct-card .footer .badge.info   { background: #EAF1FC; color: #4785D9; }
  .ct-card .footer .badge.good   { background: #E8F7ED; color: #33A666; }

  /* Inline form for header buttons */
  .ct-inline-form { margin: 0; display: inline; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info" style="flex-direction: row; align-items: baseline; gap: 8px;">
    <span class="title"><?php echo xlt('Chart Tracker'); ?></span>
    <span class="bullet">·</span>
    <span class="meta" style="font-size: 13px; color: #4F5763;"><?php echo xlt('Encounter completion workflow'); ?> · <?php echo text((string)$inProgress); ?> <?php echo xlt('in progress'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost" onclick="document.getElementById('ct-adv').classList.toggle('open');"><span aria-hidden="true">&#9783;</span> <?php echo xlt('Filter'); ?></button>
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF'] . ct_qs([], $providerId)); ?>" class="ct-inline-form">
    <input type="hidden" name="action" value="export_csv">
    <button type="submit" class="cp-btn primary"><span aria-hidden="true">&darr;</span> <?php echo xlt('Export tracker'); ?></button>
  </form>
  <button type="button" class="help" disabled title="<?php echo xla('Coming soon'); ?>">? <?php echo xlt('Help'); ?></button>
</header>

<?php if ($flash === 'export_failed'): ?>
  <div class="cp-flash err"><?php echo xlt('Export failed — try again'); ?></div>
<?php endif; ?>

<div id="ct-adv" class="ct-advanced">
  <form method="get" action="<?php echo attr($_SERVER['PHP_SELF']); ?>" class="row">
    <label for="ct-provider-select"><?php echo xlt('Provider'); ?>:</label>
    <select id="ct-provider-select" name="provider">
      <option value="0"><?php echo xlt('All providers'); ?></option>
      <?php foreach ($providers as $pr): ?>
        <?php
          $prId = (int)($pr['id'] ?? 0);
          $prLabel = cp_format_provider_name([
              'username' => (string)($pr['username'] ?? ''),
              'fname'    => (string)($pr['fname'] ?? ''),
              'lname'    => (string)($pr['lname'] ?? ''),
              'title'    => (string)($pr['title'] ?? ''),
          ]);
        ?>
        <option value="<?php echo attr((string)$prId); ?>" <?php echo $providerId === $prId ? 'selected' : ''; ?>><?php echo text($prLabel); ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="cp-btn primary"><?php echo xlt('Apply'); ?></button>
    <a href="<?php echo attr($_SERVER['PHP_SELF']); ?>" class="cp-btn ghost"><?php echo xlt('Reset'); ?></a>
  </form>
</div>

<div class="ct-statsbar">
  <div class="pills">
    <a href="<?php echo attr(ct_qs(['provider' => 0], $providerId)); ?>" class="<?php echo $providerId === 0 ? 'active' : ''; ?>"><?php echo xlt('All providers'); ?></a>
    <?php foreach ($providers as $pr): ?>
      <?php
        $prId = (int)($pr['id'] ?? 0);
        $prLabel = cp_format_provider_name([
            'username' => (string)($pr['username'] ?? ''),
            'fname'    => (string)($pr['fname'] ?? ''),
            'lname'    => (string)($pr['lname'] ?? ''),
            'title'    => (string)($pr['title'] ?? ''),
        ]);
      ?>
      <a href="<?php echo attr(ct_qs(['provider' => $prId], $providerId)); ?>" class="<?php echo $providerId === $prId ? 'active' : ''; ?>"><?php echo text($prLabel); ?></a>
    <?php endforeach; ?>
  </div>
  <div class="spacer"></div>
  <div class="kpi">
    <span class="lbl"><?php echo xlt('Avg time to sign'); ?></span>
    <span class="val"><?php echo text($avgSignLabel); ?> <?php echo xlt('hrs'); ?></span>
  </div>
  <div class="kpi">
    <span class="lbl"><?php echo xlt('Overdue (>48h)'); ?></span>
    <span class="val danger"><?php echo text((string)(int)$kpi['overdue_48h']); ?></span>
  </div>
  <div class="kpi">
    <span class="lbl"><?php echo xlt('Locked today'); ?></span>
    <span class="val green"><?php echo text((string)(int)$kpi['locked_today']); ?></span>
  </div>
  <div class="kpi">
    <span class="lbl"><?php echo xlt('Re-opened'); ?></span>
    <span class="val warn"><?php echo text($reopenedCount === null ? '—' : (string)$reopenedCount); ?></span>
  </div>
</div>

<div class="ct-board">
  <?php foreach ($colDefs as $key => $def): ?>
    <?php $cards = $colData[$key] ?? []; ?>
    <div class="ct-col">
      <div class="ct-col-hdr">
        <span class="dot" style="background: <?php echo attr($def['dotColor']); ?>;"></span>
        <span class="lbl"><?php echo text($def['label']); ?></span>
        <span class="ct" style="background: <?php echo attr($def['cntBg']); ?>; color: <?php echo attr($def['cntFg']); ?>;"><?php echo text((string) count($cards)); ?></span>
      </div>
      <?php if ($cards === []): ?>
        <div class="ct-col-empty"><?php echo xlt('No encounters'); ?></div>
      <?php endif; ?>
      <?php foreach ($cards as $card): ?>
        <?php
          $href = '/interface/patient_file/encounter/copilot_encounter.php?eid=' . (int)$card['encounter'];
        ?>
        <a class="ct-card" href="<?php echo attr($href); ?>">
          <div class="av"></div>
          <div class="name"><?php echo text($card['name']); ?></div>
          <div class="mrn"><?php echo text($card['mrn']); ?></div>
          <div class="enc"><?php echo text($card['enc']); ?></div>
          <div class="meta"><?php echo text($card['prov']); ?></div>
          <div class="footer">
            <span class="ago"><?php echo text($card['ago']); ?></span>
            <?php if (!empty($card['badge'])): ?>
              <span class="badge <?php echo attr($card['badge']['kind']); ?>"><?php echo text($card['badge']['text']); ?></span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
