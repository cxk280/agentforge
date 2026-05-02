<?php

/**
 * e-Rx Renewal Queue — Screen 41.
 *
 * Refill request queue. Bulk select + per-row approve/deny for renewal
 * requests inbound from pharmacies. Card-style row layout (not a table)
 * with patient block, drug block, status block, and per-row actions.
 *
 * Backing store. OpenEMR has no first-class "inbound refill request"
 * table — `prescriptions.refills` is the count of refills *authorized*,
 * not a queue of pharmacy-initiated renewal requests. We therefore keep
 * refill-request rows in a small custom table:
 *     cp_refill_requests(id, prescription_id, pid, drug, sig, pharmacy_id,
 *                        requested_at, status, schedule, needs_pa,
 *                        sub_line, last_filled, decided_at, decided_by,
 *                        created_at, updated_at)
 *
 * The table is created idempotently the first time the page loads
 * (CREATE TABLE IF NOT EXISTS), and seeded ONCE — guarded by a marker
 * row in `globals` (gl_name='cp_refill_requests_seed_v1') so seeded
 * data is immutable across reloads. Edits/inserts at runtime are kept;
 * the seeder never runs again.
 *
 * The chrome (top nav, demographics banner, navtab strip) is rendered
 * by the parent shell — this page renders only the body.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

// ──────────────────────────────────────────────────────────────────────
// 1. Backing table + idempotent seed.
// ──────────────────────────────────────────────────────────────────────

sqlStatement(
    "CREATE TABLE IF NOT EXISTS cp_refill_requests (
        id              BIGINT NOT NULL AUTO_INCREMENT,
        prescription_id INT NULL,
        pid             BIGINT NOT NULL,
        drug            VARCHAR(255) NOT NULL DEFAULT '',
        sig             VARCHAR(255) NOT NULL DEFAULT '',
        pharmacy_id     INT NULL,
        requested_at    DATETIME NULL,
        status          VARCHAR(32) NOT NULL DEFAULT 'Standard',
        schedule        VARCHAR(32) NOT NULL DEFAULT '',
        needs_pa        TINYINT(1) NOT NULL DEFAULT 0,
        sub_line        VARCHAR(255) NOT NULL DEFAULT '',
        last_filled     DATE NULL,
        decided_at      DATETIME NULL,
        decided_by      VARCHAR(64) NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_cp_rr_pid (pid),
        KEY ix_cp_rr_status (status),
        KEY ix_cp_rr_prescription (prescription_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$seedMarker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'cp_refill_requests_seed_v1'");
if (empty($seedMarker['gl_value'])) {
    // Pull a few real prescription rows so seeded refill requests reference
    // real foreign keys. Order by id DESC so we tend to grab the freshest.
    $rxRows = [];
    $rs = sqlStatement(
        "SELECT p.id, p.patient_id, p.drug, p.dosage, p.refills, p.pharmacy_id, p.start_date
           FROM prescriptions p
          WHERE p.active = 1
       ORDER BY p.id DESC
          LIMIT 20"
    );
    while ($row = sqlFetchArray($rs)) {
        $rxRows[] = $row;
    }
    // Pharmacies for round-robin if a prescription has no pharmacy_id.
    $pharmacyIds = [];
    $rs = sqlStatement("SELECT id FROM pharmacies ORDER BY id");
    while ($row = sqlFetchArray($rs)) {
        $pharmacyIds[] = (int)$row['id'];
    }
    $pickPharmacy = static function (int $idx, ?int $existing) use ($pharmacyIds): ?int {
        if ($existing !== null && $existing > 0) {
            return $existing;
        }
        if (!$pharmacyIds) {
            return null;
        }
        return $pharmacyIds[$idx % count($pharmacyIds)];
    };

    // Seed list. Each entry maps to an existing prescription (by index into
    // $rxRows) and overlays a status / schedule / sub-line / last-filled
    // date. If the DB has fewer rx rows than seed entries we silently skip
    // the overflow (mock fidelity > arbitrary count).
    //   [rxIdx, sig, status, schedule, needs_pa, sub_line, days_since_filled]
    $seed = [
        [0,  'Tab, BID with meals',       'Standard',       '',            0, 'HbA1c 7.9% — consider increasing dose', 30],
        [1,  'Tab, daily',                'Out of refills', '',            0, '',                                       48],
        [2,  'Tab, nightly',              'Out of refills', '',            0, '',                                       41],
        [3,  'Tab, daily AM',             'Standard',       '',            0, '',                                       20],
        [4,  'Cap, q6h PRN pain',         'Controlled',     'Schedule IV', 0, 'Requires EPCS · last dispensed 17 days ago', 17],
        [5,  'Tab, daily AM',             'PA required',    '',            1, 'Insurance prefers generic — switch?',    35],
        [6,  'Tab, daily',                'Standard',       '',            0, '',                                       28],
    ];
    $insertSql = "INSERT INTO cp_refill_requests
        (prescription_id, pid, drug, sig, pharmacy_id, requested_at, status, schedule, needs_pa, sub_line, last_filled)
        VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)";
    foreach ($seed as $idx => $entry) {
        [$rxIdx, $sig, $status, $schedule, $needsPa, $subLine, $daysAgo] = $entry;
        if (!isset($rxRows[$rxIdx])) {
            continue;
        }
        $rx = $rxRows[$rxIdx];
        $drugDisplay = trim((string)($rx['drug'] ?? ''));
        $dosage = trim((string)($rx['dosage'] ?? ''));
        if ($dosage !== '' && stripos($drugDisplay, $dosage) === false) {
            $drugDisplay = $drugDisplay . ' ' . $dosage;
        }
        $lastFilled = (new \DateTimeImmutable('today'))
            ->modify("-{$daysAgo} days")
            ->format('Y-m-d');
        try {
            sqlStatement($insertSql, [
                (int)$rx['id'],
                (int)$rx['patient_id'],
                $drugDisplay,
                $sig,
                $pickPharmacy($idx, isset($rx['pharmacy_id']) ? (int)$rx['pharmacy_id'] : null),
                $status,
                $schedule,
                $needsPa,
                $subLine,
                $lastFilled,
            ]);
        } catch (\Throwable $e) {
            // Race or duplicate — skip silently; the marker below ensures
            // we don't try again on the next request.
        }
    }
    sqlStatement(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('cp_refill_requests_seed_v1', 0, ?)",
        [date('c')]
    );
}

// ──────────────────────────────────────────────────────────────────────
// 2. POST handlers (POST/redirect/GET). CSRF skipped — internal mock page.
// ──────────────────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    $authUser = (string)($_SESSION['authUser'] ?? 'admin');

    /** Apply approval to one request: set status, bump prescriptions.refills. */
    $approveOne = static function (int $id) use ($authUser): bool {
        $row = sqlQuery("SELECT id, prescription_id, pid, drug FROM cp_refill_requests WHERE id = ?", [$id]);
        if (!$row) {
            return false;
        }
        sqlStatement(
            "UPDATE cp_refill_requests SET status = 'Approved', decided_at = NOW(), decided_by = ? WHERE id = ?",
            [$authUser, $id]
        );
        if (!empty($row['prescription_id'])) {
            // Increment authorized refills on the underlying prescription.
            sqlStatement(
                "UPDATE prescriptions SET refills = COALESCE(refills, 0) + 1, date_modified = NOW() WHERE id = ?",
                [(int)$row['prescription_id']]
            );
        }
        sqlStatement(
            "INSERT INTO extended_log (date, event, user, recipient, description, patient_id) VALUES (NOW(), 'refill_approve', ?, '', ?, ?)",
            [$authUser, "Approved refill request #{$id}: " . ($row['drug'] ?? ''), (int)($row['pid'] ?? 0)]
        );
        return true;
    };

    /** Apply denial to one request. */
    $denyOne = static function (int $id) use ($authUser): bool {
        $row = sqlQuery("SELECT id, pid, drug FROM cp_refill_requests WHERE id = ?", [$id]);
        if (!$row) {
            return false;
        }
        sqlStatement(
            "UPDATE cp_refill_requests SET status = 'Denied', decided_at = NOW(), decided_by = ? WHERE id = ?",
            [$authUser, $id]
        );
        sqlStatement(
            "INSERT INTO extended_log (date, event, user, recipient, description, patient_id) VALUES (NOW(), 'refill_deny', ?, '', ?, ?)",
            [$authUser, "Denied refill request #{$id}: " . ($row['drug'] ?? ''), (int)($row['pid'] ?? 0)]
        );
        return true;
    };

    if ($action === 'approve_one') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $approveOne($id);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode('Approved & signed 1 refill'));
            exit;
        }
    }
    if ($action === 'deny') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $denyOne($id);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode('Denied 1 refill'));
            exit;
        }
    }
    if ($action === 'approve_bulk' || $action === 'deny_bulk') {
        $ids = (array)($_POST['ids'] ?? []);
        $count = 0;
        foreach ($ids as $rawId) {
            if (!is_scalar($rawId)) {
                continue;
            }
            $id = (int)$rawId;
            if ($id <= 0) {
                continue;
            }
            $ok = $action === 'approve_bulk' ? $approveOne($id) : $denyOne($id);
            if ($ok) {
                $count++;
            }
        }
        $verb = $action === 'approve_bulk' ? 'Approved & signed' : 'Denied';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode("{$verb} {$count} refill" . ($count === 1 ? '' : 's')));
        exit;
    }
}
$flash = $_GET['msg'] ?? null;

