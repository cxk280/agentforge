<?php

/**
 * Recalls landing page — Figma "Screen 33 — Recalls".
 *
 * Thin manifest-loading wrapper that hands the page body off to the React
 * bundle built from /frontend/src/pages/recalls/. The PHP outer shell at
 * /interface/main/tabs/main.php still owns the navy top nav and the
 * Messages sub-tab strip; this file only renders inside the iframe.
 *
 * Boot context (CSRF token, current user id, current patient id, API base)
 * is passed to React via data-* attributes on the #cp-root mount node and
 * parsed in TS by readBootContext() — no global window.__INITIAL_STATE__.
 *
 * The recall queue is sourced from `medex_recalls` joined to `patient_data`
 * (for name + MRN) and `users` (for provider). Three Co-Pilot extension
 * columns are seeded by /interface/super/copilot_seed_recalls.php:
 *   cp_status        - workflow state (sent_awaiting | scheduled |
 *                      no_response | refused | pending_outreach |
 *                      lm_voicemail)
 *   cp_last_contact  - DATETIME of most recent outreach (NULL = none)
 *   cp_attempts      - count of outreach attempts
 *
 * Recalls is practice-wide (NOT patient-scoped) — same query runs for every
 * user. Filters and search are held entirely in React local state; no GET
 * params are observed here.
 *
 * The original DB-backed mock is preserved at copilot_recalls.php.bak so
 * a side-by-side screenshot diff remains possible.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../copilot_helpers.php");

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
$entry        = $manifest['src/pages/recalls/index.tsx'] ?? null;
// Apache serves the repo root as DocumentRoot, so /public is part of the URL.
$jsHref       = is_array($entry) && isset($entry['file']) ? '/public/build/' . $entry['file'] : null;
$cssHrefs     = is_array($entry) && isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

$session     = SessionWrapperFactory::getInstance()->getActiveSession();
$authUserId  = (string)($session->get('authUserID') ?? '');
$patientId   = (string)($session->get('pid') ?? '');
$csrfToken   = CsrfUtils::collectCsrfToken(session: $session);

// ---------------------------------------------------------------------------
// Status taxonomy — keys match the cp_status enum seeded by
// /interface/super/copilot_seed_recalls.php; values are the user-visible
// pill label and the tone class consumed by Recalls.module.css.
// ---------------------------------------------------------------------------
$statusMeta = [
    'sent_awaiting'    => ['Sent · awaiting',    'warn'],
    'scheduled'        => ['Scheduled',          'good'],
    'no_response'      => ['No response',        'danger'],
    'refused'          => ['Refused',            'danger'],
    'pending_outreach' => ['Pending outreach',   'neutral'],
    'lm_voicemail'     => ['LM left voicemail',  'warn'],
];

// ---------------------------------------------------------------------------
// Live recall roster — practice-wide. Sort overdue rows first, then by date.
// ---------------------------------------------------------------------------

$sql = "SELECT r.r_ID, r.r_pid, r.r_eventDate, r.r_reason,
               r.cp_status, r.cp_last_contact, r.cp_attempts,
               p.fname, p.lname, p.pubpid,
               u.fname AS prov_fname, u.lname AS prov_lname,
               u.title AS prov_title, u.username AS prov_username
        FROM medex_recalls r
        LEFT JOIN patient_data p ON p.pid = r.r_pid
        LEFT JOIN users u ON u.id = r.r_provider
        ORDER BY (r.r_eventDate < CURDATE()) DESC,
                 r.r_eventDate ASC,
                 r.r_ID ASC
        LIMIT 200";

$rows = [];
$rs   = sqlStatement($sql);
$today = date('Y-m-d');
while ($r = sqlFetchArray($rs)) {
    $statusKey = (string)($r['cp_status'] ?? 'pending_outreach');
    [$stLabel, $stTone] = $statusMeta[$statusKey] ?? [$statusKey, 'neutral'];

    $eventDate = (string)($r['r_eventDate'] ?? '');
    $isOverdue = $eventDate !== ''
        && strtotime($eventDate) < strtotime($today)
        && !in_array($statusKey, ['scheduled', 'refused'], true);

    $dueDisplay = $isOverdue
        ? 'OVERDUE'
        : ($eventDate !== '' ? date('m/d/Y', strtotime($eventDate)) : '—');

    $lastContact = (string)($r['cp_last_contact'] ?? '');
    $contactMethod = '';
    if ($lastContact !== '') {
        $contactMethod = 'Outreach';
        if (preg_match('/\(([A-Za-z]+) outreach\)/i', (string)($r['r_reason'] ?? ''), $mm)) {
            $contactMethod = ucfirst(strtolower($mm[1]));
        }
        $lastDisplay = $contactMethod . ' — ' . date('m/d/Y', strtotime($lastContact));
    } else {
        $lastDisplay = '—';
    }

    // Strip parenthetical outreach annotation from the displayed type.
    $typeDisplay = trim((string)preg_replace(
        '/\s*\([^)]+\s+outreach\)\s*$/i',
        '',
        (string)($r['r_reason'] ?? '')
    ));
    if ($typeDisplay === '') {
        $typeDisplay = '(unspecified)';
    }

    $patientName = trim((string)($r['fname'] ?? '') . ' ' . (string)($r['lname'] ?? ''));
    if ($patientName === '') {
        $patientName = 'Patient #' . (int)($r['r_pid'] ?? 0);
    }
    $mrn = (string)($r['pubpid'] ?? '');
    if ($mrn !== '' && $mrn[0] !== '#') {
        $mrn = '#' . str_pad($mrn, 6, '0', STR_PAD_LEFT);
    }
    if ($mrn === '') {
        $mrn = '#' . str_pad((string)((int)($r['r_pid'] ?? 0)), 6, '0', STR_PAD_LEFT);
    }

    $provDisplay = cp_format_provider_name([
        'fname'    => $r['prov_fname'] ?? '',
        'lname'    => $r['prov_lname'] ?? '',
        'title'    => $r['prov_title'] ?? '',
        'username' => $r['prov_username'] ?? '',
    ]);

    // Pre-select rows that need outreach so the bulk-action header CTA
    // lights up with a meaningful default count.
    $checked = !in_array($statusKey, ['scheduled', 'refused'], true);

    $rows[] = [
        'id'              => (int)($r['r_ID'] ?? 0),
        'pid'             => (int)($r['r_pid'] ?? 0),
        'patient_name'    => $patientName,
        'mrn'             => $mrn,
        'recall_type'     => $typeDisplay,
        'due_date'        => $dueDisplay,
        'overdue'         => $isOverdue,
        'last_contact'    => $lastDisplay,
        'contact_method'  => $contactMethod,
        'attempts'        => (int)($r['cp_attempts'] ?? 0),
        'status'          => $stLabel,
        'status_key'      => $statusKey,
        'tone'            => $stTone,
        'provider'        => $provDisplay,
        'default_checked' => $checked,
    ];
}

// ---------------------------------------------------------------------------
// KPI strip values — practice-wide aggregates, all live.
// ---------------------------------------------------------------------------
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
$kOverdue       = (int)($kpi['overdue']         ?? 0);
$kDueWeek       = (int)($kpi['due_week']        ?? 0);
$kDueMonth      = (int)($kpi['due_month']       ?? 0);
$kScheduled     = (int)($kpi['scheduled_total'] ?? 0);
$kContacted     = (int)($kpi['contacted_total'] ?? 0);
$kContactedWeek = (int)($kpi['contacted_week']  ?? 0);
$kTotal         = (int)($kpi['total']           ?? 0);

$kResponseRate = $kContacted > 0
    ? (int)round(($kScheduled / $kContacted) * 100)
    : 0;

$avgRow = sqlQuery(
    "SELECT AVG(TIMESTAMPDIFF(DAY, r_created, cp_last_contact)) AS d
     FROM medex_recalls
     WHERE cp_status = 'scheduled' AND cp_last_contact IS NOT NULL"
);
$kAvgDays = $avgRow !== false && $avgRow !== null && ($avgRow['d'] ?? null) !== null
    ? round((float)$avgRow['d'], 1)
    : null;

$payload = [
    'rows' => $rows,
    'kpi'  => [
        'overdue'        => $kOverdue,
        'dueWeek'        => $kDueWeek,
        'dueMonth'       => $kDueMonth,
        'scheduled'      => $kScheduled,
        'contacted'      => $kContacted,
        'contactedWeek'  => $kContactedWeek,
        'total'          => $kTotal,
        'responseRate'   => $kResponseRate,
        'avgDays'        => $kAvgDays,
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Recalls'); ?></title>
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
     data-page="recalls"
     data-csrf="<?php echo attr($csrfToken); ?>"
     data-user-id="<?php echo attr($authUserId); ?>"
     data-patient-id="<?php echo attr($patientId); ?>"
     data-api-base="<?php echo attr($webroot); ?>/apis"
     data-recalls="<?php echo attr((string)json_encode($payload)); ?>"></div>
<?php if ($jsHref !== null): ?>
<script type="module" src="<?php echo attr($webroot . $jsHref); ?>"></script>
<?php else: ?>
<div class="cp-boot-error">
  <?php echo xlt('Recalls UI bundle not found. Run "npm run build" in the /frontend directory to generate it.'); ?>
</div>
<?php endif; ?>
</body>
</html>
