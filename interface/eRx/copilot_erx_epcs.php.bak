<?php

declare(strict_types=1);

/**
 * e-Rx EPCS Signing Queue - Screen 42.
 *
 * DEA-controlled prescription signing flow. Renders the EPCS signing
 * queue (background) with the two-factor authentication modal layered
 * on top - enter password + authenticator code, attest 21 CFR 1311
 * compliance, then sign & send.
 *
 * Chrome (top nav, sub-tab strip) is rendered by the parent shell -
 * this page renders only the body.
 *
 * Source data:
 *   - prescriptions.cp_dea_schedule  ('II' / 'III' / 'IV' / 'V')
 *   - prescriptions.cp_dispense_status ('awaiting_sign' / 'signed' / ...)
 *   - prescriptions.cp_date_signed
 *   - prescriptions.cp_signer_user_id
 *
 * The four cp_* columns are added by /interface/super/copilot_seed_epcs.php
 * (idempotent ALTER + INSERT, marker globals.copilot_epcs_v1). If the
 * column is missing, the page degrades to drug-name pattern matching so
 * it never crashes on a fresh DB.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Logging\EventAuditLogger;

// ---- Helpers --------------------------------------------------------------

/**
 * Detect whether the prescriptions table has the EPCS sign-off columns.
 * Driven by INFORMATION_SCHEMA so the page is safe on a DB where the
 * idempotent EPCS seeder has not yet been run.
 */
function cp_epcs_has_columns(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $row = sqlQuery(
        "SELECT COUNT(*) AS n
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'prescriptions'
           AND COLUMN_NAME IN ('cp_dea_schedule', 'cp_dispense_status', 'cp_date_signed', 'cp_signer_user_id')"
    );
    $cache = ((int)($row['n'] ?? 0)) >= 4;
    return $cache;
}

/**
 * SQL fragment that filters to controlled-substance prescriptions that
 * still need EPCS sign-off.
 *
 * Uses cp_dea_schedule when available, otherwise falls back to a
 * drug-name pattern match so the page never breaks on fresh seed data.
 *
 * Returned fragment is a literal SQL string with no user input - safe to
 * concatenate into a WHERE clause.
 */
function cp_epcs_awaiting_where(): string
{
    if (cp_epcs_has_columns()) {
        return "p.cp_dea_schedule IS NOT NULL
                AND COALESCE(p.cp_dispense_status, 'awaiting_sign') = 'awaiting_sign'";
    }
    // Defensive fallback: match controlled substances by name only.
    return "(p.drug REGEXP 'oxycodone|tramadol|lorazepam|adderall|hydrocodone|fentanyl|morphine|codeine|alprazolam|clonazepam|diazepam|methadone|amphetamine|vyvanse|concerta|xanax|valium|ativan|klonopin|ritalin')";
}

/**
 * Map drug name to a DEA schedule label tone. Schedule II is the most
 * tightly controlled, displayed in red; Schedule III/IV/V in amber.
 */
function cp_schedule_tone(?string $schedule): string
{
    return $schedule === 'II' ? 'danger' : 'warn';
}

/**
 * Validate an OTP code: 4-6 digits, allow-list of digits only.
 */
function cp_epcs_valid_otp(string $otp): bool
{
    return preg_match('/^[0-9]{4,6}$/', $otp) === 1;
}

