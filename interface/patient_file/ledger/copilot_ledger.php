<?php

/**
 * Patient Ledger landing page — Figma "Screen 18 — Ledger".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/ledger/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
 * and patient header2 banner; this file only renders inside the
 * patient-file iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The original static-HTML mock is preserved at copilot_ledger.php.bak
 * so a side-by-side screenshot diff remains possible.
 *
 * Live ledger data is composed from the OpenEMR billing tables:
 *   - billing                — charges (debits) per encounter line item
 *   - ar_activity            — applied payments / adjustments (credits)
 *   - ar_session             — payment session (payer, reference, post date)
 *   - insurance_companies    — payer name for the "INSURANCE" column
 * Each row is shaped into the LedgerRow contract the React component
 * already consumes; aging buckets are computed from the still-outstanding
 * charge balances bucketed by age in days.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");

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

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/ledger/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
// Use the OpenEMR session wrapper (not $_SESSION directly) — globals.php
// runs a read_and_close session, so $_SESSION values can be empty by the
// time this wrapper file reads them. The wrapper queries the live store.
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live ledger data — preserves the spirit of the static mock at
// copilot_ledger.php.bak by composing real charge/payment activity into the
// same row shape the React component already renders. With no active
// patient we fall through to empty arrays; React then shows the empty state.
// ---------------------------------------------------------------------------

$activePid = (int)$patientId;

/**
 * @var array<int, array{
 *     date: string, txn: string, desc: string, debit: string, credit: string,
 *     ins: string, bal: string, outstanding?: bool, _ts: int, _signed: float
 * }> $rawRows
 */
$rawRows         = [];
$totalCharges    = 0.0;
$totalCredits    = 0.0;
$lastActivityIso = '';

