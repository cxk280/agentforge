<?php

/**
 * Payment Intake — Screen 58, billing archetype.
 *
 * Patient-scoped page for posting a co-pay / self-pay charge against a
 * patient's open balances. Pulls open charges from `billing` joined to
 * `form_encounter`, computes paid-to-date from `ar_activity`, and on
 * POST writes a new `ar_session` plus per-charge `ar_activity` rows
 * via the OpenEMR PaymentProcessing Recorder.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Session\SessionWrapperFactory;

// Patient context from session; default to pid=1 in dev. cp_helpers not
// strictly needed here but kept for symmetry with other Co-Pilot pages.
require_once(__DIR__ . "/../main/copilot_helpers.php");

$pid = (int)(SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 1);
$userId = (int)($_SESSION['authUserID'] ?? 1);

// --------------------------------------------------------------------
// Patient header
// --------------------------------------------------------------------
$patient = sqlQuery(
    "SELECT pid, fname, lname, email FROM patient_data WHERE pid = ?",
    [$pid]
) ?: ['fname' => '', 'lname' => '', 'email' => ''];
$patientName = trim((string)($patient['fname'] ?? '') . ' ' . (string)($patient['lname'] ?? ''));
if ($patientName === '') {
    $patientName = 'Patient #' . $pid;
}
$patientEmail = (string)($patient['email'] ?? '');

// --------------------------------------------------------------------
// Open balances: charges with activity=1 and a fee, joined to encounter,
// with payments aggregated from ar_activity.
// --------------------------------------------------------------------
$openSql = <<<SQL
    SELECT b.id,
           b.date,
           b.code,
           b.code_type,
           b.code_text,
           b.modifier,
           b.fee,
           b.encounter AS encounter_id,
           fe.reason   AS encounter_reason,
           fe.last_level_closed,
           COALESCE((
               SELECT SUM(a.pay_amount)
                 FROM ar_activity a
                WHERE a.pid = b.pid
                  AND a.encounter = b.encounter
                  AND a.code = b.code
                  AND a.deleted IS NULL
           ), 0) AS paid_amount
      FROM billing b
      JOIN form_encounter fe ON fe.id = b.encounter AND fe.pid = b.pid
     WHERE b.pid = ?
       AND b.activity = 1
       AND COALESCE(b.fee, 0) > 0
     ORDER BY b.date DESC, b.id DESC
SQL;

$openBalances = [];
$totalBalance = 0.0;
$res = sqlStatement($openSql, [$pid]);
while ($r = sqlFetchArray($res)) {
    $fee = (float)$r['fee'];
    $paid = (float)$r['paid_amount'];
    $bal = round($fee - $paid, 2);
    if ($bal <= 0) {
        continue;
    }

    // Mammogram detection: CPT 77067 (screening) / 77065 / 77066 (diagnostic)
    // or anything tagged "mammogram" in code_text. When a balance like that
    // is open we surface an "Insurance" pill since payer adjudication
    // typically handles imaging before patient responsibility kicks in.
    $codeText = (string)($r['code_text'] ?? '');
    $code = (string)($r['code'] ?? '');
    $isMammo = in_array($code, ['77067', '77066', '77065'], true)
        || (stripos($codeText, 'mammogram') !== false);
    $tag = $isMammo ? 'Insurance' : null;

    $charge = $codeText !== '' ? $codeText : ($code !== '' ? $code : 'Charge');
    $encReason = (string)($r['encounter_reason'] ?? '');
    $encLine = 'Encounter #' . (int)$r['encounter_id'];
    if ($encReason !== '') {
        $encLine .= ' · ' . $encReason;
    }

    $openBalances[] = [
        'billing_id'   => (int)$r['id'],
        'encounter_id' => (int)$r['encounter_id'],
        'dos'          => $r['date'] ? date('m/d/Y', strtotime((string)$r['date'])) : '—',
        'charge'       => $charge,
        'enc'          => $encLine,
        'cpt'          => $code !== '' ? $code : (string)$r['code_type'],
        'code_type'    => (string)$r['code_type'],
        'modifier'     => (string)($r['modifier'] ?? ''),
        'total'        => $fee,
        'paid'         => $paid,
        'balance'      => $bal,
        'tag'          => $tag,
    ];
    $totalBalance += $bal;
}

// --------------------------------------------------------------------
// Allocation seed: by default pre-apply against the first three rows
// with a positive balance (excluding insurance-pending mammogram rows),
// applying the full balance up to a cap of 3 charges. This produces a
// real "applied total" that the user can edit.
// --------------------------------------------------------------------
$applied = []; // billing_id => float
$allocLines = []; // [label, balance, applied, billing_id, encounter_id]
$count = 0;
foreach ($openBalances as $b) {
    if ($count >= 3) {
        $allocLines[] = [
            'label'        => $b['charge'],
            'balance'      => $b['balance'],
            'applied'      => 0.0,
            'billing_id'   => $b['billing_id'],
            'encounter_id' => $b['encounter_id'],
        ];
        continue;
    }
    if ($b['tag'] === 'Insurance') {
        $allocLines[] = [
            'label'        => $b['charge'],
            'balance'      => $b['balance'],
            'applied'      => 0.0,
            'billing_id'   => $b['billing_id'],
            'encounter_id' => $b['encounter_id'],
        ];
        continue;
    }
    $applied[$b['billing_id']] = $b['balance'];
    $allocLines[] = [
        'label'        => $b['charge'],
        'balance'      => $b['balance'],
        'applied'      => $b['balance'],
        'billing_id'   => $b['billing_id'],
        'encounter_id' => $b['encounter_id'],
    ];
    $count++;
}
$totalApply = 0.0;
foreach ($allocLines as $line) {
    $totalApply += (float)$line['applied'];
}

// --------------------------------------------------------------------
// Tender selection (GET ?tender=card|cash|check|wire|hsa). Default card.
// --------------------------------------------------------------------
$validTenders = ['card', 'cash', 'check', 'wire', 'hsa'];
$tender = $_GET['tender'] ?? 'card';
if (!in_array($tender, $validTenders, true)) {
    $tender = 'card';
}

// --------------------------------------------------------------------
// POST: charge_payment. Inserts ar_session and one ar_activity per
// non-zero allocation. CSRF skipped — internal mock page.
// --------------------------------------------------------------------
$flash = null;
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'charge_payment'
) {
    $alloc = $_POST['allocations'] ?? [];
    $note = trim((string)($_POST['note'] ?? ''));
    $method = $_POST['method'] ?? 'card';
    if (!in_array($method, $validTenders, true)) {
        $method = 'card';
    }
    // Build a quick lookup of submitted allocations: billing_id => amount.
    $submitted = []; // billing_id => float
    if (is_array($alloc)) {
        foreach ($alloc as $bidStr => $amtStr) {
            $bid = (int)$bidStr;
            $amt = (float)preg_replace('/[^0-9.]/', '', (string)$amtStr);
            if ($bid > 0 && $amt > 0) {
                $submitted[$bid] = $amt;
            }
        }
    }
    // Index open balances by billing_id so we can pull encounter/code/etc.
    $byId = [];
    foreach ($openBalances as $b) {
        $byId[$b['billing_id']] = $b;
    }
    $payTotal = 0.0;
    foreach ($submitted as $bid => $amt) {
        if (!isset($byId[$bid])) {
            unset($submitted[$bid]);
            continue;
        }
        // Cap each line at its own balance to prevent over-application.
        $cap = (float)$byId[$bid]['balance'];
        $submitted[$bid] = min($amt, $cap);
        $payTotal += $submitted[$bid];
    }
    $payTotal = round($payTotal, 2);

    if ($payTotal > 0) {
        // Map UI tender → ar_session.payment_method (varchar(25)).
        $methodMap = [
            'card'  => 'credit_card',
            'cash'  => 'cash',
            'check' => 'check',
            'wire'  => 'bank_draft',
            'hsa'   => 'credit_card',
        ];
        $pmethod = $methodMap[$method] ?? 'credit_card';
        $reference = ($method === 'card') ? 'CC charge' : ucfirst($method) . ' payment';

        $sessionId = (int)sqlInsert(
            "INSERT INTO ar_session
                 (payer_id, user_id, closed, reference, check_date, deposit_date,
                  pay_total, modified_time, global_amount, payment_type, description,
                  adjustment_code, post_to_date, patient_id, payment_method)
             VALUES
                 (0, ?, 0, ?, CURDATE(), CURDATE(),
                  ?, NOW(), 0.00, 'patient', ?,
                  '', CURDATE(), ?, ?)",
            [$userId, $reference, $payTotal, $note, $pid, $pmethod]
        );

        // One ar_activity row per allocated charge. Use Recorder-style
        // sequence numbering: max(sequence_no)+1 per (pid, encounter).
        foreach ($submitted as $bid => $amt) {
            $b = $byId[$bid];
            $encId = (int)$b['encounter_id'];
            $seqRow = sqlQuery(
                "SELECT IFNULL(MAX(sequence_no), 0) + 1 AS seq
                   FROM ar_activity WHERE pid = ? AND encounter = ?",
                [$pid, $encId]
            );
            $seq = (int)($seqRow['seq'] ?? 1);
            sqlStatement(
                "INSERT INTO ar_activity
                     (pid, encounter, sequence_no, code_type, code, modifier,
                      payer_type, post_time, post_user, session_id, modified_time,
                      pay_amount, adj_amount, memo, account_code, follow_up,
                      follow_up_note, reason_code, post_date, payer_claim_number)
                 VALUES
                     (?, ?, ?, ?, ?, ?,
                      0, NOW(), ?, ?, NOW(),
                      ?, 0.00, ?, '', '',
                      NULL, NULL, CURDATE(), NULL)",
                [
                    $pid, $encId, $seq,
                    $b['code_type'] ?: 'CPT4',
                    $b['cpt'],
                    $b['modifier'],
                    $userId, $sessionId,
                    $amt, $note,
                ]
            );
        }

        header('Location: copilot_payment.php?msg=payment_posted&amount=' . urlencode(number_format($payTotal, 2, '.', '')));
        exit;
    }
    header('Location: copilot_payment.php?msg=invalid_amount');
    exit;
}

// --------------------------------------------------------------------
// Flash message from POST/redirect/GET.
// --------------------------------------------------------------------
$msg = $_GET['msg'] ?? null;
if ($msg === 'payment_posted') {
    $amt = (float)($_GET['amount'] ?? 0);
    $flash = 'Payment posted: $' . number_format($amt, 2);
} elseif ($msg === 'invalid_amount') {
    $flash = 'Nothing to charge — allocate at least one amount above $0.';
}

// --------------------------------------------------------------------
// Card on file. Real query against payments / payment_gateway_details.
// In the seeded DB neither holds patient-scoped tokenized cards, so
// we render a documented stub matching the Figma. Replace with a real
// vault lookup when the practice integrates Stripe / Authorize.net.
// --------------------------------------------------------------------
$cardOnFile = null;
$cardRow = sqlQuery(
    "SELECT method, source FROM payments WHERE pid = ? AND method LIKE '%card%' ORDER BY dtime DESC LIMIT 1",
    [$pid]
);
if ($cardRow && !empty($cardRow['source'])) {
    // `source` historically holds last-4 / brand text in OpenEMR.
    $cardOnFile = [
        'label' => (string)$cardRow['source'],
        'sub'   => $patientName,
    ];
}
// Hardcoded fallback for the demo — no real tokenized card vault in the seed.
if ($cardOnFile === null) {
    $cardOnFile = [
        'label' => 'Visa ending in •• 4291',
        'sub'   => 'Exp 08/2027 · ' . $patientName,
    ];
}

$cancelHref = '/interface/main/copilot_mock_index.php';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Payment Intake'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .pi-shell { padding: 16px 20px 28px; display: grid; grid-template-columns: 1fr 380px; gap: 16px; align-items: start; }
  .pi-col { display: flex; flex-direction: column; gap: 16px; }
  .pi-col.right { gap: 0; }

  .pi-section { background:#FFFFFF; border:1px solid #E4E5E8; border-radius:12px; }
  .pi-section .hd { padding: 14px 18px 8px; border-bottom: 1px solid #F0F1F3; }
  .pi-section .hd .lbl { font-size:10px; font-weight:600; color:#8A91A1; letter-spacing:0.6px; }
  .pi-section .hd .sub { font-size:11px; color:#8A91A1; margin-top:4px; }

  .pi-tbl { width:100%; border-collapse: collapse; font-size:12px; }
  .pi-tbl th { text-align:left; padding:10px 14px; font-size:10px; font-weight:600; color:#8A91A1; letter-spacing:0.5px; text-transform:uppercase; border-bottom:1px solid #F0F1F3; }
  .pi-tbl td { padding:12px 14px; border-top:1px solid #F0F1F3; color:#0D1B2A; vertical-align: top; font-size:12px; }
  .pi-tbl td.r { text-align:right; }
  .pi-tbl td.muted { color:#8A91A1; }
  .pi-tbl td.bold  { font-weight:600; }
  .pi-tbl tr.unchecked td { color:#8A91A1; }
  .pi-tbl tr.unchecked td.bold { color:#8A91A1; }
  .pi-tbl .charge { font-weight:600; color:#0D1B2A; line-height:1.3; }
  .pi-tbl .enc { font-size:11px; color:#8A91A1; margin-top:2px; }
  .pi-tbl tr.unchecked .charge { color:#8A91A1; }
  .pi-tbl .empty td { padding: 30px 14px; text-align:center; color:#8A91A1; font-size:12px; }

  .pi-check { width:16px; height:16px; border-radius:4px; border:1.5px solid #C7CBD2; background:#FFFFFF; display:inline-block; vertical-align:middle; position:relative; flex:0 0 auto; }
  .pi-check.on { background:#008C8C; border-color:#008C8C; }
  .pi-check.on::after { content:''; position:absolute; left:4px; top:1px; width:5px; height:9px; border:solid #FFFFFF; border-width:0 2px 2px 0; transform:rotate(45deg); }

  .pi-tag { display:inline-block; background:#F0F4F9; color:#4785D9; font-size:10px; font-weight:600; padding:3px 8px; border-radius:999px; letter-spacing:0.3px; }

  .pi-alloc-input { width:90px; height:28px; border:1px solid #E4E5E8; border-radius:6px; padding:0 8px; font-size:12px; color:#0D1B2A; text-align:left; outline:none; font-family:inherit; }
  .pi-alloc-input:focus { border-color:#008C8C; }
  .pi-rem { color:#1F8C4D; font-weight:500; }

  .pi-total-row td { border-top:2px solid #E4E5E8; padding-top:14px; padding-bottom:14px; font-weight:700; }
  .pi-total-row td.muted { font-weight:500; color:#4F5763; }

  .pi-note { padding: 12px 18px 16px; display:flex; align-items:center; gap:10px; }
  .pi-note label { font-size:11px; color:#8A91A1; font-weight:500; flex:0 0 auto; }
  .pi-note input { flex:1; height:32px; border:1px solid #E4E5E8; border-radius:6px; padding:0 10px; font-size:12px; color:#0D1B2A; outline:none; font-family:inherit; background:#FFFFFF; }
  .pi-note input:focus { border-color:#008C8C; }

  /* Right column */
  .pi-right { background:#FFFFFF; border:1px solid #E4E5E8; border-radius:12px; padding: 14px 16px 16px; display:flex; flex-direction:column; gap:14px; }
  .pi-grp-lbl { font-size:10px; font-weight:600; color:#8A91A1; letter-spacing:0.6px; }

  .pi-tender { display:grid; grid-template-columns: repeat(5, 1fr); gap:6px; margin-top:6px; }
  .pi-tender label { background:#FFFFFF; border:1px solid #E4E5E8; border-radius:8px; padding:8px 4px; font-size:11px; font-weight:500; color:#4F5763; display:flex; align-items:center; justify-content:center; gap:5px; line-height:1; cursor:pointer; }
  .pi-tender label.active { background:#FFFFFF; border-color:#008C8C; color:#008C8C; font-weight:600; }
  .pi-tender input { display:none; }
  .pi-tender .ico { font-size:12px; }

  .pi-card-on-file { border:1.5px solid #008C8C; border-radius:8px; padding:10px 12px; display:flex; align-items:center; gap:10px; background:#FFFFFF; }
  .pi-card-thumb { width:30px; height:22px; border-radius:4px; background:linear-gradient(135deg, #F2C674 0%, #DBA94B 100%); flex:0 0 auto; position:relative; }
  .pi-card-thumb::after { content:''; position:absolute; left:4px; top:13px; width:8px; height:5px; background:#A87B22; border-radius:1px; }
  .pi-card-info { flex:1; line-height:1.3; }
  .pi-card-info .l1 { font-size:12px; font-weight:600; color:#0D1B2A; }
  .pi-card-info .l2 { font-size:11px; color:#8A91A1; margin-top:2px; }
  .pi-card-pill { background:#EBF8F0; color:#1F8C4D; font-size:10px; font-weight:600; padding:3px 10px; border-radius:999px; letter-spacing:0.3px; }

  .pi-fld { display:flex; flex-direction:column; gap:4px; }
  .pi-fld label { font-size:11px; color:#8A91A1; font-weight:500; }
  .pi-fld input { height:32px; border:1px solid #E4E5E8; border-radius:6px; padding:0 10px; font-size:12px; color:#0D1B2A; outline:none; font-family:inherit; background:#FFFFFF; width:100%; min-width:0; }
  .pi-fld input:focus { border-color:#008C8C; }
  .pi-fld input::placeholder { color:#C7CBD2; }
  .pi-fld-row { display:grid; grid-template-columns: 1fr 1fr 1fr; gap:8px; }

  .pi-receipt { display:flex; align-items:center; gap:8px; font-size:12px; color:#4F5763; }
  .pi-receipt .pi-check { width:14px; height:14px; }
  .pi-receipt .pi-check.on::after { left:3px; top:0px; width:4px; height:8px; }
  .pi-receipt input[type=email] { flex:1; height:28px; border:1px solid #E4E5E8; border-radius:6px; padding:0 8px; font-size:12px; color:#0D1B2A; outline:none; font-family:inherit; background:#FFFFFF; min-width:0; }
  .pi-receipt input[type=email]:focus { border-color:#008C8C; }

  .pi-amount { background:#F8F9FA; border-radius:8px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; }
  .pi-amount .lbl { font-size:10px; color:#8A91A1; font-weight:600; letter-spacing:0.6px; }
  .pi-amount .val { font-size:26px; font-weight:700; color:#0D1B2A; line-height:1.1; margin-top:4px; }
  .pi-amount .right { text-align:right; }
  .pi-amount .right .l1 { font-size:11px; color:#0D1B2A; font-weight:500; }
  .pi-amount .right .l2 { font-size:10px; color:#8A91A1; margin-top:3px; }

  .pi-actions { display:grid; grid-template-columns: 1fr 1.6fr; gap:8px; }
  .pi-actions .cancel { background:#FFFFFF; border:1px solid #E4E5E8; color:#4F5763; border-radius:999px; padding:10px 14px; font-size:12px; font-weight:500; line-height:1; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
  .pi-actions .charge { background:#008C8C; color:#FFFFFF; border:0; border-radius:999px; padding:10px 14px; font-size:12px; font-weight:600; line-height:1; display:inline-flex; align-items:center; justify-content:center; gap:6px; }

  .pi-flash { background:#EBF8F0; border:1px solid #B6E0C5; padding:8px 12px; color:#1F8C4D; font-size:12px; border-radius:8px; margin: 12px 20px 0; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info" style="flex-direction:row; align-items:center; gap:8px;">
    <span class="title"><?php echo xlt('Payment Intake'); ?></span>
    <span class="bullet">•</span>
    <span class="meta" style="color:#4F5763;"><?php echo xlt('Post a payment for'); ?> <?php echo text($patientName); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">? <?php echo xlt('Help'); ?></button>
</header>

<?php if ($flash !== null): ?>
  <div class="pi-flash"><?php echo text($flash); ?></div>
<?php endif; ?>

<form method="post" action="copilot_payment.php" class="pi-shell">
  <input type="hidden" name="action" value="charge_payment">

  <!-- LEFT COLUMN -->
  <div class="pi-col">

    <section class="pi-section">
      <div class="hd">
        <div class="lbl"><?php echo xlt('OPEN BALANCES'); ?></div>
        <div class="sub">
          $<?php echo text(number_format($totalBalance, 2)); ?> <?php echo xlt('total'); ?>
          &middot; <?php echo text((string)count($openBalances)); ?> <?php echo xlt('charges'); ?>
        </div>
      </div>
      <table class="pi-tbl">
        <thead>
          <tr>
            <th style="width:32px;"></th>
            <th style="width:90px;"><?php echo xlt('DOS'); ?></th>
            <th><?php echo xlt('CHARGE'); ?></th>
            <th style="width:90px;"><?php echo xlt('CPT'); ?></th>
            <th style="width:80px;" class="r"><?php echo xlt('TOTAL'); ?></th>
            <th style="width:90px;" class="r"><?php echo xlt('PAYMENTS'); ?></th>
            <th style="width:90px;" class="r"><?php echo xlt('BALANCE'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($openBalances)): ?>
            <tr class="empty">
              <td colspan="7"><?php echo xlt('No open balances for this patient.'); ?></td>
            </tr>
          <?php else: ?>
            <?php foreach ($openBalances as $b):
                $isApplied = isset($applied[$b['billing_id']]);
            ?>
              <tr class="<?php echo $isApplied ? 'checked' : 'unchecked'; ?>">
                <td><span class="pi-check<?php echo $isApplied ? ' on' : ''; ?>"></span></td>
                <td class="muted" style="white-space:nowrap;"><?php echo text($b['dos']); ?></td>
                <td>
                  <div class="charge"><?php echo text($b['charge']); ?></div>
                  <div class="enc"><?php echo text($b['enc']); ?></div>
                </td>
                <td class="muted"><?php echo text($b['cpt']); ?></td>
                <td class="r muted">$<?php echo text(number_format($b['total'], 2)); ?></td>
                <td class="r muted">$<?php echo text(number_format($b['paid'], 2)); ?></td>
                <td class="r bold">
                  $<?php echo text(number_format($b['balance'], 2)); ?>
                  <?php if (!empty($b['tag'])): ?>
                    <div style="margin-top:6px;"><span class="pi-tag"><?php echo text($b['tag']); ?></span></div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="pi-section">
      <div class="hd"><div class="lbl"><?php echo xlt('PAYMENT ALLOCATION'); ?></div></div>
      <table class="pi-tbl">
        <thead>
          <tr>
            <th><?php echo xlt('CHARGE'); ?></th>
            <th style="width:120px;" class="r"><?php echo xlt('BALANCE'); ?></th>
            <th style="width:130px;" class="r"><?php echo xlt('APPLIED'); ?></th>
            <th style="width:130px;" class="r"><?php echo xlt('REMAINING'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allocLines)): ?>
            <tr class="empty">
              <td colspan="4"><?php echo xlt('Nothing to allocate.'); ?></td>
            </tr>
          <?php else: ?>
            <?php foreach ($allocLines as $line):
                $remaining = round((float)$line['balance'] - (float)$line['applied'], 2);
            ?>
              <tr>
                <td><?php echo text($line['label']); ?></td>
                <td class="r muted">$<?php echo text(number_format((float)$line['balance'], 2)); ?></td>
                <td class="r">
                  <input
                    type="text"
                    name="allocations[<?php echo attr((string)$line['billing_id']); ?>]"
                    class="pi-alloc-input"
                    value="$<?php echo text(number_format((float)$line['applied'], 2)); ?>"
                    style="text-align:left;">
                </td>
                <td class="r"><span class="pi-rem">$<?php echo text(number_format($remaining, 2)); ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          <tr class="pi-total-row">
            <td><?php echo xlt('Total to apply'); ?></td>
            <td class="r muted"></td>
            <td class="r" style="padding-right:24px;">$<?php echo text(number_format($totalApply, 2)); ?></td>
            <td class="r muted">$0.00</td>
          </tr>
        </tbody>
      </table>
      <div class="pi-note">
        <label><?php echo xlt('Note:'); ?></label>
        <input type="text" name="note" value="<?php echo attr('Visit copay + lab self-pay portion'); ?>">
      </div>
    </section>

  </div>

  <!-- RIGHT COLUMN -->
  <aside class="pi-right">

    <div>
      <div class="pi-grp-lbl"><?php echo xlt('PAYMENT TENDER'); ?></div>
      <div class="pi-tender">
        <?php
        $tenders = [
            ['card',  '&#128179;', xl('Card')],
            ['cash',  '&#128181;', xl('Cash')],
            ['check', '&#128221;', xl('Check')],
            ['wire',  '&#127974;', xl('Wire')],
            ['hsa',   '&#127973;', xl('HSA')],
        ];
        foreach ($tenders as [$tk, $ico, $tlabel]):
            $isActive = ($tender === $tk);
        ?>
          <label class="<?php echo $isActive ? 'active' : ''; ?>">
            <input type="radio" name="method" value="<?php echo attr($tk); ?>" <?php echo $isActive ? 'checked' : ''; ?>>
            <span class="ico"><?php echo $ico; /* static emoji glyph */ ?></span> <?php echo text($tlabel); ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div>
      <div class="pi-grp-lbl"><?php echo xlt('CARD ON FILE'); ?></div>
      <div class="pi-card-on-file" style="margin-top:6px;">
        <div class="pi-card-thumb"></div>
        <div class="pi-card-info">
          <div class="l1"><?php echo text($cardOnFile['label']); ?></div>
          <div class="l2"><?php echo text($cardOnFile['sub']); ?></div>
        </div>
        <span class="pi-card-pill"><?php echo xlt('On file'); ?></span>
      </div>
    </div>

    <div>
      <div class="pi-grp-lbl"><?php echo xlt('OR USE NEW CARD'); ?></div>
      <div style="border:1px solid #E4E5E8; border-radius:8px; padding:12px; margin-top:6px; display:flex; flex-direction:column; gap:10px;">
        <div class="pi-fld">
          <label><?php echo xlt('Card number'); ?></label>
          <input type="text" name="card_number" placeholder="4242 4242 4242 4242" autocomplete="off">
        </div>
        <div class="pi-fld-row">
          <div class="pi-fld">
            <label><?php echo xlt('Expiry'); ?></label>
            <input type="text" name="card_exp" placeholder="MM / YY" autocomplete="off">
          </div>
          <div class="pi-fld">
            <label><?php echo xlt('CVC'); ?></label>
            <input type="text" name="card_cvc" placeholder="CVC" autocomplete="off">
          </div>
          <div class="pi-fld">
            <label><?php echo xlt('ZIP'); ?></label>
            <input type="text" name="card_zip" placeholder="ZIP" autocomplete="off">
          </div>
        </div>
      </div>
    </div>

    <div class="pi-receipt">
      <span class="pi-check on"></span>
      <span><?php echo xlt('Email receipt to'); ?></span>
      <input type="email" name="receipt_email" value="<?php echo attr($patientEmail); ?>" placeholder="email@example.com">
    </div>

    <div class="pi-amount">
      <div>
        <div class="lbl"><?php echo xlt('AMOUNT'); ?></div>
        <div class="val">$<?php echo text(number_format($totalApply, 2)); ?></div>
      </div>
      <div class="right">
        <div class="l1"><?php echo text($cardOnFile['label']); ?></div>
        <div class="l2"><?php echo xlt('Stripe processor'); ?> &middot; ~2 sec</div>
      </div>
    </div>

    <div class="pi-actions">
      <a class="cancel" href="<?php echo attr($cancelHref); ?>"><?php echo xlt('Cancel'); ?></a>
      <button type="submit" class="charge">
        <span>&#128179;</span> <?php echo xlt('Charge'); ?> $<?php echo text(number_format($totalApply, 2)); ?>
      </button>
    </div>

  </aside>

</form>

</body>
</html>
