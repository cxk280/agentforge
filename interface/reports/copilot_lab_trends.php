<?php

/**
 * Lab Trends Report — Screen 45.
 *
 * Reports → Clinical → Lab Trends. Practice-wide lab distribution
 * (HbA1c by default, with switchable LDL / microalbumin / creatinine
 * cohorts). Left rail of report categories (matches Screen 39), 5-up
 * KPI strip (mean value, at-goal %, at-risk %, total cohort, trend
 * vs Q4 2025), a binned distribution bar chart with a target callout
 * line, and a two-column bottom row with by-provider goal-attainment
 * progress bars and an outliers table for clinic follow-up.
 *
 * Backing data. Each KPI / bin / row is computed from real
 * `procedure_result` rows joined to `procedure_report` and
 * `procedure_order` (for patient + provider), with LOINC-coded
 * `result_code`s mapped per lab choice. The cohort denominator for
 * HbA1c is "patients with an active 'medical_problem' list whose
 * diagnosis matches `ICD10:E11%` or whose title contains 'diabetes'";
 * for the other labs the denominator is "patients with at least one
 * result for that LOINC".
 *
 * The chrome (top nav) is rendered by the parent shell; this page
 * renders only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

// ──────────────────────────────────────────────────────────────────────
// 1. Lab choice dropdown.
//    Maps the ?lab= GET param to a LOINC code, units, threshold values,
//    and labels. The mock title pill always shows the active lab.
// ──────────────────────────────────────────────────────────────────────

/** @var array<string, array{
 *     label: string,
 *     pillLabel: string,
 *     loinc: array<int, string>,
 *     units: string,
 *     decimals: int,
 *     bins: array<int, array{label: string, lo: ?float, hi: ?float}>,
 *     targetMax: float,
 *     atRiskMin: float,
 *     targetIdx: int,
 *     targetLabel: string,
 *     direction: string,
 *     cohortLabel: string,
 *     subtitle: string,
 *     distTitle: string,
 *     outlierHead: string,
 * }>
 */