if ($activePid > 0) {
    // ----- Charges (debits) -------------------------------------------------
    // billing.activity > 0 filters out voided line items the same way
    // pat_ledger.php does. We pull encounter date for "DATE" and primary
    // payer hint via the encounter→billing payer mapping.
    $chargeSql = "SELECT b.id, b.code, b.code_text, b.fee, b.units, b.modifier,
                         COALESCE(fe.date, b.date) AS svc_date,
                         b.encounter,
                         ins.name AS payer_name,
                         (SELECT MAX(ar.post_time) FROM ar_activity ar
                            WHERE ar.pid = b.pid AND ar.encounter = b.encounter
                              AND ar.deleted IS NULL) AS last_pay_time
                    FROM billing b
                    LEFT JOIN form_encounter fe ON fe.encounter = b.encounter AND fe.pid = b.pid
                    LEFT JOIN insurance_companies ins ON ins.id = b.payer_id
                   WHERE b.pid = ?
                     AND b.activity > 0
                     AND (b.code_type IS NULL OR b.code_type <> 'COPAY')
                   ORDER BY svc_date DESC, b.id DESC
                   LIMIT 200";
    $crs = sqlStatement($chargeSql, [$activePid]);
    while ($r = sqlFetchArray($crs)) {
        $fee   = (float)($r['fee'] ?? 0);
        $units = (int)($r['units'] ?? 1);
        if ($units <= 0) {
            $units = 1;
        }
        $amt = $fee * $units;
        if ($amt == 0.0) {
            continue;
        }

        $svc = (string)($r['svc_date'] ?? '');
        $ts  = strtotime($svc) ?: 0;
        $code = trim((string)($r['code'] ?? ''));
        $codeText = trim((string)($r['code_text'] ?? ''));
        $desc = $codeText !== '' && $code !== ''
            ? sprintf('%s (%s)', $codeText, $code)
            : ($codeText !== '' ? $codeText : ($code !== '' ? $code : 'Charge'));

        $payer = trim((string)($r['payer_name'] ?? ''));
        $hasPay = !empty($r['last_pay_time']);
        $insCell = $payer !== ''
            ? sprintf('%s • %s', $payer, $hasPay ? 'Paid' : 'Pending')
            : ($hasPay ? '—' : 'Self-pay • Pending');

        $totalCharges += $amt;
        if ($svc !== '' && $svc !== '0000-00-00 00:00:00') {
            if ($lastActivityIso === '' || strcmp($svc, $lastActivityIso) > 0) {
                $lastActivityIso = $svc;
            }
        }

        $rawRows[] = [
            'date'    => $ts > 0 ? date('m/d/Y', $ts) : '',
            'txn'     => sprintf('CH-%05d', (int)$r['id']),
            'desc'    => $desc,
            'debit'   => '$' . number_format($amt, 2),
            'credit'  => '—',
            'ins'     => $insCell,
            'bal'     => '', // running balance computed below
            '_ts'     => $ts,
            '_signed' => $amt, // +charges add to balance
        ];
    }

    // ----- Credits / adjustments -------------------------------------------
    // ar_activity rows are payments + write-offs applied to encounters. The
    // same shape pat_ledger.php uses: pay_amount + adj_amount, joined to
    // ar_session for payer + payment_method, joined to insurance_companies
    // when the session has a non-zero payer (zero = patient).
    $creditSql = "SELECT a.pid, a.encounter, a.sequence_no, a.post_time,
                          a.pay_amount, a.adj_amount, a.payer_type, a.memo,
                          s.session_id, s.payer_id, s.reference, s.payment_method,
                          ins.name AS payer_name
                     FROM ar_activity a
                     LEFT JOIN ar_session s ON s.session_id = a.session_id
                     LEFT JOIN insurance_companies ins ON ins.id = s.payer_id
                    WHERE a.pid = ?
                      AND a.deleted IS NULL
                    ORDER BY a.post_time DESC, a.sequence_no DESC
                    LIMIT 200";
    $crs = sqlStatement($creditSql, [$activePid]);
    while ($r = sqlFetchArray($crs)) {
        $pay  = (float)($r['pay_amount'] ?? 0);
        $adj  = (float)($r['adj_amount'] ?? 0);
        $amt  = $pay + $adj;
        if ($amt == 0.0) {
            continue;
        }

        $post = (string)($r['post_time'] ?? '');
        $ts   = strtotime($post) ?: 0;
        $payer = trim((string)($r['payer_name'] ?? ''));
        $isPatient = ((int)($r['payer_type'] ?? 0)) === 0 && $payer === '';
        $ref  = trim((string)($r['reference'] ?? ''));
        $method = trim((string)($r['payment_method'] ?? ''));
        $isAdj = $pay == 0.0 && $adj != 0.0;

        if ($isAdj) {
            $desc = $payer !== ''
                ? sprintf('Contractual write-off (%s)', $payer)
                : 'Adjustment / write-off';
            $insCell = '—';
            $txnPrefix = 'AJ';
        } elseif ($isPatient) {
            $desc = $method !== ''
                ? sprintf('Patient payment — %s', $method)
                : 'Patient payment collected';
            if ($ref !== '') {
                $desc .= sprintf(' (%s)', $ref);
            }
            $insCell = '—';
            $txnPrefix = 'PT';
        } else {
            $payerLabel = $payer !== '' ? $payer : 'Insurance';
            $desc = $ref !== ''
                ? sprintf('%s payment — claim %s', $payerLabel, $ref)
                : sprintf('%s payment', $payerLabel);
            $insCell = $payer !== '' ? sprintf('%s • Paid', $payer) : '—';
            $txnPrefix = 'PM';
        }

        $totalCredits += $amt;
        if ($post !== '' && $post !== '0000-00-00 00:00:00') {
            if ($lastActivityIso === '' || strcmp($post, $lastActivityIso) > 0) {
                $lastActivityIso = $post;
            }
        }

        $rawRows[] = [
            'date'    => $ts > 0 ? date('m/d/Y', $ts) : '',
            'txn'     => sprintf('%s-%05d', $txnPrefix, (int)($r['sequence_no'] ?? 0)),
            'desc'    => $desc,
            'debit'   => '—',
            'credit'  => '$' . number_format($amt, 2),
            'ins'     => $insCell,
            'bal'     => '',
            '_ts'     => $ts,
            '_signed' => -$amt,
        ];
    }
}

// ---------------------------------------------------------------------------
// Sort newest-first and compute the running balance from the bottom up. The
// "bal" shown on each row is the running outstanding patient balance AS OF
// AND INCLUDING that row, matching how the static mock displayed it.
// ---------------------------------------------------------------------------
usort($rawRows, static function (array $a, array $b): int {
    $ta = (int)$a['_ts'];
    $tb = (int)$b['_ts'];
    if ($ta !== $tb) {
        return $tb <=> $ta;
    }
    return ((string)$b['txn']) <=> ((string)$a['txn']);
});