// ──────────────────────────────────────────────────────────────────────
// 3. Filter parsing.
// ──────────────────────────────────────────────────────────────────────

// Sub-tabs (Active / New Rx / Renewals / EPCS / Pharmacy / Drug check).
$validSubtabs = ['active', 'new_rx', 'renewals', 'epcs', 'pharmacy', 'drug_check'];
$subtab = (string)($_GET['subtab'] ?? 'renewals');
if (!in_array($subtab, $validSubtabs, true)) {
    $subtab = 'renewals';
}

// Filter pills (All / Standard / PA required / Controlled / Out of refills).
$validFilters = ['all', 'standard', 'pa', 'controlled', 'out'];
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

// ──────────────────────────────────────────────────────────────────────
// 4. Counts (for header + filter pill badges).
// ──────────────────────────────────────────────────────────────────────

// Pending only. Approved/Denied rows drop out of the queue immediately.
$pendingWhere = "rr.status NOT IN ('Approved','Denied')";

$counts = [
    'all'        => 0,
    'standard'   => 0,
    'pa'         => 0,
    'controlled' => 0,
    'out'        => 0,
];
$countSql = "SELECT
    SUM(1) AS total,
    SUM(CASE WHEN rr.status = 'Standard' THEN 1 ELSE 0 END) AS standard_ct,
    SUM(CASE WHEN rr.needs_pa = 1 OR rr.status = 'PA required' THEN 1 ELSE 0 END) AS pa_ct,
    SUM(CASE WHEN rr.status = 'Controlled' THEN 1 ELSE 0 END) AS controlled_ct,
    SUM(CASE WHEN rr.status = 'Out of refills' THEN 1 ELSE 0 END) AS out_ct
  FROM cp_refill_requests rr
 WHERE {$pendingWhere}";