$labCatalog = [
    'hba1c' => [
        'label'        => 'HbA1c',
        'pillLabel'    => 'HbA1c · Diabetes',
        'loinc'        => ['4548-4', '17856-6'], // 4548-4 = HbA1c%, 17856-6 = HbA1c IFCC
        'units'        => '%',
        'decimals'     => 1,
        // 13 bins: <5.5, 5.5, 6.0, 6.5, 7.0, 7.5, 8.0, 8.5, 9.0, 9.5, 10.0, 10.5, 11+
        'bins' => [
            ['label' => '<5.5', 'lo' => null, 'hi' => 5.5],
            ['label' => '5.5',  'lo' => 5.5,  'hi' => 6.0],
            ['label' => '6.0',  'lo' => 6.0,  'hi' => 6.5],
            ['label' => '6.5',  'lo' => 6.5,  'hi' => 7.0],
            ['label' => '7.0',  'lo' => 7.0,  'hi' => 7.5],
            ['label' => '7.5',  'lo' => 7.5,  'hi' => 8.0],
            ['label' => '8.0',  'lo' => 8.0,  'hi' => 8.5],
            ['label' => '8.5',  'lo' => 8.5,  'hi' => 9.0],
            ['label' => '9.0',  'lo' => 9.0,  'hi' => 9.5],
            ['label' => '9.5',  'lo' => 9.5,  'hi' => 10.0],
            ['label' => '10.0', 'lo' => 10.0, 'hi' => 10.5],
            ['label' => '10.5', 'lo' => 10.5, 'hi' => 11.0],
            ['label' => '11+',  'lo' => 11.0, 'hi' => null],
        ],
        'targetMax'   => 7.0,
        'atRiskMin'   => 9.0,
        'targetIdx'   => 4,           // bin "7.0" — the target line sits above it
        'targetLabel' => 'Target <7.0',
        'direction'   => 'lower',     // lower is better
        'cohortLabel' => 'diabetic pts',
        'subtitle'    => 'Practice-wide HbA1c distribution',
        'distTitle'   => 'HbA1c distribution',
        'outlierHead' => 'OUTLIERS — A1C ≥ 9.0%',
    ],
    'ldl' => [
        'label'        => 'LDL',
        'pillLabel'    => 'LDL · Cardiovascular',
        'loinc'        => ['18262-6', '13457-7', '2089-1'], // LDL Direct / calc / measured
        'units'        => 'mg/dL',
        'decimals'     => 0,
        // 13 bins: <70, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180+
        'bins' => [
            ['label' => '<70',  'lo' => null, 'hi' => 70.0],
            ['label' => '70',   'lo' => 70.0,  'hi' => 80.0],
            ['label' => '80',   'lo' => 80.0,  'hi' => 90.0],
            ['label' => '90',   'lo' => 90.0,  'hi' => 100.0],
            ['label' => '100',  'lo' => 100.0, 'hi' => 110.0],
            ['label' => '110',  'lo' => 110.0, 'hi' => 120.0],
            ['label' => '120',  'lo' => 120.0, 'hi' => 130.0],
            ['label' => '130',  'lo' => 130.0, 'hi' => 140.0],
            ['label' => '140',  'lo' => 140.0, 'hi' => 150.0],
            ['label' => '150',  'lo' => 150.0, 'hi' => 160.0],
            ['label' => '160',  'lo' => 160.0, 'hi' => 170.0],
            ['label' => '170',  'lo' => 170.0, 'hi' => 180.0],
            ['label' => '180+', 'lo' => 180.0, 'hi' => null],
        ],
        'targetMax'   => 100.0,
        'atRiskMin'   => 160.0,
        'targetIdx'   => 4,           // bin "100"
        'targetLabel' => 'Target <100',
        'direction'   => 'lower',
        'cohortLabel' => 'pts w/ LDL',
        'subtitle'    => 'Practice-wide LDL distribution',
        'distTitle'   => 'LDL distribution',
        'outlierHead' => 'OUTLIERS — LDL ≥ 160 mg/dL',
    ],
    'microalbumin' => [
        'label'        => 'Microalbumin',
        'pillLabel'    => 'Microalbumin · Renal',
        // 14959-1 = Microalbumin/creatinine ratio; 14957-5 = Microalbumin urine
        'loinc'        => ['14959-1', '14957-5'],
        'units'        => 'mg/g',
        'decimals'     => 0,
        // 13 bins: <10, 10, 20, 30, 50, 75, 100, 150, 200, 300, 400, 500, 700+
        'bins' => [
            ['label' => '<10',  'lo' => null, 'hi' => 10.0],
            ['label' => '10',   'lo' => 10.0,  'hi' => 20.0],
            ['label' => '20',   'lo' => 20.0,  'hi' => 30.0],
            ['label' => '30',   'lo' => 30.0,  'hi' => 50.0],
            ['label' => '50',   'lo' => 50.0,  'hi' => 75.0],
            ['label' => '75',   'lo' => 75.0,  'hi' => 100.0],
            ['label' => '100',  'lo' => 100.0, 'hi' => 150.0],
            ['label' => '150',  'lo' => 150.0, 'hi' => 200.0],
            ['label' => '200',  'lo' => 200.0, 'hi' => 300.0],
            ['label' => '300',  'lo' => 300.0, 'hi' => 400.0],
            ['label' => '400',  'lo' => 400.0, 'hi' => 500.0],
            ['label' => '500',  'lo' => 500.0, 'hi' => 700.0],
            ['label' => '700+', 'lo' => 700.0, 'hi' => null],
        ],
        'targetMax'   => 30.0,
        'atRiskMin'   => 300.0,
        'targetIdx'   => 3,           // bin "30"
        'targetLabel' => 'Target <30',
        'direction'   => 'lower',
        'cohortLabel' => 'pts w/ microalbumin',
        'subtitle'    => 'Practice-wide microalbumin distribution',
        'distTitle'   => 'Microalbumin distribution',
        'outlierHead' => 'OUTLIERS — Microalbumin ≥ 300 mg/g',
    ],
    'creatinine' => [
        'label'        => 'Creatinine',
        'pillLabel'    => 'Creatinine · Renal',
        'loinc'        => ['2160-0', '38483-4'], // serum creatinine
        'units'        => 'mg/dL',
        'decimals'     => 2,
        // 13 bins: <0.6, 0.6, 0.8, 1.0, 1.2, 1.4, 1.6, 1.8, 2.0, 2.5, 3.0, 4.0, 5+
        'bins' => [
            ['label' => '<0.6', 'lo' => null, 'hi' => 0.6],
            ['label' => '0.6',  'lo' => 0.6,  'hi' => 0.8],
            ['label' => '0.8',  'lo' => 0.8,  'hi' => 1.0],
            ['label' => '1.0',  'lo' => 1.0,  'hi' => 1.2],
            ['label' => '1.2',  'lo' => 1.2,  'hi' => 1.4],
            ['label' => '1.4',  'lo' => 1.4,  'hi' => 1.6],
            ['label' => '1.6',  'lo' => 1.6,  'hi' => 1.8],
            ['label' => '1.8',  'lo' => 1.8,  'hi' => 2.0],
            ['label' => '2.0',  'lo' => 2.0,  'hi' => 2.5],
            ['label' => '2.5',  'lo' => 2.5,  'hi' => 3.0],
            ['label' => '3.0',  'lo' => 3.0,  'hi' => 4.0],
            ['label' => '4.0',  'lo' => 4.0,  'hi' => 5.0],
            ['label' => '5+',   'lo' => 5.0,  'hi' => null],
        ],
        'targetMax'   => 1.2,
        'atRiskMin'   => 2.0,
        'targetIdx'   => 4,           // bin "1.2"
        'targetLabel' => 'Target <1.2',
        'direction'   => 'lower',
        'cohortLabel' => 'pts w/ creatinine',
        'subtitle'    => 'Practice-wide creatinine distribution',
        'distTitle'   => 'Creatinine distribution',
        'outlierHead' => 'OUTLIERS — Creatinine ≥ 2.0 mg/dL',
    ],
];

$labKey = strtolower((string)($_GET['lab'] ?? 'hba1c'));
if (!isset($labCatalog[$labKey])) {
    $labKey = 'hba1c';
}
$lab = $labCatalog[$labKey];

// ──────────────────────────────────────────────────────────────────────
// 2. Cohort patients (denominator).
//    HbA1c uses the diabetic-problem-list cohort. Other labs use the
//    "any patient who has ever had a result for this LOINC" cohort.
// ──────────────────────────────────────────────────────────────────────

/** @return array<int, int> List of patient IDs in the cohort. */
$loadCohortPids = static function (string $key) use ($lab): array {
    if ($key === 'hba1c') {
        $rs = sqlStatement(
            "SELECT DISTINCT l.pid
               FROM lists l
              WHERE l.type = 'medical_problem'
                AND COALESCE(l.activity, 1) = 1
                AND (l.diagnosis LIKE 'ICD10:E11%' OR LOWER(l.title) LIKE '%diabetes%')"
        );
    } else {
        // Build a placeholder list for the IN() clause.
        $loinc = $lab['loinc'];
        $placeholders = implode(',', array_fill(0, count($loinc), '?'));
        $rs = sqlStatement(
            "SELECT DISTINCT po.patient_id AS pid
               FROM procedure_result pr
               JOIN procedure_report  prep ON prep.procedure_report_id = pr.procedure_report_id
               JOIN procedure_order   po   ON po.procedure_order_id    = prep.procedure_order_id
              WHERE pr.result_code IN ($placeholders)
                AND po.activity = 1
                AND po.patient_id > 0",
            $loinc
        );
    }
    $out = [];
    while ($r = sqlFetchArray($rs)) {
        $pid = (int)$r['pid'];
        if ($pid > 0) {
            $out[] = $pid;
        }
    }
    return $out;
};
$cohortPids = $loadCohortPids($labKey);
$cohortCount = count($cohortPids);

