<?php

/**
 * Patient Report — Figma "Screen 14 — Report".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/report/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * #maimain iframe / patient-file frame.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * Live patient-report payload (demographics, insurance, allergies, active
 * problems, current medications, recent visits, immunizations) is queried
 * server-side from patient_data / lists / prescriptions / form_encounter /
 * immunizations and JSON-encoded onto data-report. Mirrors the same set of
 * tables OpenEMR's existing patient_report.php walks. If pid is empty/0
 * (no patient context) we ship empty arrays so the page renders cleanly
 * instead of crashing.
 *
 * The original static-HTML mock is preserved at copilot_report.php.bak
 * so a side-by-side screenshot diff remains possible.
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
$entry        = $manifest['src/pages/report/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live patient-report payload.
//
// Mirrors the SQL OpenEMR's existing patient_report.php (and the AgentForge
// peer pages copilot_dashboard.php.bak / copilot_history.php.bak) used to
// drive the same tables. The React component renders the typed payload
// client-side; missing scalars are emitted as '' / 0 and the UI falls back
// to em-dashes / "No record" copy.
// ---------------------------------------------------------------------------

$pid = (int)$patientId;

/**
 * Format a YYYY-MM-DD or ISO datetime string as MM/DD/YYYY (US locale to
 * match the Figma mock). Returns '' when the input is empty / placeholder.
 */
$fmtDate = static function (string $raw): string {
    if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return '';
    }
    return date('m/d/Y', $ts);
};

// Patient block (demographics + insurance + primary provider).
$patient = [
    'fname'         => '',
    'lname'         => '',
    'name'          => '',
    'sex'           => '',
    'age'           => null,
    'dob'           => '',
    'mrn'           => '',
    'memberSince'   => '',
    'insurance'     => '',
    'insGroup'      => '',
    'insMember'     => '',
    'providerName'  => '',
];

if ($pid > 0) {
    $row = sqlQuery(
        "SELECT pd.pid, pd.pubpid, pd.fname, pd.lname, pd.DOB, pd.sex,
                pd.regdate, pd.providerID,
                u.username AS prov_username,
                u.fname    AS prov_fname,
                u.lname    AS prov_lname,
                u.title    AS prov_title
         FROM patient_data pd
         LEFT JOIN users u ON u.id = pd.providerID
         WHERE pd.pid = ?",
        [$pid]
    );
    if (is_array($row)) {
        $fn   = (string)($row['fname'] ?? '');
        $ln   = (string)($row['lname'] ?? '');
        $dob  = (string)($row['DOB'] ?? '');
        $reg  = (string)($row['regdate'] ?? '');
        $age  = null;
        if ($dob !== '' && $dob !== '0000-00-00') {
            try {
                $age = (int)(new DateTime($dob))->diff(new DateTime('now'))->y;
            } catch (Throwable $_) {
                $age = null;
            }
        }
        $sexLetter = strtoupper(substr((string)($row['sex'] ?? ''), 0, 1));
        $memberSince = ($reg !== '' && !str_starts_with($reg, '0000')) ? substr($reg, 0, 4) : '';

        $patient['fname']        = $fn;
        $patient['lname']        = $ln;
        $patient['name']         = trim($fn . ' ' . $ln);
        $patient['sex']          = $sexLetter !== '' ? $sexLetter : '';
        $patient['age']          = $age;
        $patient['dob']          = $fmtDate($dob);
        $patient['mrn']          = '#' . str_pad((string)($row['pubpid'] ?? $row['pid'] ?? ''), 6, '0', STR_PAD_LEFT);
        $patient['memberSince']  = $memberSince;
        $patient['providerName'] = cp_format_provider_name([
            'username' => (string)($row['prov_username'] ?? ''),
            'fname'    => (string)($row['prov_fname'] ?? ''),
            'lname'    => (string)($row['prov_lname'] ?? ''),
            'title'    => (string)($row['prov_title'] ?? ''),
        ]);
    }

    $insRow = sqlQuery(
        "SELECT plan_name, group_number, policy_number
         FROM insurance_data
         WHERE pid = ? AND type = 'primary'
         ORDER BY date DESC LIMIT 1",
        [$pid]
    );
    if (is_array($insRow)) {
        $patient['insurance'] = (string)($insRow['plan_name'] ?? '');
        $patient['insGroup']  = (string)($insRow['group_number'] ?? '');
        $patient['insMember'] = (string)($insRow['policy_number'] ?? '');
    }
}

// Allergies — type='allergy', activity=1 (open-ended).
$allergies = [];
if ($pid > 0) {
    $rows = sqlStatement(
        "SELECT title, severity_al, reaction, comments, date, modifydate
         FROM lists
         WHERE pid = ? AND type = 'allergy'
           AND (activity = 1 OR activity IS NULL)
           AND COALESCE(enddate, '0000-00-00') = '0000-00-00'
         ORDER BY date ASC",
        [$pid]
    );
    while ($r = sqlFetchArray($rows)) {
        $reviewed = (string)($r['modifydate'] ?? $r['date'] ?? '');
        $allergies[] = [
            'name'     => (string)($r['title'] ?? ''),
            'severity' => (string)($r['severity_al'] ?? ''),
            'reaction' => (string)($r['reaction'] ?? ''),
            'comments' => (string)($r['comments'] ?? ''),
            'reviewed' => $fmtDate($reviewed),
        ];
    }
}