$countRow = sqlQuery($countSql) ?: [];
$counts['all']        = (int)($countRow['total']        ?? 0);
$counts['standard']   = (int)($countRow['standard_ct']  ?? 0);
$counts['pa']         = (int)($countRow['pa_ct']        ?? 0);
$counts['controlled'] = (int)($countRow['controlled_ct']?? 0);
$counts['out']        = (int)($countRow['out_ct']       ?? 0);

// ──────────────────────────────────────────────────────────────────────
// 5. Row query.
// ──────────────────────────────────────────────────────────────────────

$whereParts = [$pendingWhere];
$params = [];
if ($filter === 'standard') {
    $whereParts[] = "rr.status = ?";
    $params[] = 'Standard';
} elseif ($filter === 'pa') {
    $whereParts[] = "(rr.needs_pa = 1 OR rr.status = 'PA required')";
} elseif ($filter === 'controlled') {
    $whereParts[] = "rr.status = ?";
    $params[] = 'Controlled';
} elseif ($filter === 'out') {
    $whereParts[] = "rr.status = ?";
    $params[] = 'Out of refills';
}
$where = implode(' AND ', $whereParts);

$rowsSql = "SELECT
        rr.id, rr.prescription_id, rr.pid, rr.drug, rr.sig, rr.pharmacy_id,
        rr.status, rr.schedule, rr.needs_pa, rr.sub_line, rr.last_filled,
        rr.requested_at,
        pd.fname, pd.lname, pd.pubpid,
        ph.name AS pharmacy_name,
        p.refills AS authorized_refills, p.dosage AS rx_dosage
      FROM cp_refill_requests rr
 LEFT JOIN patient_data pd ON pd.pid = rr.pid
 LEFT JOIN pharmacies ph   ON ph.id = rr.pharmacy_id
 LEFT JOIN prescriptions p ON p.id  = rr.prescription_id
     WHERE {$where}
  ORDER BY rr.requested_at DESC, rr.id DESC
     LIMIT 50";
