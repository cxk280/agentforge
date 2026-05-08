<?php

/**
 * Patient Transactions landing page — Figma "Screen 16 — Transactions".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/transactions/. The PHP outer shell
 * at /interface/main/tabs/main.php still owns the navy top nav, left
 * sidebar, and patient header2 banner; this file only renders inside the
 * #maimain iframe / patient navtab area.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * Live billing transactions for the current pid are queried server-side
 * and JSON-encoded onto data-tx, mirroring the pattern used by Finder /
 * Issues. Charges come from the billing table; payments and adjustments
 * come from ar_activity (joined to ar_session + insurance_companies). The
 * 4 KPI tiles (Total Charges / Insurance Paid / Patient Paid / Outstanding)
 * are aggregated from the same underlying rows.
 *
 * The original static demo mock is preserved at copilot_transactions.php.bak
 * so a side-by-side screenshot diff remains possible.
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
$entry        = $manifest['src/pages/transactions/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Live transactions for the current patient.
//
// Charges (TYPE=CHARGE):
//   billing rows where activity=1 AND fee != 0 — fee, code, code_text,
//   provider_id (LEFT JOIN users for the provider's display name).
//
// Payments / adjustments (TYPE=PAYMENT or ADJUSTMENT):
//   ar_activity rows (deleted IS NULL). pay_amount > 0 → PAYMENT;
//   adj_amount != 0 → ADJUSTMENT. session_id LEFT JOINs ar_session for the
//   reference / check number; ar_session.payer_id LEFT JOINs
//   insurance_companies for the display name (when payer_type > 0; payer_type
//   = 0 means "patient").
//
// Money formatting + date formatting happens here (PHP) so the React side
// just renders the prepared strings — same shape as the previous static demo.
// If pid is empty/0 we render an empty payload so the page comes up cleanly
// instead of crashing.
// ---------------------------------------------------------------------------

$pid = (int)$patientId;

/** @var list<array{date:string,sortKey:string,type:string,desc:string,cpt:string,prov:string,ins:string,pt:string,bal:string,outstanding:bool,insGreen:bool,balRed:bool}> $rows */
$rows = [];

// Aggregates feeding the 4 KPI tiles.
$totalCharges  = 0.0;
$insurancePaid = 0.0;
$patientPaid   = 0.0;
$adjustments   = 0.0;

