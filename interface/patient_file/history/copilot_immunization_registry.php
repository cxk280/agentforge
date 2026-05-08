<?php

/**
 * Immunization Registry landing page — Figma "Screen 47 — Immunization Registry".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/immunization_registry/. The PHP outer
 * shell at /interface/main/tabs/main.php still owns the navy top nav, left
 * sidebar, and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * Page is NOT patient-scoped — it's a practice-wide registry/coverage view.
 * Coverage tiles, due-list, monthly chart, and sync card are computed in
 * PHP (lifted from copilot_immunization_registry.php.bak) and JSON-encoded
 * onto data-imm so the React island has live numbers without an extra
 * round-trip. The Tx-DSHS POST handler from the .bak is intentionally
 * omitted: the current React mock keeps "Force re-sync" as a static button
 * matching the rest of the migrated Reports pages. Re-add when the React
 * tree grows a real submit handler.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

// ---------------------------------------------------------------------------
// Resolve built React assets via the Vite manifest.
//
// Vite emits hashed filenames + a manifest.json mapping logical entry paths
// (relative to /frontend) to the built file + its imported CSS. We read it
// at request time so a fresh build is picked up without restarting Apache.
//
// If the manifest is missing (e.g. /frontend has not been built yet), fall
// through to a clear in-page error rather than silently rendering nothing.
// ---------------------------------------------------------------------------

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/immunization_registry/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Vaccine catalog. Each entry:
//   - label: how the vaccine is shown in the "vaccines due" column
//   - tile: [name, sub, goalLabel, goalPct]  for the KPI tile
//   - note_like: array of LIKE patterns matched against immunizations.note
//                (case-insensitive). Any match counts as a dose of this vaccine.
//   - recency_months: a dose newer than this counts as "vaccinated"
//   - min_age / max_age: eligibility window (age in years; null = open-ended)
//   - sex: 'M' | 'F' | null
// ---------------------------------------------------------------------------
$vaccines = [
    'flu' => [
        'label'          => 'Influenza',
        'tile'           => ['Influenza',        '2025-26',          'Goal ≥65%', 65],
        'note_like'      => ['%influenza%', '%flu %', '%flu,%', 'flu', '%flu shot%'],
        'recency_months' => 12,
        'min_age'        => 0.5,
        'max_age'        => null,
        'sex'            => null,
        'suffix'         => 'Season ends 04/30',
    ],
    'covid' => [
        'label'          => 'COVID booster',
        'tile'           => ['COVID-19 booster', '2025-26',          'Tracked',   50],
        'note_like'      => ['%covid%', '%sars%'],
        'recency_months' => 12,
        'min_age'        => 0.5,
        'max_age'        => null,
        'sex'            => null,
        'suffix'         => 'Updated formula',
    ],
    'pneumo' => [
        'label'          => 'PPSV23 booster',
        'tile'           => ['Pneumococcal',     'PPSV23 + PCV',     'Goal ≥75%', 75],
        'note_like'      => ['%pneumococcal%', '%ppsv%', '%pcv%', '%prevnar%', '%pneumovax%'],
        'recency_months' => 60,
        'min_age'        => 65,
        'max_age'        => null,
        'sex'            => null,
        'suffix'         => 'Adults 65+',
    ],
    'tdap' => [
        'label'          => 'Tdap (10-yr)',
        'tile'           => ['Tdap',             '10-yr booster',    'Goal ≥80%', 80],
        'note_like'      => ['%tdap%', '%boostrix%', '%adacel%', '%td booster%'],
        'recency_months' => 120,
        'min_age'        => 18,
        'max_age'        => null,
        'sex'            => null,
        'suffix'         => 'All adults',
    ],
    'shingrix' => [
        'label'          => 'Shingrix',
        'tile'           => ['Shingrix',         'Adults 50+',       'Goal ≥60%', 60],
        'note_like'      => ['%shingrix%', '%zoster%'],
        'recency_months' => 60,
        'min_age'        => 50,
        'max_age'        => null,
        'sex'            => null,
        'suffix'         => '2-dose series',
    ],
    'hpv' => [
        'label'          => 'HPV',
        'tile'           => ['HPV',              'Series complete',  'Goal ≥70%', 70],
        'note_like'      => ['%hpv%', '%gardasil%'],
        'recency_months' => 120,
        'min_age'        => 9,
        'max_age'        => 26,
        'sex'            => null,
        'suffix'         => 'Adolescents',
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
        if ($doseTs !== null && ($now - $doseTs) <= $cutoffSeconds((int)$vdef['recency_months'])) {
            $vaccinated++;
        }
    }
    $pct = $eligible > 0 ? (int)round(($vaccinated / $eligible) * 100) : 0;
    if ($eligible === 0) {
        $tone = 'info';
    } elseif ($vkey === 'covid') {
        $tone = 'info';
    } elseif ($pct >= (int)$goalPct) {
        $tone = 'good';
    } else {
        $tone = 'warn';
    }
    $suffix = (string)($vdef['suffix'] ?? '');
    $denom = (string)$vaccinated . ' of ' . (string)$eligible . ($suffix !== '' ? ' · ' . $suffix : '');
    $coverage[] = [
        'name'      => (string)$nm,
        'sub'       => (string)$sub,
        'goalLabel' => (string)$goalLabel,
        'pct'       => $pct,
        'tone'      => $tone,
        'denom'     => $denom,
    ];
}

// ---------------------------------------------------------------------------
// Patients-due queue. A patient is "due" for a vaccine if eligible AND no
// dose-within-recency. Status:
//   - OVERDUE if last dose exists but past recency_months (or never received)
//   - "Due …" otherwise
// ---------------------------------------------------------------------------
$dueRows = [];
$tonePalette = ['orange', 'blue', 'purple', 'teal', 'pink', 'green', 'mint', 'violet'];

$tabVaccines = [
    'all'      => array_keys($vaccines),
    'flu'      => ['flu'],
    'covid'    => ['covid'],
    'tdap'     => ['tdap'],
    'shingrix' => ['shingrix'],
];

$tabCounts = [];
foreach ($tabVaccines as $tk => $vKeys) {
    $tabCounts[$tk] = 0;
}

$avIdx = 0;
foreach ($patients as $p) {
    if ($p['age'] === null) {
        continue;
    }
    $missing = [];
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
        $window = $cutoffSeconds((int)$vdef['recency_months']);
        if ($doseTs === null) {
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

    foreach ($tabVaccines as $tk => $vKeys) {
        if (count(array_intersect($missing, $vKeys)) > 0) {
            $tabCounts[$tk]++;
        }
    }

    $vacText = implode(', ', array_map(static fn (string $k) => (string)$vaccines[$k]['label'], $missing));
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
    $mrn = $p['pubpid'] !== ''
        ? '#' . str_pad($p['pubpid'], 6, '0', STR_PAD_LEFT)
        : '#' . str_pad((string)$p['pid'], 6, '0', STR_PAD_LEFT);

    $initials = strtoupper(substr($p['fname'], 0, 1) . substr($p['lname'], 0, 1));
    if ($initials === '') {
        $initials = '?';
    }

    $dueRows[] = [
        'av'       => $tonePalette[$avIdx++ % count($tonePalette)],
        'initials' => $initials,
        'name'     => $name,
        'mrn'      => $mrn,
        'dem'      => $dem,
        'vac'      => $vacText !== '' ? $vacText : '—',
        'pill'     => $statusLabel,
        'pillTone' => $statusTone,
        'pid'      => $p['pid'],
        'sortKey'  => ($worstStatus === 'overdue' ? '0' : '1') . '|' . ($p['dob'] ?: '9999'),
    ];
}

usort($dueRows, static fn (array $a, array $b) => strcmp((string)$a['sortKey'], (string)$b['sortKey']));
// sortKey is a wrapper-side helper only; strip before encoding.
foreach ($dueRows as &$r) {
    unset($r['sortKey']);
}
unset($r);

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
$cursor = strtotime('first day of -11 months') ?: $now;
$peakStart = strtotime('first day of -7 months') ?: $now;
$peakEnd   = strtotime('first day of -3 months') ?: $now;
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
    $showLabel = ($i >= 4 && $i <= 8);
    $months[] = [
        'label'   => substr(date('M', $cursor), 0, 1),
        'count'   => $count,
        'showNum' => $showLabel,
        'tone'    => $isPeak ? 'orange' : 'teal',
    ];
    $next = strtotime('+1 month', $cursor);
    $cursor = $next !== false ? $next : $cursor;
}

// ---------------------------------------------------------------------------
// Sync card spine — last sync from extended_log, pushed-this-quarter from
// immunizations.administered_date >= CURDATE() - 3 mo.
// ---------------------------------------------------------------------------
$lastSyncRow = sqlQuery(
    "SELECT date FROM extended_log WHERE event = ? ORDER BY date DESC LIMIT 1",
    ['registry_sync']
);
$lastSyncTs = is_array($lastSyncRow) && isset($lastSyncRow['date'])
    ? strtotime((string)$lastSyncRow['date'])
    : false;
if ($lastSyncTs !== false) {
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
$pushedQ = is_array($pushedThisQuarterRow) ? (int)($pushedThisQuarterRow['n'] ?? 0) : 0;

// Tab counts shape (preserve key order; React keys by 'all'|'flu'|'covid'|'tdap'|'shingrix').
$tabCountsOut = [
    'all'      => (int)($tabCounts['all'] ?? 0),
    'flu'      => (int)($tabCounts['flu'] ?? 0),
    'covid'    => (int)($tabCounts['covid'] ?? 0),
    'tdap'     => (int)($tabCounts['tdap'] ?? 0),
    'shingrix' => (int)($tabCounts['shingrix'] ?? 0),
];

$payload = [
    'coverage'  => $coverage,
    'dueRows'   => $dueRows,
    'tabCounts' => $tabCountsOut,
    'months'    => $months,
    'sync'      => [
        'lastSyncLabel' => $lastSyncLabel,
        'pushedQuarter' => $pushedQ,
        'pending'       => 0,
        'errors'        => 0,
    ],
    'peakTotal'   => $peakTotal,
    'totalAdmins' => $totalAdmins,
];

$payloadJson = (string)json_encode($payload);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Immunization Registry'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo attr($webroot); ?>/public/copilot-tokens.css">
<?php foreach ($cssHrefs as $h): ?>
<link rel="stylesheet" href="<?php echo attr($webroot); ?>/public/build/<?php echo attr((string)$h); ?>">
<?php endforeach; ?>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: var(--cp-font);
    background: var(--cp-bg);
    color: var(--cp-navy);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
  }
  button { font-family: inherit; }
  #cp-root { height: 100%; }
  /* Visible-by-default error if the React bundle fails to load. Hidden by
   * the React tree on first render. */
  .cp-boot-error {
    display: none;
    padding: 24px;
    color: #4F5763;
    font-size: 13px;
  }
  #cp-root:empty + .cp-boot-error { display: block; }
</style>
</head>
<body>
<div id="cp-root"
     data-page="immunization_registry"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-imm="<?php echo attr($payloadJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Immunization Registry UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