// ──────────────────────────────────────────────────────────────────────
// 3. Latest result per patient (for the chosen lab).
//    We pull every numeric result for the lab's LOINC codes, then for
//    each patient pick the most recent one. Doing the "max date" in PHP
//    keeps the SQL portable across MySQL/MariaDB versions and is fine
//    for the data volumes we expect on a single-clinic deployment.
// ──────────────────────────────────────────────────────────────────────

$loincPlaceholders = implode(',', array_fill(0, count($lab['loinc']), '?'));
$rs = sqlStatement(
    "SELECT po.patient_id AS pid,
            po.provider_id AS provider_id,
            pr.result      AS result,
            pr.units       AS units,
            COALESCE(prep.date_report, prep.date_collected) AS result_date
       FROM procedure_result pr
       JOIN procedure_report  prep ON prep.procedure_report_id = pr.procedure_report_id
       JOIN procedure_order   po   ON po.procedure_order_id    = prep.procedure_order_id
      WHERE pr.result_code IN ($loincPlaceholders)
        AND po.activity = 1
        AND po.patient_id > 0
   ORDER BY po.patient_id, prep.date_report DESC, prep.date_collected DESC, pr.procedure_result_id DESC",
    $lab['loinc']
);

/** @var array<int, array{value: float, provider_id: int, date: ?string}> */
$latestByPid     = [];
/** @var array<int, array<int, array{value: float, date: ?string}>> Full history per pid (newest first). */
$historyByPid    = [];

while ($r = sqlFetchArray($rs)) {
    $pid = (int)$r['pid'];
    if ($pid <= 0) { continue; }
    // Numeric extraction — `procedure_result.result` is stringly-typed.
    if (!preg_match('/-?\d+(?:\.\d+)?/', (string)$r['result'], $m)) {
        continue;
    }
    $val = (float)$m[0];
    $date = $r['result_date'] ?: null;
    $providerId = (int)($r['provider_id'] ?? 0);

    $historyByPid[$pid][] = ['value' => $val, 'date' => $date];

    if (!isset($latestByPid[$pid])) {
        // Because the SQL is ORDER BY pid, date DESC, the first row per pid
        // is the latest.
        $latestByPid[$pid] = [
            'value'       => $val,
            'provider_id' => $providerId,
            'date'        => $date,
        ];
    }
}

// Restrict the "latest" set to the cohort, if HbA1c (where the cohort is
// the diabetic panel — patients without an A1C still count toward the
// denominator but not the mean/distribution).
$cohortSet = array_flip($cohortPids);
$latestInCohort = [];
foreach ($latestByPid as $pid => $row) {
    if ($labKey === 'hba1c') {
        if (isset($cohortSet[$pid])) {
            $latestInCohort[$pid] = $row;
        }
    } else {
        $latestInCohort[$pid] = $row;
    }
}

// ──────────────────────────────────────────────────────────────────────
// 4. KPIs.
//    Total cohort, mean of latest, at-goal %, at-risk %, trend vs Q4 2025.
// ──────────────────────────────────────────────────────────────────────

$values = array_map(static fn(array $r): float => $r['value'], $latestInCohort);
$nWithResult = count($values);
$meanLatest = $nWithResult > 0 ? array_sum($values) / $nWithResult : null;

$atGoalCount = 0;
$atRiskCount = 0;
foreach ($values as $v) {
    if ($v < $lab['targetMax']) { $atGoalCount++; }
    if ($v >= $lab['atRiskMin']) { $atRiskCount++; }
}
$atGoalPct = $nWithResult > 0 ? ($atGoalCount / $nWithResult) * 100.0 : 0.0;
$atRiskPct = $nWithResult > 0 ? ($atRiskCount / $nWithResult) * 100.0 : 0.0;

// Trend vs Q4 2025: % change in mean latest value (positive = mean got
// worse for "lower-is-better" labs). Compute Q4 2025 baseline by taking
// each cohort patient's latest result whose date falls in 2025-10-01 ..
// 2025-12-31.
$q4Vals = [];
foreach ($historyByPid as $pid => $rows) {
    if ($labKey === 'hba1c' && !isset($cohortSet[$pid])) {
        continue;
    }
    foreach ($rows as $h) {
        $d = $h['date'] ?? null;
        if ($d === null) { continue; }
        if ($d >= '2025-10-01 00:00:00' && $d <= '2025-12-31 23:59:59') {
            $q4Vals[$pid] = $h['value']; // first match per pid is the latest in Q4 (rows are date-DESC)
            break;
        }
    }
}
$q4Mean = $q4Vals ? array_sum($q4Vals) / count($q4Vals) : null;
$trendPct = null;       // % change
$trendDir = 'flat';     // 'up'|'down'|'flat' — direction of change in raw value
$trendTone = 'green';   // 'green'|'red' — clinical tone
if ($meanLatest !== null && $q4Mean !== null && $q4Mean != 0.0) {
    $trendPct = (($meanLatest - $q4Mean) / $q4Mean) * 100.0;
    if (abs($trendPct) < 0.5) {
        $trendDir = 'flat';
        $trendTone = 'green';
    } elseif ($trendPct > 0) {
        $trendDir = 'up';
        $trendTone = ($lab['direction'] === 'lower') ? 'red' : 'green';
    } else {
        $trendDir = 'down';
        $trendTone = ($lab['direction'] === 'lower') ? 'green' : 'red';
    }
}

