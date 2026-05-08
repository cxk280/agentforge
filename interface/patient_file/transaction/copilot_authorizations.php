<?php

/**
 * Authorizations landing page — Figma "Screen 34 — Authorizations".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/authorizations/. The PHP outer shell
 * (parent transaction tab in /interface/patient_file/) still owns the navy
 * top nav and patient demographics banner; this file only renders inside
 * the inner content area.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original DB-backed mock is preserved at copilot_authorizations.php.bak
 * so a side-by-side screenshot diff remains possible.
 *
 * Live authorization queue is composed from the custom `cp_authorizations`
 * table (created idempotently here on first request, seeded once via a
 * marker row in `globals`) joined to `patient_data` and `users`. The
 * authorizations queue is intentionally PRACTICE-WIDE — the .bak rendered
 * other patients' rows in the same view, so we do too.
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
// ---------------------------------------------------------------------------

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/authorizations/index.tsx'] ?? null;
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
// Use the OpenEMR session wrapper (not $_SESSION directly) — globals.php
// runs a read_and_close session, so $_SESSION values can be empty by the
// time this wrapper file reads them. The wrapper queries the live store.
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

$activePid = (int)$patientId;

// ---------------------------------------------------------------------------
// 1. Backing table + idempotent seed (mirrors the .bak's behavior).
// ---------------------------------------------------------------------------

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
    $providerIdByUsername = [];
    $r = sqlStatement("SELECT id, username FROM users WHERE authorized = 1 AND active = 1");
    while ($row = sqlFetchArray($r)) {
        $providerIdByUsername[$row['username']] = (int)$row['id'];
    }
    $pickProvider = static function (string $preferred) use ($providerIdByUsername): ?int {
        if (isset($providerIdByUsername[$preferred])) {
            return $providerIdByUsername[$preferred];
        }
        return $providerIdByUsername ? (int)reset($providerIdByUsername) : null;
    };

    $patientPids = [];
    $r = sqlStatement("SELECT pid FROM patient_data ORDER BY pid LIMIT 14");
    while ($row = sqlFetchArray($r)) {
        $patientPids[] = (int)$row['pid'];
    }
    if ($activePid > 0 && !in_array($activePid, $patientPids, true)) {
        array_unshift($patientPids, $activePid);
    }
    $pickPid = static function (int $idx) use ($patientPids): int {
        if (!$patientPids) {
            return 1;
        }
        return $patientPids[$idx % count($patientPids)];
    };

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

// ---------------------------------------------------------------------------
// 2. KPI computation. All five tiles come from the live table.
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// 3. Main row query — practice-wide queue, newest first.
// ---------------------------------------------------------------------------

$rowsRs = sqlStatement(
    "SELECT a.id, a.auth_number, a.pid, a.payer, a.service, a.cpt, a.status,
            a.requested_date, a.due_date, a.decision_date, a.expedited,
            pd.fname AS p_fname, pd.lname AS p_lname,
            u.id AS prov_id, u.username AS prov_username, u.fname AS prov_fname,
            u.lname AS prov_lname, u.title AS prov_title
       FROM cp_authorizations a
  LEFT JOIN patient_data pd ON pd.pid = a.pid
  LEFT JOIN users u ON u.id = a.provider_id
   ORDER BY a.requested_date DESC, a.id DESC
      LIMIT 50"
);

$rows = [];
while ($r = sqlFetchArray($rowsRs)) {
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

    $patientName = trim(((string)($r['p_fname'] ?? '')) . ' ' . ((string)($r['p_lname'] ?? '')));
    if ($patientName === '') {
        $patientName = '(unknown)';
    }

    $providerName = $r['prov_id']
        ? cp_format_provider_name([
            'username' => (string)($r['prov_username'] ?? ''),
            'fname'    => (string)($r['prov_fname']    ?? ''),
            'lname'    => (string)($r['prov_lname']    ?? ''),
            'title'    => (string)($r['prov_title']    ?? ''),
        ])
        : '—';

    $rows[] = [
        'auth'      => (string)($r['auth_number'] ?? ''),
        'patient'   => $patientName,
        'payer'     => (string)($r['payer']   ?? ''),
        'service'   => (string)($r['service'] ?? ''),
        'cpt'       => (string)($r['cpt']     ?? ''),
        'requested' => $r['requested_date'] ? date('m/d/Y', strtotime((string)$r['requested_date'])) : '—',
        'due'       => $r['due_date']       ? date('m/d/Y', strtotime((string)$r['due_date']))       : '—',
        'status'    => $statusLabel,
        'tone'      => $tone,
        'provider'  => $providerName,
        'expedited' => ((int)($r['expedited'] ?? 0)) === 1,
    ];
}

// ---------------------------------------------------------------------------
// 4. Build the payload React consumes. Numeric KPIs are stringified into
//    the "value" field the component already renders. Subtext mirrors what
//    the .bak printed.
// ---------------------------------------------------------------------------

$kpiAwaitingSub = $awaitingAvgDays !== null
    ? sprintf('avg %s days', $awaitingAvgDays)
    : 'no open requests';

$kpiApprovedSub = $approvalRate !== null
    ? sprintf('%d%% rate', $approvalRate)
    : 'no decisions yet';

$kpiDeniedSub = $kpiDenied30 > 0 ? 'all appealed' : 'none';

$kpiSubmittedSub = sprintf('%d expedited', $kpiExpeditedToday);

$kpiTurnaroundValue = $kpiTurnaround !== null ? (string)$kpiTurnaround : '—';
$kpiTurnaroundUnit  = $kpiTurnaround !== null ? 'd' : '';

$kpis = [
    [
        'label' => 'Awaiting payer',
        'value' => (string)$kpiAwaiting,
        'tone'  => 'orange',
        'sub'   => $kpiAwaitingSub,
    ],
    [
        'label' => 'Approved (30d)',
        'value' => (string)$kpiApproved30,
        'tone'  => 'green',
        'sub'   => $kpiApprovedSub,
    ],
    [
        'label' => 'Denied (30d)',
        'value' => (string)$kpiDenied30,
        'tone'  => 'red',
        'sub'   => $kpiDeniedSub,
    ],
    [
        'label' => 'Submitted today',
        'value' => (string)$kpiSubmittedToday,
        'sub'   => $kpiSubmittedSub,
    ],
    [
        'label' => 'Avg turnaround',
        'value' => $kpiTurnaroundValue,
        'unit'  => $kpiTurnaroundUnit,
        'sub'   => 'last 90 days',
    ],
];

$authPayload = [
    'meta' => [
        'active'         => $activeCount,
        'awaitingPayer'  => $awaitingHdr,
        'deniedThisWeek' => $deniedThisWeek,
    ],
    'kpis' => $kpis,
    'rows' => $rows,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Authorizations'); ?></title>
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
    background: #F5F6F7;
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
     data-page="authorizations"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-auth="<?php echo attr((string)json_encode($authPayload)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Authorizations UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
