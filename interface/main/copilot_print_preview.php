<?php

/**
 * Print Preview — Screen 61.
 * Single archetype for Demographics summary / Superbill / Referral letter /
 * Visit summary / Patient list / Care plan / Mailing labels / Encounter form /
 * Walk-out receipt / Recall list / Appointment slip / Patient instructions.
 *
 * Backend wiring:
 *   - $pid sourced from session (dev fallback: 1).
 *   - Demographics summary content driven entirely from real DB rows
 *     (patient_data, facility, insurance_data + insurance_companies, lists,
 *     prescriptions).
 *   - Output type left rail is clickable — switches via ?type=… (12 archetypes).
 *     For non-demographics types we render a "Coming soon" stub and link out to
 *     OpenEMR's existing print routes where appropriate.
 *   - Print settings panel is a real <form> POST — paper_size, orientation,
 *     copies, include[], confidentiality.
 *   - Confidentiality dropdown filters which sections render on demographics.
 *   - Header buttons:
 *       * HP-LJ-Front Desk: hardcoded printer list (no real printer registry
 *         in OpenEMR — see comment near $printers).
 *       * Save PDF: POST action=save_pdf — logs via newEvent() and streams an
 *         HTML download (PDF lib not installed in dev).
 *       * Print 1 page: POST action=print — logs via newEvent() and redirects
 *         with ?msg=printed.
 *       * Help: disabled.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Session\SessionWrapperFactory;
require_once(__DIR__ . "/copilot_helpers.php");

use OpenEMR\Common\Logging\EventAuditLogger;

// CSRF skipped — internal mock page (per brief).

// Patient context.
$pid = (int)(SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 1);

// Output types — 12 archetypes per brief. Each maps to a ?type= slug, an icon,
// a label, and (for non-demographics) an external print route we link to in
// the "Coming soon" stub.
$output_types = [
    ['slug' => 'demographics',  'icon' => '📋', 'label' => 'Demographics summary', 'external' => null],
    ['slug' => 'superbill',     'icon' => '💵', 'label' => 'Superbill',            'external' => '/interface/billing/sl_eob_search.php'],
    ['slug' => 'referral',      'icon' => '📤', 'label' => 'Referral letter',      'external' => '/interface/patient_file/transaction/transactions.php'],
    ['slug' => 'visit',         'icon' => '📃', 'label' => 'Visit summary',        'external' => '/interface/patient_file/encounter/encounter_top.php'],
    ['slug' => 'patient_list',  'icon' => '📒', 'label' => 'Patient list',         'external' => '/interface/main/finder/dynamic_finder.php'],
    ['slug' => 'care_plan',     'icon' => '🩺', 'label' => 'Care plan',            'external' => '/interface/patient_file/summary/demographics.php'],
    ['slug' => 'labels',        'icon' => '🏷', 'label' => 'Mailing labels',       'external' => null],
    ['slug' => 'encounter',     'icon' => '📝', 'label' => 'Encounter form',       'external' => '/interface/patient_file/printed_fee_sheet.php?pid=' . $pid],
    ['slug' => 'walkout',       'icon' => '🧾', 'label' => 'Walk-out receipt',     'external' => '/interface/patient_file/printed_fee_sheet.php?pid=' . $pid],
    ['slug' => 'recall',        'icon' => '📨', 'label' => 'Recall list',          'external' => '/interface/main/messages/messages.php'],
    ['slug' => 'appointment',   'icon' => '📅', 'label' => 'Appointment slip',     'external' => '/interface/main/calendar/index.php'],
    ['slug' => 'instructions',  'icon' => '🆔', 'label' => 'Patient instructions', 'external' => null],
];

// Validate ?type=
$validTypes = array_column($output_types, 'slug');
$selectedType = (string)($_GET['type'] ?? 'demographics');
if (!in_array($selectedType, $validTypes, true)) {
    $selectedType = 'demographics';
}

// Validate confidentiality. Affects which sections render on demographics.
$validConf = ['standard', 'restricted', 'highly_restricted'];
$confidentiality = (string)($_GET['conf'] ?? 'standard');
if (!in_array($confidentiality, $validConf, true)) {
    $confidentiality = 'standard';
}

// POST handlers — print / save_pdf / save_settings.
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $user   = (string)($_SESSION['authUser'] ?? 'admin');
    $group  = (string)($_SESSION['authProvider'] ?? 'Default');
    $postedType = (string)($_POST['type'] ?? $selectedType);
    if (!in_array($postedType, $validTypes, true)) {
        $postedType = 'demographics';
    }

    if ($action === 'print' || $action === 'save_pdf') {
        // Compose audit-grade detail line from the form fields.
        $detail = json_encode([
            'type'        => $postedType,
            'paper_size'  => (string)($_POST['paper_size'] ?? 'letter'),
            'orientation' => (string)($_POST['orientation'] ?? 'portrait'),
            'copies'      => (int)($_POST['copies'] ?? 1),
            'include'     => array_values((array)($_POST['include'] ?? [])),
            'printer'     => (string)($_POST['printer'] ?? ''),
            'conf'        => (string)($_POST['conf'] ?? 'standard'),
        ]);

        // Audit-grade record per brief. EventAuditLogger writes to `log` (and
        // mirrors to extended_log when configured). Patient-scoped event.
        try {
            EventAuditLogger::getInstance()->newEvent(
                ($action === 'print') ? 'print' : 'save_pdf',
                $user,
                $group,
                1,
                "copilot_print_preview type=$postedType detail=$detail",
                $pid
            );
        } catch (\Throwable $t) {
            // Swallow — still redirect/stream so the UX works.
        }

        if ($action === 'save_pdf') {
            // No PDF lib wired in dev — stream a self-contained HTML the user
            // can save / print to PDF locally. Honest about scope.
            $patient = sqlQuery("SELECT fname, lname, pubpid FROM patient_data WHERE pid = ?", [$pid]);
            $fname = trim(($patient['fname'] ?? '') . '_' . ($patient['lname'] ?? '')) ?: 'patient';
            $stamp = date('Ymd_His');
            $filename = "print_{$postedType}_{$fname}_{$stamp}.html";
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $body = '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($postedType, ENT_QUOTES) . '</title></head><body>';
            $body .= '<h1>OpenEMR — ' . htmlspecialchars(ucfirst(str_replace('_', ' ', $postedType)), ENT_QUOTES) . '</h1>';
            $body .= '<p>Patient: ' . htmlspecialchars(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''), ENT_QUOTES) . ' · MRN ' . htmlspecialchars((string)($patient['pubpid'] ?? ''), ENT_QUOTES) . '</p>';
            $body .= '<p>Generated ' . date('Y-m-d H:i:s') . ' by ' . htmlspecialchars($user, ENT_QUOTES) . '</p>';
            $body .= '<p>Print settings: ' . htmlspecialchars($detail, ENT_QUOTES) . '</p>';
            $body .= '<p><em>(In production this would be a PDF rendered via Dompdf/mPDF.)</em></p>';
            $body .= '</body></html>';
            echo $body;
            exit;
        }

        $msg = ($action === 'print') ? 'printed' : 'saved';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?type=' . urlencode($postedType) . '&conf=' . urlencode((string)($_POST['conf'] ?? 'standard')) . '&msg=' . $msg);
        exit;
    }
}

if (isset($_GET['msg'])) {
    $flash = (string)$_GET['msg'];
}

// ---- Data loads (real DB) ----------------------------------------------------

// Practice header. Use first primary_business_entity facility.
$facility = sqlQuery(
    "SELECT name, street, city, state, postal_code, phone, facility_npi, federal_ein
     FROM facility
     WHERE primary_business_entity = 1 AND inactive = 0
     ORDER BY id ASC
     LIMIT 1"
);
if (!$facility) {
    $facility = [
        'name' => 'OpenEMR Clinic', 'street' => '', 'city' => '', 'state' => '',
        'postal_code' => '', 'phone' => '', 'facility_npi' => '', 'federal_ein' => '',
    ];
}

// Patient demographics.
$patient = sqlQuery(
    "SELECT pid, fname, mname, lname, pubpid, DOB, sex, status, language,
            street, city, state, postal_code, phone_home, phone_cell, email
     FROM patient_data
     WHERE pid = ?",
    [$pid]
);
if (!$patient) {
    $patient = [
        'pid' => $pid, 'fname' => '', 'mname' => '', 'lname' => '(no patient)',
        'pubpid' => '', 'DOB' => null, 'sex' => '', 'status' => '', 'language' => '',
        'street' => '', 'city' => '', 'state' => '', 'postal_code' => '',
        'phone_home' => '', 'phone_cell' => '', 'email' => '',
    ];
}

// Insurance — primary plan if present.
$insurance = sqlQuery(
    "SELECT id.type, id.policy_number, id.group_number, id.date AS eff_start,
            id.date_end AS eff_end, ic.name AS company
     FROM insurance_data id
     LEFT JOIN insurance_companies ic ON ic.id = id.provider
     WHERE id.pid = ? AND id.type = 'primary' AND COALESCE(id.policy_number, '') != ''
     ORDER BY id.date DESC
     LIMIT 1",
    [$pid]
);

// Active problems.
$problemsRs = sqlStatement(
    "SELECT title, diagnosis, begdate
     FROM lists
     WHERE pid = ? AND type = 'medical_problem'
       AND COALESCE(outcome, 0) <> 1
     ORDER BY COALESCE(begdate, date) DESC",
    [$pid]
);
$problems = [];
$seenProblem = [];
while ($r = sqlFetchArray($problemsRs)) {
    // Collapse seed duplicates (same title + diagnosis).
    $key = trim((string)$r['title']) . '|' . trim((string)$r['diagnosis']);
    if (isset($seenProblem[$key])) {
        continue;
    }
    $seenProblem[$key] = true;
    $problems[] = $r;
}

// Current medications.
$medsRs = sqlStatement(
    "SELECT drug, dosage, route
     FROM prescriptions
     WHERE patient_id = ? AND active = 1
     ORDER BY date_added DESC",
    [$pid]
);
$meds = [];
$seenMed = [];
while ($r = sqlFetchArray($medsRs)) {
    $key = strtolower(trim((string)$r['drug']) . '|' . trim((string)$r['dosage']));
    if (isset($seenMed[$key])) {
        continue;
    }
    $seenMed[$key] = true;
    $meds[] = $r;
}

// Allergies.
$allergyRs = sqlStatement(
    "SELECT title, reaction, severity_al
     FROM lists
     WHERE pid = ? AND type = 'allergy'
       AND COALESCE(outcome, 0) <> 1
     ORDER BY COALESCE(begdate, date) DESC",
    [$pid]
);
$allergies = [];
$seenAllergy = [];
while ($r = sqlFetchArray($allergyRs)) {
    $key = strtolower(trim((string)$r['title']));
    if (isset($seenAllergy[$key])) {
        continue;
    }
    $seenAllergy[$key] = true;
    $allergies[] = $r;
}

// ---- Helpers -----------------------------------------------------------------

/**
 * Compute age in completed years.
 */