// ---- POST handler: sign_epcs ---------------------------------------------
// CSRF skipped - internal mock page, demo-only flow guarded by session auth.

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'sign_epcs'
) {
    $rxIds = $_POST['rx_ids'] ?? [];
    if (!is_array($rxIds)) {
        $rxIds = [];
    }
    $rxIds = array_values(array_filter(array_map('intval', $rxIds), static fn ($v) => $v > 0));

    $password = (string)($_POST['password'] ?? '');
    $otp      = trim((string)($_POST['otp'] ?? ''));
    $attest   = ($_POST['attest'] ?? '') === '1';

    $errors = [];
    if ($rxIds === []) {
        $errors[] = 'no_rx';
    }
    if ($password === '') {
        $errors[] = 'password';
    }
    if (!cp_epcs_valid_otp($otp)) {
        $errors[] = 'otp';
    }
    if (!$attest) {
        $errors[] = 'attest';
    }

    $signerId   = (int)($_SESSION['authUserID'] ?? 0);
    $signerUser = (string)($_SESSION['authUser'] ?? 'unknown');
    $signerGrp  = (string)($_SESSION['authProvider'] ?? 'Default');

    if ($errors === []) {
        $signed = 0;
        foreach ($rxIds as $rxId) {
            // Confirm the row still exists and is awaiting sign-off (defensive).
            $existing = sqlQuery(
                "SELECT id, patient_id, drug FROM prescriptions WHERE id = ?",
                [$rxId]
            );
            if (empty($existing)) {
                continue;
            }
            if (cp_epcs_has_columns()) {
                sqlStatement(
                    "UPDATE prescriptions
                     SET active = 1,
                         cp_dispense_status = 'signed',
                         cp_date_signed = NOW(),
                         cp_signer_user_id = ?,
                         date_modified = NOW()
                     WHERE id = ?",
                    [$signerId, $rxId]
                );
            } else {
                // No cp_* columns - just flip active so the demo flow still
                // produces a visible side effect on a fresh DB.
                sqlStatement(
                    "UPDATE prescriptions SET active = 1, date_modified = NOW() WHERE id = ?",
                    [$rxId]
                );
            }
            EventAuditLogger::getInstance()->newEvent(
                'sign_epcs',
                $signerUser,
                $signerGrp,
                1,
                'EPCS sign rx_id=' . $rxId . ' drug=' . (string)($existing['drug'] ?? ''),
                isset($existing['patient_id']) ? (int)$existing['patient_id'] : null
            );
            $signed++;
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode('signed_' . $signed));
        exit;
    }

    // Validation failed - redirect back to the originally selected rx so
    // the modal stays open with the same patient context.
    $selected = (int)($_POST['selected'] ?? 0);
    $location = $_SERVER['PHP_SELF']
        . '?msg=' . urlencode('error_' . implode(',', $errors))
        . ($selected > 0 ? '&selected=' . $selected : '');
    header('Location: ' . $location);
    exit;
}

// ---- Flash message decoding ---------------------------------------------
$flash = null;
$flashTone = 'good';
$msg = $_GET['msg'] ?? null;
if (is_string($msg)) {
    if (preg_match('/^signed_(\d+)$/', $msg, $m)) {
        $n = (int)$m[1];
        $flash = $n === 1
            ? '1 prescription signed and queued for transmission'
            : $n . ' prescriptions signed and queued for transmission';
        $flashTone = 'good';
    } elseif (str_starts_with($msg, 'error_')) {
        $codes = explode(',', substr($msg, 6));
        $msgs = [];
        foreach ($codes as $c) {
            $msgs[] = match ($c) {
                'no_rx'    => 'No prescriptions selected',
                'password' => 'Password required',
                'otp'      => 'Authenticator code must be 4-6 digits',
                'attest'   => 'Compliance attestation must be checked',
                default    => 'Unknown error',
            };
        }
        $flash = 'Cannot sign: ' . implode(' . ', $msgs);
        $flashTone = 'danger';
    }
}

// ---- Background queue -----------------------------------------------------
$where = cp_epcs_awaiting_where();
$queueSql = "
    SELECT p.id, p.patient_id, p.drug, p.dosage, p.quantity, p.refills, p.note,
           p.cp_dea_schedule,
           pd.fname, pd.lname, pd.pubpid, pd.DOB, pd.sex
    FROM prescriptions p
    JOIN patient_data pd ON pd.pid = p.patient_id
    WHERE $where
    ORDER BY p.patient_id ASC, p.id ASC
    LIMIT 12