// Active problems — type='medical_problem'.
$problems = [];
if ($pid > 0) {
    $rows = sqlStatement(
        "SELECT title, diagnosis, date
         FROM lists
         WHERE pid = ? AND type = 'medical_problem'
           AND (activity = 1 OR activity IS NULL)
           AND COALESCE(enddate, '0000-00-00') = '0000-00-00'
         ORDER BY date ASC",
        [$pid]
    );
    while ($r = sqlFetchArray($rows)) {
        $diagnosis = (string)($r['diagnosis'] ?? '');
        // diagnosis column often stores e.g. "ICD10:E11.9" — pull the
        // bare code for the chip.
        $icd = '';
        if ($diagnosis !== '') {
            $parts = explode(':', $diagnosis, 2);
            $icd = trim($parts[1] ?? $parts[0]);
        }
        $year = '';
        $rawDate = (string)($r['date'] ?? '');
        if ($rawDate !== '' && !str_starts_with($rawDate, '0000')) {
            $year = substr($rawDate, 0, 4);
        }
        $problems[] = [
            'icd'  => $icd,
            'name' => (string)($r['title'] ?? ''),
            'meta' => ($year !== '' ? 'Onset ' . $year . ' • ' : '') . 'Active',
        ];
    }
}

// Current medications — active prescriptions, dedup on drug+dosage.
$meds = [];
if ($pid > 0) {
    $rows = sqlStatement(
        "SELECT drug,
                MAX(dosage)         AS dosage,
                MAX(size)           AS size_,
                MAX(unit)           AS unit_,
                MAX(`interval`)     AS freq_interval,
                MAX(date_added)     AS last_added
         FROM prescriptions
         WHERE patient_id = ? AND active = 1
         GROUP BY drug
         ORDER BY last_added DESC
         LIMIT 12",
        [$pid]
    );
    while ($r = sqlFetchArray($rows)) {
        $size = trim((string)($r['size_'] ?? ''));
        $unit = trim((string)($r['unit_'] ?? ''));
        $dose = trim($size . ($unit !== '' ? ' ' . $unit : ''));
        if ($dose === '') {
            $dose = trim((string)($r['dosage'] ?? ''));
        }
        $freq = trim((string)($r['freq_interval'] ?? ''));
        if ($freq === '') {
            $freq = trim((string)($r['dosage'] ?? ''));
        }
        $refill = $fmtDate((string)($r['last_added'] ?? ''));
        $meds[] = [
            'name'   => (string)($r['drug'] ?? ''),
            'dose'   => $dose,
            'freq'   => $freq,
            'refill' => $refill !== '' ? 'Refilled ' . $refill : '',
        ];
    }
}

// Recent visits — last 6 encounters.
$visits = [];
if ($pid > 0) {
    $rows = sqlStatement(
        "SELECT fe.date, fe.reason, fe.last_level_closed,
                u.username, u.fname, u.lname, u.title
         FROM form_encounter fe
         LEFT JOIN users u ON u.id = fe.provider_id
         WHERE fe.pid = ?
         ORDER BY fe.date DESC
         LIMIT 6",
        [$pid]
    );
    while ($r = sqlFetchArray($rows)) {
        $reason = trim((string)($r['reason'] ?? ''));
        if ($reason === '') {
            $reason = 'Office visit';
        }
        $visits[] = [
            'date'     => $fmtDate((string)($r['date'] ?? '')),
            'reason'   => $reason,
            'provider' => cp_format_provider_name([
                'username' => (string)($r['username'] ?? ''),
                'fname'    => (string)($r['fname'] ?? ''),
                'lname'    => (string)($r['lname'] ?? ''),
                'title'    => (string)($r['title'] ?? ''),
            ]),
            'closed'   => (int)($r['last_level_closed'] ?? 0) > 0,
        ];
    }
}

// Immunizations — most recent doses, name + administration date.
$immunizations = [];
if ($pid > 0) {
    $rows = sqlStatement(
        "SELECT immunization_name, cvx_code, administered_date
         FROM immunizations
         WHERE patient_id = ?
           AND COALESCE(added_erroneously, 0) = 0
         ORDER BY administered_date DESC
         LIMIT 12",
        [$pid]
    );
    while ($r = sqlFetchArray($rows)) {
        $immunizations[] = [
            'name'    => (string)($r['immunization_name'] ?? ''),
            'cvx'     => (string)($r['cvx_code'] ?? ''),
            'admined' => $fmtDate((string)($r['administered_date'] ?? '')),
        ];
    }
}

$reportData = [
    'generatedOn'   => date('m/d/Y'),
    'patient'       => $patient,
    'allergies'     => $allergies,
    'problems'      => $problems,
    'medications'   => $meds,
    'visits'        => $visits,
    'immunizations' => $immunizations,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Report'); ?></title>
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
     data-page="report"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-report="<?php echo attr((string)json_encode($reportData)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Report UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