function pp_age(?string $dob): ?int
{
    if (!$dob || $dob === '0000-00-00') {
        return null;
    }
    try {
        $b = new \DateTimeImmutable($dob);
        $n = new \DateTimeImmutable('now');
        return (int)$b->diff($n)->y;
    } catch (\Throwable $t) {
        return null;
    }
}

/**
 * Format a date as m/d/Y, or em-dash on empty.
 */
function pp_fmt_date(?string $d): string
{
    if (!$d || $d === '0000-00-00' || str_starts_with($d, '0000-00-00')) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('m/d/Y', $ts) : '—';
}

/**
 * Title-case a one-word string for display ("married" -> "Married").
 */
function pp_titlecase(string $s): string
{
    return ucwords(strtolower(trim($s)));
}

// Pre-computed display values.
$patient_full = trim(preg_replace('/\s+/', ' ', ((string)$patient['fname']) . ' ' . ((string)$patient['mname']) . ' ' . ((string)$patient['lname'])));
$age = pp_age((string)$patient['DOB']);
$dob_display = pp_fmt_date((string)$patient['DOB']) . ($age !== null ? " ({$age} years)" : '');
$city_state_zip = trim(
    (string)$patient['city']
    . (((string)$patient['city'] !== '' && (string)$patient['state'] !== '') ? ', ' : '')
    . (string)$patient['state']
    . ' '
    . (string)$patient['postal_code']
);