";
$queue = [];
$rs = sqlStatement($queueSql);
while ($row = sqlFetchArray($rs)) {
    $queue[] = $row;
}

// Distinct patient count for the queue header badge.
$distinctPatients = [];
foreach ($queue as $q) {
    $distinctPatients[(int)$q['patient_id']] = true;
}

// ---- Modal patient context -----------------------------------------------
//
// `selected` is the rx_id the user has clicked to focus. If absent or
// invalid, default to the first awaiting-sign rx in the queue. The modal
// then shows ALL awaiting-sign Rx for that patient.
$selectedRxId = (int)($_GET['selected'] ?? 0);
$modalPatient = null;
$modalRxs = [];
$showModal = false;

if ($queue !== []) {
    // Resolve modalPatient from the selected rx (or fall back to first row).
    $focus = null;
    if ($selectedRxId > 0) {
        foreach ($queue as $q) {
            if ((int)$q['id'] === $selectedRxId) {
                $focus = $q;
                break;
            }
        }
    }
    if ($focus === null) {
        $focus = $queue[0];
        $selectedRxId = (int)$focus['id'];
    }

    $modalPatient = $focus;
    $patientId = (int)$focus['patient_id'];
    foreach ($queue as $q) {
        if ((int)$q['patient_id'] === $patientId) {
            $modalRxs[] = $q;
        }
    }
    $showModal = true;
}

// ---- Display helpers ------------------------------------------------------
function cp_epcs_patient_name(array $row): string
{
    $fn = trim((string)($row['fname'] ?? ''));
    $ln = trim((string)($row['lname'] ?? ''));
    $name = trim($fn . ' ' . $ln);
    return $name === '' ? 'Unknown patient' : $name;
}