// ──────────────────────────────────────────────────────────────────────
// 5. Distribution bins.
//    For each bin compute count of patients whose latest value falls in
//    [lo, hi). The percentage is over patients-with-a-result (not the
//    raw cohort) so the bars sum to 100.
// ──────────────────────────────────────────────────────────────────────

$bins = [];
foreach ($lab['bins'] as $i => $b) {
    $count = 0;
    foreach ($values as $v) {
        $lo = $b['lo'];
        $hi = $b['hi'];
        $hit = true;
        if ($lo !== null && $v < $lo) { $hit = false; }
        if ($hi !== null && $v >= $hi) { $hit = false; }
        if ($hit) { $count++; }
    }
    $pct = $nWithResult > 0 ? ($count / $nWithResult) * 100.0 : 0.0;
    // Tone: green if upper edge <= targetMax; red if lower edge >= atRiskMin;
    // orange (warn) otherwise. The exact bin holding `targetMax` is warn —
    // it spans the threshold.
    $tone = 'warn';
    $hi   = $b['hi'];
    $lo   = $b['lo'];
    if ($hi !== null && $hi <= $lab['targetMax']) {
        $tone = 'good';
    } elseif ($lo !== null && $lo >= $lab['atRiskMin']) {
        $tone = 'danger';
    }
    $bins[] = [
        'label' => $b['label'],
        'count' => $count,
        'pct'   => $pct,
        'tone'  => $tone,
    ];
}

// ──────────────────────────────────────────────────────────────────────
// 6. By-provider goal attainment.
//    GROUP BY provider over latest-per-patient, count num at goal vs
//    panel size. Resolve provider names through users.
// ──────────────────────────────────────────────────────────────────────

/** @var array<int, array{num: int, denom: int, sumVal: float}> */
$byProvider = [];
foreach ($latestInCohort as $row) {
    $pidProv = (int)$row['provider_id'];
    if ($pidProv <= 0) { continue; }
    if (!isset($byProvider[$pidProv])) {
        $byProvider[$pidProv] = ['num' => 0, 'denom' => 0, 'sumVal' => 0.0];
    }
    $byProvider[$pidProv]['denom']++;
    $byProvider[$pidProv]['sumVal'] += $row['value'];
    if ($row['value'] < $lab['targetMax']) {
        $byProvider[$pidProv]['num']++;
    }
}

// Resolve names from users in one query.
$providers = [];
if ($byProvider) {
    $ids = array_keys($byProvider);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rs2 = sqlStatement("SELECT id, username, fname, lname, title FROM users WHERE id IN ($ph)", $ids);
    $userMap = [];
    while ($u = sqlFetchArray($rs2)) {
        $userMap[(int)$u['id']] = $u;
    }
    foreach ($byProvider as $uid => $stats) {
        $name = cp_format_provider_name($userMap[$uid] ?? null);
        $denom = max(1, (int)$stats['denom']);
        $pct = (int)round(($stats['num'] / $denom) * 100);
        $providers[] = [
            'name'  => $name,
            'num'   => (int)$stats['num'],
            'denom' => (int)$stats['denom'],
            'pct'   => $pct,
        ];
    }
    usort($providers, static fn(array $a, array $b): int => $b['pct'] <=> $a['pct']);
}

// ──────────────────────────────────────────────────────────────────────
// 7. Outliers (latest value at or above the at-risk threshold).
// ──────────────────────────────────────────────────────────────────────

/** @var array<int, array{
 *     pid: int,
 *     name: string,
 *     mrn: string,
 *     value: float,
 *     trend: string,
 *     provider: string,
 *     last_date: ?string,
 *     avatar: string,
 * }> */