if ($pid > 0) {
    // ── Charges ─────────────────────────────────────────────────────────
    $chargeRes = sqlStatement(
        "SELECT b.date, b.code, b.code_text, b.fee, b.encounter, b.provider_id,
                NULLIF(TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))), '') AS provider_name
           FROM billing b
           LEFT JOIN users u ON u.id = b.provider_id
          WHERE b.pid = ? AND b.activity = 1 AND b.fee IS NOT NULL AND b.fee <> 0
          ORDER BY b.date DESC, b.id DESC",
        [$pid],
    );
    while ($r = sqlFetchArray($chargeRes)) {
        $rawDate  = (string)($r['date'] ?? '');
        $sortKey  = $rawDate;
        $dateLbl  = '';
        if ($rawDate !== '' && $rawDate !== '0000-00-00 00:00:00') {
            try {
                $dateLbl = (new DateTime($rawDate))->format('m/d/Y');
            } catch (Throwable $_) {
                $dateLbl = substr($rawDate, 0, 10);
            }
        }

        $fee       = (float)($r['fee'] ?? 0);
        $encounter = (int)($r['encounter'] ?? 0);
        $code      = (string)($r['code'] ?? '');
        $codeText  = (string)($r['code_text'] ?? '');
        $providerId = (int)($r['provider_id'] ?? 0);
        $providerNm = (string)($r['provider_name'] ?? '');

        // Per-charge insurance / patient / adjustment totals via ar_activity.
        // Joined on the encounter (ar_activity is per-encounter, not per
        // billing line — multiple charges in the same visit share these
        // totals; we display them once per encounter to match the Figma
        // "INS PAID / PT PAID / BALANCE" columns).
        $aggRes = sqlStatement(
            "SELECT
                COALESCE(SUM(CASE WHEN payer_type > 0 THEN pay_amount ELSE 0 END), 0) AS ins_paid,
                COALESCE(SUM(CASE WHEN payer_type = 0 THEN pay_amount ELSE 0 END), 0) AS pt_paid,
                COALESCE(SUM(adj_amount), 0)                                          AS adj_total
               FROM ar_activity
              WHERE pid = ? AND encounter = ? AND deleted IS NULL",
            [$pid, $encounter],
        );
        $agg = sqlFetchArray($aggRes) ?: [];
        $insPaid = (float)($agg['ins_paid'] ?? 0);
        $ptPaid  = (float)($agg['pt_paid'] ?? 0);
        $adjTotal = (float)($agg['adj_total'] ?? 0);

        $balance = $fee - $insPaid - $ptPaid - $adjTotal;
        $outstanding = $balance > 0.005;

        $totalCharges += $fee;

        $rows[] = [
            'date'        => $dateLbl,
            'sortKey'     => $sortKey,
            'type'        => 'charge',
            'desc'        => $codeText !== '' ? $codeText : ($code !== '' ? $code : 'Charge'),
            'cpt'         => $code !== '' ? $code : '—',
            'prov'        => $providerNm !== '' ? 'Dr. ' . $providerNm : ($providerId > 0 ? 'Provider #' . $providerId : '—'),
            'ins'         => sprintf('$%s', number_format($insPaid, 2)),
            'pt'          => sprintf('$%s', number_format($ptPaid, 2)),
            'bal'         => sprintf('$%s', number_format(max($balance, 0), 2)),
            'outstanding' => $outstanding,
            'insGreen'    => false,
            'balRed'      => false,
        ];
    }

    // ── Payments + adjustments ──────────────────────────────────────────
    $arRes = sqlStatement(
        "SELECT a.post_time, a.payer_type, a.pay_amount, a.adj_amount, a.memo, a.code,
                a.session_id, s.reference, s.payment_method, ic.name AS insurance_name
           FROM ar_activity a
           LEFT JOIN ar_session s        ON s.session_id = a.session_id
           LEFT JOIN insurance_companies ic ON ic.id     = s.payer_id
          WHERE a.pid = ? AND a.deleted IS NULL
            AND (a.pay_amount <> 0 OR a.adj_amount <> 0)
          ORDER BY a.post_time DESC, a.sequence_no DESC",
        [$pid],
    );
    while ($r = sqlFetchArray($arRes)) {
        $rawDate = (string)($r['post_time'] ?? '');
        $sortKey = $rawDate;
        $dateLbl = '';
        if ($rawDate !== '' && $rawDate !== '0000-00-00 00:00:00') {
            try {
                $dateLbl = (new DateTime($rawDate))->format('m/d/Y');
            } catch (Throwable $_) {
                $dateLbl = substr($rawDate, 0, 10);
            }
        }

        $pay     = (float)($r['pay_amount'] ?? 0);
        $adj     = (float)($r['adj_amount'] ?? 0);
        $payer   = (int)($r['payer_type'] ?? 0);
        $insName = (string)($r['insurance_name'] ?? '');
        $ref     = (string)($r['reference'] ?? '');
        $memo    = (string)($r['memo'] ?? '');
        $cpt     = (string)($r['code'] ?? '');

        if ($pay > 0) {
            $payerLbl = $payer > 0 ? ($insName !== '' ? $insName : 'Insurance') : 'Patient';
            $desc     = $payerLbl . ($ref !== '' ? ' — claim #' . $ref : '');
            if ($payer > 0) {
                $insurancePaid += $pay;
            } else {
                $patientPaid += $pay;
            }
            $rows[] = [
                'date'        => $dateLbl,
                'sortKey'     => $sortKey,
                'type'        => 'payment',
                'desc'        => $desc,
                'cpt'         => $cpt !== '' ? $cpt : '—',
                'prov'        => '—',
                'ins'         => $payer > 0 ? sprintf('$%s', number_format($pay, 2)) : '—',
                'pt'          => $payer === 0 ? sprintf('$%s', number_format($pay, 2)) : '—',
                'bal'         => '—',
                'outstanding' => false,
                'insGreen'    => true,
                'balRed'      => false,
            ];
        }

        if (abs($adj) > 0.005) {
            $payerLbl = $payer > 0 ? ($insName !== '' ? $insName : 'Insurance') : 'Patient';
            $desc     = ($memo !== '' ? $memo : 'Adjustment') . ' (' . $payerLbl . ')';
            $adjustments += $adj;
            // Adjustments reduce the receivable; show as a negative balance
            // delta with red styling, matching the original "Contract write-off"
            // row in the static mock.
            $signed = $adj > 0 ? -$adj : $adj; // a positive adj_amount is a write-off off A/R
            $rows[] = [
                'date'        => $dateLbl,
                'sortKey'     => $sortKey,
                'type'        => 'adjustment',
                'desc'        => $desc,
                'cpt'         => $cpt !== '' ? $cpt : '—',
                'prov'        => '—',
                'ins'         => '—',
                'pt'          => '—',
                'bal'         => ($signed < 0 ? '−' : '') . '$' . number_format(abs($signed), 2),
                'outstanding' => false,
                'insGreen'    => false,
                'balRed'      => true,
            ];
        }
    }

    // Newest first across both row sources.
    usort($rows, static function (array $a, array $b): int {
        return strcmp((string)$b['sortKey'], (string)$a['sortKey']);
    });
}

// Strip the internal sort key — the React side doesn't need it.
$rows = array_map(static function (array $r): array {
    unset($r['sortKey']);
    return $r;
}, $rows);

// ── KPI tiles ───────────────────────────────────────────────────────────
$outstanding = max($totalCharges - $insurancePaid - $patientPaid - $adjustments, 0.0);
$pct = static fn (float $part, float $whole): string =>
    ($whole > 0.005 ? (string)(int)round(($part / $whole) * 100) : '0') . '% of charges';

$kpis = [
    [
        'label' => 'Total Charges',
        'value' => '$' . number_format($totalCharges, 2),
        'sub'   => 'All time',
        'tone'  => 'neutral',
    ],
    [
        'label' => 'Insurance Paid',
        'value' => '$' . number_format($insurancePaid, 2),
        'sub'   => $pct($insurancePaid, $totalCharges),
        'tone'  => 'good',
    ],
    [
        'label' => 'Patient Paid',
        'value' => '$' . number_format($patientPaid, 2),
        'sub'   => $pct($patientPaid, $totalCharges),
        'tone'  => 'info',
    ],
    [
        'label' => 'Outstanding',
        'value' => '$' . number_format($outstanding, 2),
        'sub'   => $pct($outstanding, $totalCharges),
        'tone'  => 'warn',
    ],
];

$txPayload = [
    'kpis' => $kpis,
    'rows' => $rows,
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Transactions'); ?></title>
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
     data-page="transactions"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-tx="<?php echo attr((string)json_encode($txPayload)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Transactions UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