function cp_epcs_dob(array $row): string
{
    $dob = (string)($row['DOB'] ?? '');
    if ($dob === '' || $dob === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($dob);
    return $ts === false ? '—' : date('m/d/Y', $ts);
}

function cp_epcs_mrn(array $row): string
{
    $mrn = trim((string)($row['pubpid'] ?? ''));
    return $mrn === '' ? '#' . (int)($row['patient_id'] ?? 0) : '#' . $mrn;
}

function cp_epcs_sig_line(array $row): string
{
    // Synthesize a sig line from dosage / quantity / refills / cp_dea_schedule
    $sched   = (string)($row['cp_dea_schedule'] ?? '');
    $dosage  = trim((string)($row['dosage'] ?? ''));
    $qty     = trim((string)($row['quantity'] ?? ''));
    $refills = (int)($row['refills'] ?? 0);
    $bits = [];
    if ($dosage !== '') {
        $bits[] = $sched === 'II' || $sched === 'III' ? 'Tab, ' . $dosage : 'Dose ' . $dosage;
    }
    if ($qty !== '') {
        $bits[] = 'Dispense ' . $qty;
    }
    $bits[] = $refills > 0 ? ($refills . ' refill' . ($refills === 1 ? '' : 's')) : 'no refills';
    return implode(' . ', $bits);
}

function cp_epcs_pharmacy_line(array $row): string
{
    $note = trim((string)($row['note'] ?? ''));
    return $note !== '' ? $note : 'Pharmacy of record on file';
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('e-Rx — EPCS Signing'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page body — relative so the modal can overlay */
  .cp-epcs-wrap { position: relative; padding: 20px 24px 32px; }

  /* Flash banner */
  .cp-flash {
    margin: 0 24px 12px;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 12px; font-weight: 500;
    line-height: 1.4;
  }
  .cp-flash.good   { background: #EBF8F0; border: 1px solid #B6E0C5; color: #1F8C4D; }
  .cp-flash.danger { background: #FCE7E7; border: 1px solid #F4B6B6; color: #D93838; }

  /* Background queue card */
  .cp-queue {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 12px;
    overflow: hidden;
  }
  .cp-queue .head {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 18px; border-bottom: 1px solid #E4E5E8;
  }
  .cp-queue .head .lbl { font-size: 13px; font-weight: 700; color: #0D1B2A; }
  .cp-queue .head .badge {
    margin-left: 8px;
    background: #F5F6F7; color: #4F5763;
    border-radius: 999px; padding: 3px 10px;
    font-size: 10px; font-weight: 600; letter-spacing: 0.3px;
  }
  .cp-queue .empty {
    padding: 24px 20px;
    font-size: 12px; color: #8A91A1; text-align: center;
  }
  .cp-queue .row {
    display: grid;
    grid-template-columns: 22px 32px 1fr 1.4fr 110px;
    align-items: center; gap: 14px;
    padding: 16px 20px;
    border-top: 1px solid #F0F1F3;
    text-decoration: none; color: inherit;
  }
  .cp-queue .row:first-of-type { border-top: 0; }
  .cp-queue .row.is-selected { background: #F0FAFA; }
  .cp-queue .chk {
    width: 16px; height: 16px;
    border-radius: 4px; border: 1.5px solid #C7CBD2;
    display: inline-flex; align-items: center; justify-content: center;
    background: #FFFFFF;
  }
  .cp-queue .chk.on {
    background: #008C8C; border-color: #008C8C; color: #FFFFFF;
    font-size: 10px; font-weight: 800;
  }
  .cp-queue .av {
    width: 32px; height: 32px; border-radius: 50%;
    background: #C7CBD2;
  }
  .cp-queue .who .nm { font-size: 13px; font-weight: 700; color: #0D1B2A; line-height: 1.2; }
  .cp-queue .who .mrn { font-size: 11px; color: #8A91A1; line-height: 1.4; margin-top: 2px; }
  .cp-queue .rx { display: flex; align-items: flex-start; gap: 10px; }
  .cp-queue .rx .pill-ic {
    width: 26px; height: 18px; border-radius: 999px; flex: 0 0 auto;
    margin-top: 2px;
    background: linear-gradient(90deg, #FA8C33 0 50%, #D93838 50% 100%);
    border: 1px solid #00000010;
  }
  .cp-queue .rx .nm { font-size: 13px; font-weight: 700; color: #0D1B2A; line-height: 1.2; }
  .cp-queue .rx .sig { font-size: 11px; color: #8A91A1; line-height: 1.4; margin-top: 2px; }
  .cp-queue .view {
    justify-self: end;
    background: #FFFFFF; border: 1px solid #E4E5E8;
    color: #4F5763; font-size: 11px; font-weight: 500;
    border-radius: 999px; padding: 6px 12px;
    line-height: 1;
    text-decoration: none;
  }

  /* Modal overlay */
  .cp-modal-overlay {
    position: absolute; inset: -10px -24px -32px -24px;
    background: rgba(13, 27, 42, 0.32);
    display: flex; align-items: flex-start; justify-content: center;
    padding-top: 96px;
    z-index: 10;
  }
  .cp-modal {
    background: #FFFFFF; border-radius: 14px;
    box-shadow: 0 24px 48px rgba(13, 27, 42, 0.25), 0 4px 12px rgba(13, 27, 42, 0.10);
    width: 460px; max-width: calc(100% - 48px);
    padding: 22px 24px 20px;
    display: flex; flex-direction: column; gap: 16px;
  }
  .cp-modal-head { display: flex; align-items: flex-start; gap: 12px; }
  .cp-modal-head .lock {
    width: 34px; height: 34px; border-radius: 9px;
    background: #FFF8EC; color: #FA8C33;
    display: inline-flex; align-items: center; justify-content: center;
    flex: 0 0 auto;
    font-size: 16px;
  }
  .cp-modal-head .ti { flex: 1; display: flex; flex-direction: column; gap: 4px; }
  .cp-modal-head .ti .t { font-size: 15px; font-weight: 700; color: #0D1B2A; line-height: 1.2; }
  .cp-modal-head .ti .s { font-size: 12px; color: #8A91A1; line-height: 1.3; }
  .cp-modal-head .x {
    width: 24px; height: 24px;
    border: 0; background: transparent;
    color: #8A91A1; font-size: 16px;
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none;
  }

  .cp-modal .sec-lbl {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    margin-bottom: 8px;
  }

  /* Patient block */
  .cp-modal .pt-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0 0;
  }
  .cp-modal .pt-row .av {
    width: 36px; height: 36px; border-radius: 50%;
    background: #C7CBD2;
  }
  .cp-modal .pt-row .nm { font-size: 13px; font-weight: 700; color: #0D1B2A; line-height: 1.2; }
  .cp-modal .pt-row .meta { font-size: 11px; color: #8A91A1; line-height: 1.4; margin-top: 3px; }

  /* Rx cards */
  .cp-modal .rx-list { display: flex; flex-direction: column; gap: 10px; }
  .cp-modal .rx-item {
    background: #FFFFFF;
    border: 1px solid #E4E5E8; border-radius: 10px;
    padding: 12px 14px;
    display: flex; flex-direction: column; gap: 4px;
  }
  .cp-modal .rx-item .top { display: flex; align-items: center; gap: 8px; }
  .cp-modal .rx-item .num { font-size: 12px; font-weight: 700; color: #4F5763; }
  .cp-modal .rx-item .nm { font-size: 13px; font-weight: 700; color: #0D1B2A; }
  .cp-modal .rx-item .sig { font-size: 11px; color: #8A91A1; line-height: 1.4; }

  /* Field row */
  .cp-modal .fields { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .cp-modal .fld { display: flex; flex-direction: column; gap: 6px; }
  .cp-modal .fld label {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
  }
  .cp-modal .fld input {
    background: #FFFFFF; border: 1px solid #E4E5E8;
    border-radius: 8px; padding: 9px 10px;
    font-size: 13px; color: #0D1B2A;
    outline: none; font-family: inherit;
  }
  .cp-modal .fld input:focus,
  .cp-modal .fld input.focused { border-color: #008C8C; }
  .cp-modal .fld .hint { font-size: 9px; color: #8A91A1; margin-top: 2px; }
  .cp-modal .fld .input-wrap { position: relative; }
  .cp-modal .fld .input-wrap .hint-r {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    font-size: 9px; color: #8A91A1;
    pointer-events: none;
  }
  .cp-modal .fld .auth-input {
    letter-spacing: 6px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    padding-right: 100px;
  }

  /* Attestation row */
  .cp-attest {
    background: #FFF8EC;
    border: 1px solid #F4D9A8;
    border-radius: 8px;
    padding: 10px 12px;
    display: flex; align-items: center; gap: 10px;
    cursor: pointer;
  }
  .cp-attest input[type="checkbox"] {
    appearance: none;
    width: 16px; height: 16px;
    border-radius: 4px;
    background: #FA8C33; border: 1px solid #FA8C33;
    display: inline-flex; align-items: center; justify-content: center;
    flex: 0 0 auto;
    cursor: pointer;
    margin: 0;
    position: relative;
  }
  .cp-attest input[type="checkbox"]::after {
    content: '\2713';
    color: #FFFFFF; font-size: 10px; font-weight: 800;
    line-height: 1;
  }
  .cp-attest input[type="checkbox"]:not(:checked) {
    background: #FFFFFF; border: 1px solid #F4D9A8;
  }
  .cp-attest input[type="checkbox"]:not(:checked)::after { content: ''; }
  .cp-attest .txt { font-size: 11px; color: #4F5763; line-height: 1.4; }

  /* Modal footer buttons */
  .cp-modal-foot { display: flex; justify-content: flex-end; gap: 10px; }
  .cp-modal-foot .cp-btn { padding: 8px 18px; font-size: 12px; }
  .cp-modal-foot .cp-btn[disabled] { opacity: 0.5; cursor: not-allowed; }

  /* Schedule pill colors that override status pill base */
  .cp-status-pill.sched-iv { background: #FFF8EC; color: #FA8C33; }
  .cp-status-pill.sched-ii { background: #FCE7E7; color: #D93838; }

  /* Header help button — disabled */
  .cp-pagehead .cp-btn.ghost[disabled] { opacity: 0.55; cursor: not-allowed; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display: flex; align-items: center; gap: 10px;">
      <span class="title"><?php echo xlt('EPCS Signing Queue'); ?></span>
      <span class="bullet">&bull;</span>
      <span class="meta"><?php echo xlt('DEA-controlled prescriptions'); ?> &middot; <?php echo xlt('2FA required'); ?></span>
    </div>
  </div>
  <button type="button" class="cp-btn ghost" disabled title="<?php echo attr(xl('Help is out of scope for this demo')); ?>">? <?php echo xlt('Help'); ?></button>
</header>

<?php if ($flash !== null): ?>
  <div class="cp-flash <?php echo attr($flashTone); ?>"><?php echo text($flash); ?></div>
<?php endif; ?>

<div class="cp-epcs-wrap">

  <!-- Background queue (dimmed by modal overlay when one is selected) -->
  <section class="cp-queue">
    <div class="head">
      <span class="lbl"><?php echo xlt('Prescriptions awaiting EPCS sign-off'); ?></span>
      <span class="badge">
        <?php echo text(count($queue) . ' ' . ($queue === [] || count($queue) === 1 ? xl('prescription') : xl('prescriptions'))); ?>
        &middot;
        <?php echo text(count($distinctPatients) . ' ' . (count($distinctPatients) === 1 ? xl('patient') : xl('patients'))); ?>
      </span>
    </div>

    <?php if ($queue === []): ?>
      <div class="empty">
        <?php echo xlt('No prescriptions awaiting EPCS sign-off.'); ?><br>
        <span style="font-size:11px; color:#A8AEB9;">
          <?php echo xlt('Run /interface/super/copilot_seed_epcs.php?confirm=1 to seed the EPCS demo queue.'); ?>
        </span>
      </div>
    <?php else: ?>
      <?php foreach ($queue as $i => $r):
        $isSel = ((int)$r['id']) === $selectedRxId;
        $isPatientSel = $modalPatient !== null && ((int)$r['patient_id']) === ((int)$modalPatient['patient_id']);
      ?>
        <a class="row<?php echo $isSel ? ' is-selected' : ''; ?>"
           href="?selected=<?php echo attr((string)(int)$r['id']); ?>">
          <span class="chk<?php echo $isPatientSel ? ' on' : ''; ?>"><?php echo $isPatientSel ? '&#10003;' : ''; ?></span>
          <span class="av"></span>
          <div class="who">
            <div class="nm"><?php echo text(cp_epcs_patient_name($r)); ?></div>
            <div class="mrn"><?php echo text(cp_epcs_mrn($r)); ?></div>
          </div>
          <div class="rx">
            <span class="pill-ic"></span>
            <div>
              <div class="nm"><?php echo text((string)$r['drug']); ?></div>
              <div class="sig"><?php echo text(cp_epcs_sig_line($r)); ?></div>
            </div>
          </div>
          <span class="view"><?php echo xlt('View details'); ?> &rarr;</span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <?php if ($showModal && $modalPatient !== null): ?>
    <!-- Two-factor authentication modal -->
    <div class="cp-modal-overlay">
      <form class="cp-modal" role="dialog" aria-modal="true"
            aria-labelledby="epcs-modal-title"
            method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>"
            autocomplete="off">

        <input type="hidden" name="action" value="sign_epcs">
        <input type="hidden" name="selected" value="<?php echo attr((string)$selectedRxId); ?>">
        <?php foreach ($modalRxs as $rx): ?>
          <input type="hidden" name="rx_ids[]" value="<?php echo attr((string)(int)$rx['id']); ?>">
        <?php endforeach; ?>

        <div class="cp-modal-head">
          <span class="lock">&#128274;</span>
          <div class="ti">
            <span class="t" id="epcs-modal-title"><?php echo xlt('EPCS Two-Factor Authentication'); ?></span>
            <span class="s">
              <?php echo text(sprintf(
                  xl('Sign %1$d controlled Rx for %2$s'),
                  count($modalRxs),
                  cp_epcs_patient_name($modalPatient)
              )); ?>
            </span>
          </div>
          <a class="x" aria-label="<?php echo attr(xl('Close')); ?>" href="?">&times;</a>
        </div>

        <div>
          <div class="sec-lbl"><?php echo xlt('PATIENT'); ?></div>
          <div class="pt-row">
            <span class="av"></span>
            <div>
              <div class="nm"><?php echo text(cp_epcs_patient_name($modalPatient)); ?></div>
              <div class="meta">
                <?php echo xlt('MRN'); ?> <?php echo text(cp_epcs_mrn($modalPatient)); ?>
                &middot; <?php echo xlt('DOB'); ?> <?php echo text(cp_epcs_dob($modalPatient)); ?>
                <?php $sex = trim((string)$modalPatient['sex']); ?>
                <?php if ($sex !== ''): ?>
                  &middot; <?php echo text($sex); ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div>
          <div class="sec-lbl"><?php echo xlt('PRESCRIPTIONS'); ?></div>
          <div class="rx-list">
            <?php foreach ($modalRxs as $i => $rx):
              $sched = (string)($rx['cp_dea_schedule'] ?? '');
              $tone  = cp_schedule_tone($sched);
              $label = $sched !== '' ? 'Schedule ' . $sched : 'Controlled';
            ?>
              <div class="rx-item">
                <div class="top">
                  <span class="num"><?php echo (int)($i + 1); ?>.</span>
                  <span class="nm"><?php echo text((string)$rx['drug']); ?></span>
                  <span class="cp-status-pill <?php echo $tone === 'warn' ? 'sched-iv' : 'sched-ii'; ?>"><?php echo text($label); ?></span>
                </div>
                <div class="sig"><?php echo text(cp_epcs_sig_line($rx)); ?></div>
                <div class="sig"><?php echo text(cp_epcs_pharmacy_line($rx)); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="fields">
          <div class="fld">
            <label for="epcs-pwd"><?php echo xlt('PASSWORD'); ?></label>
            <input id="epcs-pwd" type="password" name="password" autocomplete="off" required>
          </div>
          <div class="fld">
            <label for="epcs-otp"><?php echo xlt('AUTHENTICATOR CODE'); ?></label>
            <div class="input-wrap">
              <input id="epcs-otp" class="auth-input focused" type="text" name="otp"
                     placeholder="4729" maxlength="6" inputmode="numeric"
                     pattern="[0-9]{4,6}" autocomplete="one-time-code" required>
              <span class="hint-r"><?php echo xlt('Yubikey · 6-digit'); ?></span>
            </div>
          </div>
        </div>

        <label class="cp-attest">
          <input type="checkbox" name="attest" value="1" required>
          <span class="txt"><?php echo xlt('I certify these prescriptions comply with 21 CFR 1311 and DEA EPCS requirements.'); ?></span>
        </label>

        <div class="cp-modal-foot">
          <a class="cp-btn ghost" href="?"><?php echo xlt('Cancel'); ?></a>
          <button type="submit" class="cp-btn primary"><?php echo xlt('Sign & send'); ?></button>
        </div>

      </form>
    </div>
  <?php endif; ?>

</div>

</body>
</html>
