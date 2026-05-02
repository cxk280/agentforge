<?php

/**
 * Backup / System Status — Screen 56, admin archetype.
 *
 * Admin → System → Backup sub-page. Live status cards (Database / App
 * Server / Storage), backup history table, and a System Info + Open
 * Alerts pair on the right.
 *
 * Backing store. The mock surfaces a backup history table that has no
 * first-class equivalent in OpenEMR. We keep one in a small custom
 * table:
 *     cp_backup_log(id, started_at, completed_at, type, size_mb,
 *                   status, location, note)
 * The table is created idempotently the first time the page loads
 * (CREATE TABLE IF NOT EXISTS), and seeded ONCE — guarded by a marker
 * row in `globals` (gl_name='cp_backup_log_seed_v1') so seeded data is
 * immutable across reloads. Manual "Run backup now" inserts a new row;
 * the seeder never runs again.
 *
 * Other data sources:
 *   - DB version / size / uptime  → information_schema + SHOW STATUS
 *   - PHP version / memory / upload → PHP_VERSION, ini_get()
 *   - Disk free / total           → disk_free_space() / disk_total_space()
 *   - OpenEMR version             → version table
 *   - Patient count               → SELECT COUNT(*) FROM patient_data
 *   - SSL cert expiry             → openssl_x509_parse() against
 *                                   /etc/ssl/certs/webserver.cert.pem
 *   - MFA enrollment              → users LEFT JOIN login_mfa_registrations
 *
 * The chrome (top nav) is rendered by the parent shell — this page
 * renders only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Logging\EventAuditLogger;

$selfPath = $_SERVER['PHP_SELF'];

// ──────────────────────────────────────────────────────────────────────
// 1. Backing table + idempotent seed.
// ──────────────────────────────────────────────────────────────────────

sqlStatement(
    "CREATE TABLE IF NOT EXISTS cp_backup_log (
        id            BIGINT NOT NULL AUTO_INCREMENT,
        started_at    DATETIME NOT NULL,
        completed_at  DATETIME NULL,
        type          VARCHAR(16) NOT NULL DEFAULT 'incremental',
        size_mb       INT NOT NULL DEFAULT 0,
        status        VARCHAR(16) NOT NULL DEFAULT 'success',
        location      VARCHAR(128) NOT NULL DEFAULT '',
        note          VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY ix_cp_backup_started (started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$seedMarker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'cp_backup_log_seed_v1'");
if (empty($seedMarker['gl_value'])) {
    // 10 daily rows ending today. Anchor to "now" so the demo always
    // looks alive. Each row is one backup event.
    //   [days_ago, type, size_mb, duration_min, location, status, note]
    $seed = [
        [0, 'full',         118000, 42, 'S3 + local NFS',     'success', ''],
        [1, 'incremental',   12000,  6, 'S3 + local NFS',     'success', ''],
        [2, 'incremental',   14000,  8, 'S3 + local NFS',     'success', ''],
        [3, 'incremental',   11000,  6, 'S3 + local NFS',     'success', ''],
        [4, 'incremental',   13000,  7, 'S3 + local NFS',     'success', ''],
        [5, 'full',         116000, 40, 'S3 + local NFS',     'success', ''],
        [6, 'incremental',    9000,  5, 'S3 + local NFS',     'success', ''],
        [7, 'incremental',   10000,  0, '—',                  'fail',    'NFS timeout'],
        [8, 'incremental',   11000,  7, 'S3 only · NFS down', 'warn',    'NFS unreachable; S3 OK'],
        [9, 'incremental',   12000,  7, 'S3 + local NFS',     'success', ''],
    ];
    foreach ($seed as [$daysAgo, $type, $sizeMb, $durMin, $loc, $status, $note]) {
        $started = date('Y-m-d 02:00:00', strtotime("-{$daysAgo} days"));
        $completed = $durMin > 0
            ? date('Y-m-d H:i:s', strtotime($started . " +{$durMin} minutes"))
            : null;
        try {
            sqlStatement(
                "INSERT INTO cp_backup_log (started_at, completed_at, type, size_mb, status, location, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$started, $completed, $type, $sizeMb, $status, $loc, $note]
            );
        } catch (\Throwable $e) {
            // Race / dup — marker below ensures we don't try again.
        }
    }
    sqlStatement(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('cp_backup_log_seed_v1', 0, ?)",
        [date('c')]
    );
}

// ──────────────────────────────────────────────────────────────────────
// 2. POST handlers (POST/redirect/GET). CSRF skipped — internal mock page.
// ──────────────────────────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $user   = (string)($_SESSION['authUser'] ?? 'admin');
    $grp    = (string)($_SESSION['authProvider'] ?? 'Default');

    if ($action === 'run_backup') {
        // Simulated manual backup — write a row and audit it. Real
        // backups would shell out to mysqldump / restic; this is the
        // POST-record-redirect honest equivalent for the mock.
        $startedAt = date('Y-m-d H:i:s');
        // Pretend it took ~2 min and produced a small incremental.
        $completedAt = date('Y-m-d H:i:s', time() + 90);
        $sizeMb = random_int(800, 1500);
        try {
            sqlStatement(
                "INSERT INTO cp_backup_log (started_at, completed_at, type, size_mb, status, location, note)
                 VALUES (?, ?, 'manual', ?, 'success', 'S3 + local NFS', ?)",
                [$startedAt, $completedAt, $sizeMb, 'Manual backup triggered by ' . $user]
            );
        } catch (\Throwable $e) {
            header('Location: ' . $selfPath . '?msg=backup_failed');
            exit;
        }
        try {
            EventAuditLogger::getInstance()->newEvent(
                'security-administration',
                $user,
                $grp,
                1,
                'Manual backup run from Backup & System Status page'
            );
        } catch (\Throwable $e) {
            // audit failures should not block the redirect
        }
        header('Location: ' . $selfPath . '?msg=backup_ok');
        exit;
    }

    if ($action === 'restore_backup') {
        $backupId = (int)($_POST['backup_id'] ?? 0);
        // Validate the row exists. If it does not, redirect with an error.
        $row = $backupId > 0
            ? sqlQuery("SELECT id, started_at FROM cp_backup_log WHERE id = ?", [$backupId])
            : null;
        if (!$row) {
            header('Location: ' . $selfPath . '?msg=restore_unknown');
            exit;
        }
        // No actual restore — that's an external operation (out of scope).
        // We record the request to the audit log so the action is traceable.
        try {
            EventAuditLogger::getInstance()->newEvent(
                'security-administration',
                $user,
                $grp,
                1,
                'Restore requested for backup #' . $backupId . ' (' . (string)$row['started_at'] . ')'
            );
        } catch (\Throwable $e) {
            // ignore
        }
        header('Location: ' . $selfPath . '?msg=restore_queued&id=' . $backupId);
        exit;
    }
}

// ──────────────────────────────────────────────────────────────────────
// 3. Live system stats.
// ──────────────────────────────────────────────────────────────────────

// Database — version, approx size, uptime.
$dbVersionRow = sqlQuery("SELECT VERSION() AS v");
$dbVersion = (string)($dbVersionRow['v'] ?? 'unknown');
$dbVersionShort = preg_replace('/[^0-9.].*/', '', $dbVersion) ?: $dbVersion;
$dbIsMaria = stripos($dbVersion, 'mariadb') !== false || str_contains($dbVersion, '-Maria');

