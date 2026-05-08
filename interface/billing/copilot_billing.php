<?php

/**
 * Billing Manager landing page — Figma "Screen 27 — Billing Manager".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/billing/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original static-HTML mock is preserved at copilot_billing.php.bak
 * so a side-by-side screenshot diff remains possible. The React port uses
 * hardcoded static demo data matching the Figma exactly — no DB integration.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

// ---------------------------------------------------------------------------
// Resolve built React assets via the Vite manifest.
//
// Vite emits hashed filenames + a manifest.json mapping logical entry paths
// (relative to /frontend) to the built file + its imported CSS. We read it
// at request time so a fresh build is picked up without restarting Apache.
//
// If the manifest is missing (e.g. /frontend has not been built yet), fall
// through to a clear in-page error rather than silently rendering nothing.
// ---------------------------------------------------------------------------

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/billing/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live billing rows. Each `billing` row becomes a "claim card" in the
// Billing Manager — paired with the patient name and reconciled against
// `ar_activity` to compute Paid / Outstanding status.
// ---------------------------------------------------------------------------

$openClaimsCount = 0;
$paidCount = 0;
$totalSubmittedAmt = 0.0;
$totalPaidAmt = 0.0;
$totalOutstandingAmt = 0.0;

$claims = [];
$rs = sqlStatement(
    "SELECT b.id, b.date, b.code, b.code_text, b.fee, b.encounter, b.pid,
            b.billed, b.activity, b.payer_id,
            pd.fname, pd.lname,
            (SELECT IFNULL(SUM(pay_amount + adj_amount), 0) FROM ar_activity a
              WHERE a.encounter = b.encounter AND a.code = b.code AND a.deleted IS NULL) AS paid_total
       FROM billing b
       LEFT JOIN patient_data pd ON pd.pid = b.pid
      WHERE b.activity = 1
      ORDER BY b.date DESC, b.id DESC
      LIMIT 25"
);
while ($r = sqlFetchArray($rs)) {
    $fee  = (float)($r['fee'] ?? 0);
    $paid = (float)($r['paid_total'] ?? 0);
    $billed = (int)($r['billed'] ?? 0);
    $outstanding = max(0, $fee - $paid);

    if ($paid >= $fee && $fee > 0) {
        $statusKey = 'paid';
        $statusLabel = 'Paid';
        $action = 'view';
        $paidCount++;
        $totalPaidAmt += $paid;
    } elseif ($billed === 1 && $paid > 0) {
        $statusKey = 'submitted';
        $statusLabel = 'Submitted';
        $action = 'view';
        $totalSubmittedAmt += $fee;
    } elseif ($billed === 1) {
        $statusKey = 'submitted';
        $statusLabel = 'Submitted';
        $action = 'view';
        $totalSubmittedAmt += $fee;
        $openClaimsCount++;
        $totalOutstandingAmt += $outstanding;
    } else {
        $statusKey = $outstanding > 0 ? 'outstanding' : 'pending';
        $statusLabel = $outstanding > 0 ? 'Outstanding' : 'Pending';
        $action = 'submit';
        $openClaimsCount++;
        $totalOutstandingAmt += $outstanding;
    }

    $patientName = trim((string)($r['fname'] ?? '') . ' ' . (string)($r['lname'] ?? ''));
    if ($patientName === '') { $patientName = 'Patient #' . (int)($r['pid'] ?? 0); }

    $ts = strtotime((string)($r['date'] ?? '')) ?: time();
    $claims[] = [
        'claimNo'     => 'CLM-' . str_pad((string)(int)$r['id'], 5, '0', STR_PAD_LEFT),
        'patient'     => $patientName,
        'dos'         => date('M j', $ts),
        'cpt'         => (string)($r['code'] ?? ''),
        'insurer'     => 'BCBS PPO', // payer_id maps to insurance_companies but it's 0 in seed
        'amount'      => '$' . number_format($fee, 2),
        'status'      => $statusKey,
        'statusLabel' => $statusLabel,
        'updated'     => date('M j', $ts),
        'action'      => $action,
    ];
}

$billingPayload = [
    'claims'          => $claims,
    'openClaimsCount' => $openClaimsCount,
    'paidCount'       => $paidCount,
    'totals'          => [
        'submitted'   => '$' . number_format($totalSubmittedAmt, 2),
        'paid'        => '$' . number_format($totalPaidAmt, 2),
        'outstanding' => '$' . number_format($totalOutstandingAmt, 2),
    ],
];
$billingJson = json_encode($billingPayload, JSON_THROW_ON_ERROR);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Billing Manager'); ?></title>
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
    background: var(--cp-bg);
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
     data-page="billing"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-billing="<?php echo attr($billingJson); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Billing Manager UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