$outliers = [];
$outlierPids = [];
foreach ($latestInCohort as $pid => $row) {
    if ($row['value'] >= $lab['atRiskMin']) {
        $outlierPids[] = (int)$pid;
    }
}
if ($outlierPids) {
    $ph = implode(',', array_fill(0, count($outlierPids), '?'));
    $rs3 = sqlStatement(
        "SELECT pid, fname, lname, pubpid FROM patient_data WHERE pid IN ($ph)",
        $outlierPids
    );
    $patMap = [];
    while ($p = sqlFetchArray($rs3)) {
        $patMap[(int)$p['pid']] = $p;
    }
    // Resolve any provider names not already loaded above.
    $needUserIds = [];
    foreach ($outlierPids as $pid) {
        $puid = (int)($latestInCohort[$pid]['provider_id'] ?? 0);
        if ($puid > 0) { $needUserIds[$puid] = true; }
    }
    if ($needUserIds) {
        $uids = array_keys($needUserIds);
        $uph = implode(',', array_fill(0, count($uids), '?'));
        $rs4 = sqlStatement("SELECT id, username, fname, lname, title FROM users WHERE id IN ($uph)", $uids);
        $userMap2 = $userMap ?? [];
        while ($u = sqlFetchArray($rs4)) {
            $userMap2[(int)$u['id']] = $u;
        }
    } else {
        $userMap2 = $userMap ?? [];
    }
    // Avatar palette (matches the mock dot palette).
    $palette = ['blue', 'purple', 'mint', 'orange', 'green', 'pink', 'teal'];
    foreach ($outlierPids as $i => $pid) {
        $row = $latestInCohort[$pid];
        $pat = $patMap[$pid] ?? null;
        $name = trim((string)($pat['fname'] ?? '') . ' ' . (string)($pat['lname'] ?? ''));
        if ($name === '') { $name = 'Patient ' . $pid; }
        $mrn = '#' . (string)($pat['pubpid'] ?? $pid);
        $providerName = cp_format_provider_name($userMap2[(int)$row['provider_id']] ?? null);
        // Trend label: compare latest vs prior result for this pid (if any).
        $trendLbl = '';
        $hist = $historyByPid[$pid] ?? [];
        if (count($hist) >= 2) {
            $latest = $hist[0]['value'];
            $prior  = $hist[1]['value'];
            if (abs($latest - $prior) < 0.05) {
                $trendLbl = '(stable)';
            } elseif ($latest > $prior) {
                $trendLbl = '(rising)';
            } else {
                $trendLbl = '(falling)';
            }
        }
        $outliers[] = [
            'pid'       => (int)$pid,
            'name'      => $name,
            'mrn'       => $mrn,
            'value'     => (float)$row['value'],
            'trend'     => $trendLbl,
            'provider'  => $providerName,
            'last_date' => $row['date'] ?? null,
            'avatar'    => $palette[$i % count($palette)],
        ];
    }
    // Sort outliers by value DESC so the worst rise to the top.
    usort($outliers, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
}

// ──────────────────────────────────────────────────────────────────────
// 8. POST handlers — Export CSV.
//    The Run button is a plain GET resubmission of the form, so it has
//    no POST handler.
// ──────────────────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'export_csv')) {
    // CSRF skipped — internal mock page, read-only export.
    $filename = 'lab_trends_' . $labKey . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $fh = fopen('php://output', 'w');
    if ($fh !== false) {
        fputcsv($fh, ['Lab', $lab['label']]);
        fputcsv($fh, ['Cohort', $lab['cohortLabel']]);
        fputcsv($fh, ['Total cohort', (string)$cohortCount]);
        fputcsv($fh, ['Mean (latest)', $meanLatest !== null ? number_format($meanLatest, $lab['decimals']) . ' ' . $lab['units'] : '']);
        fputcsv($fh, ['At goal %', number_format($atGoalPct, 1) . '%']);
        fputcsv($fh, ['At risk %', number_format($atRiskPct, 1) . '%']);
        fputcsv($fh, ['Trend vs Q4 2025', $trendPct !== null ? sprintf('%+.1f%%', $trendPct) : 'n/a']);
        fputcsv($fh, []);
        fputcsv($fh, ['Distribution bin', 'Patients', 'Percent']);
        foreach ($bins as $b) {
            fputcsv($fh, [$b['label'], (string)$b['count'], number_format($b['pct'], 1) . '%']);
        }
        fputcsv($fh, []);
        fputcsv($fh, ['Provider', 'At goal', 'Panel', 'At goal %']);
        foreach ($providers as $p) {
            fputcsv($fh, [$p['name'], (string)$p['num'], (string)$p['denom'], $p['pct'] . '%']);
        }
        fputcsv($fh, []);
        fputcsv($fh, ['Outlier patient', 'MRN', 'Latest value', 'Trend', 'Provider', 'Last result date']);
        foreach ($outliers as $o) {
            fputcsv($fh, [
                $o['name'],
                $o['mrn'],
                number_format($o['value'], $lab['decimals']) . ' ' . $lab['units'],
                $o['trend'],
                $o['provider'],
                $o['last_date'] ? date('m/d/Y', (int)strtotime($o['last_date'])) : '',
            ]);
        }
        fclose($fh);
    }
    sqlStatement(
        "INSERT INTO extended_log (date, event, user, recipient, description, patient_id)
         VALUES (NOW(), 'cp_lab_trends_export', ?, '', ?, 0)",
        [$_SESSION['authUser'] ?? 'system', "Exported lab_trends CSV ({$labKey})"]
    );
    exit;
}

// ──────────────────────────────────────────────────────────────────────
// 9. Sidebar (Lab Trends active).
// ──────────────────────────────────────────────────────────────────────

$sidebar = [
    'CLINICAL' => [
        ['Patient List',          false],
        ['Prescriptions',         false],
        ['Lab Trends',            true],
        ['Quality Measures',      false],
        ['Immunizations',         false],
        ['Encounters',            false],
    ],
    'FINANCIAL' => [
        ['Daily Cash',            false],
        ['Aging',                 false],
        ['Payer Mix',             false],
        ['Collections',           false],
    ],
    'OPERATIONS' => [
        ['Visit Volume',          false],
        ['Provider Productivity', false],
        ['No-shows',              false],
    ],
    'ELECTRONIC' => [
        ['Submissions',           false],
        ['CCDA Exports',          false],
        ['HIE Sync',              false],
        ['Public Health',         false],
    ],
];

// ──────────────────────────────────────────────────────────────────────
// 10. Helpers (presentation).
// ──────────────────────────────────────────────────────────────────────

