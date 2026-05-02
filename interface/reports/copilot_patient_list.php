<?php

/**
 * Patient List Report — Screen 43.
 *
 * Reports → Clinical → Patient List sub-page. Cross-patient cohort table
 * driven by 7 filter dropdowns (provider, age range, sex, visit window,
 * diagnosis, insurance, payer status), with a wide-table render of every
 * patient matching the filters and an HbA1c column color-coded by value.
 *
 * Sourcing.
 *   - patient_data pd
 *     LEFT JOIN insurance_data id (id.pid=pd.pid AND id.type='primary')
 *     LEFT JOIN insurance_companies ic (ic.id = id.provider)
 *     LEFT JOIN users u (u.id = pd.providerID)
 *   - LAST VISIT — subquery MAX(date) on form_encounter per pid.
 *   - DX — top primary medical_problem from `lists` (activity=1).
 *   - HBA1C — latest value via subquery preferring form_observation
 *     (code='4548-4'); falls back to procedure_result if available.
 *   - INSURANCE — insurance_companies.name (NULL-safe; empty seed shows '—').
 *
 * POST handlers (CSRF skipped — internal mock page):
 *   - action=save_view&name=… → store the current filter set as JSON in
 *     user_settings (label = 'cp_patient_list_view_<slug>'). POST/redirect/GET.
 *   - action=export_csv → stream a CSV of the filtered cohort and exit.
 *   - action=run        → POST/redirect/GET (no-op; refresh with filters).
 *
 * GET filters (all parameterized, never concatenated into SQL):
 *   provider=<users.id|all>
 *   age=18-39|40-59|60-74|75+|all
 *   age_min=<int>&age_max=<int>  (override; honored before `age`)
 *   sex=M|F|all
 *   visit=30d|90d|365d|all
 *   dx=<ICD-10 prefix, e.g. E11>|all
 *   insurance=<insurance_companies.id|all>
 *   payer=active|inactive|self_pay|all
 *   page=<int>
 *
 * The chrome (top nav) is rendered by the parent shell; this page renders
 * only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");
require_once(($GLOBALS['srcdir'] ?? (__DIR__ . "/../../library")) . "/user.inc.php");

// ──────────────────────────────────────────────────────────────────────
// 1. Filter parsing.
// ──────────────────────────────────────────────────────────────────────

/** Map the named "age" buckets to numeric [min,max] (max=null = open). */
$ageBuckets = [
    '18-39' => [18, 39],
    '40-59' => [40, 59],
    '60-74' => [60, 74],
    '75+'   => [75, null],
];

$validVisit = ['all', '30d', '90d', '365d'];
$validSex   = ['all', 'M', 'F'];
$validPayer = ['all', 'active', 'inactive', 'self_pay'];

// Filters travel as GET on normal page loads and as hidden POST fields on
// Save view / Export CSV / Run, so we read from $_REQUEST (which merges
// both, with POST taking precedence) and let the validators below decide
// what's actually safe to use.
$fProvider  = $_REQUEST['provider']  ?? 'all';
$fAge       = $_REQUEST['age']       ?? 'all';
$fAgeMin    = (isset($_REQUEST['age_min']) && $_REQUEST['age_min'] !== '') ? (int)$_REQUEST['age_min'] : null;
$fAgeMax    = (isset($_REQUEST['age_max']) && $_REQUEST['age_max'] !== '') ? (int)$_REQUEST['age_max'] : null;
$fSex       = $_REQUEST['sex']       ?? 'all';
$fVisit     = $_REQUEST['visit']     ?? 'all';
$fDx        = $_REQUEST['dx']        ?? 'all';
$fInsurance = $_REQUEST['insurance'] ?? 'all';
$fPayer     = $_REQUEST['payer']     ?? 'all';

// Normalize: only allow whitelist values for enum-style filters.
if (!in_array($fSex,   $validSex,   true)) { $fSex   = 'all'; }
if (!in_array($fVisit, $validVisit, true)) { $fVisit = 'all'; }
if (!in_array($fPayer, $validPayer, true)) { $fPayer = 'all'; }
if ($fAge !== 'all' && !isset($ageBuckets[$fAge])) { $fAge = 'all'; }
// Provider/Insurance/Dx are kept as raw strings here; we parameterize on use.

// Resolve effective age range.
$effAgeMin = null;
$effAgeMax = null;
if ($fAgeMin !== null || $fAgeMax !== null) {
    if ($fAgeMin !== null && $fAgeMin >= 0 && $fAgeMin <= 130) { $effAgeMin = $fAgeMin; }
    if ($fAgeMax !== null && $fAgeMax >= 0 && $fAgeMax <= 130) { $effAgeMax = $fAgeMax; }
} elseif ($fAge !== 'all') {
    [$effAgeMin, $effAgeMax] = $ageBuckets[$fAge];
}

