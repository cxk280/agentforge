<?php

/**
 * Recalls - Screen 33.
 *
 * Cross-patient recall queue: who is due, who is overdue, who has been
 * contacted, and where each outreach attempt sits. Selecting rows arms
 * the "Message N selected" header CTA for bulk SMS / letter outreach.
 *
 * Source of truth: `medex_recalls` joined to `patient_data` (for name +
 * MRN) and `users` (for provider). Three Co-Pilot columns are added by
 * `interface/super/copilot_seed_recalls.php`:
 *   cp_status        - workflow state (sent_awaiting | scheduled |
 *                      no_response | refused | pending_outreach |
 *                      lm_voicemail)
 *   cp_last_contact  - DATETIME of most recent outreach (NULL = none)
 *   cp_attempts      - count of outreach attempts
 *
 * The chrome (top nav, Messages tab strip) is rendered by the parent
 * shell - this page renders only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../copilot_helpers.php");

// -------------------------------------------------------------------------
// Status taxonomy
// -------------------------------------------------------------------------
// status_code => [display label, pill tone]
$statusMeta = [
    'sent_awaiting'    => ['Sent · awaiting',    'warn'],
    'scheduled'        => ['Scheduled',          'good'],
    'no_response'      => ['No response',        'danger'],
    'refused'          => ['Refused',            'danger'],
    'pending_outreach' => ['Pending outreach',   'neutral'],
    'lm_voicemail'     => ['LM left voicemail',  'warn'],
];

// -------------------------------------------------------------------------
// POST handlers (run before any output, redirect on success)
// -------------------------------------------------------------------------
// CSRF skipped - internal mock page; the surrounding OpenEMR auth gate
// (`globals.php` -> `authCheckCore()`) prevents anonymous POSTs.
$selfPath = $_SERVER['PHP_SELF'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'new_recall') {
        $newPid    = (int)($_POST['pid'] ?? 0);
        $newType   = trim((string)($_POST['recall_type'] ?? 'Annual physical'));
        $newDate   = (string)($_POST['recall_date'] ?? date('Y-m-d', strtotime('+30 days')));
        $newProv   = (int)($_POST['provider_id'] ?? 0);
        if ($newPid > 0 && $newType !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
            sqlStatement(
                "INSERT INTO medex_recalls
                    (r_PRACTID, r_pid, r_eventDate, r_facility, r_provider, r_reason,
                     cp_status, cp_last_contact, cp_attempts)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending_outreach', NULL, 0)",
                [1, $newPid, $newDate, 3, $newProv, $newType]
            );
            $row = sqlQuery("SELECT LAST_INSERT_ID() AS id");
            $newId = (int)($row['id'] ?? 0);
            header('Location: ' . $selfPath . '?msg=created_' . $newId);
            exit;
        }
        header('Location: ' . $selfPath . '?msg=create_failed');
        exit;
    }

    if ($action === 'bulk_message') {
        $rawIds = $_POST['ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }
        $ids = [];
        foreach ($rawIds as $id) {
            $i = (int)$id;
            if ($i > 0) {
                $ids[] = $i;
            }
        }
        $count = 0;
        if ($ids !== []) {
            $senderName = (string)($_SESSION['authUser'] ?? 'admin');
            $senderId   = (int)($_SESSION['authUserID'] ?? 1);
            foreach ($ids as $id) {
                $rcl = sqlQuery(
                    "SELECT r.r_pid, r.r_reason, r.r_eventDate,
                            p.fname, p.lname, p.pubpid
                     FROM medex_recalls r
                     LEFT JOIN patient_data p ON p.pid = r.r_pid
                     WHERE r.r_ID = ?",
                    [$id]
                );
                if (empty($rcl)) {
                    continue;
                }
                $body = sprintf(
                    "Recall outreach for %s %s (MRN %s) - %s due %s",
                    (string)($rcl['fname'] ?? ''),
                    (string)($rcl['lname'] ?? ''),
                    (string)($rcl['pubpid'] ?? ''),
                    (string)($rcl['r_reason'] ?? 'recall'),
                    (string)($rcl['r_eventDate'] ?? '')
                );
                sqlStatement(
                    "INSERT INTO onsite_messages
                        (username, message, ip, date, sender_id, recip_id)
                     VALUES (?, ?, ?, NOW(), ?, ?)",
                    [
                        $senderName,
                        $body,
                        (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
                        (string)$senderId,
                        'pid:' . (int)($rcl['r_pid'] ?? 0),
                    ]
                );
                sqlStatement(
                    "UPDATE medex_recalls
                       SET cp_last_contact = NOW(),
                           cp_attempts = cp_attempts + 1,
                           cp_status = CASE
                               WHEN cp_status = 'pending_outreach' THEN 'sent_awaiting'
                               ELSE cp_status
                           END
                     WHERE r_ID = ?",
                    [$id]
                );
                $count++;
            }
        }
        header('Location: ' . $selfPath . '?msg=messaged_' . $count);
        exit;
    }

    if ($action === 'export_csv') {
        $idsRaw = $_POST['ids'] ?? [];
        if (!is_array($idsRaw)) {
            $idsRaw = [$idsRaw];
        }
        $idFilter = [];
        foreach ($idsRaw as $i) {
            $n = (int)$i;
            if ($n > 0) {
                $idFilter[] = $n;
            }
        }
        $where = '1=1';
        $params = [];
        if ($idFilter !== []) {
            $place = implode(',', array_fill(0, count($idFilter), '?'));
            $where .= " AND r.r_ID IN ($place)";
            $params = $idFilter;
        }
        $sql = "SELECT r.r_ID, p.fname, p.lname, p.pubpid, r.r_reason,
                       r.r_eventDate, r.cp_last_contact, r.cp_attempts,
                       r.cp_status, u.fname AS prov_fname, u.lname AS prov_lname,
                       u.title AS prov_title, u.username AS prov_username
                FROM medex_recalls r
                LEFT JOIN patient_data p ON p.pid = r.r_pid
                LEFT JOIN users u ON u.id = r.r_provider
                WHERE $where
                ORDER BY r.r_eventDate";
        $rs = sqlStatement($sql, $params);

        $stamp = date('Ymd_His');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="recalls_' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Recall ID', 'Patient', 'MRN', 'Recall type', 'Due date',
            'Last contact', 'Attempts', 'Status', 'Provider',
        ]);
        while ($r = sqlFetchArray($rs)) {
            $provDisplay = cp_format_provider_name([
                'fname'    => $r['prov_fname'] ?? '',
                'lname'    => $r['prov_lname'] ?? '',
                'title'    => $r['prov_title'] ?? '',
                'username' => $r['prov_username'] ?? '',
            ]);
            fputcsv($out, [
                (string)$r['r_ID'],
                trim((string)($r['fname'] ?? '') . ' ' . (string)($r['lname'] ?? '')),
                (string)($r['pubpid'] ?? ''),
                (string)($r['r_reason'] ?? ''),
                (string)($r['r_eventDate'] ?? ''),
                (string)($r['cp_last_contact'] ?? ''),
                (string)($r['cp_attempts'] ?? '0'),
                (string)($statusMeta[$r['cp_status'] ?? '']
                    [0] ?? ($r['cp_status'] ?? '')),
                $provDisplay,
            ]);
        }
        fclose($out);
        exit;
    }
}

// -------------------------------------------------------------------------
// Filter inputs (GET)
// -------------------------------------------------------------------------
$qSearch = trim((string)($_GET['q'] ?? ''));

$validRecallTypes = ['all', 'physical', 'mammogram', 'colonoscopy', 'lab',
                     'imaging', 'flu', 'pap', 'dexa', 'bp', 'diabetes',
                     'cholesterol', 'intake'];
$fRecallType = strtolower((string)($_GET['recall_type'] ?? 'all'));
if (!in_array($fRecallType, $validRecallTypes, true)) {
    $fRecallType = 'all';
}

$validDueWindow = ['all', 'overdue', 'this_week', 'next_30', 'next_90'];
$fDueWindow = (string)($_GET['due_window'] ?? 'next_30');
if (!in_array($fDueWindow, $validDueWindow, true)) {
    $fDueWindow = 'next_30';
}

$fProvider = (int)($_GET['provider'] ?? 0); // 0 = all

$validLastContact = ['any', 'never', 'last_7', 'last_30', 'over_30'];
$fLastContact = (string)($_GET['last_contact'] ?? 'any');
if (!in_array($fLastContact, $validLastContact, true)) {
    $fLastContact = 'any';
}

$fStatus = strtolower((string)($_GET['status'] ?? 'pending'));
$validStatusFilters = array_merge(['all', 'pending', 'overdue'], array_keys($statusMeta));
if (!in_array($fStatus, $validStatusFilters, true)) {
    $fStatus = 'pending';
}

// -------------------------------------------------------------------------
// Build WHERE clause from filters
// -------------------------------------------------------------------------
$where = '1=1';
$params = [];

// Search across patient name, MRN, recall reason
if ($qSearch !== '') {
    $where .= ' AND (p.fname LIKE ? OR p.lname LIKE ? OR p.pubpid LIKE ? OR r.r_reason LIKE ?)';
    $like = '%' . $qSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Recall type filter - keyword match on r_reason (literal column expr)
$typeKeyword = match ($fRecallType) {
    'physical'    => 'physical',
    'mammogram'   => 'Mammogram',
    'colonoscopy' => 'Colonoscopy',
    'lab'         => 'lab',
    'imaging'     => 'DEXA',
    'flu'         => 'Flu',
    'pap'         => 'Pap',
    'dexa'        => 'DEXA',
    'bp'          => 'BP',
    'diabetes'    => 'Diabetes',
    'cholesterol' => 'Cholesterol',
    'intake'      => 'intake',
    default       => null,
};
if ($typeKeyword !== null) {
    $where .= ' AND r.r_reason LIKE ?';
    $params[] = '%' . $typeKeyword . '%';
}

// Due window filter
switch ($fDueWindow) {
    case 'overdue':
        $where .= ' AND r.r_eventDate < CURDATE()';
        break;
    case 'this_week':
        $where .= ' AND r.r_eventDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
        break;
    case 'next_30':
        $where .= ' AND r.r_eventDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
        break;
    case 'next_90':
        $where .= ' AND r.r_eventDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)';
        break;
    case 'all':
    default:
        // no constraint
        break;
}

if ($fProvider > 0) {
    $where .= ' AND r.r_provider = ?';
    $params[] = $fProvider;
}

switch ($fLastContact) {
    case 'never':
        $where .= ' AND r.cp_last_contact IS NULL';
        break;
    case 'last_7':
        $where .= ' AND r.cp_last_contact IS NOT NULL AND r.cp_last_contact >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        break;
    case 'last_30':
        $where .= ' AND r.cp_last_contact IS NOT NULL AND r.cp_last_contact >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        break;
    case 'over_30':
        $where .= ' AND r.cp_last_contact IS NOT NULL AND r.cp_last_contact <  DATE_SUB(NOW(), INTERVAL 30 DAY)';
        break;
    case 'any':
    default:
        break;
}

switch ($fStatus) {
    case 'all':
        break;
    case 'pending':
        // Anything except resolved (scheduled / refused) - the default working queue
        $where .= " AND r.cp_status NOT IN ('scheduled', 'refused')";
        break;
    case 'overdue':
        $where .= " AND r.r_eventDate < CURDATE() AND r.cp_status NOT IN ('scheduled', 'refused')";
        break;
    default:
        if (isset($statusMeta[$fStatus])) {
            $where .= ' AND r.cp_status = ?';
            $params[] = $fStatus;
        }
        break;
}

// -------------------------------------------------------------------------
// Fetch recall rows
// -------------------------------------------------------------------------
$sql = "SELECT r.r_ID, r.r_pid, r.r_eventDate, r.r_reason,
               r.cp_status, r.cp_last_contact, r.cp_attempts,
               p.fname, p.lname, p.pubpid,
               u.id   AS prov_id,
               u.fname AS prov_fname, u.lname AS prov_lname,
               u.title AS prov_title, u.username AS prov_username
        FROM medex_recalls r
        LEFT JOIN patient_data p ON p.pid = r.r_pid
        LEFT JOIN users u ON u.id = r.r_provider
        WHERE $where
        ORDER BY (r.r_eventDate < CURDATE()) DESC,
                 r.r_eventDate ASC,
                 r.r_ID ASC
        LIMIT 200";
$rs = sqlStatement($sql, $params);

$rows = [];
$selectedCount = 0;
while ($r = sqlFetchArray($rs)) {
    $statusKey  = (string)($r['cp_status'] ?? 'pending_outreach');
    [$stLabel, $stTone] = $statusMeta[$statusKey] ?? [$statusKey, 'neutral'];

    $eventDate = (string)($r['r_eventDate'] ?? '');
    $isOverdue = $eventDate !== ''
        && strtotime($eventDate) < strtotime(date('Y-m-d'))
        && !in_array($statusKey, ['scheduled', 'refused'], true);

    $dueDisplay = $isOverdue ? 'OVERDUE' : ($eventDate !== '' ? date('m/d/Y', strtotime($eventDate)) : '-');

    $lastContact = (string)($r['cp_last_contact'] ?? '');
    if ($lastContact !== '') {
        // Try to derive an outreach method from the reason column (we
        // tagged seeded rows with " (Letter outreach)" etc).
        $method = 'Outreach';
        if (preg_match('/\(([A-Za-z]+) outreach\)/i', (string)$r['r_reason'], $mm)) {
            $method = ucfirst(strtolower($mm[1]));
        }
        $lastDisplay = $method . ' - ' . date('m/d/Y', strtotime($lastContact));
    } else {
        $lastDisplay = '-';
    }

    // Strip parenthetical outreach annotation from the displayed type
    $typeDisplay = trim((string)preg_replace('/\s*\([^)]+\s+outreach\)\s*$/i', '', (string)$r['r_reason']));
    if ($typeDisplay === '') {
        $typeDisplay = '(unspecified)';
    }

    $patientName = trim((string)($r['fname'] ?? '') . ' ' . (string)($r['lname'] ?? ''));
    if ($patientName === '') {
        $patientName = 'Patient #' . (int)$r['r_pid'];
    }
    $mrn = (string)($r['pubpid'] ?? '');
    if ($mrn !== '' && $mrn[0] !== '#') {
        $mrn = '#' . str_pad($mrn, 6, '0', STR_PAD_LEFT);
    }

    $provDisplay = cp_format_provider_name([
        'fname'    => $r['prov_fname'] ?? '',
        'lname'    => $r['prov_lname'] ?? '',
        'title'    => $r['prov_title'] ?? '',
        'username' => $r['prov_username'] ?? '',
    ]);

    // Pre-select rows that need outreach (so the bulk action lights up
    // with a meaningful default count).
    $checked = !in_array($statusKey, ['scheduled', 'refused'], true);
    if ($checked) {
        $selectedCount++;
    }

    $rows[] = [
        'id'         => (int)$r['r_ID'],
        'pid'        => (int)$r['r_pid'],
        'checked'    => $checked,
        'name'       => $patientName,
        'mrn'        => $mrn,
        'type'       => $typeDisplay,
        'due'        => $dueDisplay,
        'overdue'    => $isOverdue,
        'last'       => $lastDisplay,
        'attempts'   => (int)($r['cp_attempts'] ?? 0),
        'status'     => $stLabel,
        'tone'       => $stTone,
        'provider'   => $provDisplay,
    ];
}

// -------------------------------------------------------------------------
// KPI strip - all five values come from real queries
// -------------------------------------------------------------------------
$kpi = sqlQuery(
    "SELECT
        SUM(CASE WHEN r_eventDate < CURDATE() AND cp_status NOT IN ('scheduled','refused') THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN r_eventDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS due_week,
        SUM(CASE WHEN r_eventDate BETWEEN CURDATE() AND LAST_DAY(CURDATE()) THEN 1 ELSE 0 END) AS due_month,
        SUM(CASE WHEN cp_status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled_total,
        SUM(CASE WHEN cp_last_contact IS NOT NULL THEN 1 ELSE 0 END) AS contacted_total,
        SUM(CASE WHEN cp_last_contact >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS contacted_week,
        COUNT(*) AS total
     FROM medex_recalls"
);
$kOverdue   = (int)($kpi['overdue']   ?? 0);
$kDueWeek   = (int)($kpi['due_week']  ?? 0);
$kDueMonth  = (int)($kpi['due_month'] ?? 0);
$kScheduled = (int)($kpi['scheduled_total'] ?? 0);
$kContacted = (int)($kpi['contacted_total'] ?? 0);
$kContactedWeek = (int)($kpi['contacted_week'] ?? 0);
$kTotal     = (int)($kpi['total']     ?? 0);

// Response rate = scheduled / contacted
$kResponseRate = $kContacted > 0
    ? (int)round(($kScheduled / $kContacted) * 100)
    : 0;

// Avg time to schedule = avg days between r_created and cp_last_contact
// for rows that ended up scheduled (proxy: time from recall created to
// the contact that flipped them to scheduled).
$avgRow = sqlQuery(
    "SELECT AVG(TIMESTAMPDIFF(DAY, r_created, cp_last_contact)) AS d
     FROM medex_recalls
     WHERE cp_status = 'scheduled' AND cp_last_contact IS NOT NULL"
);
$kAvgDays = $avgRow && $avgRow['d'] !== null
    ? round((float)$avgRow['d'], 1)
    : null;

// -------------------------------------------------------------------------
// Provider dropdown options (from real users with at least one recall)
// -------------------------------------------------------------------------
$provRs = sqlStatement(
    "SELECT DISTINCT u.id, u.fname, u.lname, u.title, u.username
     FROM medex_recalls r
     JOIN users u ON u.id = r.r_provider
     ORDER BY u.lname, u.fname"
);
$providerOptions = [];
while ($pr = sqlFetchArray($provRs)) {
    $providerOptions[(int)$pr['id']] = cp_format_provider_name([
        'fname'    => $pr['fname'] ?? '',
        'lname'    => $pr['lname'] ?? '',
        'title'    => $pr['title'] ?? '',
        'username' => $pr['username'] ?? '',
    ]);
}

// -------------------------------------------------------------------------
// Flash message
// -------------------------------------------------------------------------
$flashRaw = (string)($_GET['msg'] ?? '');
$flash = null;
if ($flashRaw !== '') {
    if (preg_match('/^messaged_(\d+)$/', $flashRaw, $m)) {
        $flash = ['ok', 'Messaged ' . (int)$m[1] . ' patient' . ((int)$m[1] === 1 ? '' : 's')];
    } elseif (preg_match('/^created_(\d+)$/', $flashRaw, $m)) {
        $flash = ['ok', 'Recall created (#' . (int)$m[1] . ')'];
    } elseif ($flashRaw === 'create_failed') {
        $flash = ['err', 'Could not create recall - missing required fields'];
    }
}

// Helper: render a hidden form preserving current GET filters (for
// submit-links the dropdowns/search use).
function rc_filter_hidden_inputs(): string
{
    $keep = ['q', 'recall_type', 'due_window', 'provider', 'last_contact', 'status'];
    $out = '';
    foreach ($keep as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            $out .= '<input type="hidden" name="' . attr($k) . '" value="' . attr((string)$_GET[$k]) . '">';
        }
    }
    return $out;
}

// All recall-type filter options [value => label]
$recallTypeLabels = [
    'all'         => 'All types',
    'physical'    => 'Annual physical',
    'mammogram'   => 'Mammogram',
    'colonoscopy' => 'Colonoscopy',
    'lab'         => 'Lab',
    'imaging'     => 'Imaging',
    'flu'         => 'Flu shot',
    'pap'         => 'Pap smear',
    'dexa'        => 'DEXA scan',
    'bp'          => 'BP recheck',
    'diabetes'    => 'Diabetes f/u',
    'cholesterol' => 'Cholesterol',
    'intake'      => 'New patient intake',
];
$dueWindowLabels = [
    'all'       => 'All',
    'overdue'   => 'Overdue',
    'this_week' => 'This week',
    'next_30'   => 'Next 30 days',
    'next_90'   => 'Next 90 days',
];
$lastContactLabels = [
    'any'     => 'Any',
    'never'   => 'Never contacted',
    'last_7'  => 'Last 7 days',
    'last_30' => 'Last 30 days',
    'over_30' => 'Over 30 days ago',
];
$statusFilterLabels = [
    'all'              => 'All statuses',
    'pending'          => 'Pending',
    'overdue'          => 'Overdue',
    'sent_awaiting'    => 'Sent · awaiting',
    'scheduled'        => 'Scheduled',
    'no_response'      => 'No response',
    'refused'          => 'Refused',
    'pending_outreach' => 'Pending outreach',
    'lm_voicemail'     => 'LM left voicemail',
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Recalls'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header dot separator + meta */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #4F5763; font-size: 12px; line-height: 1; }
  .cp-pagehead .cp-btn.primary .ic { font-size: 11px; }

  /* Flash strip */
  .rc-flash {
    margin: 0 24px 12px;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
  }
  .rc-flash.ok  { background: #EBF8F0; color: #1F8C4D; border: 1px solid #C6E8D2; }
  .rc-flash.err { background: #FCE7E7; color: #D93838; border: 1px solid #F0C0C0; }

  /* Filter row: search + 5 stacked-label dropdowns */
  .rc-filter {
    display: flex; align-items: flex-end; gap: 10px;
  }
  .rc-search {
    flex: 1 1 auto;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 44px;
    display: flex; align-items: center;
    padding: 0 14px;
    gap: 8px;
  }
  .rc-search .ic { color: #8A91A1; font-size: 13px; }
  .rc-search input {
    border: none; background: transparent; outline: none;
    flex: 1; font-size: 12px; color: #0D1B2A;
  }
  .rc-search input::placeholder { color: #8A91A1; }

  .rc-select {
    flex: 0 0 auto;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 44px;
    padding: 5px 12px;
    display: flex; flex-direction: column; justify-content: center; gap: 2px;
    min-width: 132px;
    position: relative;
  }
  .rc-select .lbl {
    font-size: 9px; color: #8A91A1; font-weight: 500; line-height: 1;
    letter-spacing: 0.2px;
  }
  .rc-select .val {
    font-size: 12px; color: #0D1B2A; font-weight: 500; line-height: 1.2;
    display: flex; align-items: center; gap: 6px;
  }
  .rc-select .val .caret { color: #8A91A1; font-size: 8px; margin-left: auto; }
  .rc-select select {
    position: absolute; inset: 0;
    opacity: 0; cursor: pointer; border: 0; padding: 0;
    font-family: inherit;
  }

  /* KPI strip - 5 columns, with colored value + sub note */
  .rc-kpi-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 0;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 4px;
  }
  .rc-kpi {
    padding: 0 22px;
    display: flex; flex-direction: column; gap: 6px;
    border-right: 1px solid #F0F1F3;
  }
  .rc-kpi:last-child { border-right: none; }
  .rc-kpi .lbl { font-size: 11px; color: #8A91A1; font-weight: 500; line-height: 1.2; }
  .rc-kpi .row {
    display: flex; align-items: baseline; gap: 10px;
  }
  .rc-kpi .val {
    font-size: 28px; font-weight: 700; line-height: 1.1; color: #0D1B2A;
    letter-spacing: -0.4px;
  }
  .rc-kpi .val.red    { color: #D93838; }
  .rc-kpi .val.orange { color: #FA8C33; }
  .rc-kpi .val.green  { color: #1F8C4D; }
  .rc-kpi .sub {
    font-size: 11px; color: #8A91A1; line-height: 1.3;
  }
  .rc-kpi .sub.up   { color: #1F8C4D; }
  .rc-kpi .sub.down { color: #1F8C4D; }
  .rc-kpi .sub.red  { color: #D93838; }

  /* Recall queue table */
  .rc-tbl table { font-size: 12px; }
  .rc-tbl th, .rc-tbl td { padding: 11px 14px; }
  .rc-tbl th.cb, .rc-tbl td.cb { width: 36px; padding-left: 18px; padding-right: 8px; }
  .rc-tbl th.act, .rc-tbl td.act { width: 80px; padding-left: 6px; padding-right: 18px; text-align: right; white-space: nowrap; }
  .rc-tbl td.bold { font-weight: 600; color: #0D1B2A; }
  .rc-tbl td.mrn { color: #8A91A1; }
  .rc-tbl td.muted-soft { color: #4F5763; }
  .rc-tbl td.due-overdue {
    color: #D93838; font-weight: 700; letter-spacing: 0.4px; font-size: 11px;
  }
  .rc-tbl td.attempts { color: #4F5763; font-variant-numeric: tabular-nums; }

  /* Patient cell with avatar bubble */
  .rc-pt { display: inline-flex; align-items: center; gap: 10px; }
  .rc-pt .av {
    width: 22px; height: 22px; border-radius: 50%;
    background: #C9CDD4;
    flex: 0 0 auto;
  }
  .rc-pt .nm { font-weight: 600; color: #0D1B2A; }

  /* Checkbox visuals (matches Screen 38 pattern) */
  .cp-cb {
    width: 16px; height: 16px;
    border: 1.5px solid #C9CDD4; border-radius: 4px;
    background: #FFFFFF; display: inline-block; vertical-align: middle;
    position: relative;
  }
  .cp-cb.on { background: #008C8C; border-color: #008C8C; }
  .cp-cb.on::after {
    content: '\2713'; color: #FFFFFF; font-size: 11px; font-weight: 700;
    position: absolute; left: 2px; top: -1px; line-height: 1;
  }
  /* Real input rendered transparently on top of the cosmetic span. */
  .rc-cb-wrap { position: relative; display: inline-block; }
  .rc-cb-wrap input[type=checkbox] {
    position: absolute; inset: 0; width: 16px; height: 16px;
    opacity: 0; margin: 0; cursor: pointer;
  }

  /* "Open ->" link cell */
  .rc-open {
    color: #008C8C; font-weight: 600; font-size: 12px;
    text-decoration: none;
    white-space: nowrap;
  }
  .rc-open:hover { color: #00787A; }

  /* Header inline form for header buttons */
  .rc-headform { display: inline; }
  .rc-headform .cp-btn { display: inline-flex; align-items: center; gap: 4px; }
  .rc-headform .cp-btn[disabled] { opacity: 0.5; cursor: not-allowed; }
</style>
</head>
<body class="cp-arch">

<!-- Stand-alone bulk-message form. Header CTA + each row checkbox use
     the HTML5 form="rc-bulk-form" attribute to participate without
     nesting forms (which is invalid HTML and breaks layout). -->
<form id="rc-bulk-form" method="post" action="<?php echo attr($selfPath); ?>" style="display:none;">
  <input type="hidden" name="action" value="bulk_message">
</form>

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="title"><?php echo xlt('Recalls'); ?></span>
      <span class="dot">·</span>
      <span class="meta-light">
        <?php echo text((string)$kTotal); ?> <?php echo xlt('total'); ?> ·
        <?php echo text((string)$kOverdue); ?> <?php echo xlt('overdue'); ?> ·
        <?php echo text((string)$kContactedWeek); ?> <?php echo xlt('contacted this week'); ?>
      </span>
    </div>
  </div>
  <button type="submit" form="rc-export-form" class="cp-btn ghost">
    &#x2913; <?php echo xlt('Export CSV'); ?>
  </button>
  <a href="<?php echo attr($selfPath); ?>?action=new_recall_form" class="cp-btn ghost" style="text-decoration:none;">
    + <?php echo xlt('New recall'); ?>
  </a>
  <button type="submit" form="rc-bulk-form" class="cp-btn primary">
    &#x2709; <?php echo xlt('Message'); ?> <?php echo text((string)$selectedCount); ?> <?php echo xlt('selected'); ?>
  </button>
  <button type="button" class="cp-btn ghost" disabled title="<?php echo xla('Help is out of scope for the demo'); ?>">
    ? <?php echo xlt('Help'); ?>
  </button>
</header>

<?php if ($flash !== null): ?>
  <div class="rc-flash <?php echo attr($flash[0]); ?>"><?php echo text($flash[1]); ?></div>
<?php endif; ?>

<main class="cp-content tight">

  <!-- Filter row: pure GET form so each dropdown change re-issues the
       filtered query. -->
  <form method="get" action="<?php echo attr($selfPath); ?>" class="rc-filter"
        onchange="this.submit()">
    <div class="rc-search">
      <span class="ic">&#x1F50D;</span>
      <input type="text" name="q" value="<?php echo attr($qSearch); ?>"
             placeholder="<?php echo xla('Search patient or recall reason...'); ?>"
             onkeydown="if(event.key==='Enter'){this.form.submit();}">
    </div>
    <div class="rc-select">
      <span class="lbl"><?php echo xlt('Recall type'); ?></span>
      <span class="val"><?php echo text($recallTypeLabels[$fRecallType] ?? 'All types'); ?><span class="caret">&#9662;</span></span>
      <select name="recall_type">
        <?php foreach ($recallTypeLabels as $k => $lbl): ?>
          <option value="<?php echo attr($k); ?>"<?php echo $k === $fRecallType ? ' selected' : ''; ?>>
            <?php echo text($lbl); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="rc-select">
      <span class="lbl"><?php echo xlt('Due window'); ?></span>
      <span class="val"><?php echo text($dueWindowLabels[$fDueWindow] ?? 'Next 30 days'); ?><span class="caret">&#9662;</span></span>
      <select name="due_window">
        <?php foreach ($dueWindowLabels as $k => $lbl): ?>
          <option value="<?php echo attr($k); ?>"<?php echo $k === $fDueWindow ? ' selected' : ''; ?>>
            <?php echo text($lbl); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="rc-select">
      <span class="lbl"><?php echo xlt('Provider'); ?></span>
      <span class="val">
        <?php echo text($fProvider > 0 && isset($providerOptions[$fProvider]) ? $providerOptions[$fProvider] : 'All providers'); ?>
        <span class="caret">&#9662;</span>
      </span>
      <select name="provider">
        <option value="0"<?php echo $fProvider === 0 ? ' selected' : ''; ?>><?php echo xlt('All providers'); ?></option>
        <?php foreach ($providerOptions as $pid => $pname): ?>
          <option value="<?php echo attr((string)$pid); ?>"<?php echo $pid === $fProvider ? ' selected' : ''; ?>>
            <?php echo text($pname); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="rc-select">
      <span class="lbl"><?php echo xlt('Last contact'); ?></span>
      <span class="val"><?php echo text($lastContactLabels[$fLastContact] ?? 'Any'); ?><span class="caret">&#9662;</span></span>
      <select name="last_contact">
        <?php foreach ($lastContactLabels as $k => $lbl): ?>
          <option value="<?php echo attr($k); ?>"<?php echo $k === $fLastContact ? ' selected' : ''; ?>>
            <?php echo text($lbl); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="rc-select">
      <span class="lbl"><?php echo xlt('Status'); ?></span>
      <span class="val"><?php echo text($statusFilterLabels[$fStatus] ?? 'Pending'); ?><span class="caret">&#9662;</span></span>
      <select name="status">
        <?php foreach ($statusFilterLabels as $k => $lbl): ?>
          <option value="<?php echo attr($k); ?>"<?php echo $k === $fStatus ? ' selected' : ''; ?>>
            <?php echo text($lbl); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <div class="rc-kpi-grid">
    <div class="rc-kpi">
      <span class="lbl"><?php echo xlt('Overdue'); ?></span>
      <div class="row">
        <span class="val red"><?php echo text((string)$kOverdue); ?></span>
        <span class="sub"><?php echo xlt('of'); ?> <?php echo text((string)$kTotal); ?> <?php echo xlt('total'); ?></span>
      </div>
    </div>
    <div class="rc-kpi">
      <span class="lbl"><?php echo xlt('Due this week'); ?></span>
      <div class="row">
        <span class="val orange"><?php echo text((string)$kDueWeek); ?></span>
        <span class="sub"><?php echo text((string)$kContactedWeek); ?> <?php echo xlt('contacted'); ?></span>
      </div>
    </div>
    <div class="rc-kpi">
      <span class="lbl"><?php echo xlt('Due this month'); ?></span>
      <div class="row">
        <span class="val"><?php echo text((string)$kDueMonth); ?></span>
        <span class="sub"><?php echo text((string)$kContacted); ?> <?php echo xlt('contacted'); ?></span>
      </div>
    </div>
    <div class="rc-kpi">
      <span class="lbl"><?php echo xlt('Response rate'); ?></span>
      <div class="row">
        <span class="val green"><?php echo text((string)$kResponseRate); ?>%</span>
        <span class="sub"><?php echo text((string)$kScheduled); ?>/<?php echo text((string)$kContacted); ?> <?php echo xlt('scheduled'); ?></span>
      </div>
    </div>
    <div class="rc-kpi">
      <span class="lbl"><?php echo xlt('Avg time to schedule'); ?></span>
      <div class="row">
        <span class="val">
          <?php echo $kAvgDays === null ? '-' : text((string)$kAvgDays . ' d'); ?>
        </span>
        <span class="sub"><?php echo xlt('contact -> scheduled'); ?></span>
      </div>
    </div>
  </div>

  <div class="cp-tbl rc-tbl">
    <table>
      <thead>
        <tr>
          <th class="cb"><span class="cp-cb<?php echo $selectedCount > 0 ? ' on' : ''; ?>"></span></th>
          <th><?php echo xlt('PATIENT'); ?></th>
          <th><?php echo xlt('MRN'); ?></th>
          <th><?php echo xlt('RECALL TYPE'); ?></th>
          <th><?php echo xlt('DUE'); ?></th>
          <th><?php echo xlt('LAST CONTACT'); ?></th>
          <th><?php echo xlt('ATTEMPTS'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('PROVIDER'); ?></th>
          <th class="act"></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows === []): ?>
          <tr>
            <td colspan="10" style="text-align:center; color:#8A91A1; padding:32px 14px;">
              <?php echo xlt('No recalls match the current filters.'); ?>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="cb">
              <span class="rc-cb-wrap">
                <span class="cp-cb<?php echo $row['checked'] ? ' on' : ''; ?>"></span>
                <input type="checkbox" form="rc-bulk-form"
                       name="ids[]" value="<?php echo attr((string)$row['id']); ?>"
                       <?php echo $row['checked'] ? 'checked' : ''; ?>>
              </span>
            </td>
            <td>
              <span class="rc-pt">
                <span class="av"></span>
                <span class="nm"><?php echo text($row['name']); ?></span>
              </span>
            </td>
            <td class="mrn"><?php echo text($row['mrn']); ?></td>
            <td class="muted-soft"><?php echo text($row['type']); ?></td>
            <?php if ($row['overdue']): ?>
              <td class="due-overdue"><?php echo text($row['due']); ?></td>
            <?php else: ?>
              <td class="muted-soft"><?php echo text($row['due']); ?></td>
            <?php endif; ?>
            <td class="muted-soft"><?php echo text($row['last']); ?></td>
            <td class="attempts"><?php echo text((string)$row['attempts']); ?></td>
            <td><span class="cp-status-pill <?php echo attr($row['tone']); ?>"><?php echo text($row['status']); ?></span></td>
            <td class="muted-soft"><?php echo text($row['provider']); ?></td>
            <td class="act">
              <a href="/interface/main/messages/messages.php?recall_id=<?php echo attr((string)$row['id']); ?>" class="rc-open">
                <?php echo xlt('Open'); ?> &rarr;
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

<!-- Hidden export form: posts the same selected ids the bulk-message form
     uses, but routes to action=export_csv. Hidden mirroring is done via
     JS on submit (mirrors checked inputs into a fresh export form). -->
<form id="rc-export-form" method="post" action="<?php echo attr($selfPath); ?>" style="display:none;">
  <input type="hidden" name="action" value="export_csv">
  <!-- ids are injected at submit time by mirrorSelected() -->
  <span id="rc-export-ids"></span>
</form>

<script>
  // When the user clicks "Export CSV", copy the currently-checked
  // checkboxes into the export form so the server can scope the CSV.
  // No selection -> server returns full result set.
  (function () {
    const exportForm = document.getElementById('rc-export-form');
    if (!exportForm) { return; }
    exportForm.addEventListener('submit', function () {
      const target = document.getElementById('rc-export-ids');
      target.innerHTML = '';
      document.querySelectorAll('input[name="ids[]"]:checked').forEach(function (cb) {
        const h = document.createElement('input');
        h.type  = 'hidden';
        h.name  = 'ids[]';
        h.value = cb.value;
        target.appendChild(h);
      });
    });
  })();

  // Keep the cosmetic checkbox span in visual sync with the real input.
  document.querySelectorAll('.rc-cb-wrap input[type=checkbox]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      const span = cb.parentElement.querySelector('.cp-cb');
      if (cb.checked) { span.classList.add('on'); }
      else            { span.classList.remove('on'); }
    });
  });
</script>

</body>
</html>