$rs = sqlStatement($rowsSql, $params);

$rows = [];
while ($r = sqlFetchArray($rs)) {
    $rows[] = $r;
}

// Pre-format each row into the structures the view expects.
$viewRows = [];
foreach ($rows as $r) {
    $name = trim(((string)($r['fname'] ?? '')) . ' ' . ((string)($r['lname'] ?? '')));
    if ($name === '') {
        $name = 'Unknown patient';
    }
    $mrn = '';
    if (!empty($r['pubpid'])) {
        $mrn = '#' . (string)$r['pubpid'];
    } elseif (!empty($r['pid'])) {
        $mrn = '#' . str_pad((string)$r['pid'], 6, '0', STR_PAD_LEFT);
    }
    $pharmacy = (string)($r['pharmacy_name'] ?? '');
    if ($pharmacy === '') {
        $pharmacy = 'Pharmacy not specified';
    }

    // Refill-line presentation.
    $refillLine = '';
    $refillTone = 'muted';
    $authorized = $r['authorized_refills'] !== null ? (int)$r['authorized_refills'] : null;
    if ($r['status'] === 'Out of refills') {
        $refillLine = 'OUT OF REFILLS';
        $refillTone = 'danger';
    } elseif ($r['status'] === 'Controlled') {
        $refillLine = '—';
    } elseif ($r['needs_pa']) {
        $brand = '';
        if (preg_match('/\((.+)\)/', (string)$r['drug'], $m)) {
            $brand = trim($m[1]);
        }
        $refillLine = $brand !== ''
            ? 'PA required (' . $brand . ' brand)'
            : 'PA required';
    } elseif ($authorized !== null) {
        $used = max(0, 5 - $authorized);
        $refillLine = "{$used} of 5 refills used";
    } else {
        $refillLine = '— refills tracked';
    }

    // Status pill.
    $status = (string)$r['status'];
    $statusLabel = $status;
    $statusTone = 'good';
    if ($status === 'Out of refills') {
        $statusTone = 'warn';
    } elseif ($status === 'Controlled') {
        $statusTone = 'warn';
        if (!empty($r['schedule'])) {
            $statusLabel = 'Controlled — ' . $r['schedule'];
        }
    } elseif ($status === 'PA required' || !empty($r['needs_pa']) && $status !== 'Standard') {
        $statusTone = 'violet';
        $statusLabel = 'Prior auth needed';
    } elseif ($status === 'Standard') {
        $statusTone = 'good';
        $statusLabel = 'Standard';
    }

    // Last-filled date (mock format m/d/Y).
    $lastFilled = '';
    if (!empty($r['last_filled'])) {
        $ts = strtotime((string)$r['last_filled']);
        if ($ts) {
            $lastFilled = date('m/d/Y', $ts);
        }
    }

    $viewRows[] = [
        'id'         => (int)$r['id'],
        'name'       => $name,
        'mrn'        => $mrn,
        'pharmacy'   => $pharmacy,
        'pid'        => (int)$r['pid'],
        'drug'       => (string)$r['drug'],
        'sig'        => (string)$r['sig'],
        'refillLine' => $refillLine,
        'refillTone' => $refillTone,
        'statusLabel'=> $statusLabel,
        'statusTone' => $statusTone,
        'lastFilled' => $lastFilled,
        'subLine'    => (string)$r['sub_line'],
    ];
}

// Active sub-tab → header counts.
$pendingTotal = $counts['all'];
$paTotal      = $counts['pa'];
$ctrlTotal    = $counts['controlled'];

