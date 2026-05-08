<?php

/**
 * Patient Dashboard landing page — Figma "Screen 11 — Dashboard".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/dashboard/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav and the patient
 * demographics banner (header2); this file only renders inside the patient
 * chart iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * Live patient vitals + lists + prescriptions + recent labs + recent visits
 * are queried server-side and JSON-encoded onto data-dashboard, mirroring the
 * pattern used by the Finder and Issues pages. The original static-HTML mock
 * is preserved at copilot_dashboard.php.bak so a side-by-side screenshot diff
 * remains possible.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../../main/copilot_helpers.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

// ---------------------------------------------------------------------------
// Resolve built React assets via the Vite manifest. See copilot_calendar.php
// for the full rationale; this is the same pattern.
// ---------------------------------------------------------------------------

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/dashboard/index.tsx'] ?? null;
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live patient dashboard payload — preserves the SQL the pre-React PHP page
// (copilot_dashboard.php.bak) ran across form_vitals, form_observation, lists,
// prescriptions, and form_encounter. We pull everything for the current pid
// and let React render it client-side. If pid is empty/0 (no patient context)
// we render an empty payload so the page renders cleanly instead of crashing.
// ---------------------------------------------------------------------------

$pid = (int)$patientId;

$vitals      = [];
$allergies   = [];
$problems    = [];
$medications = [];
$labs        = [];
$visits      = [];

if ($pid > 0) {
    // ── Vitals from form_vitals (most recent + previous for trend) ─────────
    $vRecent = sqlQuery(
        "SELECT bps, bpd, BMI, weight FROM form_vitals "
        . "WHERE pid = ? ORDER BY date DESC LIMIT 1",
        [$pid],
    );
    $vPrev = sqlQuery(
        "SELECT bps, bpd, BMI, weight FROM form_vitals "
        . "WHERE pid = ? ORDER BY date DESC LIMIT 1 OFFSET 1",
        [$pid],
    );

    // Latest + previous A1C / LDL from form_observation (seed uses this table
    // for LOINC-coded labs). Codes: HbA1c = 4548-4 / 17856-6, LDL = 13457-7 /
    // 2089-1 / 18262-6.
    $labLookup = static function (int $pid, array $codes): array {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $rows = sqlStatement(
            "SELECT ob_value, date FROM form_observation "
            . "WHERE pid = ? AND ob_code IN ($placeholders) "
            . "ORDER BY date DESC LIMIT 2",
            array_merge([$pid], $codes),
        );
        $out = [];
        while ($r = sqlFetchArray($rows)) {
            $out[] = $r;
        }
        return $out;
    };
    $a1cRows = $labLookup($pid, ['4548-4', '17856-6']);
    $ldlRows = $labLookup($pid, ['13457-7', '2089-1', '18262-6']);

    // Build the 4-tile KPI strip — BP / A1C / LDL / BMI per the Figma mock.
    // Tone: 'down' when value moved toward target (lower is better for all
    // four metrics here), 'up' otherwise, 'flat' for missing/no-prev.
    if ($vRecent && $vRecent['bps']) {
        $bp = (int)$vRecent['bps'] . '/' . (int)$vRecent['bpd'];
        if ($vPrev && $vPrev['bps']) {
            $prevBp = (int)$vPrev['bps'] . '/' . (int)$vPrev['bpd'];
            $down   = ((int)$vRecent['bps'] < (int)$vPrev['bps']);
            $vitals[] = [
                'label'      => 'BP',
                'value'      => $bp,
                'unit'       => 'mmHg',
                'trend'      => "from $prevBp",
                'trendArrow' => $down ? '↓' : '↑',
                'trendDir'   => $down ? 'down' : 'up',
            ];
        } else {
            $vitals[] = [
                'label'      => 'BP',
                'value'      => $bp,
                'unit'       => 'mmHg',
                'trend'      => 'No prior reading',
                'trendArrow' => '↓',
                'trendDir'   => 'flat',
            ];
        }
    } else {
        $vitals[] = [
            'label'      => 'BP',
            'value'      => '—',
            'unit'       => 'mmHg',
            'trend'      => 'No reading',
            'trendArrow' => '↓',
            'trendDir'   => 'flat',
        ];
    }

    if ($a1cRows) {
        $a1c = number_format((float)$a1cRows[0]['ob_value'], 1) . '%';
        if (isset($a1cRows[1])) {
            $prev = number_format((float)$a1cRows[1]['ob_value'], 1) . '%';
            $down = ((float)$a1cRows[0]['ob_value'] < (float)$a1cRows[1]['ob_value']);
            $vitals[] = [
                'label'      => 'A1C',
                'value'      => $a1c,
                'unit'       => 'current',
                'trend'      => "from $prev",
                'trendArrow' => $down ? '↓' : '↑',
                'trendDir'   => $down ? 'down' : 'up',
            ];
        } else {
            $vitals[] = [
                'label'      => 'A1C',
                'value'      => $a1c,
                'unit'       => 'current',
                'trend'      => 'No prior labs',
                'trendArrow' => '↑',
                'trendDir'   => 'flat',
            ];
        }
    } else {
        $vitals[] = [
            'label'      => 'A1C',
            'value'      => '—',
            'unit'       => 'current',
            'trend'      => 'No labs',
            'trendArrow' => '↓',
            'trendDir'   => 'flat',
        ];
    }

    if ($ldlRows) {
        $ldl = (int)$ldlRows[0]['ob_value'];
        if (isset($ldlRows[1])) {
            $prev = (int)$ldlRows[1]['ob_value'];
            $down = ($ldl < $prev);
            $vitals[] = [
                'label'      => 'LDL',
                'value'      => (string)$ldl,
                'unit'       => 'mg/dL',
                'trend'      => "from $prev",
                'trendArrow' => $down ? '↓' : '↑',
                'trendDir'   => $down ? 'down' : 'up',
            ];
        } else {
            $vitals[] = [
                'label'      => 'LDL',
                'value'      => (string)$ldl,
                'unit'       => 'mg/dL',
                'trend'      => 'No prior labs',
                'trendArrow' => '↓',
                'trendDir'   => 'flat',
            ];
        }
    } else {
        $vitals[] = [
            'label'      => 'LDL',
            'value'      => '—',
            'unit'       => 'mg/dL',
            'trend'      => 'No labs',
            'trendArrow' => '↓',
            'trendDir'   => 'flat',
        ];
    }

    if ($vRecent && $vRecent['BMI']) {
        $bmi = number_format((float)$vRecent['BMI'], 1);
        if ($vPrev && $vPrev['BMI']) {
            $prevBmi = number_format((float)$vPrev['BMI'], 1);
            $down    = ((float)$vRecent['BMI'] < (float)$vPrev['BMI']);
            $vitals[] = [
                'label'      => 'BMI',
                'value'      => $bmi,
                'unit'       => 'kg/m²',
                'trend'      => "from $prevBmi",
                'trendArrow' => $down ? '↓' : '↑',
                'trendDir'   => $down ? 'down' : 'up',
            ];
        } else {
            $vitals[] = [
                'label'      => 'BMI',
                'value'      => $bmi,
                'unit'       => 'kg/m²',
                'trend'      => 'No prior reading',
                'trendArrow' => '↑',
                'trendDir'   => 'flat',
            ];
        }
    } else {
        $vitals[] = [
            'label'      => 'BMI',
            'value'      => '—',
            'unit'       => 'kg/m²',
            'trend'      => 'No reading',
            'trendArrow' => '↓',
            'trendDir'   => 'flat',
        ];
    }

    // ── Allergies ──────────────────────────────────────────────────────────
    $rows = sqlStatement(
        "SELECT DISTINCT title, severity_al, comments FROM lists "
        . "WHERE pid = ? AND type = 'allergy' "
        . "AND COALESCE(enddate, '0000-00-00') = '0000-00-00' "
        . "ORDER BY date ASC",
        [$pid],
    );
    while ($r = sqlFetchArray($rows)) {
        $sev      = trim((string)($r['severity_al'] ?? ''));
        $comments = trim((string)($r['comments'] ?? ''));
        $sub      = $sev !== ''
            ? ucfirst(strtolower($sev)) . ($comments !== '' ? ' — ' . $comments : '')
            : ($comments !== '' ? $comments : 'Active allergy');
        $allergies[] = [
            'icon' => '⚠',
            'tone' => 'alert',
            'name' => (string)($r['title'] ?? ''),
            'sub'  => $sub,
        ];
    }
    if (!$allergies) {
        $allergies[] = [
            'icon' => '+',
            'tone' => 'note',
            'name' => 'No allergies recorded',
            'sub'  => 'Reviewed today',
        ];
    }

    // ── Problems ───────────────────────────────────────────────────────────
    $rows = sqlStatement(
        "SELECT DISTINCT title, diagnosis, date FROM lists "
        . "WHERE pid = ? AND type = 'medical_problem' "
        . "AND COALESCE(enddate, '0000-00-00') = '0000-00-00' "
        . "ORDER BY date ASC",
        [$pid],
    );
    while ($r = sqlFetchArray($rows)) {
        $dateStr = (string)($r['date'] ?? '');
        $year    = $dateStr !== '' ? substr($dateStr, 0, 4) : '';
        $sub     = ($year !== '' ? 'Since ' . $year . ' • ' : '') . 'Active';
        $problems[] = [
            'icon' => "\u{1FA7A}",
            'tone' => 'note',
            'name' => (string)($r['title'] ?? ''),
            'sub'  => $sub,
        ];
    }
    if (!$problems) {
        $problems[] = [
            'icon' => "\u{1FA7A}",
            'tone' => 'note',
            'name' => 'No active problems',
            'sub'  => 'Reviewed today',
        ];
    }

    // ── Medications (active prescriptions) ─────────────────────────────────
    $rows = sqlStatement(
        "SELECT drug, dosage, MAX(date_added) AS dt "
        . "FROM prescriptions WHERE patient_id = ? AND active = 1 "
        . "GROUP BY drug, dosage ORDER BY dt DESC LIMIT 6",
        [$pid],
    );
    while ($r = sqlFetchArray($rows)) {
        $name = trim((string)($r['drug'] ?? '') . ' ' . (string)($r['dosage'] ?? ''));
        $medications[] = [
            'icon' => "\u{1F48A}",
            'tone' => 'med',
            'name' => $name,
            'sub'  => 'Active',
        ];
    }
    if (!$medications) {
        $medications[] = [
            'icon' => "\u{1F48A}",
            'tone' => 'med',
            'name' => 'No active medications',
            'sub'  => 'Reviewed today',
        ];
    }

    // ── Labs (placeholder — no procedure_result data in demo) ──────────────
    // Show vitals trends as faux labs so this card isn't empty. Mirrors .bak.
    if ($vRecent) {
        if ($vRecent['bps']) {
            $bps    = (int)$vRecent['bps'];
            $status = ($bps >= 140 || $bps < 90) ? 'high' : 'normal';
            $labs[] = [
                'test'   => 'BP (sys)',
                'value'  => $bps . ' mmHg',
                'status' => $status,
                'range'  => '<140',
                'date'   => 'recent',
            ];
        }
        if ($vRecent['BMI']) {
            $bm     = (float)$vRecent['BMI'];
            $status = $bm >= 30 ? 'high' : 'normal';
            $labs[] = [
                'test'   => 'BMI',
                'value'  => number_format($bm, 1),
                'status' => $status,
                'range'  => '18.5–25',
                'date'   => 'recent',
            ];
        }
    }
    if ($a1cRows) {
        $a1cVal = (float)$a1cRows[0]['ob_value'];
        $status = $a1cVal >= 7.0 ? 'high' : 'normal';
        $a1cDate = (string)($a1cRows[0]['date'] ?? '');
        $labs[] = [
            'test'   => 'HbA1c',
            'value'  => number_format($a1cVal, 1) . ' %',
            'status' => $status,
            'range'  => '<7.0',
            'date'   => $a1cDate !== '' ? date('m/d/Y', strtotime($a1cDate)) : 'recent',
        ];
    }
    if ($ldlRows) {
        $ldlVal = (int)$ldlRows[0]['ob_value'];
        $status = $ldlVal >= 100 ? 'high' : 'normal';
        $ldlDate = (string)($ldlRows[0]['date'] ?? '');
        $labs[] = [
            'test'   => 'LDL',
            'value'  => $ldlVal . ' mg/dL',
            'status' => $status,
            'range'  => '<100',
            'date'   => $ldlDate !== '' ? date('m/d/Y', strtotime($ldlDate)) : 'recent',
        ];
    }
    if (!$labs) {
        $labs[] = [
            'test'   => '(no labs on file)',
            'value'  => '—',
            'status' => 'normal',
            'range'  => '—',
            'date'   => '—',
        ];
    }

    // ── Recent visits ──────────────────────────────────────────────────────
    $rows = sqlStatement(
        "SELECT fe.date, fe.reason, u.username, u.fname, u.lname, u.title "
        . "FROM form_encounter fe LEFT JOIN users u ON fe.provider_id = u.id "
        . "WHERE fe.pid = ? ORDER BY fe.date DESC LIMIT 5",
        [$pid],
    );
    while ($r = sqlFetchArray($rows)) {
        $prov = cp_format_provider_name([
            'username' => (string)($r['username'] ?? ''),
            'fname'    => (string)($r['fname'] ?? ''),
            'lname'    => (string)($r['lname'] ?? ''),
            'title'    => (string)($r['title'] ?? ''),
        ]);
        $reason  = (string)($r['reason'] ?? '');
        $title   = ($reason !== '' ? $reason : 'Office visit') . ' — ' . $prov;
        $dateStr = (string)($r['date'] ?? '');
        $sub     = ($dateStr !== '' ? date('m/d/Y', strtotime($dateStr)) : '—') . ' • Signed';
        $visits[] = [
            'title' => $title,
            'sub'   => $sub,
        ];
    }
    if (!$visits) {
        $visits[] = [
            'title' => '(no encounters on file)',
            'sub'   => '—',
        ];
    }
}

$dashboard = [
    'vitals'      => $vitals,
    'allergies'   => $allergies,
    'problems'    => $problems,
    'medications' => $medications,
    'labs'        => $labs,
    'visits'      => $visits,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Dashboard'); ?></title>
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
  #cp-root { min-height: 100%; }
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
     data-page="dashboard"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-dashboard="<?php echo attr((string)json_encode($dashboard)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Patient Dashboard UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
