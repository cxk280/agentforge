<?php

/**
 * Payment Intake — Screen 58, billing archetype.
 * Handles Payment / Checkout / Batch Payments / Posting Payments.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

// CRUD: take payment. Inserts into ar_session.
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'take_payment') {
    $amount  = (float)preg_replace('/[^0-9.]/', '', $_POST['amount'] ?? '0');
    $method  = $_POST['method'] ?? 'card';
    $patient = (int)($_POST['patient_id'] ?? 1);
    $userId  = (int)($_SESSION['authUserID'] ?? 1);
    $ref     = trim($_POST['reference'] ?? '');
    if ($amount > 0) {
        sqlInsert(
            "INSERT INTO ar_session (payer_id, user_id, closed, reference, check_date, deposit_date, pay_total, modified_time, global_amount, payment_type, description, adjustment_code, post_to_date, patient_id, payment_method) VALUES (0, ?, 0, ?, CURDATE(), CURDATE(), ?, NOW(), ?, 'patient', ?, '', CURDATE(), ?, ?)",
            [$userId, $ref, $amount, $amount, ($_POST['note'] ?? 'Co-Pay'), $patient, $method]
        );
        $flash = 'Payment posted: $' . number_format($amount, 2);
        header('Location: copilot_payment.php?msg=' . urlencode($flash));
        exit;
    }
}
$flash = $_GET['msg'] ?? null;

// Live KPIs from ar_session
$todayTotal = (float)(sqlQuery("SELECT COALESCE(SUM(pay_total), 0) AS s FROM ar_session WHERE deposit_date = CURDATE()")['s'] ?? 0);
$cashTotal  = (float)(sqlQuery("SELECT COALESCE(SUM(pay_total), 0) AS s FROM ar_session WHERE deposit_date = CURDATE() AND payment_method = 'cash'")['s'] ?? 0);
$cardTotal  = (float)(sqlQuery("SELECT COALESCE(SUM(pay_total), 0) AS s FROM ar_session WHERE deposit_date = CURDATE() AND payment_method = 'card'")['s'] ?? 0);
$todayCount = (int)(sqlQuery("SELECT COUNT(*) AS n FROM ar_session WHERE deposit_date = CURDATE()")['n'] ?? 0);
$cashCount  = (int)(sqlQuery("SELECT COUNT(*) AS n FROM ar_session WHERE deposit_date = CURDATE() AND payment_method = 'cash'")['n'] ?? 0);
$cardCount  = (int)(sqlQuery("SELECT COUNT(*) AS n FROM ar_session WHERE deposit_date = CURDATE() AND payment_method = 'card'")['n'] ?? 0);

// Recent payments
$payments = [];
$rows = sqlStatement(
    "SELECT s.created_time, s.pay_total, s.payment_method, s.reference, s.description,
            pat.fname, pat.lname
     FROM ar_session s
     LEFT JOIN patient_data pat ON s.patient_id = pat.pid
     ORDER BY s.created_time DESC LIMIT 15"
);
while ($r = sqlFetchArray($rows)) {
    $patName = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? '')) ?: '—';
    $time = $r['created_time'] ? date('g:i A', strtotime($r['created_time'])) : '—';
    $payments[] = [$time, $patName, '$' . number_format((float)$r['pay_total'], 2),
                   ucfirst($r['payment_method']), $r['description'] ?: '—', 'Posted', '#1F8C4D'];
}

$kpis = [
    ['Today',         '$' . number_format($todayTotal, 0), $todayCount . ' payments',          '#0D1B2A'],
    ['Cash on hand',  '$' . number_format($cashTotal, 0),  $cashCount . ' cash transactions',  '#1F8C4D'],
    ['Card payments', '$' . number_format($cardTotal, 0),  $cardCount . ' transactions',       '#4785D9'],
    ['Active sessions', (string)((int)(sqlQuery("SELECT COUNT(*) AS n FROM ar_session WHERE closed = 0")['n'] ?? 0)), 'Open A/R sessions', '#FA8C33'],
];


?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Payments'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-pay-body { display: grid; grid-template-columns: 360px 1fr; gap: 16px; padding: 20px 24px 32px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Payments'); ?></span>
    <span class="meta"><?php echo xlt('Intake, batch posting, and reconciliation'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Open batch'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('New payment'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-kpi-grid">
    <?php foreach ($kpis as [$lbl, $val, $sub, $color]): ?>
      <div class="cp-kpi">
        <div class="lbl"><?php echo text($lbl); ?></div>
        <div class="val" style="color: <?php echo attr($color); ?>;"><?php echo text($val); ?></div>
        <div class="sub"><?php echo text($sub); ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cp-pay-body">

    <form class="cp-panel" method="post">
      <input type="hidden" name="action" value="take_payment">
      <div class="cp-panel-lbl"><?php echo xlt('TAKE PAYMENT'); ?></div>

      <?php if ($flash): ?>
        <div style="background:#EBF8F0; border:1px solid #B6E0C5; padding:8px 10px; color:#1F8C4D; font-size:12px; border-radius:6px; margin-bottom:10px;"><?php echo text($flash); ?></div>
      <?php endif; ?>

      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Patient'); ?></label>
        <select class="cp-input cp-select" name="patient_id">
          <?php
          $pp = sqlStatement("SELECT pid, fname, lname FROM patient_data ORDER BY date DESC LIMIT 50");
          while ($p = sqlFetchArray($pp)):
              $label = trim(($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')) . ' — MRN ' . str_pad((string)$p['pid'], 6, '0', STR_PAD_LEFT);
          ?><option value="<?php echo attr($p['pid']); ?>"><?php echo text($label); ?></option><?php endwhile; ?>
        </select>
      </div>
      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Amount'); ?></label>
        <input class="cp-input" type="text" name="amount" value="$25.00" required>
      </div>
      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Method'); ?></label>
        <select class="cp-input cp-select" name="method">
          <option value="card">Card</option>
          <option value="cash">Cash</option>
          <option value="check">Check</option>
        </select>
      </div>
      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Note'); ?></label>
        <input class="cp-input" type="text" name="note" value="Co-Pay">
      </div>
      <div class="cp-row" style="grid-template-columns: 100px 1fr;">
        <label><?php echo xlt('Reference #'); ?></label>
        <input class="cp-input" type="text" name="reference" placeholder="<?php echo xla('Auth code or check number'); ?>">
      </div>

      <button type="submit" class="cp-btn primary" style="width:100%; justify-content:center; padding:10px;"><?php echo xlt('Charge'); ?> →</button>
    </form>

    <section class="cp-panel flush">
      <div class="cp-panel-head">
        <h3><?php echo xlt("Today's Payments"); ?></h3>
        <span class="cnt">14</span>
      </div>
      <div class="cp-tbl" style="border:none; border-radius:0;">
        <table>
          <thead>
            <tr>
              <th><?php echo xlt('TIME'); ?></th>
              <th><?php echo xlt('PATIENT'); ?></th>
              <th><?php echo xlt('AMOUNT'); ?></th>
              <th><?php echo xlt('METHOD'); ?></th>
              <th><?php echo xlt('APPLIED TO'); ?></th>
              <th><?php echo xlt('STATUS'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payments as [$time, $pat, $amt, $method, $applied, $status, $color]): ?>
              <tr>
                <td class="muted"><?php echo text($time); ?></td>
                <td class="bold"><?php echo text($pat); ?></td>
                <td class="bold"><?php echo text($amt); ?></td>
                <td class="muted"><?php echo text($method); ?></td>
                <td class="muted"><?php echo text($applied); ?></td>
                <td>
                  <span class="cp-status-pill <?php echo $status === 'Posted' ? 'good' : 'warn'; ?>"><?php echo text($status); ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  </div>

</main>

</body>
</html>