$now = date('m/d/Y H:i') . ' CT';
$practice_meta1 = trim(
    (string)$facility['street'] .
    (((string)$facility['street'] !== '') ? ' · ' : '') .
    (string)$facility['city'] .
    (((string)$facility['state'] !== '') ? ', ' : '') .
    (string)$facility['state'] . ' ' . (string)$facility['postal_code'] .
    (((string)$facility['phone'] !== '') ? ' · ' . (string)$facility['phone'] : '')
);
$practice_meta2 = '';
if (!empty($facility['facility_npi'])) {
    $practice_meta2 .= 'NPI ' . (string)$facility['facility_npi'];
}
if (!empty($facility['federal_ein'])) {
    $practice_meta2 .= ($practice_meta2 ? ' · ' : '') . 'Tax ID ' . (string)$facility['federal_ein'];
}
$practice_meta2 .= ($practice_meta2 ? ' · ' : '') . 'Generated ' . $now;

// Printer registry — OpenEMR has no native printer table. Hardcoded set with
// a comment per brief; in production these would come from a `printers`
// settings table or CUPS lookup.
$printers = [
    'HP-LJ-Front Desk',
    'HP-LJ-Nursing',
    'Brother-MFC-Back Office',
];
$selectedPrinter = (string)($_GET['printer'] ?? $printers[0]);
if (!in_array($selectedPrinter, $printers, true)) {
    $selectedPrinter = $printers[0];
}

// Confidentiality affects which sections render. "restricted" hides insurance,
// "highly_restricted" additionally hides allergies & meds detail (clinically
// restricted scenarios — sealed records, etc.).
$showInsurance  = ($confidentiality === 'standard');
$showMeds       = ($confidentiality !== 'highly_restricted');
$showAllergies  = ($confidentiality !== 'highly_restricted');
$showProblems   = ($confidentiality !== 'highly_restricted');

// Page count is always 1 for this archetype today.
$page_count = 1;

// Preselected include[] checkbox state — read GET so a settings-form GET
// (browser back button) still pre-checks correctly.
$includeKeys = [
    'practice_header'   => 'Practice header / logo',
    'patient_demos'     => 'Patient demographics',
    'insurance_info'    => 'Insurance info',
    'active_meds'       => 'Active medications',
    'active_problems'   => 'Active problems',
    'allergies'         => 'Allergies',
    'recent_labs'       => 'Recent labs (last 90d)',
    'care_team'         => 'Care team / referrals',
    'signoff'           => 'Sign-off line',
];
$defaultInclude = ['practice_header','patient_demos','insurance_info','active_meds','active_problems','allergies','signoff'];
$selectedInclude = isset($_GET['include']) && is_array($_GET['include'])
    ? array_values(array_intersect($_GET['include'], array_keys($includeKeys)))
    : $defaultInclude;

$paperSize  = (string)($_GET['paper_size'] ?? 'letter');
if (!in_array($paperSize, ['letter','legal','a4'], true)) {
    $paperSize = 'letter';
}
$orientation = (string)($_GET['orientation'] ?? 'portrait');
if (!in_array($orientation, ['portrait','landscape'], true)) {
    $orientation = 'portrait';
}
$copies = (int)($_GET['copies'] ?? 1);
if ($copies < 1) { $copies = 1; }
if ($copies > 99) { $copies = 99; }

// Helper: build a URL preserving current settings.
$buildUrl = function (array $overrides = []) use ($selectedType, $confidentiality, $selectedPrinter, $paperSize, $orientation, $copies, $selectedInclude): string {
    $params = array_merge([
        'type' => $selectedType,
        'conf' => $confidentiality,
        'printer' => $selectedPrinter,
        'paper_size' => $paperSize,
        'orientation' => $orientation,
        'copies' => $copies,
    ], $overrides);
    $qs = http_build_query($params);
    foreach ($selectedInclude as $i) {
        $qs .= '&include%5B%5D=' . urlencode($i);
    }
    return $_SERVER['PHP_SELF'] . '?' . $qs;
};

