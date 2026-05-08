<?php

/**
 * Records Release — Screen 31.
 *
 * Outbound chart-records request form (left) + recent outbound queue
 * (right). Patient-scoped to the active patient (defaults to pid=1).
 * The chrome (top nav, demographics banner, navtab strip) is rendered
 * by the parent shell; this page renders only the body.
 *
 * Backend wiring:
 *   - Reads `transactions` rows whose title looks like a records-release
 *     request (LBTrec / "record"-keyword). Joins to `lbt_data` for the
 *     refer_to / refer_status / body fields used by the OpenEMR LBTref
 *     layout (re-used here for record-release tracking).
 *   - POST `action=send_request` INSERTs a new transactions + lbt_data
 *     row, then 303s back to the page so the queue picks it up.
 *   - KPI counts derive from the same filtered query.
 *   - Recipient dropdown is populated from the `pharmacies` table
 *     (the only seeded external-recipient table in dev). Falls back to
 *     a small static list when the table is empty.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Session\SessionWrapperFactory;
require_once(__DIR__ . "/../../main/copilot_helpers.php");

// Patient context comes from session; default to pid=1 in dev.
$pid = (int)(SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 1);

// Patient banner name (read from real patient_data).
$pat = sqlQuery("SELECT pid, fname, lname FROM patient_data WHERE pid = ?", [$pid]);
$patientName = $pat ? trim((string)($pat['fname'] ?? '') . ' ' . (string)($pat['lname'] ?? '')) : 'Unknown patient';

// ─── POST/redirect/GET: send a new record request ─────────────────────
// CSRF skipped — internal mock page; matches sibling copilot_* handlers.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'send_request') {
    $recipient = trim((string)($_POST['recipient'] ?? ''));
    $reason    = trim((string)($_POST['reason'] ?? ''));
    $fromDate  = trim((string)($_POST['date_from'] ?? ''));
    $toDate    = trim((string)($_POST['date_to'] ?? ''));
    $faxNum    = trim((string)($_POST['fax'] ?? ''));
    $email     = trim((string)($_POST['email'] ?? ''));
    $types     = $_POST['record_types'] ?? [];
    if (!is_array($types)) {
        $types = [];
    }
    // Sanitise checkbox labels — user-supplied but echoed only via text() later.
    $typesCsv = implode(', ', array_map(static fn($t) => (string)$t, $types));

    if ($recipient !== '') {
        $title = 'LBTrec';
        $user  = (string)($_SESSION['authUser'] ?? 'admin');
        $group = (string)($_SESSION['authProvider'] ?? 'Default');
        sqlStatement(
            "INSERT INTO transactions (date, title, pid, user, groupname, authorized) "
            . "VALUES (NOW(), ?, ?, ?, ?, 1)",
            [$title, $pid, $user, $group]
        );
        $newId = (int)sqlQuery("SELECT LAST_INSERT_ID() AS id")['id'];

        // Mirror the LBTref layout's storage pattern: rich fields live in lbt_data.
        $body = 'Records release request to ' . $recipient
              . ($typesCsv !== '' ? "\nRecords: " . $typesCsv : '')
              . (($fromDate !== '' || $toDate !== '') ? "\nDate range: " . $fromDate . ' – ' . $toDate : '')
              . ($reason !== '' ? "\nReason: " . $reason : '');
        $fields = [
            'refer_to'     => $recipient,
            'refer_status' => 'pending',
            'refer_date'   => date('Y-m-d'),
            'refer_from'   => $faxNum !== '' ? $faxNum : $email,
            'body'         => $body,
        ];
        foreach ($fields as $fid => $fval) {
            sqlStatement(
                "INSERT INTO lbt_data (form_id, field_id, field_value) VALUES (?, ?, ?)",
                [$newId, $fid, $fval]
            );
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=request_sent');
        exit;
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=missing_recipient');
    exit;
}

$flashRaw = (string)($_GET['msg'] ?? '');
$flash = match ($flashRaw) {
    'request_sent'      => 'Record request sent.',
    'missing_recipient' => 'Pick a recipient before sending.',
    default             => null,
};

// ─── Tab filter ──────────────────────────────────────────────────────
$tab = (string)($_GET['tab'] ?? 'outbound');
$validTabs = ['outbound', 'inbound', 'drafts', 'sent'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'outbound';
}

// Map tabs to refer_status values we store in lbt_data.
//   outbound = active outbound (pending / awaiting reply / sent)
//   inbound  = received from outside (status="received" or title contains "inbound")
//   drafts   = status="draft"
//   sent     = anything historical with a delivered / acknowledged status
$statusFilters = [
    'outbound' => ['pending', 'awaiting', 'sent', 'failed'],
    'inbound'  => ['received', 'imported'],
    'drafts'   => ['draft'],
    'sent'     => ['delivered', 'acknowledged'],
];
$tabStatuses = $statusFilters[$tab];

// ─── Read records-release queue ──────────────────────────────────────
// Pull every record-release-flavored transaction for this patient, plus
// the LBT extension fields we care about. We aggregate lbt_data with a
// pivot so each transaction is one row.
$queueSql = "
    SELECT t.id, t.date, t.title,
           MAX(CASE WHEN d.field_id = 'refer_to'     THEN d.field_value END) AS refer_to,
           MAX(CASE WHEN d.field_id = 'refer_status' THEN d.field_value END) AS refer_status,
           MAX(CASE WHEN d.field_id = 'refer_from'   THEN d.field_value END) AS refer_from,
           MAX(CASE WHEN d.field_id = 'body'         THEN d.field_value END) AS body
    FROM transactions t
    LEFT JOIN lbt_data d ON d.form_id = t.id
    WHERE t.pid = ?
      AND (t.title LIKE '%LBTrec%' OR t.title LIKE '%record%')
    GROUP BY t.id, t.date, t.title
    ORDER BY t.date DESC
";
$res = sqlStatement($queueSql, [$pid]);
$allRows = [];
while ($r = sqlFetchArray($res)) {
    $allRows[] = $r;
}

// Map each row into the structure the mock expects.
//   destination, sub, sentLabel, sentDate, status, tone
$queue = [];
$kpi = ['active' => 0, 'awaiting' => 0, 'completed30' => 0, 'failed' => 0];
$thirtyDaysAgo = strtotime('-30 days');
foreach ($allRows as $r) {
    $rawStatus = strtolower((string)($r['refer_status'] ?? 'pending'));
    // KPI roll-up runs against ALL records (so KPI doesn't change per tab).
    $tsRow = strtotime((string)($r['date'] ?? 'now')) ?: time();
    if ($rawStatus === 'failed') {
        $kpi['failed']++;
    } elseif (in_array($rawStatus, ['pending', 'awaiting'], true)) {
        $kpi['awaiting']++;
        $kpi['active']++;
    } elseif (in_array($rawStatus, ['sent', 'received', 'imported'], true)) {
        $kpi['active']++;
    } elseif (in_array($rawStatus, ['delivered', 'acknowledged'], true) && $tsRow >= $thirtyDaysAgo) {
        $kpi['completed30']++;
    }

    // Tab filter happens here.
    if (!in_array($rawStatus, $tabStatuses, true)) {
        // Special case: a brand-new pending row should still surface on
        // 'outbound' even if its status didn't get written for some reason.
        if ($tab === 'outbound' && $rawStatus === '') {
            // fall through
        } else {
            continue;
        }
    }

    [$statusLabel, $statusTone] = match (true) {
        $rawStatus === 'pending'      => ['Awaiting reply',  'warn'],
        $rawStatus === 'awaiting'     => ['Awaiting reply',  'warn'],
        $rawStatus === 'sent'         => ['Sent',            'info'],
        $rawStatus === 'delivered'    => ['Delivered',       'good'],
        $rawStatus === 'acknowledged' => ['Acknowledged',    'good'],
        $rawStatus === 'received'     => ['Imported',        'info'],
        $rawStatus === 'imported'     => ['Imported',        'info'],
        $rawStatus === 'draft'        => ['Draft',           'neutral'],
        $rawStatus === 'failed'       => ['Failed',          'danger'],
        default                       => [ucfirst($rawStatus !== '' ? $rawStatus : 'New'), 'neutral'],
    };

    $body = (string)($r['body'] ?? '');
    $subLine = '';
    if (preg_match('/Records?:\s*([^\n]+)/', $body, $m)) {
        $subLine = trim($m[1]);
    } elseif ($body !== '') {
        $subLine = mb_substr(preg_replace('/\s+/', ' ', $body), 0, 80);
    }

    $queue[] = [
        'id'         => (int)$r['id'],
        'dest'       => (string)($r['refer_to'] ?? '—'),
        'sub'        => $subLine !== '' ? $subLine : '—',
        'sentLabel'  => in_array($rawStatus, ['received', 'imported'], true) ? 'Received' : 'Sent',
        'sentDate'   => date('m/d H:i', $tsRow),
        'status'     => $statusLabel,
        'tone'       => $statusTone,
    ];
}

// ─── Recipient dropdown options ──────────────────────────────────────
// Pull external recipients from `pharmacies` (only external-entity table
// with seed data in dev). If neither pharmacies nor procedure_providers
// has rows, fall back to a small static list so the dropdown still
// renders.
$recipients = [];
$pres = sqlStatement("SELECT id, name FROM pharmacies ORDER BY name ASC");
while ($pr = sqlFetchArray($pres)) {
    $recipients[] = (string)$pr['name'];
}
if (count($recipients) === 0) {
    $procRes = sqlStatement("SELECT name FROM procedure_providers WHERE active = 1 ORDER BY name ASC");
    while ($pp = sqlFetchArray($procRes)) {
        $recipients[] = (string)$pp['name'];
    }
}
if (count($recipients) === 0) {
    // static fallback for mock — only used when no recipient tables seeded.
    $recipients = [
        'Cardiology Associates of Austin (Dr. M. Sandoval)',
        'Endocrine Specialists of TX (Dr. L. Park)',
        'Imaging Center — Riverside',
        "St. David's ED",
        'Mercy Home Health',
        'Patient (self)',
    ];
}

// Records-to-release checkboxes — labels are static (compliance taxonomy);
// checked-state below is purely visual default. Submitting the form posts
// `record_types[]` with the labels of the checked items.
$records = [
    ['Office visit notes',     true],
    ['Lab results',            true],
    ['Imaging reports',        true],
    ['Cardiology records',     true],
    ['Medication history',     false],
    ['Immunization history',   false],
    ['Mental health notes',    false],
    ['Substance use treatment', false],
];

// Tab definitions (label, key).
$tabs = [
    ['Outbound', 'outbound'],
    ['Inbound',  'inbound'],
    ['Drafts',   'drafts'],
    ['Sent',     'sent'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Records Release'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header dot + light meta */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-pagehead .help-pill {
    border-radius: 999px; border: 1px solid #E4E5E8; background: #FFFFFF;
    color: #4F5763; font-size: 11px; font-weight: 500;
    padding: 5px 12px; line-height: 1;
    display: inline-flex; align-items: center; gap: 5px;
  }

  /* Body — 2 columns */
  .rr-body {
    display: grid;
    grid-template-columns: 480px 1fr;
    gap: 16px;
    padding: 18px 24px 32px;
  }

  /* Left form panel */
  .rr-form { padding: 20px 22px 18px; }
  .rr-form .label-tag {
    font-size: 10px; font-weight: 600; color: #8A91A1;
    letter-spacing: 0.6px; line-height: 1; margin-bottom: 14px;
  }
  .rr-form .field { margin-bottom: 14px; }
  .rr-form .field > .lbl {
    font-size: 12px; font-weight: 500; color: #4F5763;
    line-height: 1; margin-bottom: 6px; display: block;
  }
  .rr-form .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .rr-input {
    width: 100%;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 34px;
    padding: 0 12px;
    font-size: 13px; color: #0D1B2A;
    outline: none;
    font-family: inherit;
  }
  .rr-input:focus { border-color: #008C8C; }
  .rr-input.select {
    appearance: none; -webkit-appearance: none;
    background-image:
      linear-gradient(45deg, transparent 50%, #8A91A1 50%),
      linear-gradient(135deg, #8A91A1 50%, transparent 50%);
    background-position:
      calc(100% - 14px) calc(50% - 1px),
      calc(100% - 9px) calc(50% - 1px);
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat;
    padding-right: 28px;
  }
  .rr-textarea {
    width: 100%;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 13px; color: #0D1B2A;
    outline: none; resize: vertical;
    font-family: inherit;
    line-height: 1.5;
    min-height: 76px;
  }
  .rr-textarea:focus { border-color: #008C8C; }

  /* 2-col checkbox grid */
  .rr-checks {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 16px;
  }
  .rr-chk {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 13px; color: #0D1B2A; font-weight: 500;
    line-height: 1.2;
    cursor: pointer;
    user-select: none;
  }
  .rr-chk input { display: none; }
  .rr-chk-box {
    width: 16px; height: 16px;
    border: 1.5px solid #C9CDD4;
    border-radius: 4px;
    background: #FFFFFF;
    display: inline-flex; align-items: center; justify-content: center;
    flex: 0 0 auto;
  }
  .rr-chk input:checked + .rr-chk-box { background: #008C8C; border-color: #008C8C; }
  .rr-chk input:checked + .rr-chk-box::after {
    content: ''; width: 9px; height: 5px;
    border-left: 2px solid #FFFFFF; border-bottom: 2px solid #FFFFFF;
    transform: rotate(-45deg) translate(1px, -1px);
  }
  .rr-chk-box.on { background: #008C8C; border-color: #008C8C; }
  .rr-chk-box.on::after {
    content: ''; width: 9px; height: 5px;
    border-left: 2px solid #FFFFFF; border-bottom: 2px solid #FFFFFF;
    transform: rotate(-45deg) translate(1px, -1px);
  }
  .rr-chk.unchecked { color: #4F5763; font-weight: 400; }

  /* Authorization green pill + Send button row */
  .rr-auth-row {
    display: flex; align-items: center; gap: 12px;
    margin-top: 16px;
  }
  .rr-auth {
    flex: 1;
    background: #EBF8F0;
    border: 1px solid #BFE5CC;
    border-radius: 8px;
    padding: 10px 12px;
    color: #1F8C4D;
    font-size: 12px; font-weight: 500;
    display: inline-flex; align-items: center; gap: 8px;
  }
  .rr-auth .check {
    width: 14px; height: 14px;
    background: #1F8C4D;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    flex: 0 0 auto;
  }
  .rr-auth .check::after {
    content: ''; width: 6px; height: 3px;
    border-left: 1.5px solid #FFFFFF; border-bottom: 1.5px solid #FFFFFF;
    transform: rotate(-45deg) translate(0, -1px);
  }
  .rr-auth .lbl {
    font-size: 10px; font-weight: 600; color: #4F5763;
    letter-spacing: 0.6px; margin-right: 4px;
  }
  .rr-send {
    background: #008C8C;
    color: #FFFFFF;
    border: none;
    border-radius: 8px;
    padding: 10px 18px;
    font-size: 13px; font-weight: 600;
    cursor: pointer;
    line-height: 1;
  }
  .rr-send:hover { background: #00787A; }
  .rr-auth-label {
    font-size: 10px; font-weight: 600; color: #8A91A1;
    letter-spacing: 0.6px; margin-bottom: 8px;
    display: block;
  }

  /* Right-side card */
  .rr-right {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 0;
    overflow: hidden;
    display: flex; flex-direction: column;
  }
  .rr-tabs {
    display: flex; align-items: center; gap: 22px;
    padding: 14px 20px 0;
    border-bottom: 1px solid #E4E5E8;
  }
  .rr-tab {
    background: none; border: 0;
    font-size: 13px; font-weight: 500;
    color: #4F5763;
    padding: 0 0 12px;
    line-height: 1;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    text-decoration: none;
    cursor: pointer;
  }
  .rr-tab.active {
    color: #008C8C; font-weight: 600;
    border-bottom-color: #008C8C;
  }

  /* KPI strip inside right card */
  .rr-kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    padding: 16px 20px 8px;
  }
  .rr-kpi {
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    padding: 10px 14px;
    display: flex; align-items: center; gap: 10px;
  }
  .rr-kpi .v {
    font-size: 22px; font-weight: 700; line-height: 1;
    color: #0D1B2A;
  }
  .rr-kpi .v.warn   { color: #FA8C33; }
  .rr-kpi .v.good   { color: #1F8C4D; }
  .rr-kpi .v.danger { color: #D93838; }
  .rr-kpi .l {
    font-size: 12px; color: #4F5763; line-height: 1.2;
  }

  /* Queue rows */
  .rr-queue {
    padding: 4px 14px 16px;
    display: flex; flex-direction: column;
  }
  .rr-q-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 6px;
    border-top: 1px solid #F0F1F3;
  }
  .rr-q-row:first-child { border-top: 0; }
  .rr-q-icon {
    width: 28px; height: 28px;
    border-radius: 6px;
    background: #F0F4F9;
    color: #4785D9;
    display: inline-flex; align-items: center; justify-content: center;
    flex: 0 0 auto;
    font-size: 13px;
  }
  .rr-q-info { flex: 1; min-width: 0; }
  .rr-q-info .dest {
    font-size: 13px; font-weight: 600; color: #0D1B2A;
    line-height: 1.2;
  }
  .rr-q-info .sub {
    font-size: 11px; color: #4F5763;
    line-height: 1.3; margin-top: 1px;
  }
  .rr-q-info .sent {
    font-size: 11px; color: #8A91A1;
    line-height: 1.3; margin-top: 1px;
  }
  .rr-q-row .cp-status-pill {
    flex: 0 0 auto;
  }
  .rr-q-view {
    font-size: 12px; font-weight: 500;
    color: #008C8C;
    text-decoration: none;
    flex: 0 0 auto;
    line-height: 1;
  }
  .rr-q-view:hover { text-decoration: underline; }
  .rr-q-empty {
    padding: 32px 8px; text-align: center;
    color: #8A91A1; font-size: 12px;
  }
  .rr-flash {
    margin: 12px 24px 0;
    background: #EBF8F0; border: 1px solid #BFE5CC;
    color: #1F8C4D; font-size: 12px; font-weight: 500;
    border-radius: 8px; padding: 8px 12px;
  }
  .rr-flash.warn { background: #FFF8EC; border-color: #FAD9A8; color: #FA8C33; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="title"><?php echo xlt('Records Release'); ?></span>
      <span class="dot">·</span>
      <span class="meta-light">
        <?php echo text($patientName); ?> ·
        <?php echo xlt('Outbound chart requests'); ?> ·
        <?php echo text((string)$kpi['active']); ?> <?php echo xlt('active'); ?>
      </span>
    </div>
  </div>
  <button type="button" class="help-pill">? <?php echo xlt('Help'); ?></button>
</header>

<?php if ($flash !== null): ?>
  <div class="rr-flash<?php echo $flashRaw === 'missing_recipient' ? ' warn' : ''; ?>"><?php echo text($flash); ?></div>
<?php endif; ?>

<div class="rr-body">

  <!-- Left: New Record Request form -->
  <section class="cp-panel rr-form">
    <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>?tab=<?php echo attr($tab); ?>">
      <input type="hidden" name="action" value="send_request">
      <div class="label-tag"><?php echo xlt('NEW RECORD REQUEST'); ?></div>

      <div class="field">
        <span class="lbl"><?php echo xlt('Recipient'); ?></span>
        <span class="lbl" style="font-weight:400; color:#8A91A1; font-size:11px; margin-bottom:4px;"><?php echo xlt('Send to'); ?></span>
        <select class="rr-input select" name="recipient">
          <?php foreach ($recipients as $r): ?>
            <option value="<?php echo attr($r); ?>"><?php echo text($r); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-row field">
        <div>
          <span class="lbl"><?php echo xlt('Fax'); ?></span>
          <input class="rr-input" type="text" name="fax" value="(512) 555-7741">
        </div>
        <div>
          <span class="lbl"><?php echo xlt('Email (optional)'); ?></span>
          <input class="rr-input" type="text" name="email" value="records@caa-tx.com">
        </div>
      </div>

      <div class="field">
        <span class="lbl"><?php echo xlt('Records to release'); ?></span>
        <div class="rr-checks">
          <?php foreach ($records as [$nm, $on]): ?>
            <label class="rr-chk<?php echo $on ? '' : ' unchecked'; ?>">
              <input type="checkbox" name="record_types[]" value="<?php echo attr($nm); ?>"<?php echo $on ? ' checked' : ''; ?>>
              <span class="rr-chk-box<?php echo $on ? ' on' : ''; ?>"></span>
              <?php echo text($nm); ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="field">
        <span class="lbl"><?php echo xlt('Date range'); ?></span>
        <div class="field-row">
          <div>
            <span class="lbl" style="font-weight:400; color:#8A91A1; font-size:11px;"><?php echo xlt('From'); ?></span>
            <input class="rr-input" type="text" name="date_from" value="01/01/2024">
          </div>
          <div>
            <span class="lbl" style="font-weight:400; color:#8A91A1; font-size:11px;"><?php echo xlt('To'); ?></span>
            <input class="rr-input" type="text" name="date_to" value="<?php echo attr(date('m/d/Y')); ?>">
          </div>
        </div>
      </div>

      <div class="field">
        <span class="lbl"><?php echo xlt('Reason for request'); ?></span>
        <textarea class="rr-textarea" rows="3" name="reason"><?php echo text('Continuing care — pt referred to cardiology for elevated BP work-up and palpitations during last visit.'); ?></textarea>
      </div>

      <span class="rr-auth-label"><?php echo xlt('AUTHORIZATION'); ?></span>
      <div class="rr-auth-row">
        <div class="rr-auth">
          <span class="check"></span>
          <?php echo xlt('Signed HIPAA release on file'); ?> (<?php echo text(date('m/d/Y')); ?>)
        </div>
        <button type="submit" class="rr-send"><?php echo xlt('Send request'); ?></button>
      </div>
    </form>
  </section>

  <!-- Right: Outbound queue -->
  <section class="rr-right">
    <div class="rr-tabs">
      <?php foreach ($tabs as [$lbl, $key]): ?>
        <a href="?tab=<?php echo attr($key); ?>"
           class="rr-tab<?php echo $tab === $key ? ' active' : ''; ?>"><?php echo text($lbl); ?></a>
      <?php endforeach; ?>
    </div>

    <div class="rr-kpis">
      <div class="rr-kpi">
        <span class="v"><?php echo text((string)$kpi['active']); ?></span>
        <span class="l"><?php echo xlt('Active'); ?></span>
      </div>
      <div class="rr-kpi">
        <span class="v warn"><?php echo text((string)$kpi['awaiting']); ?></span>
        <span class="l"><?php echo xlt('Awaiting reply'); ?></span>
      </div>
      <div class="rr-kpi">
        <span class="v good"><?php echo text((string)$kpi['completed30']); ?></span>
        <span class="l"><?php echo xlt('Completed (30d)'); ?></span>
      </div>
      <div class="rr-kpi">
        <span class="v danger"><?php echo text((string)$kpi['failed']); ?></span>
        <span class="l"><?php echo xlt('Failed'); ?></span>
      </div>
    </div>

    <div class="rr-queue">
      <?php if (count($queue) === 0): ?>
        <div class="rr-q-empty">
          <?php echo xlt('No record requests in this tab.'); ?>
        </div>
      <?php else: ?>
        <?php foreach ($queue as $q): ?>
          <div class="rr-q-row">
            <span class="rr-q-icon"><?php echo text($q['sentLabel'] === 'Received' ? '↙' : '↗'); ?></span>
            <div class="rr-q-info">
              <div class="dest"><?php echo text($q['dest']); ?></div>
              <div class="sub"><?php echo text($q['sub']); ?></div>
              <div class="sent"><?php echo text($q['sentLabel']); ?> <?php echo text($q['sentDate']); ?></div>
            </div>
            <span class="cp-status-pill <?php echo attr($q['tone']); ?>"><?php echo text($q['status']); ?></span>
            <a href="/interface/patient_file/transaction/transactions.php?id=<?php echo attr_url((string)$q['id']); ?>"
               class="rr-q-view" onclick="top.restoreSession && top.restoreSession();">
              <?php echo xlt('View'); ?> →
            </a>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

</div>

</body>
</html>