// ──────────────────────────────────────────────────────────────────────
// 2. WHERE-clause builder. Returns [string $whereSql, array $bind].
//    Caller is responsible for prefixing patient_data as pd.
// ──────────────────────────────────────────────────────────────────────

$buildWhere = static function () use (
    $fProvider,
    $effAgeMin,
    $effAgeMax,
    $fSex,
    $fVisit,
    $fDx,
    $fInsurance,
    $fPayer
): array {
    $sql  = '1=1';
    $bind = [];

    // Provider — `pd.providerID` matches `users.id`.
    if ($fProvider !== 'all' && $fProvider !== '' && ctype_digit((string)$fProvider)) {
        $sql .= ' AND pd.providerID = ?';
        $bind[] = (int)$fProvider;
    }

    // Age — computed from DOB. Use TIMESTAMPDIFF so MariaDB does the math.
    if ($effAgeMin !== null) {
        $sql .= ' AND TIMESTAMPDIFF(YEAR, pd.DOB, CURDATE()) >= ?';
        $bind[] = $effAgeMin;
    }
    if ($effAgeMax !== null) {
        $sql .= ' AND TIMESTAMPDIFF(YEAR, pd.DOB, CURDATE()) <= ?';
        $bind[] = $effAgeMax;
    }

    // Sex — patient_data stores "Male"/"Female" (varchar). Match by prefix.
    if ($fSex === 'M') {
        $sql .= " AND pd.sex LIKE 'M%'";
    } elseif ($fSex === 'F') {
        $sql .= " AND pd.sex LIKE 'F%'";
    }

    // Visit window — relative to NOW().
    $visitDays = match ($fVisit) {
        '30d'  => 30,
        '90d'  => 90,
        '365d' => 365,
        default => null,
    };
    if ($visitDays !== null) {
        $sql .= ' AND EXISTS (
            SELECT 1 FROM form_encounter fe
             WHERE fe.pid = pd.pid AND fe.date >= NOW() - INTERVAL ? DAY
        )';
        $bind[] = $visitDays;
    }

    // Diagnosis — match ICD-10 prefix on `lists.diagnosis` (stored as "ICD10:E11.9").
    if ($fDx !== 'all' && $fDx !== '' && preg_match('/^[A-Za-z0-9.]{1,12}$/', (string)$fDx)) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM lists l
             WHERE l.pid = pd.pid AND l.type = 'medical_problem'
               AND l.activity = 1 AND l.diagnosis LIKE ?
        )";
        $bind[] = 'ICD10:' . $fDx . '%';
    }

    // Insurance company id — only joins when filter is set; otherwise rows
    // with no insurance still appear.
    if ($fInsurance !== 'all' && $fInsurance !== '' && ctype_digit((string)$fInsurance)) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM insurance_data id2
             WHERE id2.pid = pd.pid AND id2.type = 'primary' AND id2.provider = ?
        )";
        $bind[] = (int)$fInsurance;
    }

    // Payer status — derived from primary insurance presence/end-date.
    if ($fPayer === 'active') {
        $sql .= " AND EXISTS (
            SELECT 1 FROM insurance_data id3
             WHERE id3.pid = pd.pid AND id3.type = 'primary'
               AND (id3.date_end IS NULL OR id3.date_end >= CURDATE())
        )";
    } elseif ($fPayer === 'inactive') {
        $sql .= " AND EXISTS (
            SELECT 1 FROM insurance_data id3
             WHERE id3.pid = pd.pid AND id3.type = 'primary'
               AND id3.date_end IS NOT NULL AND id3.date_end < CURDATE()
        )";
    } elseif ($fPayer === 'self_pay') {
        $sql .= " AND NOT EXISTS (
            SELECT 1 FROM insurance_data id3
             WHERE id3.pid = pd.pid AND id3.type = 'primary'
        )";
    }

    return [$sql, $bind];
};

[$whereSql, $whereBind] = $buildWhere();

// ──────────────────────────────────────────────────────────────────────
// 3. Cohort query — composed once and reused for COUNT, page, and CSV.
// ──────────────────────────────────────────────────────────────────────

$cohortFromAndWhere = "
    FROM patient_data pd
    LEFT JOIN insurance_data id_ins
           ON id_ins.pid = pd.pid AND id_ins.type = 'primary'
    LEFT JOIN insurance_companies ic
           ON ic.id = id_ins.provider
    LEFT JOIN users u
           ON u.id = pd.providerID
   WHERE {$whereSql}
";

