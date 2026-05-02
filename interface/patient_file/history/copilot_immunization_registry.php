<?php

/**
 * Immunization Registry — Screen 47.
 *
 * Reports → Clinical → Immunizations sub-page. Population-level
 * coverage report: KPI tiles per vaccine antigen, "patients due"
 * outreach queue, TX-DSHS registry sync status, and a 12-month
 * administrations bar chart.
 *
 * The chrome (top nav) is rendered by the parent shell; this page
 * renders only the body. Page is NOT patient-scoped — it's a
 * practice-wide registry/coverage view.
 *
 * Backend wiring:
 *   - Coverage tiles compute % from `immunizations` joined to `patient_data`,
 *     using note-text LIKE matching (cvx_code is null in seed) and
 *     vaccine-specific age cutoffs and recency windows.
 *   - "Patients due" tabs filter by ?tab=all|flu|covid|tdap|shingrix.
 *   - Patients-due rows are computed in PHP from the same per-patient
 *     latest-dose map and ordered by status (overdue first), then DOB asc.
 *   - "Force re-sync now" / "Sync to TX-DSHS" header POSTs action=sync_registry,
 *     records to `extended_log`, and redirects with msg=registry_synced.
 *   - Monthly admins bar chart is GROUP BY YEAR(administered_date),
 *     MONTH(administered_date) over the last 12 months.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");

// ---------------------------------------------------------------------------
// POST handler — TX-DSHS registry sync (records an audit row, redirects).
// CSRF skipped — internal mock page.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'sync_registry') {
    $when = date('Y-m-d H:i:s');
    $user = (string)($_SESSION['authUser'] ?? 'admin');
    sqlStatement(
        "INSERT INTO extended_log (date, event, user, recipient, description, patient_id) "
        . "VALUES (?, ?, ?, ?, ?, NULL)",
        [
            $when,
            'registry_sync',
            $user,
            'TX-DSHS ImmTrac2',
            'Force re-sync requested from Immunization Registry screen',
        ]
    );
    header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=registry_synced'
        . (isset($_GET['tab']) ? '&tab=' . urlencode((string)$_GET['tab']) : ''));
    exit;
}

$flash = $_GET['msg'] ?? null;

// ---------------------------------------------------------------------------
// Tab filter.
// ---------------------------------------------------------------------------
$tab = strtolower((string)($_GET['tab'] ?? 'all'));
$validTabs = ['all', 'flu', 'covid', 'tdap', 'shingrix'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}

// ---------------------------------------------------------------------------
// Vaccine catalog. Each entry:
//   - key: short id used for tabs / pill counts
//   - label: how the vaccine is shown in the "vaccines due" column
//   - tile: [name, sub, goalLabel, goalPct]  for the KPI tile
//   - note_like: array of LIKE patterns matched against immunizations.note
//                (case-insensitive). Any match counts as a dose of this vaccine.
//   - recency_months: a dose newer than this counts as "vaccinated"
//   - min_age / max_age: eligibility window (age in years; null = open-ended)
//   - sex: 'M' | 'F' | null  (HPV typically all sexes; we leave null)
// ---------------------------------------------------------------------------
$vaccines = [
    'flu' => [
        'label'          => 'Influenza',
        'tile'           => ['Influenza',        '2025-26',          'Goal ≥65%', 65],
        'note_like'      => ['%influenza%', '%flu %', '%flu,%', 'flu', '%flu shot%'],
        'recency_months' => 12,
        'min_age'        => 0.5,    // 6 months+
        'max_age'        => null,
        'sex'            => null,
    ],
    'covid' => [
        'label'          => 'COVID booster',
        'tile'           => ['COVID-19 booster', '2025-26',          'Tracked',   50],
        'note_like'      => ['%covid%', '%sars%'],
        'recency_months' => 12,
        'min_age'        => 0.5,
        'max_age'        => null,
        'sex'            => null,
    ],
    'pneumo' => [
        'label'          => 'PPSV23 booster',
        'tile'           => ['Pneumococcal',     'PPSV23 + PCV',     'Goal ≥75%', 75],
        'note_like'      => ['%pneumococcal%', '%ppsv%', '%pcv%', '%prevnar%', '%pneumovax%'],
        'recency_months' => 60,
        'min_age'        => 65,
        'max_age'        => null,
        'sex'            => null,
    ],
    'tdap' => [
        'label'          => 'Tdap (10-yr)',
        'tile'           => ['Tdap',             '10-yr booster',    'Goal ≥80%', 80],
        'note_like'      => ['%tdap%', '%boostrix%', '%adacel%', '%td booster%'],
        'recency_months' => 120,
        'min_age'        => 18,
        'max_age'        => null,
        'sex'            => null,
    ],
    'shingrix' => [
        'label'          => 'Shingrix',
        'tile'           => ['Shingrix',         'Adults 50+',       'Goal ≥60%', 60],
        'note_like'      => ['%shingrix%', '%zoster%'],
        'recency_months' => 60,
        'min_age'        => 50,
        'max_age'        => null,
        'sex'            => null,
    ],
    'hpv' => [
        'label'          => 'HPV',
        'tile'           => ['HPV',              'Series complete',  'Goal ≥70%', 70],
        'note_like'      => ['%hpv%', '%gardasil%'],
        'recency_months' => 120,
        'min_age'        => 9,
        'max_age'        => 26,
        'sex'            => null,
    ],
];

// ---------------------------------------------------------------------------
// Build the per-patient, per-vaccine latest-dose map in one pass.
//   $latest[$pid][$vkey] = unix timestamp of most recent matching dose
// ---------------------------------------------------------------------------
$latest = [];
$rs = sqlStatement(
    "SELECT patient_id, administered_date, note "
    . "FROM immunizations "
    . "WHERE added_erroneously = 0 AND administered_date IS NOT NULL"
);
while ($row = sqlFetchArray($rs)) {
    $patid = (int)$row['patient_id'];
    if ($patid <= 0) {
        continue;
    }
    $note = strtolower((string)$row['note']);
    $ts = strtotime((string)$row['administered_date']);
    if ($ts === false) {
        continue;
    }
    foreach ($vaccines as $vkey => $vdef) {
        foreach ($vdef['note_like'] as $pat) {
            // Convert SQL LIKE pattern to a substring check.
            $needle = trim((string)$pat, '%');
            if ($needle === '') {
                continue;
            }
            if (str_contains($note, strtolower($needle))) {
                if (!isset($latest[$patid][$vkey]) || $latest[$patid][$vkey] < $ts) {
                    $latest[$patid][$vkey] = $ts;
                }
                break;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Pull the patient roster once (live patients only).
// ---------------------------------------------------------------------------
$patients = [];
$rs = sqlStatement(
    "SELECT pid, fname, lname, DOB, sex, pubpid "
    . "FROM patient_data "
    . "WHERE (deceased_date IS NULL OR deceased_date = '0000-00-00 00:00:00') "
    . "ORDER BY pid"
);
$now = time();
while ($p = sqlFetchArray($rs)) {
    $dob = (string)($p['DOB'] ?? '');
    $age = null;
    if ($dob !== '' && $dob !== '0000-00-00') {
        $dobTs = strtotime($dob);
        if ($dobTs !== false) {
            $age = (int)floor(($now - $dobTs) / (365.25 * 86400));
        }
    }
    $patients[(int)$p['pid']] = [
        'pid'    => (int)$p['pid'],
        'fname'  => (string)($p['fname'] ?? ''),
        'lname'  => (string)($p['lname'] ?? ''),
        'sex'    => (string)($p['sex'] ?? ''),
        'pubpid' => (string)($p['pubpid'] ?? ''),
        'dob'    => $dob,
        'age'    => $age,
    ];
}

// ---------------------------------------------------------------------------
// Coverage tile computation.
// ---------------------------------------------------------------------------
$cutoffSeconds = static fn (int $months): int => (int)($months * 30.44 * 86400);

$coverage = [];
foreach ($vaccines as $vkey => $vdef) {
    [$nm, $sub, $goalLabel, $goalPct] = $vdef['tile'];
    $eligible = 0;
    $vaccinated = 0;
    foreach ($patients as $p) {
        $age = $p['age'];
        if ($age === null) {
            // Skip patients with no DOB — can't determine eligibility.
            continue;
        }
        if ($vdef['min_age'] !== null && $age < $vdef['min_age']) {
            continue;
        }
        if ($vdef['max_age'] !== null && $age > $vdef['max_age']) {
            continue;
        }
        if ($vdef['sex'] !== null && strtoupper(substr($p['sex'], 0, 1)) !== $vdef['sex']) {
            continue;
        }
        $eligible++;
        $doseTs = $latest[$p['pid']][$vkey] ?? null;
        if ($doseTs !== null && ($now - $doseTs) <= $cutoffSeconds($vdef['recency_months'])) {
            $vaccinated++;
        }
    }
    $pct = $eligible > 0 ? (int)round(($vaccinated / $eligible) * 100) : 0;
    if ($eligible === 0) {
        // No eligible cohort in the seed — show a neutral N/A tile rather than red 0%.
        $tone = 'info';
        $ptone = 'info';
    } elseif ($vkey === 'covid') {
        // COVID is "tracked" rather than goal-driven; tone is informational.
        $tone = 'info';
        $ptone = 'info';
    } elseif ($pct >= $goalPct) {
        $tone = 'good';
        $ptone = 'good';
    } else {
        $tone = 'warn';
        $ptone = 'warn';
    }
    // Footnote: "<vaccinated> of <eligible> · <suffix>"
    $suffixMap = [
        'flu'      => 'Season ends 04/30',
        'covid'    => 'Updated formula',
        'pneumo'   => 'Adults 65+',
        'tdap'     => 'All adults',
        'shingrix' => '2-dose series',
        'hpv'      => 'Adolescents',
    ];
    $suffix = $suffixMap[$vkey] ?? '';
    $denom = (string)$vaccinated . ' of ' . (string)$eligible . ($suffix !== '' ? ' · ' . $suffix : '');
    $coverage[] = [$nm, $sub, $goalLabel, $ptone, $pct, $tone, $denom];
}

// ---------------------------------------------------------------------------
// Patients-due queue.
// A patient is "due" for a vaccine if eligible AND no dose-within-recency.
// Status:
//   - OVERDUE if last dose exists but >> recency_months past
//   - "Due …" if eligible and no dose at all (use today as the soft due date)
// ---------------------------------------------------------------------------
$dueRows = [];
$tonePalette = ['orange', 'blue', 'purple', 'teal', 'pink', 'green', 'mint', 'violet'];

// What vaccines does each tab consider?
$tabVaccines = [
    'all'      => array_keys($vaccines),
    'flu'      => ['flu'],
    'covid'    => ['covid'],
    'tdap'     => ['tdap'],
    'shingrix' => ['shingrix'],
];

// Per-tab counts (computed always so the pill row is honest).
$tabCounts = [];
foreach ($tabVaccines as $tk => $vKeys) {
    $tabCounts[$tk] = 0;
}

$avIdx = 0;
foreach ($patients as $p) {
    if ($p['age'] === null) {
        continue;
    }
    $missing = []; // vaccine keys this patient is due for
    $worstStatus = 'duesoon';
    foreach ($vaccines as $vkey => $vdef) {
        $age = $p['age'];
        if ($vdef['min_age'] !== null && $age < $vdef['min_age']) {
            continue;
        }
        if ($vdef['max_age'] !== null && $age > $vdef['max_age']) {
            continue;
        }
        if ($vdef['sex'] !== null && strtoupper(substr($p['sex'], 0, 1)) !== $vdef['sex']) {
            continue;
        }
        $doseTs = $latest[$p['pid']][$vkey] ?? null;
        $window = $cutoffSeconds($vdef['recency_months']);
        if ($doseTs === null) {
            // Never received → due (overdue if patient is well past first eligibility)
            $missing[] = $vkey;
            $worstStatus = 'overdue';
        } elseif (($now - $doseTs) > $window) {
            $missing[] = $vkey;
            $worstStatus = 'overdue';
        }
    }
    if (count($missing) === 0) {
        continue;
    }

    // Increment per-tab counters
    foreach ($tabVaccines as $tk => $vKeys) {
        if (count(array_intersect($missing, $vKeys)) > 0) {
            $tabCounts[$tk]++;
        }
    }

    // Apply current tab filter
    if (count(array_intersect($missing, $tabVaccines[$tab])) === 0) {
        continue;
    }

    $vacText = implode(', ', array_map(static fn (string $k) => $vaccines[$k]['label'], $missing));
    if (mb_strlen($vacText) > 32) {
        $vacText = mb_substr($vacText, 0, 31) . '…';
    }
    $statusLabel = $worstStatus === 'overdue' ? 'OVERDUE' : 'Due ' . date('m/d', $now + 14 * 86400);
    $statusTone = $worstStatus === 'overdue' ? 'danger' : 'warn';

    $name = trim($p['fname'] . ' ' . $p['lname']);
    if ($name === '') {
        $name = 'Patient ' . $p['pid'];
    }
    $sex = strtoupper(substr($p['sex'], 0, 1));
    $dem = ($p['age'] !== null ? (string)$p['age'] : '?') . ($sex !== '' ? $sex : '');
    $mrn = $p['pubpid'] !== '' ? '#' . str_pad($p['pubpid'], 6, '0', STR_PAD_LEFT) : '#' . str_pad((string)$p['pid'], 6, '0', STR_PAD_LEFT);

    $dueRows[] = [
        'av'         => $tonePalette[$avIdx++ % count($tonePalette)],
        'name'       => $name,
        'mrn'        => $mrn,
        'dem'        => $dem,
        'vac'        => $vacText !== '' ? $vacText : '—',
        'pill'       => $statusLabel,
        'pillTone'   => $statusTone,
        'pid'        => $p['pid'],
        'sortKey'    => ($worstStatus === 'overdue' ? '0' : '1') . '|' . ($p['dob'] ?: '9999'),
    ];
}

usort($dueRows, static fn (array $a, array $b) => strcmp($a['sortKey'], $b['sortKey']));

// Pills row [label, isActive, count]
$pills = [
    ['All',      $tab === 'all',      $tabCounts['all'] ?? 0],
    ['Flu',      $tab === 'flu',      $tabCounts['flu'] ?? 0],
    ['COVID',    $tab === 'covid',    $tabCounts['covid'] ?? 0],
    ['Tdap',     $tab === 'tdap',     $tabCounts['tdap'] ?? 0],
    ['Shingrix', $tab === 'shingrix', $tabCounts['shingrix'] ?? 0],
];
$tabSlug = [
    'All'      => 'all',
    'Flu'      => 'flu',
    'COVID'    => 'covid',
    'Tdap'     => 'tdap',
    'Shingrix' => 'shingrix',
];

// ---------------------------------------------------------------------------
// Monthly administrations chart (last 12 months ending this month).
// ---------------------------------------------------------------------------
$monthCounts = [];
$rs = sqlStatement(
    "SELECT YEAR(administered_date) AS y, MONTH(administered_date) AS m, COUNT(*) AS n "
    . "FROM immunizations "
    . "WHERE added_erroneously = 0 AND administered_date IS NOT NULL "
    . "  AND administered_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) "
    . "GROUP BY y, m"
);
while ($r = sqlFetchArray($rs)) {
    $key = ((int)$r['y']) . '-' . str_pad((string)(int)$r['m'], 2, '0', STR_PAD_LEFT);
    $monthCounts[$key] = (int)$r['n'];
}
$months = [];
$cursor = strtotime('first day of -11 months');
$peakStart = strtotime('first day of -7 months');
$peakEnd   = strtotime('first day of -3 months');
$peakTotal = 0;
$totalAdmins = 0;
for ($i = 0; $i < 12; $i++) {
    $y = (int)date('Y', $cursor);
    $m = (int)date('n', $cursor);
    $key = $y . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
    $count = $monthCounts[$key] ?? 0;
    $totalAdmins += $count;
    $isPeak = ($cursor >= $peakStart && $cursor <= $peakEnd);
    if ($isPeak) {
        $peakTotal += $count;
    }
    $showLabel = ($i >= 4 && $i <= 8);  // Sep/Oct/Nov/Dec/Jan style
    $months[] = [
        substr(date('M', $cursor), 0, 1),
        $count,
        $showLabel,
        $isPeak ? 'orange' : 'teal',
    ];
    $cursor = strtotime('+1 month', $cursor);
}
$maxBar = 0;
foreach ($months as [, $c]) {
    if ($c > $maxBar) {
        $maxBar = $c;
    }
}
if ($maxBar < 1) {
    $maxBar = 1;
}
$barMaxPx = 180;

// ---------------------------------------------------------------------------
// Right-rail static figures with a real-data spine.
// ---------------------------------------------------------------------------
$lastSyncRow = sqlQuery(
    "SELECT date FROM extended_log WHERE event = ? ORDER BY date DESC LIMIT 1",
    ['registry_sync']
);
$lastSyncTs = $lastSyncRow ? strtotime((string)$lastSyncRow['date']) : null;
if ($lastSyncTs !== null) {
    $delta = max(0, $now - $lastSyncTs);
    if ($delta < 90) {
        $lastSyncLabel = 'just now';
    } elseif ($delta < 3600) {
        $lastSyncLabel = (int)round($delta / 60) . ' min ago';
    } elseif ($delta < 86400) {
        $lastSyncLabel = (int)round($delta / 3600) . ' hr ago';
    } else {
        $lastSyncLabel = (int)round($delta / 86400) . ' d ago';
    }
} else {
    $lastSyncLabel = 'never';
}

$pushedThisQuarterRow = sqlQuery(
    "SELECT COUNT(*) AS n FROM immunizations "
    . "WHERE added_erroneously = 0 AND administered_date IS NOT NULL "
    . "  AND administered_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)"
);
$pushedQ = (int)($pushedThisQuarterRow['n'] ?? 0);

$sidebar = [
    'Clinical' => [
        ['Patient List',          false],
        ['Prescriptions',         false],
        ['Lab Trends',            false],
        ['Quality Measures',      false],
        ['Immunizations',         true],
        ['Encounters',            false],
    ],
    'Financial' => [
        ['Daily Cash',            false],
        ['Aging',                 false],
        ['Payer Mix',             false],
        ['Collections',           false],
    ],
    'Operations' => [
        ['Visit Volume',          false],
        ['Provider Productivity', false],
        ['No-shows',              false],
    ],
    'Electronic' => [
        ['Submissions',           false],
        ['CCDA Exports',          false],
        ['HIE Sync',              false],
        ['Public Health',         false],
    ],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Immunization Registry'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Reports left sidebar (matches Screen 39 pattern) */
  .cp-rep-side {
    flex: 0 0 200px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 16px 0 24px;
    overflow-y: auto;
  }
  .cp-rep-side .header {
    font-size: 11px; font-weight: 700;
    color: #8A91A1; letter-spacing: 0.7px;
    padding: 0 16px 8px;
  }
  .cp-rep-side .grp { margin-bottom: 12px; }
  .cp-rep-side .grp .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1;
    padding: 6px 16px 4px;
  }
  .cp-rep-side .item {
    display: block;
    margin: 0 8px;
    padding: 9px 16px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    text-decoration: none;
    line-height: 1.2;
    border-radius: 6px;
  }
  .cp-rep-side .item:hover { background: #F5F6F7; color: #0D1B2A; }
  .cp-rep-side .item.active {
    color: #008C8C; font-weight: 600;
    background: #E6F5F5;
  }

  /* Page head with inline subtitle */
  .cp-pagehead { padding: 14px 24px; }
  .cp-pagehead .title { font-size: 18px; font-weight: 700; color: #181D26; line-height: 1; }
  .cp-pagehead .dot { color: #8A91A1; font-size: 16px; padding: 0 4px; line-height: 1; }
  .cp-pagehead .subt { color: #4F5763; font-size: 13px; line-height: 1; }
  .cp-pagehead .row1 { display: flex; align-items: center; gap: 4px; }

  .cp-content { padding: 18px 24px 32px; gap: 16px; }

  /* Flash banner */
  .cp-flash {
    background: #E8F7ED; color: #1F8C4D;
    border: 1px solid #BFE3CC;
    border-radius: 6px;
    padding: 8px 14px;
    font-size: 12px; font-weight: 500;
    margin-bottom: 4px;
  }

  /* Coverage 3-col grid */
  .cov-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px 20px;
  }
  .cov-card {
    position: relative;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 92px;
    padding: 15px;
  }
  .cov-card .nm {
    font-size: 13px; font-weight: 600;
    color: #181D26; line-height: 1;
  }
  .cov-card .sub {
    font-size: 11px; color: #8A91A1;
    line-height: 1; margin-top: 6px;
  }
  .cov-card .denom {
    position: absolute;
    left: 15px; top: 51px;
    font-size: 11px; color: #4F5763;
    line-height: 1;
  }
  .cov-card .pct {
    position: absolute;
    right: 15px; top: 28px;
    font-size: 22px; font-weight: 700;
    line-height: 1;
  }
  .cov-card .pct.warn { color: #FA8C33; }
  .cov-card .pct.info { color: #4785D9; }
  .cov-card .pct.good { color: #33A666; }
  .cov-card .gpill {
    position: absolute;
    right: 15px; top: 14px;
    height: 18px;
    border-radius: 9px;
    padding: 0 8px;
    font-size: 10px; font-weight: 600;
    line-height: 18px;
    display: inline-block;
  }
  .cov-card .gpill.warn { background: #FFF4EA; color: #FA8C33; }
  .cov-card .gpill.info { background: #EAF1FC; color: #4785D9; }
  .cov-card .gpill.good { background: #E8F7ED; color: #33A666; }
  .cov-card .bar-bg {
    position: absolute;
    left: 15px; right: 15px; bottom: 15px;
    height: 6px; border-radius: 3px;
    background: #F5F6F7;
  }
  .cov-card .bar-fill {
    position: absolute;
    height: 6px; border-radius: 3px;
  }
  .cov-card .bar-fill.warn { background: #FA8C33; }
  .cov-card .bar-fill.info { background: #4785D9; }
  .cov-card .bar-fill.good { background: #33A666; }

  /* Two-column layout for due-list + right rail */
  .cols {
    display: grid;
    grid-template-columns: 1fr 448px;
    gap: 20px;
    align-items: start;
  }

  /* Due-for-vaccination panel */
  .due-panel {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    overflow: hidden;
  }
  .due-panel .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.5px;
    padding: 15px 19px 0;
    line-height: 1;
  }
  .due-tabs {
    display: flex;
    padding: 8px 20px 0;
    border-bottom: 1px solid #E4E5E8;
    gap: 4px;
  }
  .due-tabs .tab {
    position: relative;
    padding: 7px 12px 9px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    background: transparent; border: 0;
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
  }
  .due-tabs .tab.active {
    color: #008C8C; font-weight: 600;
  }
  .due-tabs .tab.active::after {
    content: '';
    position: absolute;
    left: 0; right: 0; bottom: -1px;
    height: 2px; border-radius: 1px;
    background: #008C8C;
  }
  .due-tabs .tab .ct {
    color: #8A91A1; font-weight: 500; margin-left: 4px;
  }
  .due-tabs .tab.active .ct { color: #008C8C; }
  .due-row {
    display: grid;
    grid-template-columns: 24px 140px 64px 40px 1fr 96px 80px;
    align-items: center;
    height: 44px;
    padding: 0 19px 0 20px;
    gap: 0;
  }
  .due-row.alt { background: #FAFBFC; }
  .due-row .av {
    width: 16px; height: 16px;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 8px; font-weight: 700; color: #FFF;
  }
  .due-row .av.teal   { background: #008C8C; }
  .due-row .av.blue   { background: #4785D9; }
  .due-row .av.purple { background: #8561C7; }
  .due-row .av.orange { background: #FA8C33; }
  .due-row .av.green  { background: #33A666; }
  .due-row .av.pink   { background: #D9668C; }
  .due-row .av.mint   { background: #33A68C; }
  .due-row .av.violet { background: #8561C7; }
  .due-row .nm {
    font-size: 12px; font-weight: 600;
    color: #181D26; line-height: 1;
  }
  .due-row .mrn {
    font-size: 11px; color: #8A91A1;
    line-height: 1;
  }
  .due-row .dem {
    font-size: 11px; color: #4F5763;
    line-height: 1;
  }
  .due-row .vac {
    font-size: 12px; color: #181D26;
    line-height: 1;
  }
  .due-row .pill {
    height: 18px;
    border-radius: 9px;
    padding: 0 8px;
    font-size: 10px; font-weight: 600;
    line-height: 18px;
    display: inline-block;
    white-space: nowrap;
  }
  .due-row .pill.danger  { background: #FCEAEA; color: #D93838; }
  .due-row .pill.warn    { background: #FFF4EA; color: #FA8C33; }
  .due-row .pill.neutral { background: #F5F6F7; color: #4F5763; }
  .due-row .sched {
    font-size: 12px; font-weight: 600;
    color: #008C8C;
    text-decoration: none;
    line-height: 1;
    text-align: right;
  }
  .due-empty {
    padding: 28px 20px;
    font-size: 12px; color: #8A91A1;
    text-align: center;
  }

  /* Right rail */
  .right {
    display: flex; flex-direction: column;
    gap: 16px;
  }
  .panel-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 15px 19px 16px;
  }
  .panel-card .plbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.5px;
    line-height: 1; margin-bottom: 0;
  }

  /* TX-DSHS sync */
  .sync-card .row {
    display: flex; align-items: center; gap: 8px;
    margin-top: 18px;
    font-size: 12px; font-weight: 500;
    color: #181D26; line-height: 1;
  }
  .sync-card .dot {
    width: 8px; height: 8px;
    background: #1F8C4D;
    border-radius: 50%;
  }
  .sync-card .meta {
    font-size: 11px; color: #4F5763;
    line-height: 1.5;
    margin-top: 8px;
  }
  .sync-card .force-form { margin: 0; }
  .sync-card .force-btn {
    display: block; width: 100%;
    margin-top: 16px;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 16px;
    height: 32px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    line-height: 30px;
    text-align: center;
    cursor: pointer;
    font-family: inherit;
  }
  .sync-card .force-btn:hover { background: #F5F6F7; }

  /* Monthly chart */
  .month-card { padding-bottom: 18px; }
  .month-card .sub2 {
    font-size: 11px; color: #8A91A1;
    line-height: 1; margin-top: 6px;
  }
  .chart {
    position: relative;
    height: 280px;
    margin-top: 14px;
    padding: 0 4px;
  }
  .chart .bars {
    display: flex; align-items: flex-end;
    gap: 8px;
    height: 220px;
  }
  .chart .col {
    flex: 1;
    display: flex; flex-direction: column;
    align-items: center;
    gap: 4px;
    height: 100%;
    justify-content: flex-end;
  }
  .chart .num {
    font-size: 10px; font-weight: 600;
    color: #181D26; line-height: 1;
    height: 12px;
  }
  .chart .bar {
    width: 100%;
    border-radius: 4px;
    min-height: 0;
  }
  .chart .bar.teal   { background: #008C8C; }
  .chart .bar.orange { background: #FA8C33; }
  .chart .axis {
    display: flex; gap: 8px;
    margin-top: 8px;
  }
  .chart .axis .lab {
    flex: 1;
    text-align: center;
    font-size: 10px; color: #8A91A1;
    line-height: 1;
  }
  .chart .caption {
    font-size: 11px; color: #4F5763;
    line-height: 1; margin-top: 12px;
  }

  /* Header buttons */
  .cp-pagehead form { margin: 0; display: inline-block; }
  .cp-btn.ghost { padding: 8px 12px; font-size: 13px; }
  .cp-btn.primary { padding: 8px 14px; font-size: 13px; font-weight: 600; }
</style>
</head>
<body class="cp-arch">

<div class="cp-shell" style="flex-direction:column;">
    <header class="cp-pagehead">
      <div class="info">
        <div class="row1">
          <span class="title"><?php echo xlt('Immunization Registry'); ?></span>
          <span class="dot">•</span>
          <span class="subt">
            <?php echo xlt('Coverage rates and outreach queue'); ?>
            · <?php echo text((string)count($patients)); ?> <?php echo xlt('patients'); ?>
            · <?php echo text((string)$totalAdmins); ?> <?php echo xlt('admins / 12 mo'); ?>
          </span>
        </div>
      </div>
      <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>">
        <input type="hidden" name="action" value="sync_registry">
        <?php if ($tab !== 'all'): ?>
          <input type="hidden" name="tab" value="<?php echo attr($tab); ?>">
        <?php endif; ?>
        <button type="submit" class="cp-btn ghost">⟳ <?php echo xlt('Sync to TX-DSHS'); ?></button>
      </form>
      <a class="cp-btn primary" href="/interface/patient_file/summary/immunizations.php?addvac=1">+ <?php echo xlt('Record vaccination'); ?></a>
    </header>

    <main class="cp-content">

      <?php if ($flash === 'registry_synced'): ?>
        <div class="cp-flash">✓ <?php echo xlt('TX-DSHS registry sync queued · audit row recorded'); ?></div>
      <?php endif; ?>

      <!-- Coverage tiles: 3×2 -->
      <div class="cov-grid">
        <?php foreach ($coverage as [$nm, $sub, $pill, $ptone, $pct, $tone, $denom]): ?>
          <div class="cov-card">
            <div class="nm"><?php echo text($nm); ?></div>
            <div class="sub"><?php echo text($sub); ?></div>
            <span class="gpill <?php echo attr($ptone); ?>"><?php echo text($pill); ?></span>
            <div class="pct <?php echo attr($tone); ?>"><?php echo text((string)$pct); ?>%</div>
            <div class="denom"><?php echo text($denom); ?></div>
            <div class="bar-bg"></div>
            <div class="bar-fill <?php echo attr($tone); ?>" style="left:15px; bottom:15px; width:calc((100% - 30px) * <?php echo (float)$pct / 100; ?>);"></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="cols">

        <!-- Patients due for vaccination -->
        <section class="due-panel">
          <div class="lbl"><?php echo xlt('PATIENTS DUE FOR VACCINATION'); ?></div>
          <div class="due-tabs">
            <?php foreach ($pills as [$nm, $active, $ct]):
                $slug = $tabSlug[$nm] ?? 'all';
                $href = $_SERVER['PHP_SELF'] . ($slug === 'all' ? '' : '?tab=' . $slug);
            ?>
              <a class="tab<?php echo $active ? ' active' : ''; ?>" href="<?php echo attr($href); ?>">
                <?php echo text($nm); ?><span class="ct"><?php echo text((string)(int)$ct); ?></span>
              </a>
            <?php endforeach; ?>
          </div>
          <div>
            <?php if (count($dueRows) === 0): ?>
              <div class="due-empty"><?php echo xlt('No patients currently due for this tab.'); ?></div>
            <?php else: ?>
              <?php foreach ($dueRows as $i => $row):
                  $schedHref = '/interface/main/calendar/add_edit_event.php?patient=' . urlencode((string)$row['pid']);
              ?>
                <div class="due-row<?php echo ($i % 2 === 1) ? ' alt' : ''; ?>">
                  <span class="av <?php echo attr($row['av']); ?>"></span>
                  <span class="nm"><?php echo text($row['name']); ?></span>
                  <span class="mrn"><?php echo text($row['mrn']); ?></span>
                  <span class="dem"><?php echo text($row['dem']); ?></span>
                  <span class="vac"><?php echo text($row['vac']); ?></span>
                  <span class="pill <?php echo attr($row['pillTone']); ?>"><?php echo text($row['pill']); ?></span>
                  <a href="<?php echo attr($schedHref); ?>" class="sched"><?php echo xlt('Schedule'); ?> →</a>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>

        <!-- Right rail: TX-DSHS sync + monthly chart -->
        <div class="right">

          <div class="panel-card sync-card">
            <div class="plbl"><?php echo xlt('TX-DSHS REGISTRY SYNC'); ?></div>
            <div class="row">
              <span class="dot"></span>
              <span><?php echo xlt('Connected · last sync'); ?> <?php echo text($lastSyncLabel); ?></span>
            </div>
            <div class="meta">
              <?php echo text((string)$pushedQ); ?> <?php echo xlt('records pushed this quarter'); ?><br>
              0 <?php echo xlt('pending'); ?> · 0 <?php echo xlt('errors'); ?>
            </div>
            <form class="force-form" method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>">
              <input type="hidden" name="action" value="sync_registry">
              <?php if ($tab !== 'all'): ?>
                <input type="hidden" name="tab" value="<?php echo attr($tab); ?>">
              <?php endif; ?>
              <button type="submit" class="force-btn"><?php echo xlt('Force re-sync now'); ?></button>
            </form>
          </div>

          <div class="panel-card month-card">
            <div class="plbl"><?php echo xlt('MONTHLY ADMINISTRATIONS'); ?></div>
            <div class="sub2"><?php echo xlt('Last 12 months'); ?></div>
            <div class="chart">
              <div class="bars">
                <?php foreach ($months as [$lab, $n, $showNum, $tone]):
                    $h = $n > 0 ? max(6, (int)round(($n / $maxBar) * $barMaxPx)) : 0;
                ?>
                  <div class="col">
                    <span class="num"><?php echo $showNum ? text((string)(int)$n) : ''; ?></span>
                    <div class="bar <?php echo attr($tone); ?>" style="height: <?php echo (int)$h; ?>px;"></div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="axis">
                <?php foreach ($months as [$lab, $n, $showNum, $tone]): ?>
                  <span class="lab"><?php echo text($lab); ?></span>
                <?php endforeach; ?>
              </div>
              <div class="caption">
                <?php echo xlt('Flu season peak Oct–Dec'); ?>
                (<?php echo text((string)$peakTotal); ?> <?php echo xlt('admins'); ?>)
              </div>
            </div>
          </div>

        </div>

      </div>

    </main>
</div>

</body>
</html>