// Output type label for the page subtitle.
$selectedTypeLabel = 'Demographics summary';
$selectedTypeExternal = null;
foreach ($output_types as $ot) {
    if ($ot['slug'] === $selectedType) {
        $selectedTypeLabel = $ot['label'];
        $selectedTypeExternal = $ot['external'];
        break;
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Print Preview'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page head with breadcrumb meta + right-aligned controls */
  .pp-head { padding: 10px 24px; height: 52px; }
  .pp-head .info { flex: 0 0 auto; flex-direction: row; align-items: center; gap: 10px; }
  .pp-head .info .titleSm { font-size: 18px; }
  .pp-head .info .bullet { font-size: 16px; }
  .pp-head .info .meta { font-size: 13px; color: #4F5763; }
  .pp-head .spacer { flex: 1; }
  .pp-head .printer-sel {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 16px;
    height: 32px; padding: 0 14px;
    font-size: 12px; font-weight: 500; color: #4F5763;
    display: inline-flex; align-items: center; gap: 6px;
    cursor: pointer;
    appearance: none;
  }
  .pp-head .help-pill {
    background: #F5F6F7; border: 0; border-radius: 12px;
    height: 24px; padding: 0 12px;
    font-size: 12px; font-weight: 500; color: #4F5763;
    display: inline-flex; align-items: center;
  }
  .pp-head .help-pill[disabled] { cursor: not-allowed; opacity: 0.55; }
  .pp-head .cp-btn { height: 32px; border-radius: 16px; padding: 0 14px; font-size: 13px; }
  .pp-head .cp-btn.primary { font-weight: 600; }
  .pp-head .pp-flash {
    position: absolute; top: 56px; right: 24px;
    background: #008C8C; color: #FFFFFF;
    border-radius: 12px; padding: 6px 12px; font-size: 12px; font-weight: 500;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    z-index: 50;
  }

  /* Body grid: sidebar | settings | preview stage */
  .pp-body {
    display: grid;
    grid-template-columns: 232px 260px 1fr;
    min-height: calc(100vh - 60px);
    background: #FFFFFF;
  }

  /* Output type sidebar */
  .pp-sidebar { background: #FFFFFF; border-right: 1px solid #E4E5E8; padding: 8px 8px 16px; }
  .pp-sidebar .lbl {
    font-size: 11px; font-weight: 600; color: #8A91A1;
    letter-spacing: 0.5px; padding: 8px 8px 4px;
  }
  .pp-cat {
    display: flex; align-items: center; gap: 10px;
    height: 36px; padding: 0 10px;
    border-radius: 6px;
    font-size: 12px; font-weight: 500; color: #4F5763;
    text-decoration: none; cursor: pointer;
    background: transparent; border: 0; width: 100%;
    text-align: left;
  }
  .pp-cat:hover { background: #F5F7F8; }
  .pp-cat .ic { font-size: 14px; line-height: 1; flex: 0 0 16px; text-align: center; }
  .pp-cat.active { background: #E6F5F5; color: #008C8C; font-weight: 600; }

  /* Settings panel column */
  .pp-settings-wrap { padding: 16px 12px; }
  .pp-settings {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 15px 15px 18px;
  }
  .pp-settings .grp-lbl-section {
    font-size: 11px; font-weight: 600; color: #8A91A1;
    letter-spacing: 0.5px; line-height: 1; margin-bottom: 18px;
  }
  .pp-settings .field-lbl {
    font-size: 11px; font-weight: 500; color: #8A91A1;
    line-height: 1; margin-bottom: 6px;
  }
  .pp-settings .field-lbl.section-spacer { margin-top: 16px; }
  .pp-settings .field-lbl.include-lbl {
    font-weight: 600; letter-spacing: 0.5px;
    margin-top: 24px; margin-bottom: 10px;
  }
  .pp-select, .pp-input {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 4px;
    height: 32px; padding: 0 10px;
    font-size: 12px; font-weight: 500; color: #181D26;
    width: 100%;
    display: flex; align-items: center; justify-content: space-between;
    appearance: none;
  }
  .pp-select select {
    border: 0; outline: none; background: transparent;
    font: inherit; color: inherit; flex: 1; padding: 0;
    appearance: none;
  }
  .pp-select .car { color: #8A91A1; font-size: 11px; pointer-events: none; }
  .pp-input input {
    border: 0; outline: none; background: transparent;
    font: inherit; color: inherit; flex: 1; padding: 0; width: 100%;
  }
  .pp-input .step { color: #8A91A1; font-size: 13px; }

  /* Orientation toggle */
  .pp-orient {
    background: #F5F6F7; border-radius: 8px;
    height: 32px; padding: 2px;
    display: grid; grid-template-columns: 1fr 1fr; gap: 0;
  }
  .pp-orient label {
    height: 28px; border-radius: 6px;
    background: transparent; border: 0;
    font-size: 12px; font-weight: 500; color: #4F5763;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
  }
  .pp-orient input[type=radio] { display: none; }
  .pp-orient input[type=radio]:checked + label,
  .pp-orient label.active {
    background: #FFFFFF; border: 1px solid #E4E5E8;
    color: #181D26;
  }

  /* INCLUDE checkbox list */
  .pp-include { display: flex; flex-direction: column; gap: 0; }
  .pp-check {
    display: flex; align-items: center; gap: 10px;
    height: 30px; cursor: pointer;
  }
  .pp-check .box {
    width: 16px; height: 16px; border-radius: 4px;
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    flex: 0 0 16px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; color: #FFFFFF; font-weight: 700;
    line-height: 1;
  }
  .pp-check input[type=checkbox] { display: none; }
  .pp-check input[type=checkbox]:checked + .box,
  .pp-check.on .box { background: #008C8C; border-color: #008C8C; }
  .pp-check input[type=checkbox]:checked + .box::after,
  .pp-check.on .box::after { content: '\2713'; }
  .pp-check .lbl { font-size: 12px; color: #181D26; }

  /* Preview stage */
  .pp-stage {
    background: #EDEDF0;
    padding: 12px;
    overflow-y: auto;
  }
  .pp-stage .scale-lbl {
    font-size: 11px; color: #8A91A1; line-height: 1;
    padding: 4px 0 16px;
  }
  .pp-paper-wrap { display: flex; justify-content: center; }
  .pp-paper {
    background: #FFFFFF;
    border: 1px solid #CCCCD1;
    border-radius: 2px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.18);
    width: 800px; min-height: 648px;
    padding: 31px 33px;
    color: #181D26;
    position: relative;
  }
  .pp-paper.landscape { width: 1000px; min-height: 540px; }
  .pp-paper-head {
    display: flex; gap: 17px; align-items: flex-start;
    padding-bottom: 12px;
    border-bottom: 2px solid #181D26;
  }
  .pp-paper-head .logo {
    width: 40px; height: 40px;
    background: #008C8C; border-radius: 6px;
    flex: 0 0 40px;
  }
  .pp-paper-head .brand h1 {
    font-size: 14px; font-weight: 700; color: #181D26;
    margin: 6px 0 4px; line-height: 1;
  }
  .pp-paper-head .brand .clinic {
    font-size: 9px; font-weight: 600; color: #4F5763;
    letter-spacing: 0.4px;
    line-height: 1;
  }
  .pp-paper-head .meta { margin-left: auto; text-align: left; padding-top: 6px; }
  .pp-paper-head .meta .l1 { font-size: 9px; color: #4F5763; line-height: 1.5; }
  .pp-paper-head .meta .l2 { font-size: 9px; color: #8A91A1; line-height: 1.5; }
  .pp-paper-title {
    font-size: 12px; font-weight: 700; color: #181D26;
    margin: 16px 0 0; line-height: 1;
  }
  .pp-paper-grid {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 0 8px;
    margin-top: 18px;
  }
  .pp-paper-section {
    font-size: 9px; font-weight: 600; color: #8A91A1;
    letter-spacing: 0.5px;
    line-height: 1;
    margin-bottom: 6px;
  }
  .pp-paper-section.spacer { margin-top: 22px; }
  .pp-kv {
    display: grid;
    grid-template-columns: 108px 1fr;
    column-gap: 0;
    row-gap: 4px;
    margin-bottom: 4px;
  }
  .pp-kv .k {
    font-size: 9px; font-weight: 500; color: #4F5763;
    line-height: 1.4;
  }
  .pp-kv .v {
    font-size: 10px; color: #181D26;
    line-height: 1.3;
  }
  .pp-paper-rcol { padding-left: 20px; }
  .pp-bullets { margin: 0; padding: 0; list-style: none; }
  .pp-bullets li {
    font-size: 9px; color: #181D26;
    line-height: 1.7;
  }
  .pp-bullets.allergies li { color: #D93838; }
  .pp-bullets li.empty { color: #8A91A1; font-style: italic; }

  .pp-paper-footer {
    margin-top: 38px;
    padding-top: 12px;
    border-top: 1px solid #4F5763;
  }
  .pp-paper-signoff {
    display: grid;
    grid-template-columns: 60px 1fr 50px 1fr;
    align-items: center;
    gap: 0 8px;
    font-size: 9px;
    color: #4F5763;
    margin-bottom: 18px;
  }
  .pp-paper-signoff .line { border-bottom: 1px solid #181D26; height: 1em; }
  .pp-paper-signoff .lbl { font-weight: 500; }
  .pp-paper-pagefooter {
    font-size: 8px; color: #8A91A1; line-height: 1;
  }

  .pp-coming-soon {
    margin-top: 90px; text-align: center;
    color: #4F5763;
  }
  .pp-coming-soon h2 {
    font-size: 18px; font-weight: 700; color: #181D26;
    margin: 0 0 8px;
  }
  .pp-coming-soon p { font-size: 13px; margin: 0 0 16px; }
  .pp-coming-soon a {
    display: inline-block; background: #008C8C; color: #FFFFFF;
    border-radius: 16px; padding: 8px 16px; font-size: 12px; font-weight: 600;
    text-decoration: none;
  }

  /* Right column line for paper key/value double row */
  .pp-paper-row2 { display: flex; gap: 0; align-items: flex-start; }
  .pp-paper-row2 .col { flex: 1; }
</style>
</head>
<body class="cp-arch">

<?php if ($flash !== null): ?>
  <div class="pp-flash">
    <?php
    $flashLabel = match ($flash) {
        'printed' => 'Print queued · ' . strtoupper((string)$selectedPrinter),
        'saved'   => 'PDF saved',
        default   => $flash,
    };
    echo text($flashLabel);
    ?>
  </div>
<?php endif; ?>

<header class="cp-pagehead pp-head">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Print Preview'); ?></span>
    <span class="bullet">•</span>
    <span class="meta">
      <?php echo text($selectedTypeLabel); ?>
      <?php echo text(' · ' . $page_count . ' page' . ($page_count === 1 ? '' : 's')); ?>
    </span>
  </div>
  <div class="spacer"></div>

  <!-- Printer dropdown — submits via GET on change. -->
  <form method="get" action="<?php echo attr($_SERVER['PHP_SELF']); ?>" style="display:inline-flex;">
    <input type="hidden" name="type" value="<?php echo attr($selectedType); ?>">
    <input type="hidden" name="conf" value="<?php echo attr($confidentiality); ?>">
    <input type="hidden" name="paper_size" value="<?php echo attr($paperSize); ?>">
    <input type="hidden" name="orientation" value="<?php echo attr($orientation); ?>">
    <input type="hidden" name="copies" value="<?php echo attr((string)$copies); ?>">
    <?php foreach ($selectedInclude as $inc): ?>
      <input type="hidden" name="include[]" value="<?php echo attr($inc); ?>">
    <?php endforeach; ?>
    <select name="printer" class="printer-sel" onchange="this.form.submit()">
      <?php foreach ($printers as $p): ?>
        <option value="<?php echo attr($p); ?>"<?php echo $p === $selectedPrinter ? ' selected' : ''; ?>>
          🖨 <?php echo text($p); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <!-- Save PDF — POST to action=save_pdf. -->
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>" style="display:inline-flex;">
    <input type="hidden" name="action" value="save_pdf">
    <input type="hidden" name="type" value="<?php echo attr($selectedType); ?>">
    <input type="hidden" name="conf" value="<?php echo attr($confidentiality); ?>">
    <input type="hidden" name="printer" value="<?php echo attr($selectedPrinter); ?>">
    <input type="hidden" name="paper_size" value="<?php echo attr($paperSize); ?>">
    <input type="hidden" name="orientation" value="<?php echo attr($orientation); ?>">
    <input type="hidden" name="copies" value="<?php echo attr((string)$copies); ?>">
    <?php foreach ($selectedInclude as $inc): ?>
      <input type="hidden" name="include[]" value="<?php echo attr($inc); ?>">
    <?php endforeach; ?>
    <button type="submit" class="cp-btn ghost">⬇ <?php echo xlt('Save PDF'); ?></button>
  </form>

  <!-- Print 1 page — POST to action=print. -->
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>" style="display:inline-flex;">
    <input type="hidden" name="action" value="print">
    <input type="hidden" name="type" value="<?php echo attr($selectedType); ?>">
    <input type="hidden" name="conf" value="<?php echo attr($confidentiality); ?>">
    <input type="hidden" name="printer" value="<?php echo attr($selectedPrinter); ?>">
    <input type="hidden" name="paper_size" value="<?php echo attr($paperSize); ?>">
    <input type="hidden" name="orientation" value="<?php echo attr($orientation); ?>">
    <input type="hidden" name="copies" value="<?php echo attr((string)$copies); ?>">
    <?php foreach ($selectedInclude as $inc): ?>
      <input type="hidden" name="include[]" value="<?php echo attr($inc); ?>">
    <?php endforeach; ?>
    <button type="submit" class="cp-btn primary">
      🖨 <?php echo xlt('Print'); ?> <?php echo text((string)$copies); ?> <?php echo xlt($copies === 1 ? 'page' : 'pages'); ?>
    </button>
  </form>

  <!-- Help — disabled per brief. -->
  <button type="button" class="help-pill" disabled title="<?php echo attr(xl('Out of scope for demo')); ?>">
    ? <?php echo xlt('Help'); ?>
  </button>
</header>

<div class="pp-body">

  <aside class="pp-sidebar">
    <div class="lbl"><?php echo xlt('OUTPUT TYPE'); ?></div>
    <?php foreach ($output_types as $ot): ?>
      <a href="<?php echo attr($buildUrl(['type' => $ot['slug']])); ?>"
         class="pp-cat<?php echo $ot['slug'] === $selectedType ? ' active' : ''; ?>">
        <span class="ic"><?php echo text($ot['icon']); ?></span>
        <span><?php echo text($ot['label']); ?></span>
      </a>
    <?php endforeach; ?>
  </aside>

  <div class="pp-settings-wrap">
    <!-- Settings form — GET so changing a field updates the URL/preview. -->
    <form method="get" action="<?php echo attr($_SERVER['PHP_SELF']); ?>" id="pp-settings-form">
      <input type="hidden" name="type" value="<?php echo attr($selectedType); ?>">
      <input type="hidden" name="printer" value="<?php echo attr($selectedPrinter); ?>">

      <div class="pp-settings">
        <div class="grp-lbl-section"><?php echo xlt('PRINT SETTINGS'); ?></div>

        <div class="field-lbl"><?php echo xlt('Paper size'); ?></div>
        <div class="pp-select">
          <select name="paper_size" onchange="this.form.submit()">
            <option value="letter"<?php echo $paperSize === 'letter' ? ' selected' : ''; ?>>
              <?php echo xlt('US Letter (8.5 × 11 in)'); ?>
            </option>
            <option value="legal"<?php echo $paperSize === 'legal' ? ' selected' : ''; ?>>
              <?php echo xlt('US Legal (8.5 × 14 in)'); ?>
            </option>
            <option value="a4"<?php echo $paperSize === 'a4' ? ' selected' : ''; ?>>
              <?php echo xlt('A4 (210 × 297 mm)'); ?>
            </option>
          </select>
          <span class="car">▾</span>
        </div>

        <div class="field-lbl section-spacer"><?php echo xlt('Orientation'); ?></div>
        <div class="pp-orient">
          <input type="radio" id="pp-port" name="orientation" value="portrait" <?php echo $orientation === 'portrait' ? 'checked' : ''; ?> onchange="this.form.submit()">
          <label for="pp-port"><?php echo xlt('Portrait'); ?></label>
          <input type="radio" id="pp-land" name="orientation" value="landscape" <?php echo $orientation === 'landscape' ? 'checked' : ''; ?> onchange="this.form.submit()">
          <label for="pp-land"><?php echo xlt('Landscape'); ?></label>
        </div>

        <div class="field-lbl section-spacer"><?php echo xlt('Copies'); ?></div>
        <div class="pp-input">
          <input type="number" name="copies" min="1" max="99" value="<?php echo attr((string)$copies); ?>" onchange="this.form.submit()">
          <span class="step">⊟</span>
        </div>

        <div class="field-lbl include-lbl"><?php echo xlt('INCLUDE'); ?></div>
        <div class="pp-include">
          <?php foreach ($includeKeys as $key => $label): ?>
            <?php $on = in_array($key, $selectedInclude, true); ?>
            <label class="pp-check<?php echo $on ? ' on' : ''; ?>">
              <input type="checkbox" name="include[]" value="<?php echo attr($key); ?>" <?php echo $on ? 'checked' : ''; ?> onchange="this.form.submit()">
              <span class="box"></span>
              <span class="lbl"><?php echo text($label); ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="field-lbl section-spacer"><?php echo xlt('Confidentiality'); ?></div>
        <div class="pp-select">
          <select name="conf" onchange="this.form.submit()">
            <option value="standard"<?php echo $confidentiality === 'standard' ? ' selected' : ''; ?>><?php echo xlt('Standard'); ?></option>
            <option value="restricted"<?php echo $confidentiality === 'restricted' ? ' selected' : ''; ?>><?php echo xlt('Restricted'); ?></option>
            <option value="highly_restricted"<?php echo $confidentiality === 'highly_restricted' ? ' selected' : ''; ?>><?php echo xlt('Highly restricted'); ?></option>
          </select>
          <span class="car">▾</span>
        </div>
      </div>
    </form>
  </div>

  <div class="pp-stage">
    <div class="scale-lbl">
      <?php
      $sizeLabel = match ($paperSize) {
          'legal' => '8.5 × 14 in',
          'a4'    => '210 × 297 mm',
          default => '8.5 × 11 in',
      };
      echo xlt($sizeLabel) . ' · ' . xlt('100% scale') . ' · ' . xlt(ucfirst($orientation));
      ?>
    </div>
    <div class="pp-paper-wrap">
      <div class="pp-paper<?php echo $orientation === 'landscape' ? ' landscape' : ''; ?>">

      <?php if ($selectedType === 'demographics'): ?>

        <?php if (in_array('practice_header', $selectedInclude, true)): ?>
        <div class="pp-paper-head">
          <div class="logo"></div>
          <div class="brand">
            <h1><?php echo text((string)$facility['name']); ?></h1>
            <div class="clinic"><?php echo text(strtoupper((string)$facility['name'])); ?></div>
          </div>
          <div class="meta">
            <div class="l1"><?php echo text($practice_meta1); ?></div>
            <div class="l2"><?php echo text($practice_meta2); ?></div>
          </div>
        </div>
        <?php endif; ?>

        <div class="pp-paper-title"><?php echo xlt('PATIENT DEMOGRAPHICS SUMMARY'); ?></div>

        <div class="pp-paper-grid">

          <div class="pp-paper-lcol">
            <?php if (in_array('patient_demos', $selectedInclude, true)): ?>
            <div class="pp-paper-section spacer" style="margin-top:18px;"><?php echo xlt('IDENTITY'); ?></div>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('Patient name'); ?></span>
              <span class="v"><?php echo text($patient_full ?: '—'); ?></span>
            </div>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('Date of birth'); ?></span>
              <span class="v"><?php echo text($dob_display); ?></span>
            </div>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('Marital status'); ?></span>
              <span class="v"><?php echo text(((string)$patient['status'] !== '') ? pp_titlecase((string)$patient['status']) : '—'); ?></span>
            </div>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('Preferred language'); ?></span>
              <span class="v"><?php echo text(((string)$patient['language'] !== '') ? pp_titlecase((string)$patient['language']) : '—'); ?></span>
            </div>

            <div class="pp-paper-section spacer"><?php echo xlt('CONTACT'); ?></div>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('Address'); ?></span>
              <span class="v"><?php echo text(((string)$patient['street'] !== '') ? (string)$patient['street'] : '—'); ?></span>
            </div>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('City / State / ZIP'); ?></span>
              <span class="v"><?php echo text($city_state_zip !== '' ? $city_state_zip : '—'); ?></span>
            </div>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('Phone'); ?></span>
              <span class="v"><?php echo text(((string)$patient['phone_home'] !== '') ? (string)$patient['phone_home'] : '—'); ?></span>
            </div>
            <?php if ((string)$patient['phone_cell'] !== ''): ?>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('Mobile'); ?></span>
              <span class="v"><?php echo text((string)$patient['phone_cell']); ?></span>
            </div>
            <?php endif; ?>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('Email'); ?></span>
              <span class="v"><?php echo text(((string)$patient['email'] !== '') ? (string)$patient['email'] : '—'); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($showInsurance && in_array('insurance_info', $selectedInclude, true)): ?>
            <div class="pp-paper-section spacer"><?php echo xlt('INSURANCE'); ?></div>
            <?php if ($insurance): ?>
              <div class="pp-kv">
                <span class="k"><?php echo xlt('Primary plan'); ?></span>
                <span class="v"><?php echo text(((string)$insurance['company'] !== '') ? (string)$insurance['company'] : '—'); ?></span>
              </div>
              <div class="pp-kv">
                <span class="k"><?php echo xlt('Member ID'); ?></span>
                <span class="v"><?php echo text((string)($insurance['policy_number'] ?? '—')); ?></span>
              </div>
              <div class="pp-kv">
                <span class="k"><?php echo xlt('Group #'); ?></span>
                <span class="v"><?php echo text(((string)($insurance['group_number'] ?? '')) !== '' ? (string)$insurance['group_number'] : '—'); ?></span>
              </div>
              <div class="pp-kv">
                <span class="k"><?php echo xlt('Effective'); ?></span>
                <span class="v"><?php echo text(pp_fmt_date((string)($insurance['eff_start'] ?? '')) . ' – ' . pp_fmt_date((string)($insurance['eff_end'] ?? ''))); ?></span>
              </div>
            <?php else: ?>
              <div class="pp-kv">
                <span class="k"><?php echo xlt('Primary plan'); ?></span>
                <span class="v"><?php echo xlt('Self-pay (no insurance on file)'); ?></span>
              </div>
            <?php endif; ?>
            <?php endif; ?>
          </div>

          <div class="pp-paper-rcol">
            <div style="height: 15px;"></div>
            <div class="pp-kv" style="margin-top:6px;">
              <span class="k"><?php echo xlt('MRN'); ?></span>
              <span class="v"><?php echo text(((string)$patient['pubpid'] !== '') ? (string)$patient['pubpid'] : '—'); ?></span>
            </div>
            <div class="pp-kv">
              <span class="k"><?php echo xlt('Sex'); ?></span>
              <span class="v"><?php echo text(((string)$patient['sex'] !== '') ? pp_titlecase((string)$patient['sex']) : '—'); ?></span>
            </div>

            <?php if ($showProblems && in_array('active_problems', $selectedInclude, true)): ?>
            <div class="pp-paper-section spacer"><?php echo xlt('ACTIVE PROBLEMS'); ?></div>
            <ul class="pp-bullets">
              <?php if (count($problems) === 0): ?>
                <li class="empty">• <?php echo xlt('None on file'); ?></li>
              <?php else: ?>
                <?php foreach ($problems as $p):
                    $diag = (string)($p['diagnosis'] ?? '');
                    if (str_starts_with($diag, 'ICD10:')) {
                        $diag = substr($diag, 6);
                    }
                    $beg = pp_fmt_date((string)($p['begdate'] ?? ''));
                ?>
                  <li>
                    • <?php echo text((string)$p['title']); ?><?php
                    if ($diag !== '') {
                        echo ' (' . text($diag) . ')';
                    }
                    if ($beg !== '—') {
                        echo ' — since ' . text(date('Y', strtotime((string)$p['begdate'])));
                    }
                    ?>
                  </li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
            <?php endif; ?>

            <?php if ($showMeds && in_array('active_meds', $selectedInclude, true)): ?>
            <div class="pp-paper-section spacer"><?php echo xlt('CURRENT MEDICATIONS'); ?></div>
            <ul class="pp-bullets">
              <?php if (count($meds) === 0): ?>
                <li class="empty">• <?php echo xlt('None on file'); ?></li>
              <?php else: ?>
                <?php foreach ($meds as $m): ?>
                  <li>• <?php
                    echo text((string)$m['drug']);
                    if (!empty($m['dosage'])) {
                        echo ' ' . text((string)$m['dosage']);
                    }
                    if (!empty($m['route'])) {
                        echo ' — ' . text((string)$m['route']);
                    }
                  ?></li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
            <?php endif; ?>

            <?php if ($showAllergies && in_array('allergies', $selectedInclude, true)): ?>
            <div class="pp-paper-section spacer"><?php echo xlt('ALLERGIES'); ?></div>
            <ul class="pp-bullets allergies">
              <?php if (count($allergies) === 0): ?>
                <li class="empty">• <?php echo xlt('No known allergies'); ?></li>
              <?php else: ?>
                <?php foreach ($allergies as $a): ?>
                  <li>• <?php
                    echo text((string)$a['title']);
                    $detailParts = [];
                    if (!empty($a['severity_al'])) {
                        $detailParts[] = (string)$a['severity_al'];
                    }
                    if (!empty($a['reaction'])) {
                        $detailParts[] = (string)$a['reaction'];
                    }
                    if ($detailParts) {
                        echo ' — ' . text(implode(', ', $detailParts));
                    }
                  ?></li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
            <?php endif; ?>
          </div>

        </div>

        <?php if (in_array('signoff', $selectedInclude, true)): ?>
        <div class="pp-paper-footer">
          <div class="pp-paper-signoff">
            <span class="lbl"><?php echo xlt('Reviewed by:'); ?></span>
            <span class="line">&nbsp;</span>
            <span class="lbl" style="text-align:right; padding-right:8px;"><?php echo xlt('Date:'); ?></span>
            <span class="line">&nbsp;</span>
          </div>
          <div class="pp-paper-pagefooter">
            <?php
            echo xlt('Page 1 of 1') . ' · ' .
                 xlt('Confidentiality:') . ' ' . text(pp_titlecase(str_replace('_', ' ', $confidentiality))) . ' · ' .
                 xlt('Confidential — protected health information');
            ?>
          </div>
        </div>
        <?php endif; ?>

      <?php else: ?>

        <!-- Non-demographics output types — fallback "Coming soon" stub. -->
        <div class="pp-coming-soon">
          <h2><?php echo text($selectedTypeLabel); ?></h2>
          <p><?php echo xlt('This output type is coming soon. For now, use the existing OpenEMR print route.'); ?></p>
          <?php if ($selectedTypeExternal): ?>
            <a href="<?php echo attr($selectedTypeExternal); ?>" target="_self">
              <?php echo xlt('Open existing print route'); ?> →
            </a>
          <?php else: ?>
            <a href="<?php echo attr($buildUrl(['type' => 'demographics'])); ?>">
              <?php echo xlt('Back to demographics summary'); ?>
            </a>
          <?php endif; ?>
        </div>

      <?php endif; ?>

      </div>
    </div>
  </div>

</div>

</body>
</html>