$cohortSelect = "
    SELECT pd.pid,
           pd.fname,
           pd.lname,
           pd.DOB,
           pd.sex,
           pd.pubpid,
           pd.providerID,
           u.fname    AS prov_fname,
           u.lname    AS prov_lname,
           u.username AS prov_username,
           u.title    AS prov_title,
           ic.name    AS insurance_name,
           id_ins.plan_name AS insurance_plan,
           id_ins.date_end  AS insurance_end,
           TIMESTAMPDIFF(YEAR, pd.DOB, CURDATE()) AS age,
           (
               SELECT MAX(fe.date)
                 FROM form_encounter fe
                WHERE fe.pid = pd.pid
           ) AS last_visit,
           (
               SELECT GROUP_CONCAT(DISTINCT SUBSTRING(l.diagnosis, 7) ORDER BY SUBSTRING(l.diagnosis, 7) ASC SEPARATOR ', ')
                 FROM lists l
                WHERE l.pid = pd.pid
                  AND l.type = 'medical_problem'
                  AND l.activity = 1
                  AND l.diagnosis LIKE 'ICD10:%'
           ) AS dx_list,
           (
               SELECT fo.ob_value
                 FROM form_observation fo
                WHERE fo.pid = pd.pid AND fo.code = '4548-4'
                ORDER BY fo.date DESC
                LIMIT 1
           ) AS hba1c_obs,
           (
               SELECT pr.result
                 FROM procedure_result pr
                 JOIN procedure_report prep ON prep.procedure_report_id = pr.procedure_report_id
                 JOIN procedure_order po    ON po.procedure_order_id   = prep.procedure_order_id
                WHERE po.patient_id = pd.pid AND pr.result_code = '4548-4'
                ORDER BY pr.date DESC
                LIMIT 1
           ) AS hba1c_pr
";

// ──────────────────────────────────────────────────────────────────────
// 4. POST handlers (POST/redirect/GET). CSRF skipped — internal mock page.
// ──────────────────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    $self   = $_SERVER['PHP_SELF'];

    // Echo current filters back into the redirect so the user lands on
    // the same view they posted from.
    $filterQuery = http_build_query(array_filter([
        'provider'  => $fProvider  !== 'all' ? $fProvider  : null,
        'age'       => $fAge       !== 'all' ? $fAge       : null,
        'age_min'   => $fAgeMin,
        'age_max'   => $fAgeMax,
        'sex'       => $fSex       !== 'all' ? $fSex       : null,
        'visit'     => $fVisit     !== 'all' ? $fVisit     : null,
        'dx'        => $fDx        !== 'all' ? $fDx        : null,
        'insurance' => $fInsurance !== 'all' ? $fInsurance : null,
        'payer'     => $fPayer     !== 'all' ? $fPayer     : null,
    ], static fn ($v) => $v !== null && $v !== ''));

    if ($action === 'save_view') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $name = 'View ' . date('m/d H:i');
        }
        // Truncate to fit the user_settings.setting_value column (varchar(255)).
        $payload = json_encode([
            'name'     => $name,
            'created'  => date('c'),
            'filters'  => [
                'provider'  => $fProvider,
                'age'       => $fAge,
                'age_min'   => $fAgeMin,
                'age_max'   => $fAgeMax,
                'sex'       => $fSex,
                'visit'     => $fVisit,
                'dx'        => $fDx,
                'insurance' => $fInsurance,
                'payer'     => $fPayer,
            ],
        ]);
        if ($payload !== false) {
            // Slug from the name + timestamp keeps multiple saves distinct.
            $slug  = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name)) ?: 'view';
            $label = 'cp_patient_list_view_' . substr($slug, 0, 60) . '_' . time();
            try {
                $authUser = (int)($_SESSION['authUserID'] ?? 0);
                setUserSetting($label, substr($payload, 0, 255), $authUser, false, true);
                $msg = 'view_saved';
            } catch (\Throwable $e) {
                $msg = 'view_save_failed';
            }
        } else {
            $msg = 'view_save_failed';
        }
        $sep = $filterQuery === '' ? '' : '&';
        header('Location: ' . $self . '?msg=' . $msg . $sep . $filterQuery);
        exit;
    }

    if ($action === 'export_csv') {
        // Stream the (filtered) cohort as CSV. No pagination — full set.
        $rs = sqlStatement($cohortSelect . $cohortFromAndWhere . ' ORDER BY pd.lname, pd.fname', $whereBind);
        $filename = 'patient-list-' . date('Y-m-d-His') . '.csv';
        // Avoid header() warnings if anything earlier emitted output.
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');
        }
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'MRN', 'DOB', 'Age', 'Sex', 'Last Visit', 'Provider', 'Insurance', 'Diagnoses', 'HbA1c']);
        while ($r = sqlFetchArray($rs)) {
            $name   = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''));
            $dob    = !empty($r['DOB']) ? date('m/d/Y', strtotime((string)$r['DOB'])) : '';
            $age    = $r['age'] !== null ? (string)$r['age'] : '';
            $sex    = (string)($r['sex'] ?? '');
            $last   = !empty($r['last_visit']) ? date('m/d/Y', strtotime((string)$r['last_visit'])) : '';
            $prov   = cp_format_provider_name([
                'fname'    => (string)($r['prov_fname']    ?? ''),
                'lname'    => (string)($r['prov_lname']    ?? ''),
                'username' => (string)($r['prov_username'] ?? ''),
                'title'    => (string)($r['prov_title']    ?? ''),
            ]);
            $ins    = (string)($r['insurance_name'] ?? '');
            $dx     = (string)($r['dx_list'] ?? '');
            $hba1c  = (string)($r['hba1c_obs'] ?? $r['hba1c_pr'] ?? '');
            fputcsv($out, [$name, (string)($r['pubpid'] ?? ''), $dob, $age, $sex, $last, $prov, $ins, $dx, $hba1c]);
        }
        fclose($out);
        exit;
    }

    if ($action === 'run') {
        // "Run" is a no-op refresh — the canonical version of the URL is
        // the GET we redirect to.
        header('Location: ' . $self . ($filterQuery === '' ? '' : '?' . $filterQuery));
        exit;
    }
}

