<?php

/**
 * Electronic Submissions — Screen 39.
 *
 * Reports → Electronic → Submissions sub-page. Cross-patient view of
 * outbound electronic submissions: CCDA exports, payer claim batches,
 * registry uploads, public-health reports, and HIE deltas.
 *
 * Backing store. OpenEMR has no unified "outbound submission ledger"
 * table — claims live in `billing` / `x12_partners`, immunizations in
 * `immunization_registry_data`, CCDA exports leave only file artifacts.
 * To give the screen a single, authoritative source we keep the ledger
 * in a small custom table:
 *     cp_submissions(id, submission_id, type, destination, target,
 *                    sent_at, status, created_at)
 *
 * The table is created idempotently the first time the page loads
 * (CREATE TABLE IF NOT EXISTS), and seeded ONCE — guarded by a marker
 * row in `globals` (gl_name='cp_submissions_seed_v1') so seeded data
 * is immutable across reloads. Inserts at runtime (Sync now / + New)
 * are kept; the seeder never runs again.
 *
 * The chrome (top nav) is rendered by the parent shell; this page
 * renders only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

// ──────────────────────────────────────────────────────────────────────
// 1. Backing table + idempotent seed.
// ──────────────────────────────────────────────────────────────────────

sqlStatement(
    "CREATE TABLE IF NOT EXISTS cp_submissions (
        id              INT NOT NULL AUTO_INCREMENT,
        submission_id   VARCHAR(20)  NOT NULL DEFAULT '',
        type            VARCHAR(80)  NOT NULL DEFAULT '',
        destination     VARCHAR(255) NOT NULL DEFAULT '',
        target          VARCHAR(255) NOT NULL DEFAULT '',
        sent_at         DATETIME NULL,
        status          VARCHAR(40)  NOT NULL DEFAULT 'Pending',
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY ux_cp_sub_submission_id (submission_id),
        KEY ix_cp_sub_status (status),
        KEY ix_cp_sub_sent (sent_at),
        KEY ix_cp_sub_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$seedMarker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'cp_submissions_seed_v1'");
if (empty($seedMarker['gl_value'])) {
    // Anchor sent_at offsets to "now" so the page looks alive and the
    // KPIs ("Sent today", "Last 30d") return non-zero counts whenever
    // the demo runs. Mock used 04/30 14:22 etc; we map those to
    // today/yesterday at the same wall-clock times.
    $today     = new \DateTimeImmutable('today');
    $yesterday = $today->modify('-1 day');

    $at = static function (\DateTimeImmutable $day, string $hhmm): string {
        return $day->format('Y-m-d') . ' ' . $hhmm . ':00';
    };

    // [submission_id, type, destination, target, sent_at, status]
    $seed = [
        ['SUB-91204', 'CCDA — Continuity', 'Cardiology Associates of Austin', 'Margaret Chen · Full chart',     $at($today,     '14:22'), 'Acknowledged'],
        ['SUB-91203', 'Payer 837P',        'Blue Cross PPO — claims batch',   '47 encounters',                  $at($today,     '13:00'), 'Accepted'],
        ['SUB-91202', 'CCDA — Referral',   'Endocrine Specialists of TX',     'David Kim · Last 90d',           $at($today,     '11:14'), 'Awaiting ack'],
        ['SUB-91201', 'Public health',     'Texas DSHS — Immunization',       'Pediatric batch (12)',           $at($today,     '10:00'), 'Acknowledged'],
        ['SUB-91200', 'Registry — CQM',    'CMS QPP Q1 2026',                 'Practice-wide submission',       $at($today,     '09:30'), 'Pending'],
        ['SUB-91199', 'CCDA — Discharge',  'Patient portal — Margaret Chen',  'Visit summary 04/30',            $at($today,     '08:45'), 'Delivered'],
        ['SUB-91198', 'Payer 837I',        'UnitedHealth — institutional',    '3 facility encounters',          $at($today,     '08:00'), 'Accepted'],
        ['SUB-91197', 'HIE Sync',          'CommonWell',                      'Daily delta (124 patients)',     $at($today,     '06:00'), 'Acknowledged'],
        ['SUB-91196', 'Registry',          'Texas Cancer Registry',           'Quarterly oncology export',      $at($yesterday, '22:00'), 'Acknowledged'],
        ['SUB-91195', 'Public health',     'Texas DSHS — STD',                'Mandatory case report',          $at($yesterday, '16:30'), 'FAILED — bad cert'],
        ['SUB-91194', 'CCDA — Continuity', 'Mercy Home Health',               'Linda Martinez · Care plan',     $at($yesterday, '15:14'), 'Acknowledged'],
        ['SUB-91193', 'Payer 270/271',     'Aetna — eligibility',             'Daily eligibility check',        $at($yesterday, '14:00'), 'Accepted'],
        ['SUB-91192', 'CCDA — Continuity', 'Imaging Center — Riverside',      'Allison Park · MRI request',     $at($yesterday, '11:30'), 'Awaiting ack'],
    ];
    $insertSql = "INSERT INTO cp_submissions (submission_id, type, destination, target, sent_at, status)
                  VALUES (?, ?, ?, ?, ?, ?)";
    foreach ($seed as $row) {
        try {
            sqlStatement($insertSql, $row);
        } catch (\Throwable $e) {
            // Race or duplicate — skip silently; the marker below ensures
            // we don't try again on the next request.
        }
    }
    sqlStatement(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('cp_submissions_seed_v1', 0, ?)",
        [date('c')]
    );
}

// ──────────────────────────────────────────────────────────────────────
// 2. POST handlers (POST/redirect/GET). CSRF skipped — internal mock page.
// ──────────────────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Helper: produce next SUB-NNNNN number from MAX existing.
    $nextSubmissionNumber = static function (): string {
        $row = sqlQuery(
            "SELECT MAX(CAST(SUBSTRING(submission_id, 5) AS UNSIGNED)) AS mx
               FROM cp_submissions WHERE submission_id LIKE 'SUB-%'"
        );
        $next = (int)($row['mx'] ?? 0) + 1;
        if ($next < 91205) {
            $next = 91205;
        }
        return 'SUB-' . $next;
    };

    if ($action === 'sync_now') {
        // Real implementation would dispatch to the HIE/clearinghouse
        // worker. For the demo we record one Pending submission so the
        // user sees the ledger move and KPIs update.
        $subNum = $nextSubmissionNumber();
        sqlStatement(
            "INSERT INTO cp_submissions (submission_id, type, destination, target, sent_at, status)
             VALUES (?, 'HIE Sync', 'CommonWell', 'Manual sync — daily delta', NOW(), 'Pending')",
            [$subNum]
        );
        sqlStatement(
            "INSERT INTO extended_log (date, event, user, recipient, description, patient_id)
             VALUES (NOW(), 'cp_submission_sync', ?, '', ?, 0)",
            [$_SESSION['authUser'] ?? 'system', "Sync queued: {$subNum}"]
        );
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=sync_started');
        exit;
    }

    if ($action === 'new_submission') {
        $subNum = $nextSubmissionNumber();
        $newId = sqlInsert(
            "INSERT INTO cp_submissions (submission_id, type, destination, target, sent_at, status)
             VALUES (?, 'CCDA — Continuity', '', '', NULL, 'Draft')",
            [$subNum]
        );
        sqlStatement(
            "INSERT INTO extended_log (date, event, user, recipient, description, patient_id)
             VALUES (NOW(), 'cp_submission_new', ?, '', ?, 0)",
            [$_SESSION['authUser'] ?? 'system', "New submission draft: {$subNum}"]
        );
        // Detail page is out of scope for this screen; preserve the
        // intended URL shape so the link is honest.
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . (int)$newId . '&msg=' . urlencode("Created {$subNum} (draft)"));
        exit;
    }
}

// ──────────────────────────────────────────────────────────────────────
// 3. Filter (GET) + flash message.
// ──────────────────────────────────────────────────────────────────────

$validFilters = ['all', 'ccda', 'payer', 'registry', 'public_health'];
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$flashRaw = $_GET['msg'] ?? null;
$flashMessages = [
    'sync_started' => 'Sync queued — new submission added to the ledger.',
];
$flashText = null;
if ($flashRaw !== null) {
    $flashText = $flashMessages[$flashRaw] ?? (string)$flashRaw;
}

// Type → filter-bucket helper. Keeps the WHERE clause expressive and
// the pill counts consistent.
$typeFilterFragment = static function (string $bucket): string {
    return match ($bucket) {
        'ccda'          => "type LIKE 'CCDA%'",
        'payer'         => "type LIKE 'Payer%'",
        'registry'      => "type LIKE 'Registry%'",
        'public_health' => "type = 'Public health'",
        default         => '1=1',
    };
};

// ──────────────────────────────────────────────────────────────────────
// 4. KPIs.
// ──────────────────────────────────────────────────────────────────────

$kpiSentToday = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_submissions WHERE sent_at >= CURDATE()"
)['c'] ?? 0);

$kpiAcknowledged = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_submissions WHERE status = 'Acknowledged'"
)['c'] ?? 0);

$kpiPending = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_submissions WHERE status = 'Pending'"
)['c'] ?? 0);

$kpiFailed = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_submissions WHERE status LIKE 'FAILED%'"
)['c'] ?? 0);

$kpiLast30d = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM cp_submissions WHERE sent_at >= NOW() - INTERVAL 30 DAY"
)['c'] ?? 0);

// "last sync 14 min ago" — compute from MAX(sent_at). Falls back to "—"
// if the ledger is empty.
$lastSync = sqlQuery("SELECT MAX(sent_at) AS mx FROM cp_submissions WHERE sent_at IS NOT NULL");
$lastSyncLabel = '—';
if (!empty($lastSync['mx'])) {
    $diffMin = (int)(sqlQuery(
        "SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) AS d",
        [$lastSync['mx']]
    )['d'] ?? 0);
    if ($diffMin < 1) {
        $lastSyncLabel = 'just now';
    } elseif ($diffMin < 60) {
        $lastSyncLabel = $diffMin . ' min ago';
    } elseif ($diffMin < 60 * 24) {
        $lastSyncLabel = (int)floor($diffMin / 60) . ' hr ago';
    } else {
        $lastSyncLabel = (int)floor($diffMin / (60 * 24)) . ' d ago';
    }
}

// ──────────────────────────────────────────────────────────────────────
// 5. Pill counts.
// ──────────────────────────────────────────────────────────────────────

$pillCount = static function (string $bucket) use ($typeFilterFragment): int {
    $where = $typeFilterFragment($bucket);
    $row = sqlQuery("SELECT COUNT(*) AS c FROM cp_submissions WHERE {$where}");
    return (int)($row['c'] ?? 0);
};
$pillCounts = [
    'all'           => $pillCount('all'),
    'ccda'          => $pillCount('ccda'),
    'payer'         => $pillCount('payer'),
    'registry'      => $pillCount('registry'),
    'public_health' => $pillCount('public_health'),
];

// ──────────────────────────────────────────────────────────────────────
// 6. Submission rows (filtered + paginated).
// ──────────────────────────────────────────────────────────────────────

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$where = $typeFilterFragment($filter);
$rows = [];
$rs = sqlStatement(
    "SELECT id, submission_id, type, destination, target, sent_at, status
       FROM cp_submissions
      WHERE {$where}
   ORDER BY sent_at DESC, id DESC
      LIMIT {$perPage} OFFSET {$offset}"
);
while ($r = sqlFetchArray($rs)) {
    $rows[] = $r;
}

// Status → tone for the pill class. Anything starting with FAILED is
// danger; the warns are explicit; everything else is good.
$statusTone = static function (string $status): string {
    if (stripos($status, 'FAILED') === 0) {
        return 'danger';
    }
    return match ($status) {
        'Pending', 'Awaiting ack', 'Draft' => 'warn',
        default => 'good',
    };
};

$formatSent = static function (?string $sentAt): string {
    if ($sentAt === null || $sentAt === '' || $sentAt === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($sentAt);
    if ($ts === false) {
        return '—';
    }
    return date('m/d H:i', $ts);
};

$sidebar = [
    'CLINICAL' => [
        ['Patient List',          false],
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
        ['Submissions',           true],
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
<title><?php echo xlt('Electronic Submissions'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }

  /* Reports left sidebar */
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

  /* 5-col KPI */
  .cp-kpi-5 { grid-template-columns: repeat(5, 1fr); }
  .cp-kpi .val.orange { color: #FA8C33; }
  .cp-kpi .val.red    { color: #D93838; }

  /* Pill counts */
  .cp-filter .pills button .ct {
    background: #F5F6F7; color: #4F5763;
    margin-left: 6px; padding: 1px 7px; border-radius: 999px;
    font-size: 10px; font-weight: 600;
  }
  .cp-filter .pills button.active .ct {
    background: #008C8C; color: #FFFFFF;
  }

  /* Table */
  .cp-tbl table { font-size: 12px; }
  .cp-tbl th { padding: 12px 16px; }
  .cp-tbl td { padding: 12px 16px; }
  .cp-tbl td.act { width: 28px; padding-left: 6px; padding-right: 14px; text-align: right; color: #8A91A1; }
  .cp-tbl td.idcell a {
    color: #008C8C; font-weight: 600; text-decoration: none;
  }

  .cp-status-pill.danger {
    background: #FCE7E7; color: #D93838;
  }

  /* Header inline forms — keep buttons sitting flush like the mock. */
  .cp-pagehead form.inline { display: inline-flex; margin: 0; }

  /* Disabled chrome buttons should look ghosted but not interactive. */
  .cp-btn[disabled] { opacity: 0.5; cursor: not-allowed; }

  .cp-flash {
    margin: 8px 24px 0;
    padding: 8px 12px;
    background: #EBF8F0;
    color: #1F8C4D;
    border: 1px solid #C6E8D2;
    border-radius: 6px;
    font-size: 12px;
  }
</style>
</head>
<body class="cp-arch">

<div class="cp-shell" style="flex-direction:column;">
    <header class="cp-pagehead">
      <div class="info">
        <div style="display:flex; align-items:center; gap:8px;">
          <span class="title"><?php echo xlt('Electronic Submissions'); ?></span>
          <span class="dot">·</span>
          <span class="meta-light"><?php
            echo xlt('CCDA, payer reports, registry exports - last sync') . ' ' . text($lastSyncLabel);
          ?></span>
        </div>
      </div>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="sync_now">
        <button type="submit" class="cp-btn ghost">⟳ <?php echo xlt('Sync now'); ?></button>
      </form>
      <form method="post" class="inline">
        <input type="hidden" name="action" value="new_submission">
        <button type="submit" class="cp-btn primary">+ <?php echo xlt('New submission'); ?></button>
      </form>
      <button type="button" class="cp-btn ghost" disabled title="<?php echo xla('Help — out of scope'); ?>">?</button>
    </header>

    <?php if ($flashText !== null): ?>
      <div class="cp-flash"><?php echo text($flashText); ?></div>
    <?php endif; ?>

    <main class="cp-content tight">

      <div class="cp-kpi-grid cp-kpi-5">
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Sent today'); ?></span>
          <span class="val"><?php echo text((string)$kpiSentToday); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Acknowledged'); ?></span>
          <span class="val"><?php echo text((string)$kpiAcknowledged); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Pending'); ?></span>
          <span class="val orange"><?php echo text((string)$kpiPending); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Failed'); ?></span>
          <span class="val red"><?php echo text((string)$kpiFailed); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Last 30d'); ?></span>
          <span class="val"><?php echo text((string)$kpiLast30d); ?></span>
        </div>
      </div>

      <div class="cp-filter">
        <div class="pills">
          <a href="?filter=all" class="cp-pill-link">
            <button type="button" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">
              <?php echo xlt('All'); ?> <span class="ct"><?php echo text((string)$pillCounts['all']); ?></span>
            </button>
          </a>
          <a href="?filter=ccda" class="cp-pill-link">
            <button type="button" class="<?php echo $filter === 'ccda' ? 'active' : ''; ?>">
              CCDA <span class="ct"><?php echo text((string)$pillCounts['ccda']); ?></span>
            </button>
          </a>
          <a href="?filter=payer" class="cp-pill-link">
            <button type="button" class="<?php echo $filter === 'payer' ? 'active' : ''; ?>">
              <?php echo xlt('Payer'); ?> <span class="ct"><?php echo text((string)$pillCounts['payer']); ?></span>
            </button>
          </a>
          <a href="?filter=registry" class="cp-pill-link">
            <button type="button" class="<?php echo $filter === 'registry' ? 'active' : ''; ?>">
              <?php echo xlt('Registry'); ?> <span class="ct"><?php echo text((string)$pillCounts['registry']); ?></span>
            </button>
          </a>
          <a href="?filter=public_health" class="cp-pill-link">
            <button type="button" class="<?php echo $filter === 'public_health' ? 'active' : ''; ?>">
              <?php echo xlt('Public health'); ?> <span class="ct"><?php echo text((string)$pillCounts['public_health']); ?></span>
            </button>
          </a>
        </div>
      </div>

      <div class="cp-tbl">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('SUBMISSION ID'); ?></th>
              <th><?php echo xlt('TYPE'); ?></th>
              <th><?php echo xlt('DESTINATION'); ?></th>
              <th><?php echo xlt('PATIENT / RECORDS'); ?></th>
              <th><?php echo xlt('SENT'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
              <th class="act"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="muted" style="text-align:center; padding:24px;">
                <?php echo xlt('No submissions match the current filter.'); ?>
              </td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php $tone = $statusTone((string)$r['status']); ?>
                <tr>
                  <td class="idcell">
                    <a href="?id=<?php echo attr((int)$r['id']); ?>"><?php echo text($r['submission_id']); ?></a>
                  </td>
                  <td class="muted"><?php echo text($r['type']); ?></td>
                  <td class="muted"><?php echo text($r['destination']); ?></td>
                  <td class="muted"><?php echo text($r['target']); ?></td>
                  <td class="muted"><?php echo text($formatSent($r['sent_at'])); ?></td>
                  <td>
                    <span class="cp-status-pill <?php echo attr($tone); ?>">
                      <?php echo text($r['status']); ?>
                    </span>
                  </td>
                  <td class="act" title="<?php echo xla('Row actions — out of scope'); ?>">⋯</td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </main>
</div>

</body>
</html>