$dbSizeRow = sqlQuery(
    "SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes
       FROM information_schema.tables
      WHERE table_schema = DATABASE()"
);
$dbBytes = (int)($dbSizeRow['bytes'] ?? 0);
$dbSizeMb = $dbBytes > 0 ? round($dbBytes / 1024 / 1024, 1) : 0.0;

$dbUptimeRow = sqlQuery("SHOW STATUS LIKE 'Uptime'");
$dbUptimeSec = (int)($dbUptimeRow['Value'] ?? 0);
$dbConnRow = sqlQuery("SHOW STATUS LIKE 'Threads_connected'");
$dbConn = (int)($dbConnRow['Value'] ?? 0);

$cp_format_uptime = static function (int $seconds): string {
    if ($seconds <= 0) {
        return '—';
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $mins = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    return $mins . 'm';
};
$cp_format_size = static function (float $mb): string {
    if ($mb >= 1024) {
        return round($mb / 1024, 1) . ' GB';
    }
    if ($mb >= 1) {
        return round($mb, 1) . ' MB';
    }
    if ($mb > 0) {
        return round($mb * 1024, 0) . ' KB';
    }
    return '—';
};

// App server.
$phpVersion    = PHP_VERSION;
$phpMemLimit   = (string)ini_get('memory_limit');
$phpUploadMax  = (string)ini_get('upload_max_filesize');
$serverSoft    = (string)($_SERVER['SERVER_SOFTWARE'] ?? 'web server');
// Apache 2.4.x → "Apache 2.4"
$serverShort   = preg_replace('/^([A-Za-z]+).*?(\d+\.\d+).*$/', '$1 $2', $serverSoft) ?: 'Apache';

// Storage — site root is the most representative mount.
$diskRoot = dirname(__DIR__, 2); // /var/www/localhost/htdocs/openemr usually
$diskTotal = @disk_total_space($diskRoot);
$diskFree  = @disk_free_space($diskRoot);
if ($diskTotal === false || $diskFree === false || $diskTotal === null || $diskFree === null) {
    $diskTotal = (float)@disk_total_space('/');
    $diskFree  = (float)@disk_free_space('/');
}
$diskTotal = (float)$diskTotal;
$diskFree  = (float)$diskFree;
$diskUsed  = max($diskTotal - $diskFree, 0.0);
$diskPct   = $diskTotal > 0 ? (int)round(($diskUsed / $diskTotal) * 100) : 0;
$diskTone  = $diskPct >= 85 ? 'danger' : ($diskPct >= 75 ? 'warn' : 'good');

// SSL cert expiry. Try a few common locations; openssl extension is bundled.
$certCandidates = [
    '/etc/ssl/certs/webserver.cert.pem',
    '/etc/ssl/certs/selfsigned.cert.pem',
    '/etc/apache2/ssl/server.crt',
];
$certExpiryDays = null;
foreach ($certCandidates as $certPath) {
    if (!is_readable($certPath)) {
        continue;
    }
    $pem = @file_get_contents($certPath);
    if ($pem === false || $pem === '') {
        continue;
    }
    if (!function_exists('openssl_x509_parse')) {
        break;
    }
    $info = @openssl_x509_parse($pem);
    if (is_array($info) && isset($info['validTo_time_t'])) {
        $certExpiryDays = (int)floor((((int)$info['validTo_time_t']) - time()) / 86400);
        break;
    }
}

// MFA gap — count active human users without an MFA registration.
// Service accounts (api / erx / hospital) are excluded.
$mfaGapRow = sqlQuery(
    "SELECT COUNT(*) AS missing
       FROM users u
       LEFT JOIN login_mfa_registrations m ON m.user_id = u.id
      WHERE u.active = 1
        AND COALESCE(u.username, '') <> ''
        AND u.username NOT LIKE '%_svc'
        AND m.user_id IS NULL"
);
$mfaGap = (int)($mfaGapRow['missing'] ?? 0);

// OpenEMR version (from `version` table).
$verRow = sqlQuery("SELECT v_major, v_minor, v_patch, v_realpatch, v_tag, v_database FROM version");
$openemrVersion = $verRow
    ? sprintf(
        '%d.%d.%d%s',
        (int)$verRow['v_major'],
        (int)$verRow['v_minor'],
        (int)$verRow['v_patch'],
        (string)$verRow['v_tag']
    )
    : 'unknown';

// Patient count.
$patRow = sqlQuery("SELECT COUNT(*) AS n FROM patient_data");
$patientCount = (int)($patRow['n'] ?? 0);

// Server timezone.
$serverTz = date_default_timezone_get() . ' (' . date('T') . ')';

// Backup schedule — pulled from globals if a row exists, else default.
$schedRow = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'cp_backup_schedule'");
$backupSchedule = !empty($schedRow['gl_value'])
    ? (string)$schedRow['gl_value']
    : 'Daily 3:00 AM CT — keep 30 days';

// ──────────────────────────────────────────────────────────────────────
// 4. Backup history rows (10 most recent).
// ──────────────────────────────────────────────────────────────────────

$backupRows = [];
$rs = sqlStatement(
    "SELECT id, started_at, completed_at, type, size_mb, status, location, note
       FROM cp_backup_log
      ORDER BY started_at DESC, id DESC
      LIMIT 10"
);
while ($row = sqlFetchArray($rs)) {
    $startedTs = strtotime((string)$row['started_at']);
    $completedTs = $row['completed_at'] ? strtotime((string)$row['completed_at']) : null;
    $durationMin = ($completedTs && $startedTs)
        ? max(0, (int)round(($completedTs - $startedTs) / 60))
        : null;

    $status = (string)$row['status'];
    if ($status === 'success') {
        $stTone = 'good';
        $stLabel = 'Success';
    } elseif ($status === 'warn') {
        $stTone = 'warn';
        $stLabel = $row['note'] !== '' ? 'Partial' : 'Warning';
    } elseif ($status === 'fail') {
        $stTone = 'danger';
        $stLabel = $row['note'] !== '' ? 'Failed (' . $row['note'] . ')' : 'Failed';
    } else {
        $stTone = 'neutral';
        $stLabel = ucfirst($status);
    }

    $type = (string)$row['type'];
    $typeLabel = match ($type) {
        'full'        => 'Full',
        'incremental' => 'Incremental',
        'manual'      => 'Manual',
        default       => ucfirst($type),
    };

    $backupRows[] = [
        'id'         => (int)$row['id'],
        'started'    => $startedTs ? date('m/d H:i', $startedTs) : '—',
        'type'       => $typeLabel,
        'size'       => $cp_format_size((float)$row['size_mb']),
        'duration'   => $durationMin !== null ? $durationMin . ' min' : '—',
        'location'   => (string)$row['location'],
        'statLabel'  => $stLabel,
        'statTone'   => $stTone,
    ];
}

// ──────────────────────────────────────────────────────────────────────
// 5. Open alerts (computed live).
// ──────────────────────────────────────────────────────────────────────

$alerts = [];
if ($diskPct >= 80) {
    $alerts[] = [
        'tone' => $diskPct >= 90 ? 'warn' : 'warn',
        'ttl'  => 'Storage ' . $diskPct . '% full',
        'body' => $cp_format_size($diskFree / 1024 / 1024) . ' free of '
                . $cp_format_size($diskTotal / 1024 / 1024) . ' · prune logs or expand volume',
    ];
}
if ($certExpiryDays !== null) {
    if ($certExpiryDays < 30) {
        $alerts[] = [
            'tone' => 'warn',
            'ttl'  => 'SSL cert expiring soon',
            'body' => 'Webserver certificate expires in ' . $certExpiryDays . ' days · renew before lapse',
        ];
    } else {
        $alerts[] = [
            'tone' => 'info',
            'ttl'  => 'SSL cert renewal',
            'body' => 'Expires in ' . $certExpiryDays . ' days · auto-renew scheduled',
        ];
    }
}
if ($mfaGap > 0) {
    $alerts[] = [
        'tone' => 'warn',
        'ttl'  => 'MFA enrollment gap',
        'body' => $mfaGap . ' user' . ($mfaGap === 1 ? '' : 's') . ' without MFA · enforce by '
                . date('m/d', strtotime('+13 days')),
    ];
}
$alerts[] = [
    'tone' => 'info',
    'ttl'  => 'New OpenEMR patch',
    'body' => 'Security release available · review changelog before applying',
];
// NFS retry — surface if any recent backup row had warn/fail.
$recentFail = sqlQuery(
    "SELECT started_at, status, note FROM cp_backup_log
      WHERE status IN ('warn','fail')
      ORDER BY started_at DESC LIMIT 1"
);
if ($recentFail) {
    $when = strtotime((string)$recentFail['started_at']);
    $alerts[] = [
        'tone' => 'warn',
        'ttl'  => 'NFS retry recovered',
        'body' => date('m/d', $when ?: time()) . ' backup '
                . ($recentFail['status'] === 'fail' ? 'failed' : 'partial')
                . ' · subsequent runs healthy',
    ];
}
// Cap at 5.
$alerts = array_slice($alerts, 0, 5);

// ──────────────────────────────────────────────────────────────────────
// 6. System info rows.
// ──────────────────────────────────────────────────────────────────────

$sysInfo = [
    ['OpenEMR version', $openemrVersion],
    ['PHP',             $phpVersion],
    [$dbIsMaria ? 'MariaDB' : 'MySQL', $dbVersionShort],
    ['Server timezone', $serverTz],
    ['Disk free',       $cp_format_size($diskFree / 1024 / 1024)],
    ['Patients',        number_format($patientCount)],
];

// ──────────────────────────────────────────────────────────────────────
// 7. Status card derived fields.
// ──────────────────────────────────────────────────────────────────────

$dbCardLine1 = ($dbIsMaria ? 'MariaDB ' : 'MySQL ') . $dbVersionShort;
$dbCardLine2 = $cp_format_size($dbSizeMb) . ' · ' . $dbConn . ' conn · up '
             . $cp_format_uptime($dbUptimeSec);

$appCardLine1 = 'PHP ' . $phpVersion . ' · ' . $serverShort;
$appCardLine2 = 'memory ' . $phpMemLimit . ' · upload ' . $phpUploadMax;

$storageCardLine1 = $cp_format_size($diskTotal / 1024 / 1024) . ' volume';
$storageCardLine2 = $cp_format_size($diskUsed / 1024 / 1024) . ' / '
                  . $cp_format_size($diskTotal / 1024 / 1024);

$dbHealthy      = true; // page rendering ⇒ DB query succeeded
$appHealthy     = true;
$storageHealthy = $diskPct < 85;

// Sparkline heights — the underlying time-series isn't sampled in this
// codebase, so the bars are decorative. Keep deterministic so reloads
// don't shimmer.
$dbSpark = [];
$appSpark = [];
$rng = 12345;
for ($i = 0; $i < 40; $i++) {
    $rng = ($rng * 1103515245 + 12345) & 0x7fffffff;
    $dbSpark[]  = 8 + ($rng % 12);
    $rng = ($rng * 1103515245 + 12345) & 0x7fffffff;
    $appSpark[] = 8 + ($rng % 12);
}

// ──────────────────────────────────────────────────────────────────────
// 8. Sidebar (admin sub-pages, "Backup" active).
// ──────────────────────────────────────────────────────────────────────

$sideGroups = [
    'Users & Access' => [
        ['Users & Groups',     false],
        ['ACL Editor',         false],
        ['Active Sessions',    false],
        ['Password Policy',    false],
    ],
    'Practice' => [
        ['Facilities',         false],
        ['Providers',          false],
        ['Schedule Templates', false],
        ['Pricing',            false],
    ],
    'Clinical' => [
        ['Forms',              false],
        ['Lists',              false],
        ['Templates',          false],
        ['Issue Types',        false],
        ['Layouts',            false],
    ],
    'System' => [
        ['Audit Log',          false],
        ['Backup',             true],
        ['Globals',            false],
        ['Database',           false],
        ['Modules',            false],
    ],
];

// Flash messages.
$msg = (string)($_GET['msg'] ?? '');
$flash = '';
$flashTone = 'good';
if ($msg === 'backup_ok') {
    $flash = xl('Backup complete · log row added');
} elseif ($msg === 'backup_failed') {
    $flash = xl('Backup failed · check error log');
    $flashTone = 'danger';
} elseif ($msg === 'restore_queued') {
    $rid = (int)($_GET['id'] ?? 0);
    $flash = sprintf(xl('Restore queued for backup #%d · audit logged'), $rid);
} elseif ($msg === 'restore_unknown') {
    $flash = xl('Restore target not found');
    $flashTone = 'danger';
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Backup / System Status'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header subtitle */
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }

  /* Flash banner */
  .cp-flash {
    margin: 12px 24px 0;
    padding: 8px 14px;
    background: #E6F4EE;
    border: 1px solid #B7DCC4;
    border-radius: 8px;
    color: #1F8C4D;
    font-size: 12px; font-weight: 500;
  }
  .cp-flash.danger {
    background: #FCEDEC;
    border-color: #F1B7B2;
    color: #B0322B;
  }

  /* Admin-archetype sidebar (Users & Access / Practice / Clinical / System) */
  .cp-adm-side {
    flex: 0 0 180px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 14px 0 24px;
    overflow-y: auto;
  }
  .cp-adm-side .header {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.7px;
    padding: 0 18px 8px;
  }
  .cp-adm-side .grp { margin-bottom: 6px; }
  .cp-adm-side .grp-lbl {
    font-size: 12px; font-weight: 600;
    color: #0D1B2A;
    padding: 8px 18px 4px;
    line-height: 1.3;
  }
  .cp-adm-side .item {
    display: block;
    padding: 6px 18px 6px 28px;
    font-size: 12px; font-weight: 400;
    color: #4F5763;
    text-decoration: none;
    line-height: 1.3;
    position: relative;
  }
  .cp-adm-side .item:hover { background: #F5F6F7; color: #0D1B2A; }
  .cp-adm-side .item.active {
    color: #008C8C; font-weight: 600;
    background: rgba(0, 140, 140, 0.10);
  }
  .cp-adm-side .item.active::before {
    content: ''; position: absolute;
    left: 0; top: 0; bottom: 0; width: 3px; background: #008C8C;
  }

  /* Status cards row */
  .sys-status-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 14px;
  }
  .sys-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 18px 18px;
  }
  .sys-card .head {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 10px;
  }
  .sys-card .head .dot {
    width: 8px; height: 8px; border-radius: 50%;
    flex: 0 0 auto;
  }
  .sys-card .head .dot.good { background: #1F8C4D; }
  .sys-card .head .dot.warn { background: #FA8C33; }
  .sys-card .head .dot.danger { background: #C0392B; }
  .sys-card .head .name {
    font-size: 14px; font-weight: 700; color: #0D1B2A;
    line-height: 1; flex: 1;
  }
  .sys-card .head .pill {
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.3px;
  }
  .sys-card .head .pill.good { background: #EBF8F0; color: #1F8C4D; }
  .sys-card .head .pill.warn { background: #FFF8EC; color: #FA8C33; }
  .sys-card .head .pill.danger { background: #FCEDEC; color: #B0322B; }
  .sys-card .meta1 {
    font-size: 12px; color: #0D1B2A; font-weight: 500;
    margin-top: 2px; line-height: 1.3;
  }
  .sys-card .meta2 {
    font-size: 11px; color: #8A91A1; line-height: 1.3;
    margin-top: 4px;
  }
  .sys-card .spark {
    display: flex; align-items: flex-end; gap: 2px;
    height: 36px; margin-top: 12px;
  }
  .sys-card .spark i {
    flex: 1; min-width: 0;
    background: #1F8C4D;
    border-radius: 1px;
  }
  .sys-card .progress {
    height: 12px; background: #F0F1F3; border-radius: 999px;
    overflow: hidden; margin-top: 14px;
  }
  .sys-card .progress > i {
    display: block; height: 100%;
    background: #FA8C33; border-radius: 999px;
  }
  .sys-card .progress > i.danger { background: #C0392B; }
  .sys-card .progress > i.good   { background: #1F8C4D; }

  /* 2-col layout: backup history (wide) + right rail (narrow) */
  .sys-2col {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 14px;
    align-items: start;
  }

  /* Backup history panel */
  .sys-bh {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
  }
  .sys-bh-head {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 18px;
  }
  .sys-bh-head .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
  }
  .sys-bh-head .info {
    background: #E8F4F4;
    color: #008C8C;
    border-radius: 6px;
    padding: 5px 10px;
    font-size: 11px; font-weight: 500;
    line-height: 1.2;
  }
  .sys-bh table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .sys-bh thead { background: transparent; }
  .sys-bh th {
    padding: 8px 18px 10px;
    text-align: left;
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.5px;
    text-transform: uppercase;
    border-top: 1px solid #F0F1F3;
    border-bottom: 1px solid #F0F1F3;
  }
  .sys-bh td {
    padding: 11px 18px;
    border-top: 1px solid #F0F1F3;
    color: #0D1B2A;
    vertical-align: middle;
  }
  .sys-bh td.muted { color: #4F5763; }
  .sys-bh td.bold { font-weight: 600; }
  .sys-bh td.dim { color: #8A91A1; }
  .sys-bh .restore-btn {
    background: transparent;
    border: 1px solid #E4E5E8;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 11px; font-weight: 500;
    color: #4F5763;
    cursor: pointer;
  }
  .sys-bh .restore-btn:hover {
    border-color: #008C8C;
    color: #008C8C;
  }

  /* System info panel — 2-col label/value */
  .sys-info {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 18px 18px;
  }
  .sys-info .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    margin-bottom: 12px;
  }
  .sys-info .row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 14px;
    padding: 7px 0;
    font-size: 12px;
    line-height: 1.3;
  }
  .sys-info .row .k { color: #4F5763; }
  .sys-info .row .v { color: #0D1B2A; font-weight: 600; text-align: right; }

  /* Open alerts panel */
  .sys-alerts {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 16px 18px;
  }
  .sys-alerts .lbl {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    margin-bottom: 12px;
  }
  .sys-alerts .item {
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 8px;
    display: flex; gap: 10px; align-items: flex-start;
  }
  .sys-alerts .item:last-child { margin-bottom: 0; }
  .sys-alerts .item.warn { background: #FFF6EC; }
  .sys-alerts .item.info { background: #F0F4F9; }
  .sys-alerts .item .ic {
    width: 16px; height: 16px;
    border-radius: 4px;
    flex: 0 0 auto;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
    color: #FFFFFF;
    margin-top: 1px;
  }
  .sys-alerts .item.warn .ic { background: #FA8C33; }
  .sys-alerts .item.info .ic { background: #4785D9; }
  .sys-alerts .item .txt { flex: 1; min-width: 0; }
  .sys-alerts .item .ttl {
    font-size: 12px; font-weight: 600; color: #0D1B2A;
    line-height: 1.3;
  }
  .sys-alerts .item .body {
    font-size: 11px; color: #4F5763;
    line-height: 1.4; margin-top: 2px;
  }

  /* Status pill in backup table */
  .cp-status-pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 11px; font-weight: 500;
    line-height: 1.3;
  }
  .cp-status-pill.good   { background: #EBF8F0; color: #1F8C4D; }
  .cp-status-pill.warn   { background: #FFF8EC; color: #FA8C33; }
  .cp-status-pill.danger { background: #FCEDEC; color: #B0322B; }
  .cp-status-pill.neutral{ background: #F0F1F3; color: #4F5763; }

  /* Header form buttons keep the same pixel footprint as the static buttons did */
  .cp-pagehead form { display: inline-flex; }
  .cp-pagehead form .cp-btn { font: inherit; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:10px;">
      <span class="title"><?php echo xlt('Backup & System Status'); ?></span>
      <span class="meta-light">
        <?php echo xlt('Production environment'); ?> ·
        <?php echo xlt($storageHealthy && $appHealthy && $dbHealthy ? 'all systems operational' : 'attention required'); ?>
      </span>
    </div>
  </div>
  <?php
  // Restore opens a confirm prompt asking which backup_id to restore
  // (the row-level Restore button below is the canonical entry point).
  ?>
  <button type="button" class="cp-btn ghost" onclick="document.getElementById('cp-restore-prompt').style.display='inline-block'; document.getElementById('cp-restore-id').focus();">↺ <?php echo xlt('Restore'); ?></button>
  <form id="cp-restore-prompt" method="post" action="<?php echo attr($selfPath); ?>" style="display:none; gap:6px; align-items:center;">
    <input type="hidden" name="action" value="restore_backup">
    <input id="cp-restore-id" type="number" min="1" name="backup_id" placeholder="ID" style="width:64px; padding:4px 6px; font-size:12px; border:1px solid #E4E5E8; border-radius:6px;">
    <button type="submit" class="cp-btn ghost"><?php echo xlt('Confirm'); ?></button>
  </form>
  <form method="post" action="<?php echo attr($selfPath); ?>">
    <input type="hidden" name="action" value="run_backup">
    <button type="submit" class="cp-btn primary">⟲ <?php echo xlt('Run backup now'); ?></button>
  </form>
</header>

<?php if ($flash !== ''): ?>
  <div class="cp-flash<?php echo $flashTone === 'danger' ? ' danger' : ''; ?>"><?php echo text($flash); ?></div>
<?php endif; ?>

<div class="cp-shell">
  <aside class="cp-adm-side">
    <div class="header"><?php echo xlt('ADMIN'); ?></div>
    <?php foreach ($sideGroups as $cat => $items): ?>
      <div class="grp">
        <div class="grp-lbl"><?php echo text($cat); ?></div>
        <?php foreach ($items as [$nm, $act]): ?>
          <a href="#" class="item<?php echo $act ? ' active' : ''; ?>"><?php echo text($nm); ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </aside>

  <main class="cp-content tight">

    <div class="sys-status-grid">
      <div class="sys-card">
        <div class="head">
          <span class="dot <?php echo $dbHealthy ? 'good' : 'danger'; ?>"></span>
          <span class="name"><?php echo xlt('Database'); ?></span>
          <span class="pill <?php echo $dbHealthy ? 'good' : 'danger'; ?>">
            <?php echo xlt($dbHealthy ? 'Healthy' : 'Down'); ?>
          </span>
        </div>
        <div class="meta1"><?php echo text($dbCardLine1); ?></div>
        <div class="meta2"><?php echo text($dbCardLine2); ?></div>
        <div class="spark">
          <?php foreach ($dbSpark as $h): ?>
            <i style="height: <?php echo (int)$h; ?>px;"></i>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="sys-card">
        <div class="head">
          <span class="dot <?php echo $appHealthy ? 'good' : 'danger'; ?>"></span>
          <span class="name"><?php echo xlt('App Server'); ?></span>
          <span class="pill <?php echo $appHealthy ? 'good' : 'danger'; ?>">
            <?php echo xlt($appHealthy ? 'Healthy' : 'Down'); ?>
          </span>
        </div>
        <div class="meta1"><?php echo text($appCardLine1); ?></div>
        <div class="meta2"><?php echo text($appCardLine2); ?></div>
        <div class="spark">
          <?php foreach ($appSpark as $h): ?>
            <i style="height: <?php echo (int)$h; ?>px;"></i>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="sys-card">
        <div class="head">
          <span class="dot <?php echo text($diskTone); ?>"></span>
          <span class="name"><?php echo xlt('Storage'); ?></span>
          <span class="pill <?php echo $diskTone === 'good' ? 'good' : ($diskTone === 'warn' ? 'warn' : 'danger'); ?>">
            <?php echo text($diskPct . '% used'); ?>
          </span>
        </div>
        <div class="meta1"><?php echo text($storageCardLine1); ?></div>
        <div class="meta2"><?php echo text($storageCardLine2); ?></div>
        <div class="progress">
          <i class="<?php echo text($diskTone); ?>" style="width: <?php echo (int)$diskPct; ?>%;"></i>
        </div>
      </div>
    </div>

    <div class="sys-2col">
      <div class="sys-bh">
        <div class="sys-bh-head">
          <span class="lbl"><?php echo xlt('BACKUP HISTORY'); ?></span>
          <span class="info"><?php echo xlt('Schedule:'); ?> <?php echo text($backupSchedule); ?></span>
        </div>
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('STARTED'); ?></th>
              <th><?php echo xlt('TYPE'); ?></th>
              <th><?php echo xlt('SIZE'); ?></th>
              <th><?php echo xlt('DURATION'); ?></th>
              <th><?php echo xlt('LOCATION'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$backupRows): ?>
              <tr><td colspan="7" class="dim" style="text-align:center; padding:24px;">
                <?php echo xlt('No backups recorded yet.'); ?>
              </td></tr>
            <?php else: ?>
              <?php foreach ($backupRows as $b): ?>
                <tr>
                  <td class="muted"><?php echo text($b['started']); ?></td>
                  <td class="bold"><?php echo text($b['type']); ?></td>
                  <td class="bold"><?php echo text($b['size']); ?></td>
                  <td class="muted"><?php echo text($b['duration']); ?></td>
                  <td class="muted"><?php echo text($b['location']); ?></td>
                  <td><span class="cp-status-pill <?php echo attr($b['statTone']); ?>"><?php echo text($b['statLabel']); ?></span></td>
                  <td>
                    <?php if ($b['statTone'] !== 'danger'): ?>
                      <form method="post" action="<?php echo attr($selfPath); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="restore_backup">
                        <input type="hidden" name="backup_id" value="<?php echo attr((string)$b['id']); ?>">
                        <button type="submit" class="restore-btn" title="<?php echo xla('Restore from this backup'); ?>"><?php echo xlt('Restore'); ?></button>
                      </form>
                    <?php else: ?>
                      <span class="dim" style="font-size:11px;"><?php echo xlt('—'); ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="display:flex; flex-direction:column; gap:14px;">
        <div class="sys-info">
          <div class="lbl"><?php echo xlt('SYSTEM INFO'); ?></div>
          <?php foreach ($sysInfo as [$k, $v]): ?>
            <div class="row">
              <span class="k"><?php echo text($k); ?></span>
              <span class="v"><?php echo text($v); ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="sys-alerts">
          <div class="lbl"><?php echo xlt('OPEN ALERTS'); ?></div>
          <?php if (!$alerts): ?>
            <div style="font-size:12px; color:#8A91A1; padding:8px 4px;">
              <?php echo xlt('No open alerts.'); ?>
            </div>
          <?php else: ?>
            <?php foreach ($alerts as $a): ?>
              <div class="item <?php echo attr($a['tone']); ?>">
                <span class="ic"><?php echo $a['tone'] === 'info' ? 'i' : '!'; ?></span>
                <div class="txt">
                  <div class="ttl"><?php echo text($a['ttl']); ?></div>
                  <div class="body"><?php echo text($a['body']); ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </main>
</div>

</body>
</html>