// Selected count = all rows in the current view (the mock pre-checks the
// majority of cards; we honor that by treating every visible row as part
// of the bulk action). The bulk-action button is hidden when the queue
// is empty.
$selectedCount = count($viewRows);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('e-Rx Renewals'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Sub-tab strip (Active / New Rx / Renewals / EPCS / Pharmacy / Drug check) */
  .erx-subtabs {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 0 24px;
    display: flex; align-items: center; gap: 4px;
    flex: 0 0 auto;
  }
  .erx-subtabs a {
    appearance: none; background: none; border: 0;
    padding: 14px 16px 12px;
    font-size: 13px; font-weight: 500;
    color: #4F5763; line-height: 1;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    text-decoration: none;
  }
  .erx-subtabs a:hover { color: #0D1B2A; }
  .erx-subtabs a.active {
    color: #008C8C; font-weight: 600;
    border-bottom-color: #008C8C;
  }

  /* Page head — title + sub-meta */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; line-height: 1; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-pagehead .title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }

  /* Filter pills — count badge */
  .cp-filter .pills a {
    display: inline-flex; align-items: center;
    background: #FFFFFF; color: #4F5763;
    border: 1px solid #E4E5E8;
    border-radius: 999px; padding: 6px 12px;
    font-size: 12px; font-weight: 500;
    line-height: 1; text-decoration: none;
    margin-right: 6px;
  }
  .cp-filter .pills a .ct {
    background: #F5F6F7; color: #4F5763;
    margin-left: 6px; padding: 1px 7px; border-radius: 999px;
    font-size: 10px; font-weight: 600;
  }
  .cp-filter .pills a.active { background: #E6F3F3; color: #008C8C; border-color: #008C8C; }
  .cp-filter .pills a.active .ct { background: #008C8C; color: #FFFFFF; }

  /* Refill request card */
  .erx-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 18px;
    display: grid;
    grid-template-columns: 22px 230px 1fr 280px auto;
    align-items: center;
    gap: 18px;
  }

  /* Checkbox */
  .cp-cb {
    width: 18px; height: 18px;
    border: 1.5px solid #C9CDD4; border-radius: 4px;
    background: #FFFFFF; display: inline-block; vertical-align: middle;
    position: relative;
  }
  .cp-cb.on { background: #008C8C; border-color: #008C8C; }
  .cp-cb.on::after {
    content: '✓'; color: #FFFFFF; font-size: 12px; font-weight: 700;
    position: absolute; left: 2px; top: -2px;
  }

  /* Patient block */
  .erx-pt .nm { font-size: 14px; font-weight: 700; color: #0D1B2A; line-height: 1.2; }
  .erx-pt .mrn { font-size: 11px; color: #8A91A1; margin-left: 6px; font-weight: 500; }
  .erx-pt .ph { font-size: 12px; color: #4F5763; margin-top: 4px; line-height: 1.3; }

  /* Drug block */
  .erx-rx { display: flex; gap: 10px; align-items: flex-start; }
  .erx-rx .pill-ic {
    flex: 0 0 auto; font-size: 16px; line-height: 1; margin-top: 1px;
  }
  .erx-rx .body { display: flex; flex-direction: column; gap: 2px; }
  .erx-rx .nm { font-size: 14px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .erx-rx .sig { font-size: 12px; color: #4F5763; line-height: 1.3; }
  .erx-rx .refills { font-size: 11px; color: #8A91A1; margin-top: 2px; line-height: 1.3; }
  .erx-rx .refills.danger { color: #D93838; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase; font-size: 10px; }

  /* Status block */
  .erx-st { display: flex; flex-direction: column; gap: 4px; }
  .erx-st .lbl { font-size: 11px; color: #8A91A1; line-height: 1.3; }
  .erx-st .sub { font-size: 11px; color: #4785D9; line-height: 1.3; font-weight: 500; }

  /* Action cluster */
  .erx-act { display: flex; align-items: center; gap: 6px; }
  .erx-act form { display: inline-flex; margin: 0; }
  .erx-act .cp-btn { padding: 7px 14px; }
  .erx-act .cp-btn.edit {
    display: inline-flex; align-items: center; gap: 4px;
    text-decoration: none;
  }
  .erx-act .cp-btn.edit .caret { color: #8A91A1; font-size: 9px; }
  .cp-btn.primary .ic { font-size: 11px; line-height: 1; margin-right: 1px; }

  /* Header right cluster */
  .cp-pagehead .help {
    background: transparent; color: #4F5763; border: 0;
    padding: 7px 10px; font-size: 12px; font-weight: 500;
  }
  .cp-pagehead .help[disabled] { opacity: 0.55; cursor: not-allowed; }
  .cp-pagehead form { display: inline-flex; margin: 0; }

  .cp-content.tight { gap: 10px; }

  /* Flash banner */
  .cp-flash {
    background: #EBF8F0; color: #1F8C4D;
    border: 1px solid #C7E8D5; border-radius: 8px;
    padding: 8px 14px; font-size: 12px; font-weight: 500;
    margin: 8px 24px 0;
  }

  .cp-empty {
    background: #FFFFFF; border: 1px dashed #E4E5E8;
    border-radius: 12px; padding: 28px;
    text-align: center; color: #8A91A1; font-size: 13px;
  }
</style>
</head>
<body class="cp-arch">

<?php
// Build a helper for sub-tab links — preserves the active filter.
$subtabHref = static function (string $name) use ($filter): string {
    $qs = http_build_query(['subtab' => $name, 'filter' => $filter]);
    return '?' . $qs;
};
$filterHref = static function (string $name) use ($subtab): string {
    $qs = http_build_query(['subtab' => $subtab, 'filter' => $name]);
    return '?' . $qs;
};
?>

<div class="erx-subtabs">
  <a href="<?php echo attr($subtabHref('active')); ?>" class="<?php echo $subtab === 'active' ? 'active' : ''; ?>"><?php echo xlt('Active'); ?></a>
  <a href="<?php echo attr($subtabHref('new_rx')); ?>" class="<?php echo $subtab === 'new_rx' ? 'active' : ''; ?>"><?php echo xlt('New Rx'); ?></a>
  <a href="<?php echo attr($subtabHref('renewals')); ?>" class="<?php echo $subtab === 'renewals' ? 'active' : ''; ?>"><?php echo xlt('Renewals'); ?></a>
  <a href="<?php echo attr($subtabHref('epcs')); ?>" class="<?php echo $subtab === 'epcs' ? 'active' : ''; ?>"><?php echo xlt('EPCS'); ?></a>
  <a href="<?php echo attr($subtabHref('pharmacy')); ?>" class="<?php echo $subtab === 'pharmacy' ? 'active' : ''; ?>"><?php echo xlt('Pharmacy'); ?></a>
  <a href="<?php echo attr($subtabHref('drug_check')); ?>" class="<?php echo $subtab === 'drug_check' ? 'active' : ''; ?>"><?php echo xlt('Drug check'); ?></a>
</div>

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="title"><?php echo xlt('Refill Requests'); ?></span>
      <span class="dot">·</span>
      <span class="meta-light">
        <?php echo text((string)$pendingTotal); ?> <?php echo xlt('pending'); ?>
        &nbsp;·&nbsp;
        <?php echo text((string)$paTotal); ?> <?php echo xlt('PA required'); ?>
        &nbsp;·&nbsp;
        <?php echo text((string)$ctrlTotal); ?> <?php echo xlt('controlled'); ?>
      </span>
    </div>
  </div>
  <?php if ($selectedCount > 0): ?>
    <form method="post" action="">
      <input type="hidden" name="action" value="deny_bulk">
      <?php foreach ($viewRows as $vr): ?>
        <input type="hidden" name="ids[]" value="<?php echo attr((string)$vr['id']); ?>">
      <?php endforeach; ?>
      <button type="submit" class="cp-btn ghost"><?php echo xlt('Deny'); ?></button>
    </form>
    <form method="post" action="">
      <input type="hidden" name="action" value="approve_bulk">
      <?php foreach ($viewRows as $vr): ?>
        <input type="hidden" name="ids[]" value="<?php echo attr((string)$vr['id']); ?>">
      <?php endforeach; ?>
      <button type="submit" class="cp-btn primary"><span class="ic">✓</span> <?php echo xlt('Approve & sign'); ?> <?php echo text((string)$selectedCount); ?> <?php echo xlt('selected'); ?></button>
    </form>
  <?php endif; ?>
  <button type="button" class="help" disabled title="<?php echo xla('Out of scope'); ?>">? <?php echo xlt('Help'); ?></button>
</header>

<?php if ($flash !== null && $flash !== ''): ?>
  <div class="cp-flash"><?php echo text((string)$flash); ?></div>
<?php endif; ?>

<main class="cp-content tight">

  <div class="cp-filter">
    <div class="pills">
      <a href="<?php echo attr($filterHref('all')); ?>" class="<?php echo $filter === 'all' ? 'active' : ''; ?>"><?php echo xlt('All'); ?> <span class="ct"><?php echo text((string)$counts['all']); ?></span></a>
      <a href="<?php echo attr($filterHref('standard')); ?>" class="<?php echo $filter === 'standard' ? 'active' : ''; ?>"><?php echo xlt('Standard'); ?> <span class="ct"><?php echo text((string)$counts['standard']); ?></span></a>
      <a href="<?php echo attr($filterHref('pa')); ?>" class="<?php echo $filter === 'pa' ? 'active' : ''; ?>"><?php echo xlt('PA required'); ?> <span class="ct"><?php echo text((string)$counts['pa']); ?></span></a>
      <a href="<?php echo attr($filterHref('controlled')); ?>" class="<?php echo $filter === 'controlled' ? 'active' : ''; ?>"><?php echo xlt('Controlled'); ?> <span class="ct"><?php echo text((string)$counts['controlled']); ?></span></a>
      <a href="<?php echo attr($filterHref('out')); ?>" class="<?php echo $filter === 'out' ? 'active' : ''; ?>"><?php echo xlt('Out of refills'); ?> <span class="ct"><?php echo text((string)$counts['out']); ?></span></a>
    </div>
  </div>

  <?php if (empty($viewRows)): ?>
    <div class="cp-empty"><?php echo xlt('No refill requests in this view.'); ?></div>
  <?php endif; ?>

  <?php foreach ($viewRows as $vr): ?>
    <div class="erx-card">
      <span class="cp-cb on"></span>

      <div class="erx-pt">
        <div>
          <span class="nm"><?php echo text($vr['name']); ?></span>
          <span class="mrn"><?php echo text($vr['mrn']); ?></span>
        </div>
        <div class="ph"><?php echo text($vr['pharmacy']); ?></div>
      </div>

      <div class="erx-rx">
        <span class="pill-ic">💊</span>
        <div class="body">
          <span class="nm"><?php echo text($vr['drug']); ?></span>
          <span class="sig"><?php echo text($vr['sig']); ?></span>
          <span class="refills <?php echo attr($vr['refillTone']); ?>"><?php echo text($vr['refillLine']); ?></span>
        </div>
      </div>

      <div class="erx-st">
        <span class="cp-status-pill <?php echo attr($vr['statusTone']); ?>" style="align-self:flex-start;"><?php echo text($vr['statusLabel']); ?></span>
        <span class="lbl"><?php echo xlt('Last filled:'); ?> <?php echo text($vr['lastFilled'] !== '' ? $vr['lastFilled'] : '—'); ?></span>
        <?php if ($vr['subLine'] !== ''): ?>
          <span class="sub">✦ <?php echo text($vr['subLine']); ?></span>
        <?php endif; ?>
      </div>

      <div class="erx-act">
        <form method="post" action="">
          <input type="hidden" name="action" value="deny">
          <input type="hidden" name="id" value="<?php echo attr((string)$vr['id']); ?>">
          <button type="submit" class="cp-btn ghost"><?php echo xlt('Deny'); ?></button>
        </form>
        <a class="cp-btn ghost edit" href="<?php echo attr('/interface/patient_file/summary/demographics.php?set_pid=' . $vr['pid']); ?>"><?php echo xlt('Edit'); ?> <span class="caret">▾</span></a>
        <form method="post" action="">
          <input type="hidden" name="action" value="approve_one">
          <input type="hidden" name="id" value="<?php echo attr((string)$vr['id']); ?>">
          <button type="submit" class="cp-btn primary"><span class="ic">✓</span> <?php echo xlt('Approve & sign'); ?></button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

</main>

</body>
</html>
