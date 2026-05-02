<?php

/**
 * Authorizations — Screen 34.
 *
 * Insurance authorization queue. Patient-scoped page (active patient
 * comes from $_SESSION['pid']) but it shows the org-wide auth queue
 * because the mock surfaces other patients in the same view.
 *
 * Backing store. OpenEMR has no first-class "prior authorization" table.
 * `transactions` is dormant in this dataset, `form_misc_billing_options`
 * has a `prior_auth_number` column but is also empty, and `form_encounter`
 * has only an `authorized` flag. Rather than overload one of those tables
 * we keep authorization records in a small custom table:
 *     cp_authorizations(id, auth_number, pid, payer, service, cpt, status,
 *                       requested_date, decision_date, due_date, provider_id,
 *                       expedited, notes, created_at)
 *
 * The table is created idempotently the first time the page loads
 * (CREATE TABLE IF NOT EXISTS), and seeded ONCE — guarded by a marker
 * row in `globals` (gl_name='cp_authorizations_seed_v1') so seeded data
 * is immutable across reloads. Edits / inserts that happen at runtime
 * are kept; the seeder never runs again.
 *
 * The chrome (top nav, demographics banner, navtab strip) is rendered
 * by the parent shell — this page renders only the body.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../../main/copilot_helpers.php");

// Patient context (legacy session — unavoidable in /interface/).
$pid = (int)($_SESSION['pid'] ?? 1);

// ──────────────────────────────────────────────────────────────────────
// 1. Backing table + idempotent seed.
// ──────────────────────────────────────────────────────────────────────

sqlStatement(
    "CREATE TABLE IF NOT EXISTS cp_authorizations (
        id              BIGINT NOT NULL AUTO_INCREMENT,
        auth_number     VARCHAR(32) NOT NULL,
        pid             BIGINT NOT NULL,
        payer           VARCHAR(128) NOT NULL DEFAULT '',
        service         VARCHAR(255) NOT NULL DEFAULT '',
        cpt             VARCHAR(16)  NOT NULL DEFAULT '',
        status          VARCHAR(32)  NOT NULL DEFAULT 'draft',
        requested_date  DATE NULL,
        decision_date   DATE NULL,
        due_date        DATE NULL,
        provider_id     INT NULL,
        expedited       TINYINT(1) NOT NULL DEFAULT 0,
        notes           TEXT NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY ux_cp_auth_number (auth_number),
        KEY ix_cp_auth_pid (pid),
        KEY ix_cp_auth_status (status),
        KEY ix_cp_auth_requested (requested_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$seedMarker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'cp_authorizations_seed_v1'");
if (empty($seedMarker['gl_value'])) {
    // Pull a few real provider IDs / patient IDs from the DB so the
    // seeded rows reference real foreign keys.
    $providerIdByUsername = [];
    $r = sqlStatement("SELECT id, username FROM users WHERE authorized = 1 AND active = 1");
    while ($row = sqlFetchArray($r)) {
        $providerIdByUsername[$row['username']] = (int)$row['id'];
    }
    $pickProvider = static function (string $preferred) use ($providerIdByUsername): ?int {
        if (isset($providerIdByUsername[$preferred])) {
            return $providerIdByUsername[$preferred];
        }
        // Fallback: first authorized provider if the named one is missing.
        return $providerIdByUsername ? (int)reset($providerIdByUsername) : null;
    };

    // Existing patients to anchor seeded auth rows. Keep `pid` first so
    // the active-patient row always exists.
    $patientPids = [];
    $r = sqlStatement("SELECT pid FROM patient_data ORDER BY pid LIMIT 14");
    while ($row = sqlFetchArray($r)) {
        $patientPids[] = (int)$row['pid'];
    }
    if (!in_array($pid, $patientPids, true)) {
        array_unshift($patientPids, $pid);
    }
    $pickPid = static function (int $idx) use ($patientPids): int {
        if (!$patientPids) {
            return 1;
        }
        return $patientPids[$idx % count($patientPids)];
    };

    // Authoritative seed list. Dates are anchored to "now" so the page
    // looks alive whenever the demo is run.
    $today = new \DateTimeImmutable('today');
    $daysAgo = static fn (int $n): string => $today->modify("-{$n} days")->format('Y-m-d');
    $daysAhead = static fn (int $n): string => $today->modify("+{$n} days")->format('Y-m-d');

    // [auth#, patient_idx, payer, service, cpt, status,
    //  requested_offset, decision_offset_or_null, due_offset, provider_user, expedited]
    $seed = [
        ['AU-2891', 0,  'Blue Cross PPO', 'Echocardiogram (cardiology)',  '93306', 'approved',       4,  2,  10, 'erivera', 0],
        ['AU-2890', 1,  'Aetna',          'Colonoscopy + biopsy (GI)',    '45380', 'pending',        4,  null, 13, 'kkim',  0],
        ['AU-2889', 2,  'Cigna',          'MRI Lumbar w/o contrast',      '72148', 'denied',         5,  3,  2,  'kkim',    0],
        ['AU-2888', 3,  'Medicare',       'Sleep study (polysomnogram)',  '95810', 'pending',        5,  null, 8,  'jpatel', 1],
        ['AU-2887', 4,  'Blue Cross PPO', 'PT — 12 sessions (knee)',      '97110', 'approved',       6,  4,  6,  'kkim',    0],
        ['AU-2886', 5,  'Humana',         'Mammogram (screening)',        '77067', 'approved',       6,  3,  4,  'erivera', 0],
        ['AU-2885', 6,  'Aetna',          'CT Chest w/ contrast',         '71260', 'pending',        7,  null, 0,  'jpatel', 1],
        ['AU-2884', 7,  'UnitedHealth',   'Endoscopy (EGD)',              '43235', 'approved',       7,  5,  3,  'kkim',    0],
        ['AU-2883', 8,  'Blue Cross PPO', 'Bone scan',                    '78306', 'denied',         8,  6,  -1, 'jpatel',  0],
        ['AU-2882', 9,  'Cigna',          'MRI Brain w/ contrast',        '70553', 'approved',       8,  6,  -2, 'erivera', 0],
        ['AU-2881', 10, 'Medicare',       'Stress test (nuclear)',        '78452', 'pending',        9,  null, 1,  'kkim',  0],
        ['AU-2880', 11, 'UnitedHealth',   'PT — 8 sessions (shoulder)',   '97110', 'approved',       10, 7,  -2, 'jpatel', 0],
        ['AU-2879', 12, 'Aetna',          'Allergy testing panel',        '95004', 'withdrawn',      10, 8,  -3, 'jpatel', 0],
        ['AU-2878', 13, 'Blue Cross PPO', 'Knee arthroscopy',             '29881', 'submitted',      0,  null, 14, 'kkim',  0],
        ['AU-2877', 0,  'Aetna',          'CT Abdomen w/ contrast',       '74160', 'submitted',      0,  null, 7,  'erivera', 0],
    ];
    $insertSql = "INSERT INTO cp_authorizations
        (auth_number, pid, payer, service, cpt, status, requested_date, decision_date, due_date, provider_id, expedited)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    foreach ($seed as $row) {
        [$num, $idx, $payer, $svc, $cpt, $status, $reqOff, $decOff, $dueOff, $provUser, $expedited] = $row;
        $providerId = $pickProvider($provUser);
        $seedPid = $pickPid($idx);
        $reqDate = $daysAgo($reqOff);
        $decDate = $decOff === null ? null : $daysAgo($decOff);
        $dueDate = $dueOff >= 0 ? $daysAhead($dueOff) : $daysAgo(-$dueOff);
        try {
            sqlStatement($insertSql, [
                $num, $seedPid, $payer, $svc, $cpt, $status,
                $reqDate, $decDate, $dueDate, $providerId, $expedited,
            ]);
        } catch (\Throwable $e) {
            // Race or duplicate — skip silently; the marker below ensures
            // we don't try again on the next request.
        }
    }
    sqlStatement(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('cp_authorizations_seed_v1', 0, ?)",
        [date('c')]
    );
}

// ──────────────────────────────────────────────────────────────────────
// 2. POST handlers (POST/redirect/GET). CSRF skipped — internal mock page.
// ──────────────────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'new_auth') {
        // Generate the next AU-#### number from the max existing.
        $maxRow = sqlQuery(
            "SELECT MAX(CAST(SUBSTRING(auth_number, 4) AS UNSIGNED)) AS mx FROM cp_authorizations WHERE auth_number LIKE 'AU-%'"
        );
        $next = (int)($maxRow['mx'] ?? 0) + 1;
        if ($next < 2900) {
            $next = 2900;
        }
        $authNumber = 'AU-' . $next;
        $newId = sqlInsert(
            "INSERT INTO cp_authorizations (auth_number, pid, payer, service, cpt, status, requested_date, due_date, provider_id, notes)
             VALUES (?, ?, '', '', '', 'draft', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), NULL, ?)",
            [$authNumber, $pid, 'Created from Authorizations queue']
        );
        // Audit trail — also writes to extended_log for traceability.
        sqlStatement(
            "INSERT INTO extended_log (date, event, user, recipient, description, patient_id) VALUES (NOW(), 'auth_create', ?, '', ?, ?)",
            [$_SESSION['authUser'] ?? 'system', "New authorization created: {$authNumber}", $pid]
        );
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . (int)$newId . '&msg=' . urlencode("Created {$authNumber} (draft)"));
        exit;
    }

    if ($action === 'export_csv') {
        // Build the same filtered list the page would render, but stream
        // it as CSV instead of HTML. Filter values come from POST so the
        // form posts the current filter state.
        $filterStatus  = $_POST['status']   ?? 'all';
        $filterPayer   = $_POST['payer']    ?? 'all';
        $filterService = $_POST['service']  ?? 'all';
        $filterProv    = $_POST['provider'] ?? 'all';
        $filterRange   = $_POST['range']    ?? 'last30';
        $q             = trim((string)($_POST['q'] ?? ''));

        $whereParts = ['1=1'];
        $params = [];
        $statusEnum = ['pending', 'approved', 'denied', 'withdrawn', 'submitted', 'draft'];
        if (in_array($filterStatus, $statusEnum, true)) {
            $whereParts[] = 'a.status = ?';
            $params[] = $filterStatus;
        }
        if ($filterPayer !== 'all' && $filterPayer !== '') {
            $whereParts[] = 'a.payer = ?';
            $params[] = $filterPayer;
        }
        if ($filterService !== 'all' && $filterService !== '') {
            $whereParts[] = 'a.service LIKE ?';
            $params[] = '%' . $filterService . '%';
        }
        if ($filterProv !== 'all' && $filterProv !== '' && ctype_digit($filterProv)) {
            $whereParts[] = 'a.provider_id = ?';
            $params[] = (int)$filterProv;
        }
        $rangeMap = ['last7' => 7, 'last30' => 30, 'last90' => 90, 'all' => null];
        $rangeDays = $rangeMap[$filterRange] ?? 30;
        if ($rangeDays !== null) {
            $whereParts[] = 'a.requested_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
            $params[] = $rangeDays;
        }
        if ($q !== '') {
            $whereParts[] = '(a.auth_number LIKE ? OR pd.fname LIKE ? OR pd.lname LIKE ? OR CONCAT(pd.fname, " ", pd.lname) LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $where = implode(' AND ', $whereParts);
        $sql = "SELECT a.auth_number, pd.fname, pd.lname, a.payer, a.service, a.cpt,
                       a.requested_date, a.due_date, a.decision_date, a.status,
                       u.fname AS pfname, u.lname AS plname, u.title AS ptitle, u.username AS pusername
                  FROM cp_authorizations a
             LEFT JOIN patient_data pd ON pd.pid = a.pid
             LEFT JOIN users u ON u.id = a.provider_id
                 WHERE $where
              ORDER BY a.requested_date DESC, a.id DESC";
        $rs = sqlStatement($sql, $params);

        $filename = 'authorizations-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Auth #', 'Patient', 'Payer', 'Service', 'CPT', 'Requested', 'Due', 'Decision', 'Status', 'Provider']);
        while ($row = sqlFetchArray($rs)) {
            $patient = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
            $provider = cp_format_provider_name([
                'username' => $row['pusername'] ?? '',
                'fname'    => $row['pfname']    ?? '',
                'lname'    => $row['plname']    ?? '',
                'title'    => $row['ptitle']    ?? '',
            ]);
            fputcsv($out, [
                $row['auth_number'],
                $patient,
                $row['payer'],
                $row['service'],
                $row['cpt'],
                $row['requested_date'],
                $row['due_date'],
                $row['decision_date'],
                ucfirst((string)$row['status']),
                $provider,
            ]);
        }
        fclose($out);
        exit;
    }
}
$flash = $_GET['msg'] ?? null;

// ──────────────────────────────────────────────────────────────────────
// 3. Filter parsing.
// ──────────────────────────────────────────────────────────────────────

$validStatuses = ['all', 'pending', 'approved', 'denied', 'withdrawn', 'submitted', 'draft'];
$status = $_GET['status'] ?? 'all';
if (!in_array($status, $validStatuses, true)) {
    $status = 'all';
}

$payer = (string)($_GET['payer'] ?? 'all');
$service = (string)($_GET['service'] ?? 'all');
$providerFilter = (string)($_GET['provider'] ?? 'all');
$validRanges = ['last7', 'last30', 'last90', 'all'];
$range = $_GET['range'] ?? 'last30';
if (!in_array($range, $validRanges, true)) {
    $range = 'last30';
}
$q = trim((string)($_GET['q'] ?? ''));

// Distinct dropdown values pulled from the DB.
$payerOptions = ['all'];
$rs = sqlStatement("SELECT DISTINCT payer FROM cp_authorizations WHERE payer <> '' ORDER BY payer");
while ($r = sqlFetchArray($rs)) { $payerOptions[] = $r['payer']; }

// Crude service-type buckets (first word) so the dropdown stays short.
$serviceOptions = ['all'];
$rs = sqlStatement(
    "SELECT DISTINCT TRIM(SUBSTRING_INDEX(service, ' ', 1)) AS svc
       FROM cp_authorizations WHERE service <> '' ORDER BY svc"
);
while ($r = sqlFetchArray($rs)) {
    if ($r['svc'] !== '') { $serviceOptions[] = $r['svc']; }
}

$providerOptions = [['id' => 'all', 'label' => 'All providers']];
$rs = sqlStatement(
    "SELECT DISTINCT u.id, u.username, u.fname, u.lname, u.title
       FROM users u
       JOIN cp_authorizations a ON a.provider_id = u.id
      ORDER BY u.lname, u.fname"
);
while ($r = sqlFetchArray($rs)) {
    $providerOptions[] = [
        'id' => (string)$r['id'],
        'label' => cp_format_provider_name($r),
    ];
}

$rangeOptions = [
    'last7'  => 'Last 7 days',
    'last30' => 'Last 30 days',
    'last90' => 'Last 90 days',
    'all'    => 'All time',
];

// ──────────────────────────────────────────────────────────────────────
// 4. KPI computation. All five tiles come from the live table.
// ──────────────────────────────────────────────────────────────────────

$kpiAwaiting = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_authorizations WHERE status IN ('pending','submitted')"
)['c'] ?? 0);

$kpiApproved30 = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_authorizations
      WHERE status = 'approved' AND decision_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
)['c'] ?? 0);

$kpiDenied30 = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_authorizations
      WHERE status = 'denied' AND decision_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
)['c'] ?? 0);

$kpiSubmittedToday = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_authorizations
      WHERE requested_date = CURDATE() AND status IN ('pending','submitted','approved','denied')"
)['c'] ?? 0);
$kpiExpeditedToday = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_authorizations
      WHERE requested_date = CURDATE() AND expedited = 1"
)['c'] ?? 0);

$turnaroundRow = sqlQuery(
    "SELECT AVG(DATEDIFF(decision_date, requested_date)) AS avg_days
       FROM cp_authorizations
      WHERE decision_date IS NOT NULL
        AND requested_date IS NOT NULL
        AND decision_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)"
);
$kpiTurnaround = $turnaroundRow && $turnaroundRow['avg_days'] !== null
    ? round((float)$turnaroundRow['avg_days'], 1)
    : null;

// Header meta-line counts (Active / Awaiting / Denied this week).
$activeCount = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_authorizations WHERE status IN ('pending','submitted','approved','draft')"
)['c'] ?? 0);
$awaitingHdr = $kpiAwaiting;
$deniedThisWeek = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_authorizations
      WHERE status = 'denied' AND decision_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
)['c'] ?? 0);

// Approval rate (last 30 days) for the "Approved (30d)" subtext.
$decided30 = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_authorizations
      WHERE status IN ('approved','denied','withdrawn')
        AND decision_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
)['c'] ?? 0);
$approvalRate = $decided30 > 0 ? (int)round(($kpiApproved30 / $decided30) * 100) : null;

// Avg days awaiting payer (subtext for "Awaiting payer" tile).
$awaitingAvgRow = sqlQuery(
    "SELECT AVG(DATEDIFF(CURDATE(), requested_date)) AS avg_days
       FROM cp_authorizations
      WHERE status IN ('pending','submitted')"
);
$awaitingAvgDays = $awaitingAvgRow && $awaitingAvgRow['avg_days'] !== null
    ? round((float)$awaitingAvgRow['avg_days'], 1)
    : null;

// ──────────────────────────────────────────────────────────────────────
// 5. Main row query — same WHERE construction as the CSV exporter.
// ──────────────────────────────────────────────────────────────────────

$whereParts = ['1=1'];
$params = [];
if ($status !== 'all') {
    $whereParts[] = 'a.status = ?';
    $params[] = $status;
}
if ($payer !== 'all' && $payer !== '') {
    $whereParts[] = 'a.payer = ?';
    $params[] = $payer;
}
if ($service !== 'all' && $service !== '') {
    $whereParts[] = 'a.service LIKE ?';
    $params[] = $service . '%';
}
if ($providerFilter !== 'all' && $providerFilter !== '' && ctype_digit($providerFilter)) {
    $whereParts[] = 'a.provider_id = ?';
    $params[] = (int)$providerFilter;
}
$rangeDays = ['last7' => 7, 'last30' => 30, 'last90' => 90, 'all' => null][$range];
if ($rangeDays !== null) {
    $whereParts[] = 'a.requested_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
    $params[] = $rangeDays;
}
if ($q !== '') {
    $whereParts[] = '(a.auth_number LIKE ? OR pd.fname LIKE ? OR pd.lname LIKE ? OR CONCAT(pd.fname, " ", pd.lname) LIKE ? OR a.payer LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$where = implode(' AND ', $whereParts);

$rowsRs = sqlStatement(
    "SELECT a.id, a.auth_number, a.pid, a.payer, a.service, a.cpt, a.status,
            a.requested_date, a.due_date, a.decision_date, a.expedited,
            pd.fname AS p_fname, pd.lname AS p_lname,
            u.id AS prov_id, u.username AS prov_username, u.fname AS prov_fname,
            u.lname AS prov_lname, u.title AS prov_title
       FROM cp_authorizations a
  LEFT JOIN patient_data pd ON pd.pid = a.pid
  LEFT JOIN users u ON u.id = a.provider_id
      WHERE $where
   ORDER BY a.requested_date DESC, a.id DESC
      LIMIT 50",
    $params
);

$rows = [];
while ($r = sqlFetchArray($rowsRs)) {
    // Status display + tone.
    $tone = match ($r['status']) {
        'approved'   => 'good',
        'denied'     => 'danger',
        'pending'    => 'warn',
        'submitted'  => 'warn',
        'draft'      => 'neutral',
        'withdrawn'  => 'neutral',
        default      => 'neutral',
    };
    $statusLabel = ucfirst((string)$r['status']);
    if ($r['status'] === 'pending') {
        $statusLabel = 'Awaiting payer';
    } elseif ($r['status'] === 'denied') {
        $statusLabel = 'Denied — appeal pending';
    }

    $patientName = trim(($r['p_fname'] ?? '') . ' ' . ($r['p_lname'] ?? ''));
    if ($patientName === '') { $patientName = '(unknown)'; }

    $providerName = $r['prov_id']
        ? cp_format_provider_name([
            'username' => $r['prov_username'],
            'fname'    => $r['prov_fname'],
            'lname'    => $r['prov_lname'],
            'title'    => $r['prov_title'],
        ])
        : '—';

    $rows[] = [
        'id' => (int)$r['id'],
        'auth' => (string)$r['auth_number'],
        'patient' => $patientName,
        'payer' => (string)$r['payer'],
        'service' => (string)$r['service'],
        'cpt' => (string)$r['cpt'],
        'requested' => $r['requested_date'] ? date('m/d/Y', strtotime((string)$r['requested_date'])) : '—',
        'due'       => $r['due_date']       ? date('m/d/Y', strtotime((string)$r['due_date']))       : '—',
        'status' => $statusLabel,
        'tone' => $tone,
        'provider' => $providerName,
        'expedited' => (int)$r['expedited'] === 1,
    ];
}

// ──────────────────────────────────────────────────────────────────────
// 6. Helper for current-filter querystring (used by Export form, etc.)
// ──────────────────────────────────────────────────────────────────────

$currentFilters = [
    'status'   => $status,
    'payer'    => $payer,
    'service'  => $service,
    'provider' => $providerFilter,
    'range'    => $range,
    'q'        => $q,
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Authorizations'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header dot separator + light meta */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #4F5763; font-size: 12px; line-height: 1; }

  /* 5-column KPI strip for Authorizations */
  .cp-kpi-5 { grid-template-columns: repeat(5, 1fr); }
  .cp-kpi .val.green  { color: #1F8C4D; }
  .cp-kpi .val.orange { color: #FA8C33; }
  .cp-kpi .val.red    { color: #D93838; }
  .cp-kpi .val .unit  { font-size: 14px; font-weight: 600; color: #0D1B2A; margin-left: 2px; }
  .cp-kpi .sub.muted  { color: #8A91A1; }
  .cp-kpi .sub .down  { color: #1F8C4D; font-weight: 600; }

  /* Filter strip with select-style fields */
  .cp-filter.auth { gap: 10px; }
  .cp-filter.auth .search {
    flex: 0 0 260px;
    height: 38px;
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 0 12px;
    color: #8A91A1;
    font-size: 12px;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-filter.auth .search input {
    background: transparent; border: 0; outline: 0;
    font: inherit; color: #0D1B2A; flex: 1; min-width: 0;
  }
  .cp-filter.auth .search input::placeholder { color: #8A91A1; }
  .cp-filter.auth .sel {
    flex: 1 1 0;
    min-width: 0;
    height: 38px;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 4px 30px 4px 12px;
    display: flex; flex-direction: column; justify-content: center; gap: 1px;
    position: relative;
  }
  .cp-filter.auth .sel::after {
    content: '';
    position: absolute; right: 12px; top: 50%; transform: translateY(-25%);
    width: 0; height: 0;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
    border-top: 5px solid #8A91A1;
  }
  .cp-filter.auth .sel .lbl {
    font-size: 9px; font-weight: 600; color: #8A91A1;
    text-transform: uppercase; letter-spacing: 0.4px;
    line-height: 1;
  }
  .cp-filter.auth .sel .vl {
    font-size: 12px; color: #0D1B2A; font-weight: 500;
    line-height: 1.3;
  }
  /* Make the native select an invisible overlay so the styled chrome stays */
  .cp-filter.auth .sel select {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    opacity: 0;
    cursor: pointer;
    border: 0; padding: 0;
    font: inherit;
  }

  /* Auth table specifics */
  .cp-at table { font-size: 12px; }
  .cp-at th, .cp-at td { padding: 11px 14px; }
  .cp-at th.cb, .cp-at td.cb {
    width: 36px; padding-left: 18px; padding-right: 8px;
  }
  .cp-at th.act, .cp-at td.act {
    width: 32px; padding-left: 6px; padding-right: 14px; text-align: right;
  }
  .cp-at td.bold     { font-weight: 600; color: #0D1B2A; }
  .cp-at td.muted    { color: #4F5763; }
  .cp-at td.muted-soft { color: #4F5763; }
  .cp-at td.authnum  { color: #008C8C; font-weight: 600; }
  .cp-at td.authnum a { color: inherit; text-decoration: none; }
  .cp-at td.authnum a:hover { text-decoration: underline; }
  .cp-at .expedited { background: #FFE8D5; color: #FA8C33; font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 4px; margin-left: 6px; letter-spacing: 0.4px; }

  /* Checkbox visuals */
  .cp-cb {
    width: 16px; height: 16px;
    border: 1.5px solid #C9CDD4; border-radius: 4px;
    background: #FFFFFF; display: inline-block; vertical-align: middle;
    position: relative;
  }
  .cp-cb.on { background: #008C8C; border-color: #008C8C; }
  .cp-cb.on::after {
    content: '✓'; color: #FFFFFF; font-size: 11px; font-weight: 700;
    position: absolute; left: 2px; top: -1px;
  }

  /* Kebab */
  .cp-kebab {
    display: inline-block; color: #8A91A1; cursor: pointer;
    padding: 4px 6px; border-radius: 6px; font-size: 14px;
    line-height: 1;
  }
  .cp-kebab:hover { background: #F5F6F7; color: #0D1B2A; }

  /* Flash */
  .cp-flash {
    margin: 10px 24px 0;
    padding: 8px 14px;
    background: #EBF8F0;
    border: 1px solid #BFE5CC;
    border-radius: 8px;
    color: #1F8C4D;
    font-size: 12px; font-weight: 500;
  }

  /* Header buttons need to act as form submitters */
  .cp-pagehead form { display: inline; margin: 0; padding: 0; }
  .cp-pagehead .cp-btn { font: inherit; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="title"><?php echo xlt('Authorizations'); ?></span>
      <span class="dot">·</span>
      <span class="meta-light">
        <?php echo text((string)$activeCount); ?> <?php echo xlt('active'); ?>
        ·
        <?php echo text((string)$awaitingHdr); ?> <?php echo xlt('awaiting payer'); ?>
        ·
        <?php echo text((string)$deniedThisWeek); ?> <?php echo xlt('denied this week'); ?>
      </span>
    </div>
  </div>
  <!-- Export — POSTs current filters and streams CSV -->
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>">
    <input type="hidden" name="action" value="export_csv">
    <?php foreach ($currentFilters as $k => $v): ?>
      <input type="hidden" name="<?php echo attr($k); ?>" value="<?php echo attr((string)$v); ?>">
    <?php endforeach; ?>
    <button type="submit" class="cp-btn ghost">⤓ <?php echo xlt('Export'); ?></button>
  </form>
  <!-- New authorization — POSTs to create a draft and redirects to it -->
  <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>">
    <input type="hidden" name="action" value="new_auth">
    <button type="submit" class="cp-btn primary">+ <?php echo xlt('New authorization'); ?></button>
  </form>
  <a class="cp-btn ghost" href="/Documentation/" target="_blank" rel="noopener">? <?php echo xlt('Help'); ?></a>
</header>

<?php if ($flash): ?>
  <div class="cp-flash"><?php echo text((string)$flash); ?></div>
<?php endif; ?>

<main class="cp-content tight">

  <div class="cp-kpi-grid cp-kpi-5">
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Awaiting payer'); ?></span>
      <span class="val orange"><?php echo text((string)$kpiAwaiting); ?></span>
      <span class="sub muted">
        <?php
        echo $awaitingAvgDays !== null
            ? text(sprintf('avg %s days', $awaitingAvgDays))
            : xlt('no open requests');
        ?>
      </span>
    </div>
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Approved (30d)'); ?></span>
      <span class="val green"><?php echo text((string)$kpiApproved30); ?></span>
      <span class="sub muted">
        <?php
        echo $approvalRate !== null
            ? text($approvalRate . '% ') . xlt('rate')
            : xlt('no decisions yet');
        ?>
      </span>
    </div>
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Denied (30d)'); ?></span>
      <span class="val red"><?php echo text((string)$kpiDenied30); ?></span>
      <span class="sub muted"><?php echo $kpiDenied30 > 0 ? xlt('all appealed') : xlt('none'); ?></span>
    </div>
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Submitted today'); ?></span>
      <span class="val"><?php echo text((string)$kpiSubmittedToday); ?></span>
      <span class="sub muted">
        <?php echo text((string)$kpiExpeditedToday); ?> <?php echo xlt('expedited'); ?>
      </span>
    </div>
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Avg turnaround'); ?></span>
      <?php if ($kpiTurnaround !== null): ?>
        <span class="val"><?php echo text((string)$kpiTurnaround); ?><span class="unit">d</span></span>
      <?php else: ?>
        <span class="val">—</span>
      <?php endif; ?>
      <span class="sub muted"><?php echo xlt('last 90 days'); ?></span>
    </div>
  </div>

  <!-- Filter strip — submits as GET so refreshing/bookmarking works -->
  <form method="get" action="<?php echo attr($_SERVER['PHP_SELF']); ?>" class="cp-filter auth" id="cp-auth-filters">
    <label class="search">🔍
      <input type="text" name="q"
             value="<?php echo attr($q); ?>"
             placeholder="<?php echo xla('Search patient, payer, or auth #...'); ?>"
             onchange="this.form.submit()">
    </label>

    <div class="sel">
      <span class="lbl"><?php echo xlt('Status'); ?></span>
      <span class="vl">
        <?php echo text(ucfirst($status === 'all' ? 'All' : $status)); ?>
      </span>
      <select name="status" onchange="this.form.submit()">
        <?php foreach ($validStatuses as $s): ?>
          <option value="<?php echo attr($s); ?>" <?php echo $s === $status ? 'selected' : ''; ?>>
            <?php echo text($s === 'all' ? 'All' : ucfirst($s)); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sel">
      <span class="lbl"><?php echo xlt('Payer'); ?></span>
      <span class="vl">
        <?php echo text($payer === 'all' ? 'All payers' : $payer); ?>
      </span>
      <select name="payer" onchange="this.form.submit()">
        <?php foreach ($payerOptions as $opt): ?>
          <option value="<?php echo attr($opt); ?>" <?php echo $opt === $payer ? 'selected' : ''; ?>>
            <?php echo text($opt === 'all' ? 'All payers' : $opt); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sel">
      <span class="lbl"><?php echo xlt('Service type'); ?></span>
      <span class="vl">
        <?php echo text($service === 'all' ? 'All' : $service); ?>
      </span>
      <select name="service" onchange="this.form.submit()">
        <?php foreach ($serviceOptions as $opt): ?>
          <option value="<?php echo attr($opt); ?>" <?php echo $opt === $service ? 'selected' : ''; ?>>
            <?php echo text($opt === 'all' ? 'All' : $opt); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sel">
      <span class="lbl"><?php echo xlt('Provider'); ?></span>
      <span class="vl">
        <?php
        $provLabel = 'All providers';
        foreach ($providerOptions as $po) {
            if ($po['id'] === $providerFilter) { $provLabel = $po['label']; break; }
        }
        echo text($provLabel);
        ?>
      </span>
      <select name="provider" onchange="this.form.submit()">
        <?php foreach ($providerOptions as $po): ?>
          <option value="<?php echo attr($po['id']); ?>" <?php echo $po['id'] === $providerFilter ? 'selected' : ''; ?>>
            <?php echo text($po['label']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sel">
      <span class="lbl"><?php echo xlt('Date range'); ?></span>
      <span class="vl"><?php echo text($rangeOptions[$range] ?? 'Last 30 days'); ?></span>
      <select name="range" onchange="this.form.submit()">
        <?php foreach ($rangeOptions as $k => $label): ?>
          <option value="<?php echo attr($k); ?>" <?php echo $k === $range ? 'selected' : ''; ?>>
            <?php echo text($label); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <div class="cp-tbl cp-at">
    <table>
      <thead>
        <tr>
          <th class="cb"><span class="cp-cb"></span></th>
          <th><?php echo xlt('AUTH #'); ?></th>
          <th><?php echo xlt('PATIENT'); ?></th>
          <th><?php echo xlt('PAYER'); ?></th>
          <th><?php echo xlt('SERVICE'); ?></th>
          <th><?php echo xlt('CPT'); ?></th>
          <th><?php echo xlt('REQUESTED'); ?></th>
          <th><?php echo xlt('DUE'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('PROVIDER'); ?></th>
          <th class="act"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="11" style="padding: 28px; text-align: center; color: #8A91A1;">
              <?php echo xlt('No authorizations match the current filters.'); ?>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="cb"><span class="cp-cb"></span></td>
            <td class="authnum">
              <a href="?id=<?php echo attr((string)$r['id']); ?>"><?php echo text($r['auth']); ?></a>
              <?php if ($r['expedited']): ?>
                <span class="expedited"><?php echo xlt('STAT'); ?></span>
              <?php endif; ?>
            </td>
            <td class="bold"><?php echo text($r['patient']); ?></td>
            <td class="muted"><?php echo text($r['payer']); ?></td>
            <td class="muted-soft"><?php echo text($r['service']); ?></td>
            <td class="muted"><?php echo text($r['cpt']); ?></td>
            <td class="muted"><?php echo text($r['requested']); ?></td>
            <td class="muted"><?php echo text($r['due']); ?></td>
            <td><span class="cp-status-pill <?php echo attr($r['tone']); ?>"><?php echo text($r['status']); ?></span></td>
            <td class="muted"><?php echo text($r['provider']); ?></td>
            <td class="act"><span class="cp-kebab">⋯</span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