$outstanding = max(0.0, $totalCharges - $totalCredits);
$cumulative = 0.0;
$rowsAsc    = array_reverse($rawRows);
$balByIdx   = [];
foreach ($rowsAsc as $i => $row) {
    $cumulative += (float)$row['_signed'];
    $balByIdx[$i] = $cumulative;
}
$rows = [];
$count = count($rowsAsc);
for ($i = $count - 1; $i >= 0; $i--) {
    $row = $rowsAsc[$i];
    $bal = (float)($balByIdx[$i] ?? 0.0);
    $row['bal'] = '$' . number_format(max(0.0, $bal), 2);
    // Drop the internal helpers before handing to React.
    unset($row['_ts'], $row['_signed']);
    $rows[] = $row;
}

// Mark the oldest still-open charge row as "OUTSTANDING" so the React table
// renders the orange pill the mock used. We pick the oldest charge whose
// running balance is still positive when read top-down.
if ($outstanding > 0.01) {
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        if (($rows[$i]['debit'] ?? '—') !== '—') {
            $rows[$i]['outstanding'] = true;
            break;
        }
    }
}

// ---------------------------------------------------------------------------
// Aging buckets — 0-30, 31-60, 61-90, 91+ days. We bucket each line item's
// remaining unpaid amount by the age (today − service date in days). Total
// payments are subtracted from charges in service-date-ascending order
// (FIFO) so the oldest charge absorbs the oldest credit, which is how
// patient aging is normally read.
// ---------------------------------------------------------------------------
$buckets = [0.0, 0.0, 0.0, 0.0]; // 0-30, 31-60, 61-90, 91+
if ($activePid > 0 && $totalCharges > 0) {
    $chargesAsc = [];
    foreach ($rowsAsc as $r) {
        if (($r['debit'] ?? '—') !== '—') {
            $chargesAsc[] = [
                'ts'  => (int)$r['_ts'],
                'amt' => (float)$r['_signed'], // positive
            ];
        }
    }
    // FIFO-apply credits to the oldest charges.
    $creditsLeft = $totalCredits;
    foreach ($chargesAsc as $idx => $ch) {
        if ($creditsLeft <= 0) {
            break;
        }
        $apply = min($creditsLeft, $ch['amt']);
        $chargesAsc[$idx]['amt'] = $ch['amt'] - $apply;
        $creditsLeft -= $apply;
    }
    $now = time();
    foreach ($chargesAsc as $ch) {
        if ($ch['amt'] <= 0) {
            continue;
        }
        $ageDays = $ch['ts'] > 0 ? max(0, (int)floor(($now - $ch['ts']) / 86400)) : 0;
        if ($ageDays <= 30) {
            $buckets[0] += $ch['amt'];
        } elseif ($ageDays <= 60) {
            $buckets[1] += $ch['amt'];
        } elseif ($ageDays <= 90) {
            $buckets[2] += $ch['amt'];
        } else {
            $buckets[3] += $ch['amt'];
        }
    }
}

$bucketLabels = ['0–30', '31–60', '61–90', '91+'];
$aging = [];
for ($i = 0; $i < 4; $i++) {
    $val = (float)($buckets[$i] ?? 0.0);
    $aging[] = [
        'range'  => $bucketLabels[$i],
        'amount' => '$' . number_format($val, 2),
        'value'  => $val,
    ];
}

$lastActivityLabel = '';
if ($lastActivityIso !== '' && $lastActivityIso !== '0000-00-00 00:00:00') {
    $lat = strtotime($lastActivityIso);
    if ($lat !== false && $lat > 0) {
        $lastActivityLabel = date('m/d/Y', $lat);
    }
}

$ledgerPayload = [
    'outstandingBalance' => '$' . number_format($outstanding, 2),
    'lastActivity'       => $lastActivityLabel,
    'aging'              => $aging,
    'rows'               => $rows,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Ledger'); ?></title>
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
     data-page="ledger"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-ledger="<?php echo attr((string)json_encode($ledgerPayload)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Ledger UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