// ──────────────────────────────────────────────────────────────────────
// 5. Flash + count + paginated rows.
// ──────────────────────────────────────────────────────────────────────

$flashRaw = $_GET['msg'] ?? null;
$flashMessages = [
    'view_saved'       => 'View saved.',
    'view_save_failed' => 'Could not save view (please try again).',
];
$flashText = null;
if ($flashRaw !== null) {
    $flashText = $flashMessages[$flashRaw] ?? null;
}

$totalRow = sqlQuery(
    "SELECT COUNT(DISTINCT pd.pid) AS c {$cohortFromAndWhere}",
    $whereBind
);
$totalPatients = (int)($totalRow['c'] ?? 0);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;
$totalPages = (int)max(1, ceil($totalPatients / $perPage));
if ($page > $totalPages) {
    $page   = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$rows = [];
$rs = sqlStatement(
    $cohortSelect . $cohortFromAndWhere
        . " ORDER BY pd.lname, pd.fname"
        . " LIMIT {$perPage} OFFSET {$offset}",
    $whereBind
);
while ($r = sqlFetchArray($rs)) {
    $rows[] = $r;
}

// "Last refreshed" — first time this session, store the timestamp.
if (empty($_SESSION['cp_patient_list_loaded_at'])) {
    $_SESSION['cp_patient_list_loaded_at'] = time();
}
$lastRefreshDelta = time() - (int)$_SESSION['cp_patient_list_loaded_at'];
if ($lastRefreshDelta < 60) {
    $lastRefreshLabel = 'just now';
} elseif ($lastRefreshDelta < 3600) {
    $lastRefreshLabel = (int)floor($lastRefreshDelta / 60) . ' min ago';
} else {
    $lastRefreshLabel = date('H:i');
}

// ──────────────────────────────────────────────────────────────────────
// 6. Dropdown option lists. All parameterized — driven off real data.
// ──────────────────────────────────────────────────────────────────────

$providerOptions = [];
$rsP = sqlStatement(
    "SELECT u.id, u.fname, u.lname, u.username, u.title
       FROM users u
      WHERE u.active = 1
        AND (u.authorized = 1 OR u.title IN ('MD','DO','NP','PA','DDS','DMD','DPM','DC','OD','PHD'))
      ORDER BY u.lname, u.fname"
);
while ($p = sqlFetchArray($rsP)) {
    $providerOptions[] = [
        'id'    => (int)$p['id'],
        'label' => cp_format_provider_name($p),
    ];
}

$insuranceOptions = [];
$rsI = sqlStatement(
    "SELECT id, name FROM insurance_companies WHERE inactive = 0 ORDER BY name"
);
while ($i = sqlFetchArray($rsI)) {
    $insuranceOptions[] = ['id' => (int)$i['id'], 'label' => (string)$i['name']];
}

// Diagnosis options — derived from distinct ICD-10 prefixes the cohort
// actually has. Sorted by frequency so common ones surface first.
$dxOptions = [];
$rsDx = sqlStatement(
    "SELECT SUBSTRING(diagnosis, 7, 3) AS dx_prefix, COUNT(*) AS c
       FROM lists
      WHERE type = 'medical_problem' AND activity = 1
        AND diagnosis LIKE 'ICD10:%'
      GROUP BY dx_prefix
      ORDER BY c DESC, dx_prefix ASC
      LIMIT 30"
);
while ($d = sqlFetchArray($rsDx)) {
    $dx = trim((string)($d['dx_prefix'] ?? ''));
    if ($dx !== '') {
        $dxOptions[] = ['code' => $dx, 'label' => $dx];
    }
}

// ──────────────────────────────────────────────────────────────────────
// 7. Display helpers (HTML-side).
// ──────────────────────────────────────────────────────────────────────

$hba1cTone = static function (?string $val): string {
    if ($val === null || $val === '') { return ''; }
    $v = (float)$val;
    if ($v >= 9.0) { return 'danger'; }
    if ($v >= 7.0) { return 'warn'; }
    return '';
};

$shortDx = static function (?string $dxList): string {
    if ($dxList === null || $dxList === '') { return '—'; }
    $parts = array_map('trim', explode(',', $dxList));
    $parts = array_values(array_unique(array_filter($parts)));
    // Show up to first 3 codes to keep the column tight.
    return implode(', ', array_slice($parts, 0, 3));
};

$sidebar = [
    'CLINICAL' => [
        ['Patient List',          true],
        ['Prescriptions',         false],
        ['Lab Trends',            false],
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

// Hidden filter fields — used by Save view / Export CSV / Run forms so
// each POST captures the user's current view.
$hiddenFilters = [
    'provider'  => $fProvider,
    'age'       => $fAge,
    'age_min'   => $fAgeMin === null ? '' : (string)$fAgeMin,
    'age_max'   => $fAgeMax === null ? '' : (string)$fAgeMax,
    'sex'       => $fSex,
    'visit'     => $fVisit,
    'dx'        => $fDx,
    'insurance' => $fInsurance,
    'payer'     => $fPayer,
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient List'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }

  /* Reports left sidebar (matches Screen 39) */
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

  /* Filter row of dropdowns */
  .cp-filter-block {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 16px 16px;
  }
  .cp-filter-block .ftitle {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    margin-bottom: 10px;
  }
  .cp-filter-block .frow {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 10px;
  }
  .cp-fdrop {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 7px 28px 8px 12px;
    position: relative;
    display: flex; flex-direction: column; gap: 2px;
    min-width: 0;
  }
  .cp-fdrop .lbl { font-size: 10px; color: #8A91A1; font-weight: 500; line-height: 1.2; }
  .cp-fdrop .val {
    font-size: 12px; color: #0D1B2A; font-weight: 500; line-height: 1.3;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .cp-fdrop::after {
    content: '';
    position: absolute;
    right: 12px; top: 50%;
    width: 0; height: 0;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
    border-top: 5px solid #8A91A1;
    margin-top: -2px;
    pointer-events: none;
  }
  /* Native select sits invisibly above the styled chrome so it actually
     opens the OS dropdown when clicked. */
  .cp-fdrop select.cp-fnative {
    position: absolute;
    inset: 0;
    width: 100%; height: 100%;
    opacity: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
    appearance: none;
  }

  /* Table tweaks */
  .cp-tbl table { font-size: 12px; }
  .cp-tbl th { padding: 12px 14px; font-size: 10px; }
  .cp-tbl td { padding: 12px 14px; }
  .cp-tbl th.chk, .cp-tbl td.chk {
    width: 28px; padding-left: 16px; padding-right: 6px;
  }
  .cp-tbl td.chk input {
    width: 13px; height: 13px; margin: 0;
    accent-color: #008C8C;
  }
  .cp-tbl td.act {
    width: 32px; padding-left: 6px; padding-right: 14px;
    text-align: center; color: #8A91A1;
  }
  .cp-tbl td.hba1c { font-weight: 600; }
  .cp-tbl td.hba1c.warn   { color: #FA8C33; }
  .cp-tbl td.hba1c.danger { color: #D93838; }
  .cp-tbl td.hba1c .arr { margin-left: 2px; font-size: 11px; }

  .cp-pagehead form.inline { display: inline-flex; margin: 0; gap: 8px; align-items: center; }
  .cp-pagehead .saveview-name {
    font-size: 12px;
    border: 1px solid #E4E5E8;
    border-radius: 6px;
    padding: 5px 8px;
    color: #0D1B2A;
    width: 130px;
  }

  .cp-flash {
    margin: 8px 24px 0;
    padding: 8px 12px;
    background: #EBF8F0;
    color: #1F8C4D;
    border: 1px solid #C6E8D2;
    border-radius: 6px;
    font-size: 12px;
  }
  .cp-flash.err { background: #FCE7E7; color: #D93838; border-color: #F5C6C6; }

  .cp-pager {
    display: flex; gap: 8px; justify-content: flex-end;
    padding: 12px 4px 0;
    font-size: 12px;
    color: #4F5763;
  }
  .cp-pager a, .cp-pager span {
    padding: 4px 10px; border: 1px solid #E4E5E8; border-radius: 6px;
    background: #FFFFFF; color: #4F5763; text-decoration: none;
  }
  .cp-pager a:hover { background: #F5F6F7; color: #0D1B2A; }
  .cp-pager .current { background: #008C8C; color: #FFFFFF; border-color: #008C8C; }
  .cp-pager .disabled { opacity: 0.4; }
</style>
</head>
<body class="cp-arch">

<div class="cp-shell" style="flex-direction:column;">
    <header class="cp-pagehead">
      <div class="info">
        <div style="display:flex; align-items:center; gap:8px;">
          <span class="title"><?php echo xlt('Patient List'); ?></span>
          <span class="dot">·</span>
          <span class="meta-light">
            <?php
              $headerLine = sprintf(
                  '%s %s - last refreshed %s',
                  number_format($totalPatients),
                  $totalPatients === 1 ? xl('patient matching filters') : xl('patients matching filters'),
                  $lastRefreshLabel
              );
              echo text($headerLine);
            ?>
          </span>
        </div>
      </div>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="save_view">
        <?php foreach ($hiddenFilters as $k => $v): ?>
          <input type="hidden" name="<?php echo attr($k); ?>" value="<?php echo attr($v); ?>">
        <?php endforeach; ?>
        <input type="text" name="name" class="saveview-name"
               placeholder="<?php echo xla('View name'); ?>"
               value="<?php echo attr('View ' . date('m/d H:i')); ?>">
        <button type="submit" class="cp-btn ghost">⊞ <?php echo xlt('Save view'); ?></button>
      </form>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="export_csv">
        <?php foreach ($hiddenFilters as $k => $v): ?>
          <input type="hidden" name="<?php echo attr($k); ?>" value="<?php echo attr($v); ?>">
        <?php endforeach; ?>
        <button type="submit" class="cp-btn ghost">⤓ <?php echo xlt('Export CSV'); ?></button>
      </form>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="run">
        <?php foreach ($hiddenFilters as $k => $v): ?>
          <input type="hidden" name="<?php echo attr($k); ?>" value="<?php echo attr($v); ?>">
        <?php endforeach; ?>
        <button type="submit" class="cp-btn primary"><?php echo xlt('Run'); ?></button>
      </form>
    </header>

    <?php if ($flashText !== null): ?>
      <div class="cp-flash<?php echo $flashRaw === 'view_save_failed' ? ' err' : ''; ?>">
        <?php echo text($flashText); ?>
      </div>
    <?php endif; ?>

    <main class="cp-content tight">

      <form method="get" id="cp-filter-form">
        <div class="cp-filter-block">
          <div class="ftitle"><?php echo xlt('FILTERS'); ?></div>
          <div class="frow">

            <!-- Provider -->
            <label class="cp-fdrop">
              <span class="lbl"><?php echo xlt('Provider'); ?></span>
              <span class="val">
                <?php
                  if ($fProvider === 'all' || $fProvider === '') {
                      echo text(xl('All providers'));
                  } else {
                      $match = null;
                      foreach ($providerOptions as $p) {
                          if ((int)$p['id'] === (int)$fProvider) { $match = $p; break; }
                      }
                      echo text($match['label'] ?? ('Provider #' . (int)$fProvider));
                  }
                ?>
              </span>
              <select name="provider" class="cp-fnative" onchange="this.form.submit()">
                <option value="all"<?php echo $fProvider === 'all' ? ' selected' : ''; ?>><?php echo xlt('All providers'); ?></option>
                <?php foreach ($providerOptions as $p): ?>
                  <option value="<?php echo attr((string)$p['id']); ?>"
                    <?php echo ((string)$p['id'] === (string)$fProvider) ? ' selected' : ''; ?>>
                    <?php echo text($p['label']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <!-- Age range -->
            <label class="cp-fdrop">
              <span class="lbl"><?php echo xlt('Age range'); ?></span>
              <span class="val">
                <?php
                  if ($fAge === 'all' && $fAgeMin === null && $fAgeMax === null) {
                      echo text(xl('All ages'));
                  } elseif ($fAge !== 'all') {
                      echo text($fAge . ' ' . xl('years'));
                  } else {
                      $loLbl = $effAgeMin !== null ? (string)$effAgeMin : '0';
                      $hiLbl = $effAgeMax !== null ? (string)$effAgeMax : '+';
                      echo text($loLbl . '-' . $hiLbl . ' ' . xl('years'));
                  }
                ?>
              </span>
              <select name="age" class="cp-fnative" onchange="this.form.submit()">
                <option value="all"<?php echo $fAge === 'all' ? ' selected' : ''; ?>><?php echo xlt('All ages'); ?></option>
                <?php foreach (array_keys($ageBuckets) as $key): ?>
                  <option value="<?php echo attr($key); ?>"<?php echo $fAge === $key ? ' selected' : ''; ?>><?php echo text($key . ' yrs'); ?></option>
                <?php endforeach; ?>
              </select>
              <!-- preserve overrides if set -->
              <?php if ($fAgeMin !== null): ?><input type="hidden" name="age_min" value="<?php echo attr((string)$fAgeMin); ?>"><?php endif; ?>
              <?php if ($fAgeMax !== null): ?><input type="hidden" name="age_max" value="<?php echo attr((string)$fAgeMax); ?>"><?php endif; ?>
            </label>

            <!-- Sex -->
            <label class="cp-fdrop">
              <span class="lbl"><?php echo xlt('Sex'); ?></span>
              <span class="val">
                <?php
                  echo text(match ($fSex) {
                      'M' => xl('Male'),
                      'F' => xl('Female'),
                      default => xl('All'),
                  });
                ?>
              </span>
              <select name="sex" class="cp-fnative" onchange="this.form.submit()">
                <option value="all"<?php echo $fSex === 'all' ? ' selected' : ''; ?>><?php echo xlt('All'); ?></option>
                <option value="F"<?php echo $fSex === 'F' ? ' selected' : ''; ?>><?php echo xlt('Female'); ?></option>
                <option value="M"<?php echo $fSex === 'M' ? ' selected' : ''; ?>><?php echo xlt('Male'); ?></option>
              </select>
            </label>

            <!-- Visit window -->
            <label class="cp-fdrop">
              <span class="lbl"><?php echo xlt('Visit window'); ?></span>
              <span class="val">
                <?php
                  echo text(match ($fVisit) {
                      '30d'  => xl('Last 30 days'),
                      '90d'  => xl('Last 90 days'),
                      '365d' => xl('Last 12 months'),
                      default => xl('Any time'),
                  });
                ?>
              </span>
              <select name="visit" class="cp-fnative" onchange="this.form.submit()">
                <option value="all"<?php echo $fVisit === 'all'  ? ' selected' : ''; ?>><?php echo xlt('Any time'); ?></option>
                <option value="30d"<?php echo $fVisit === '30d'  ? ' selected' : ''; ?>><?php echo xlt('Last 30 days'); ?></option>
                <option value="90d"<?php echo $fVisit === '90d'  ? ' selected' : ''; ?>><?php echo xlt('Last 90 days'); ?></option>
                <option value="365d"<?php echo $fVisit === '365d' ? ' selected' : ''; ?>><?php echo xlt('Last 12 months'); ?></option>
              </select>
            </label>

            <!-- Diagnosis -->
            <label class="cp-fdrop">
              <span class="lbl"><?php echo xlt('Diagnosis'); ?></span>
              <span class="val">
                <?php
                  echo text($fDx === 'all' || $fDx === '' ? xl('All diagnoses') : ('ICD-10 ' . $fDx));
                ?>
              </span>
              <select name="dx" class="cp-fnative" onchange="this.form.submit()">
                <option value="all"<?php echo $fDx === 'all' ? ' selected' : ''; ?>><?php echo xlt('All diagnoses'); ?></option>
                <?php foreach ($dxOptions as $d): ?>
                  <option value="<?php echo attr((string)$d['code']); ?>"
                    <?php echo $fDx === $d['code'] ? ' selected' : ''; ?>><?php echo text($d['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <!-- Insurance -->
            <label class="cp-fdrop">
              <span class="lbl"><?php echo xlt('Insurance'); ?></span>
              <span class="val">
                <?php
                  if ($fInsurance === 'all' || $fInsurance === '') {
                      echo text(xl('All payers'));
                  } else {
                      $imatch = null;
                      foreach ($insuranceOptions as $i) {
                          if ((int)$i['id'] === (int)$fInsurance) { $imatch = $i; break; }
                      }
                      echo text($imatch['label'] ?? ('Insurance #' . (int)$fInsurance));
                  }
                ?>
              </span>
              <select name="insurance" class="cp-fnative" onchange="this.form.submit()">
                <option value="all"<?php echo $fInsurance === 'all' ? ' selected' : ''; ?>><?php echo xlt('All payers'); ?></option>
                <?php foreach ($insuranceOptions as $i): ?>
                  <option value="<?php echo attr((string)$i['id']); ?>"
                    <?php echo ((string)$i['id'] === (string)$fInsurance) ? ' selected' : ''; ?>>
                    <?php echo text($i['label']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <!-- Payer status -->
            <label class="cp-fdrop">
              <span class="lbl"><?php echo xlt('Payer status'); ?></span>
              <span class="val">
                <?php
                  echo text(match ($fPayer) {
                      'active'   => xl('Active'),
                      'inactive' => xl('Inactive'),
                      'self_pay' => xl('Self-pay'),
                      default    => xl('Any'),
                  });
                ?>
              </span>
              <select name="payer" class="cp-fnative" onchange="this.form.submit()">
                <option value="all"<?php echo $fPayer === 'all'      ? ' selected' : ''; ?>><?php echo xlt('Any'); ?></option>
                <option value="active"<?php echo $fPayer === 'active'   ? ' selected' : ''; ?>><?php echo xlt('Active'); ?></option>
                <option value="inactive"<?php echo $fPayer === 'inactive' ? ' selected' : ''; ?>><?php echo xlt('Inactive'); ?></option>
                <option value="self_pay"<?php echo $fPayer === 'self_pay' ? ' selected' : ''; ?>><?php echo xlt('Self-pay'); ?></option>
              </select>
            </label>

          </div>
        </div>
      </form>

      <div class="cp-tbl">
        <table>
          <thead>
            <tr>
              <th class="chk"></th>
              <th><?php echo xlt('NAME'); ?></th>
              <th><?php echo xlt('MRN'); ?></th>
              <th><?php echo xlt('DOB'); ?></th>
              <th><?php echo xlt('AGE'); ?></th>
              <th><?php echo xlt('SEX'); ?></th>
              <th><?php echo xlt('LAST VISIT'); ?></th>
              <th><?php echo xlt('PROVIDER'); ?></th>
              <th><?php echo xlt('INSURANCE'); ?></th>
              <th><?php echo xlt('DX'); ?></th>
              <th><?php echo xlt('HBA1C'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="12" class="muted" style="text-align:center; padding:24px;">
                <?php echo xlt('No patients match the current filters.'); ?>
              </td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $name = trim((string)($r['fname'] ?? '') . ' ' . (string)($r['lname'] ?? ''));
                  $dob  = !empty($r['DOB']) ? date('m/d/Y', strtotime((string)$r['DOB'])) : '—';
                  $age  = $r['age'] !== null ? (string)$r['age'] : '—';
                  $sex  = (string)($r['sex'] ?? '');
                  $sexShort = $sex !== '' ? strtoupper($sex[0]) : '—';
                  $last = !empty($r['last_visit']) ? date('m/d/Y', strtotime((string)$r['last_visit'])) : '—';
                  $prov = cp_format_provider_name([
                      'fname'    => (string)($r['prov_fname']    ?? ''),
                      'lname'    => (string)($r['prov_lname']    ?? ''),
                      'username' => (string)($r['prov_username'] ?? ''),
                      'title'    => (string)($r['prov_title']    ?? ''),
                  ]);
                  if (empty($r['providerID'])) { $prov = '—'; }
                  $ins = $r['insurance_name'] ?? null;
                  if ($ins === null || $ins === '') { $ins = 'Self-pay'; }
                  $dx  = $shortDx((string)($r['dx_list'] ?? ''));
                  $hba1c = $r['hba1c_obs'] ?? $r['hba1c_pr'] ?? null;
                  $tone  = $hba1cTone($hba1c === null ? null : (string)$hba1c);
                ?>
                <tr>
                  <td class="chk"><input type="checkbox"></td>
                  <td class="bold"><?php echo text($name !== '' ? $name : '—'); ?></td>
                  <td class="muted">#<?php echo text((string)($r['pubpid'] ?? $r['pid'])); ?></td>
                  <td class="muted"><?php echo text($dob); ?></td>
                  <td class="muted"><?php echo text($age); ?></td>
                  <td class="muted"><?php echo text($sexShort); ?></td>
                  <td class="muted"><?php echo text($last); ?></td>
                  <td class="muted"><?php echo text($prov); ?></td>
                  <td class="muted"><?php echo text((string)$ins); ?></td>
                  <td class="muted"><?php echo text($dx); ?></td>
                  <td class="hba1c <?php echo attr($tone); ?>">
                    <?php if ($hba1c === null || $hba1c === ''): ?>
                      —
                    <?php else: ?>
                      <?php echo text((string)$hba1c); ?><?php if ($tone !== ''): ?><span class="arr">↑</span><?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td class="muted"></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPatients > $perPage): ?>
        <?php
          $baseQuery = $_GET;
          unset($baseQuery['page']);
          $qs = http_build_query($baseQuery);
          $href = static fn (int $n): string => $_SERVER['PHP_SELF'] . '?' . ($qs === '' ? '' : $qs . '&') . 'page=' . $n;
        ?>
        <div class="cp-pager">
          <?php if ($page > 1): ?>
            <a href="<?php echo attr($href($page - 1)); ?>">‹ <?php echo xlt('Prev'); ?></a>
          <?php else: ?>
            <span class="disabled">‹ <?php echo xlt('Prev'); ?></span>
          <?php endif; ?>
          <span class="current">
            <?php echo text(sprintf('%d / %d', $page, $totalPages)); ?>
          </span>
          <?php if ($page < $totalPages): ?>
            <a href="<?php echo attr($href($page + 1)); ?>"><?php echo xlt('Next'); ?> ›</a>
          <?php else: ?>
            <span class="disabled"><?php echo xlt('Next'); ?> ›</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </main>
</div>

</body>
</html>
