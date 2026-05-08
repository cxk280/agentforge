<?php

/**
 * Patient Record Request — Figma "Screen 31 — Patient Record Request".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/record_request/. The PHP outer
 * shell at /interface/main/tabs/main.php still owns the navy top nav,
 * left sidebar, and patient demographics banner; this file only renders
 * inside the #maimain iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * Backend wiring (matches the .bak):
 *   - Reads the records-release queue from `transactions` joined to
 *     `lbt_data` (refer_to / refer_status / refer_from / body), patient-
 *     scoped to the active pid.
 *   - KPI tiles are aggregated from the same queue.
 *   - Recipient dropdown is populated from `pharmacies` (only seeded
 *     external-recipient table); falls back to `procedure_providers`,
 *     then to a small static list when neither is seeded.
 *   - No POST handler — the React form is a no-op submit for the demo
 *     (matches the rest of the React-ported pages).
 *
 * The original PHP-rendered page is preserved at
 * copilot_record_request.php.bak so a side-by-side screenshot diff
 * remains possible.
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
// ---------------------------------------------------------------------------

$fileroot     = $GLOBALS['fileroot'] ?? __DIR__ . '/../../..';
$webroot      = $GLOBALS['webroot'] ?? '';
$manifestPath = $fileroot . '/public/build/.vite/manifest.json';
$manifest     = is_file($manifestPath)
    ? (json_decode((string)file_get_contents($manifestPath), true) ?: [])
    : [];
$entry        = $manifest['src/pages/record_request/index.tsx'] ?? null;
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Build the typed payload for the React side.
//
// payload = {
//   patientName: string,
//   recipients:  string[],
//   queue:       QueueRow[],
//   kpis:        { active, awaiting, completed30, failed },
// }
//
// QueueRow shape mirrors the React component's existing demo type:
//   { direction: 'out'|'in', dest, sub, sentLabel, sentDate, status, tone }
// ---------------------------------------------------------------------------

$pid = (int)$patientId;

/** @var array{
 *     patientName: string,
 *     recipients:  list<string>,
 *     queue:       list<array{direction:string,dest:string,sub:string,sentLabel:string,sentDate:string,status:string,tone:string}>,
 *     kpis:        array{active:int,awaiting:int,completed30:int,failed:int}
 * } $payload
 */
$payload = [
    'patientName' => '',
    'recipients'  => [],
    'queue'       => [],
    'kpis'        => ['active' => 0, 'awaiting' => 0, 'completed30' => 0, 'failed' => 0],
];

// Patient banner name.
if ($pid > 0) {
    $pat = sqlQuery("SELECT pid, fname, lname FROM patient_data WHERE pid = ?", [$pid]);
    if ($pat) {
        $name = trim((string)($pat['fname'] ?? '') . ' ' . (string)($pat['lname'] ?? ''));
        $payload['patientName'] = $name;
    }
}

// ── Records-release queue ────────────────────────────────────────────────
if ($pid > 0) {
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
    $thirtyDaysAgo = strtotime('-30 days') ?: 0;
    while ($r = sqlFetchArray($res)) {
        $rawStatus = strtolower((string)($r['refer_status'] ?? 'pending'));
        $tsRow = strtotime((string)($r['date'] ?? 'now')) ?: time();

        // KPI roll-up runs against ALL records.
        if ($rawStatus === 'failed') {
            $payload['kpis']['failed']++;
        } elseif (in_array($rawStatus, ['pending', 'awaiting'], true)) {
            $payload['kpis']['awaiting']++;
            $payload['kpis']['active']++;
        } elseif (in_array($rawStatus, ['sent', 'received', 'imported'], true)) {
            $payload['kpis']['active']++;
        } elseif (in_array($rawStatus, ['delivered', 'acknowledged'], true) && $tsRow >= $thirtyDaysAgo) {
            $payload['kpis']['completed30']++;
        }

        // Map status → display label + tone.
        [$statusLabel, $statusTone] = match (true) {
            $rawStatus === 'pending'      => ['Awaiting reply', 'warn'],
            $rawStatus === 'awaiting'     => ['Awaiting reply', 'warn'],
            $rawStatus === 'sent'         => ['Sent',           'info'],
            $rawStatus === 'delivered'    => ['Delivered',      'good'],
            $rawStatus === 'acknowledged' => ['Acknowledged',   'good'],
            $rawStatus === 'received'     => ['Imported',       'good'],
            $rawStatus === 'imported'     => ['Imported',       'good'],
            $rawStatus === 'draft'        => ['Draft',          'info'],
            $rawStatus === 'failed'       => ['Failed',         'danger'],
            default                       => [ucfirst($rawStatus !== '' ? $rawStatus : 'New'), 'info'],
        };

        $body = (string)($r['body'] ?? '');
        $subLine = '';
        if ($body !== '' && preg_match('/Records?:\s*([^\n]+)/', $body, $m)) {
            $subLine = trim($m[1]);
        } elseif ($body !== '') {
            $collapsed = preg_replace('/\s+/', ' ', $body) ?? '';
            $subLine = mb_substr($collapsed, 0, 80);
        }

        $direction = in_array($rawStatus, ['received', 'imported'], true) ? 'in' : 'out';
        $sentLabel = $direction === 'in' ? 'Received' : 'Sent';

        $payload['queue'][] = [
            'direction' => $direction,
            'dest'      => (string)($r['refer_to'] ?? '—'),
            'sub'       => $subLine !== '' ? $subLine : '—',
            'sentLabel' => $sentLabel,
            'sentDate'  => date('m/d H:i', $tsRow),
            'status'    => $statusLabel,
            'tone'      => $statusTone,
        ];
    }
}

// ── Recipient dropdown options ───────────────────────────────────────────
$pres = sqlStatement("SELECT name FROM pharmacies ORDER BY name ASC");
while ($pr = sqlFetchArray($pres)) {
    $nm = trim((string)($pr['name'] ?? ''));
    if ($nm !== '') {
        $payload['recipients'][] = $nm;
    }
}
if (count($payload['recipients']) === 0) {
    $procRes = sqlStatement("SELECT name FROM procedure_providers ORDER BY name ASC");
    while ($pp = sqlFetchArray($procRes)) {
        $nm = trim((string)($pp['name'] ?? ''));
        if ($nm !== '') {
            $payload['recipients'][] = $nm;
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Record Request'); ?></title>
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
     data-page="record_request"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-records="<?php echo attr((string)json_encode($payload)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Patient Record Request UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