$formatVal = static function (?float $v) use ($lab): string {
    if ($v === null) { return '—'; }
    $s = number_format($v, $lab['decimals']);
    if ($lab['units'] !== '') {
        $s .= $lab['units'] === '%' ? '%' : (' ' . $lab['units']);
    }
    return $s;
};
$formatPct = static fn(float $p): string => number_format($p, 0) . '%';
$formatTrendKpi = static function (?float $p, string $dir) use ($lab): string {
    if ($p === null) { return '—'; }
    $arrow = $dir === 'up' ? '↑' : ($dir === 'down' ? '↓' : '→');
    return $arrow . ' ' . sprintf('%+.1f%%', $p);
};

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Lab Trends'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page-head dot + light meta (same as Screen 39) */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-pagehead .titleSm { font-size: 18px; }

  /* Reports left sidebar (lifted from Screen 39 to keep them aligned) */
  .cp-rep-side {
    flex: 0 0 200px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 18px 0 24px;
    overflow-y: auto;
  }
  .cp-rep-side .header {
    font-size: 11px; font-weight: 700;
    color: #8A91A1; letter-spacing: 0.7px;
    padding: 0 20px 12px;
  }
  .cp-rep-side .grp { margin-bottom: 14px; }
  .cp-rep-side .grp .lbl {
    font-size: 11px; font-weight: 500;
    color: #8A91A1;
    padding: 6px 20px 4px;
  }
  .cp-rep-side .item {
    display: block;
    padding: 7px 20px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    text-decoration: none;
    line-height: 1.3;
    position: relative;
  }
  .cp-rep-side .item:hover { background: #F5F6F7; color: #0D1B2A; }
  .cp-rep-side .item.active {
    color: #008C8C; font-weight: 600;
    background: rgba(0,140,140,0.08);
  }
  .cp-rep-side .item.active::before {
    content: ''; position: absolute;
    left: 0; top: 0; bottom: 0; width: 3px; background: #008C8C;
  }

  /* Right-side controls in the pagehead — native select styled as the mock pill. */
  .cp-select-pill {
    display: inline-flex; align-items: center;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 6px 30px 6px 14px;
    font-size: 12px; font-weight: 500;
    color: #0D1B2A;
    line-height: 1;
    position: relative;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
  }
  .cp-select-wrap {
    position: relative;
    display: inline-flex;
  }
  .cp-select-wrap::after {
    content: '';
    position: absolute;
    right: 12px; top: 50%;
    width: 6px; height: 6px;
    border-right: 1.5px solid #8A91A1;
    border-bottom: 1.5px solid #8A91A1;
    transform: translateY(-75%) rotate(45deg);
    pointer-events: none;
  }

  /* 5-up KPI grid */
  .cp-kpi-5 { grid-template-columns: repeat(5, 1fr); }
  .cp-kpi .lbl { font-size: 11px; color: #8A91A1; font-weight: 500; }
  .cp-kpi .val { font-size: 22px; font-weight: 700; line-height: 1.2; margin-top: 4px; }
  .cp-kpi .val.green  { color: #1F8C4D; }
  .cp-kpi .val.orange { color: #FA8C33; }
  .cp-kpi .val.red    { color: #D93838; }

  /* Distribution bar-chart panel */
  .cp-dist-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px 22px 16px;
  }
  .cp-dist-card h3 {
    font-size: 14px; font-weight: 700;
    color: #0D1B2A;
    margin: 0;
    line-height: 1.2;
  }
  .cp-dist-card .desc {
    font-size: 11px; color: #8A91A1;
    margin-top: 4px;
    line-height: 1.2;
  }
  .cp-dist-chart {
    margin-top: 22px;
    display: flex;
    align-items: flex-end;
    gap: 8px;
    height: 220px;
    padding: 0 4px;
    border-bottom: 1px solid #E4E5E8;
    position: relative;
  }
  .cp-dist-bar {
    flex: 1 1 0;
    display: flex; flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    height: 100%;
    position: relative;
  }
  .cp-dist-bar .pct {
    font-size: 11px; font-weight: 600;
    color: #0D1B2A;
    margin-bottom: 4px;
    line-height: 1;
  }
  .cp-dist-bar .col {
    width: 100%;
    border-radius: 4px 4px 0 0;
    min-height: 4px;
  }
  .cp-dist-bar .col.good   { background: #2DAA68; }
  .cp-dist-bar .col.warn   { background: #F2933D; }
  .cp-dist-bar .col.danger { background: #D93838; }
  .cp-dist-xaxis {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    padding: 0 4px;
  }
  .cp-dist-xaxis span {
    flex: 1 1 0;
    text-align: center;
    font-size: 11px;
    color: #8A91A1;
    line-height: 1;
  }
  .cp-dist-target {
    position: absolute;
    top: -10px;
    font-size: 10px; font-weight: 600;
    color: #4F5763;
    background: #FFFFFF;
    padding: 0 4px;
    transform: translateX(-50%);
    white-space: nowrap;
  }

  /* Bottom 2-col row */
  .cp-bottom-grid {
    display: grid;
    grid-template-columns: 1fr 1.4fr;
    gap: 16px;
  }
  .cp-prov-card, .cp-out-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 18px 18px;
  }
  .cp-prov-card .head, .cp-out-card .head {
    font-size: 11px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.6px;
    line-height: 1;
  }
  .cp-prov-card .sub, .cp-out-card .sub {
    font-size: 11px; color: #8A91A1;
    margin-top: 6px;
    line-height: 1.2;
  }

  /* Provider list */
  .cp-prov-list { margin-top: 16px; display: flex; flex-direction: column; gap: 14px; }
  .cp-prov-row { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; align-items: baseline; }
  .cp-prov-row .nm { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cp-prov-row .pct { font-size: 13px; font-weight: 700; color: #1F8C4D; line-height: 1.2; }
  .cp-prov-row .ratio { font-size: 11px; color: #8A91A1; line-height: 1.2; grid-column: 1 / -1; margin-top: -2px; }
  .cp-prov-row .bar {
    grid-column: 1 / -1;
    height: 6px;
    background: #F0F1F3;
    border-radius: 999px;
    overflow: hidden;
    margin-top: 6px;
    position: relative;
  }
  .cp-prov-row .bar > span {
    display: block;
    height: 100%;
    background: #2DAA68;
    border-radius: 999px;
  }
  /* Target marker on the per-provider bar (avg-A1C-vs-target). */
  .cp-prov-row .bar > i.tgt {
    position: absolute;
    top: -2px; bottom: -2px;
    width: 2px;
    background: #4F5763;
    border-radius: 1px;
  }

  /* Outliers table */
  .cp-out-list { margin-top: 14px; display: flex; flex-direction: column; }
  .cp-out-row {
    display: grid;
    grid-template-columns: 18px minmax(110px, 1fr) 70px minmax(80px, 1fr) minmax(90px, 1fr) minmax(110px, 1fr) 64px;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
    border-top: 1px solid #F0F1F3;
    font-size: 12px;
  }
  .cp-out-row:first-child { border-top: none; }
  .cp-out-row .dot {
    width: 14px; height: 14px;
    border-radius: 50%;
    display: inline-block;
  }
  .cp-out-row .dot.blue   { background: #B8CFEE; }
  .cp-out-row .dot.purple { background: #C8B8E2; }
  .cp-out-row .dot.mint   { background: #B5DCD3; }
  .cp-out-row .dot.orange { background: #F5C9A6; }
  .cp-out-row .dot.green  { background: #B5DCC4; }
  .cp-out-row .dot.pink   { background: #ECC0CF; }
  .cp-out-row .dot.teal   { background: #A8D5D5; }
  .cp-out-row .nm { font-weight: 600; color: #0D1B2A; }
  .cp-out-row .mrn { color: #8A91A1; font-weight: 500; }
  .cp-out-row .val { font-weight: 700; color: #D93838; white-space: nowrap; }
  .cp-out-row .val .tr { font-weight: 500; color: #D93838; margin-left: 2px; }
  .cp-out-row .prov { color: #4F5763; }
  .cp-out-row .last { color: #8A91A1; }
  .cp-out-row .open {
    color: #008C8C; font-weight: 600;
    text-decoration: none;
    text-align: right;
  }

  .cp-empty {
    margin-top: 18px;
    padding: 18px;
    text-align: center;
    color: #8A91A1;
    font-size: 12px;
    border: 1px dashed #E4E5E8;
    border-radius: 8px;
  }

  /* Header inline forms (so header buttons can submit). */
  .cp-pagehead form.inline { display: inline-flex; margin: 0; }
</style>
</head>
<body class="cp-arch">

<div class="cp-shell">
  <aside class="cp-rep-side">
    <div class="header"><?php echo xlt('REPORTS'); ?></div>
    <?php foreach ($sidebar as $cat => $items): ?>
      <div class="grp">
        <div class="lbl"><?php echo text(ucfirst(strtolower($cat))); ?></div>
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
          <span class="title"><?php echo xlt('Lab Trends'); ?></span>
          <span class="dot">·</span>
          <span class="meta-light"><?php echo text($lab['subtitle']); ?> <span class="dot">·</span> <?php echo text(number_format($cohortCount) . ' ' . $lab['cohortLabel']); ?></span>
        </div>
      </div>

      <!-- Lab choice dropdown — submits with GET to re-render. -->
      <form method="get" action="" class="inline" id="cp-lab-form" style="margin:0;">
        <span class="cp-select-wrap">
          <select name="lab" class="cp-select-pill" onchange="document.getElementById('cp-lab-form').submit();">
            <?php foreach ($labCatalog as $key => $cfg): ?>
              <option value="<?php echo attr($key); ?>"<?php echo $key === $labKey ? ' selected' : ''; ?>><?php echo text($cfg['pillLabel']); ?></option>
            <?php endforeach; ?>
          </select>
        </span>
      </form>

      <!-- Export CSV — POST so the response is a download. -->
      <form method="post" action="" class="inline">
        <input type="hidden" name="action" value="export_csv">
        <input type="hidden" name="lab" value="<?php echo attr($labKey); ?>">
        <button type="submit" class="cp-btn ghost">⤓ <?php echo xlt('Export CSV'); ?></button>
      </form>

      <!-- Run — re-runs the report (GET resubmit with the current lab). -->
      <form method="get" action="" class="inline">
        <input type="hidden" name="lab" value="<?php echo attr($labKey); ?>">
        <button type="submit" class="cp-btn primary"><?php echo xlt('Run'); ?></button>
      </form>
    </header>

    <main class="cp-content tight">

      <div class="cp-kpi-grid cp-kpi-5">
        <div class="cp-kpi">
          <span class="lbl"><?php echo text('Total ' . $lab['cohortLabel']); ?></span>
          <span class="val green"><?php echo text(number_format($cohortCount)); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo text('Mean ' . $lab['label']); ?></span>
          <span class="val <?php echo ($meanLatest !== null && $meanLatest < $lab['targetMax']) ? 'green' : 'orange'; ?>">
            <?php echo text($formatVal($meanLatest)); ?>
          </span>
        </div>
        <div class="cp-kpi">
          <span class="lbl">&lt;<?php echo text(number_format($lab['targetMax'], $lab['decimals'])); ?> (target)</span>
          <span class="val green"><?php echo text($formatPct($atGoalPct)); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl">&ge;<?php echo text(number_format($lab['atRiskMin'], $lab['decimals'])); ?> (at risk)</span>
          <span class="val red"><?php echo text($formatPct($atRiskPct)); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Trend vs Q4 2025'); ?></span>
          <span class="val <?php echo attr($trendTone); ?>">
            <?php echo text($formatTrendKpi($trendPct, $trendDir)); ?>
          </span>
        </div>
      </div>

      <section class="cp-dist-card">
        <h3><?php echo text($lab['distTitle']); ?> · <?php echo xlt('latest per patient'); ?></h3>
        <div class="desc"><?php echo text(sprintf('%s patients with a result · each bar = %% of patients in bin', number_format($nWithResult))); ?></div>
        <?php if ($nWithResult === 0): ?>
          <div class="cp-empty"><?php echo xlt('No results in this lab cohort yet.'); ?></div>
        <?php else: ?>
          <div class="cp-dist-chart">
            <?php
              $maxPct = 0.0;
              foreach ($bins as $b) { if ($b['pct'] > $maxPct) { $maxPct = $b['pct']; } }
              $n = max(1, count($bins));
              $targetIdx = (int)$lab['targetIdx'];
              // Position % of that bar's center across the chart.
              $targetLeftPct = (($targetIdx + 0.5) / $n) * 100;
            ?>
            <div class="cp-dist-target" style="left: <?php echo (float)$targetLeftPct; ?>%;"><?php echo text($lab['targetLabel']); ?></div>
            <?php foreach ($bins as $b):
              $h = max(4, ($b['pct'] / max($maxPct, 1.0)) * 180);
            ?>
              <div class="cp-dist-bar" title="<?php echo attr($b['label'] . ' — ' . $b['count'] . ' pts (' . number_format($b['pct'], 1) . '%)'); ?>">
                <?php if ($b['pct'] >= 1.0): ?>
                  <span class="pct"><?php echo text(number_format($b['pct'], 0) . '%'); ?></span>
                <?php endif; ?>
                <span class="col <?php echo attr($b['tone']); ?>" style="height: <?php echo (int)$h; ?>px;"></span>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="cp-dist-xaxis">
            <?php foreach ($bins as $b): ?>
              <span><?php echo text($b['label']); ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <div class="cp-bottom-grid">
        <section class="cp-prov-card">
          <div class="head"><?php echo text(strtoupper($lab['label']) . ' BY PROVIDER'); ?></div>
          <div class="sub">
            <?php
            // Bar shows mean value vs target. Width = mean / scaleMax * 100.
            // For HbA1c we scale on 0–11 (the full chart range).
            $scaleMax = max(
                (float)$lab['targetMax'] * 1.5,
                (float)($lab['bins'][count($lab['bins']) - 1]['lo'] ?? $lab['targetMax'] * 1.5)
            );
            ?>
            <?php echo text('Avg ' . $lab['label'] . ' vs target ' . number_format($lab['targetMax'], $lab['decimals']) . $lab['units']); ?>
          </div>
          <div class="cp-prov-list">
            <?php if (!$providers): ?>
              <div class="cp-empty"><?php echo xlt('No provider data yet.'); ?></div>
            <?php endif; ?>
            <?php foreach ($providers as $p):
              $denom = max(1, $p['denom']);
              $avg = 0.0;
              // Recompute avg per provider from byProvider (need to map name->uid).
              // Easier: stash sumVal/denom on $p when building.
            ?>
              <?php
                // Look up the avg by traversing $byProvider (small list).
                $avgVal = null;
                foreach ($byProvider as $uid => $stats) {
                    $nm = cp_format_provider_name($userMap[$uid] ?? null);
                    if ($nm === $p['name']) {
                        $avgVal = $stats['denom'] > 0 ? $stats['sumVal'] / $stats['denom'] : null;
                        break;
                    }
                }
                $barPct = ($avgVal !== null && $scaleMax > 0)
                    ? max(2, min(100, ($avgVal / $scaleMax) * 100))
                    : $p['pct'];
                $tgtLeft = $scaleMax > 0 ? ($lab['targetMax'] / $scaleMax) * 100 : 0;
              ?>
              <div class="cp-prov-row">
                <span class="nm"><?php echo text($p['name']); ?></span>
                <span class="pct"><?php echo text($p['pct'] . '%'); ?></span>
                <span class="ratio">
                  <?php echo text(number_format($p['num']) . ' / ' . number_format($p['denom']) . ' pts at goal'); ?>
                  <?php if ($avgVal !== null): ?>
                    · <?php echo text('avg ' . number_format($avgVal, $lab['decimals']) . $lab['units']); ?>
                  <?php endif; ?>
                </span>
                <span class="bar">
                  <span style="width: <?php echo (float)$barPct; ?>%;"></span>
                  <?php if ($tgtLeft > 0): ?>
                    <i class="tgt" style="left: <?php echo (float)$tgtLeft; ?>%;" title="<?php echo attr('target ' . number_format($lab['targetMax'], $lab['decimals']) . $lab['units']); ?>"></i>
                  <?php endif; ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="cp-out-card">
          <div class="head"><?php echo text($lab['outlierHead']); ?></div>
          <div class="sub">
            <?php echo text(number_format(count($outliers)) . ' patients · contact for follow-up'); ?>
          </div>
          <div class="cp-out-list">
            <?php if (!$outliers): ?>
              <div class="cp-empty"><?php echo xlt('No outliers — every patient is below the at-risk threshold.'); ?></div>
            <?php endif; ?>
            <?php foreach ($outliers as $o):
              $valLabel = number_format($o['value'], $lab['decimals']) . ($lab['units'] === '%' ? '%' : (' ' . $lab['units']));
              $lastLabel = $o['last_date']
                  ? ('Last ' . $lab['label'] . ' ' . date('m/d', (int)strtotime($o['last_date'])))
                  : '';
              $openHref = '/interface/patient_file/summary/copilot_dashboard.php?pid=' . (int)$o['pid'];
            ?>
              <div class="cp-out-row">
                <span class="dot <?php echo attr($o['avatar']); ?>"></span>
                <span class="nm"><?php echo text($o['name']); ?></span>
                <span class="mrn"><?php echo text($o['mrn']); ?></span>
                <span class="val">
                  <?php echo text($valLabel); ?>
                  <?php if ($o['trend'] !== ''): ?> <span class="tr"><?php echo text($o['trend']); ?></span><?php endif; ?>
                </span>
                <span class="prov"><?php echo text($o['provider']); ?></span>
                <span class="last"><?php echo text($lastLabel); ?></span>
                <a href="<?php echo attr($openHref); ?>" class="open"><?php echo xlt('Open'); ?> &rarr;</a>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

    </main>
  </div>
</div>

</body>
</html>
