<?php

/**
 * Create Visit — Screen 29.
 *
 * Visit-type picker (6-card grid) + scheduling form + visit template +
 * chief complaint, alongside a Quick Context right rail (Last Visit,
 * Open Orders, Care Gaps & Alerts, Co-Pilot Suggestion).
 *
 * The chrome (top nav, demographics banner, navtab strip) is rendered
 * by the parent shell; this page renders only the body.
 *
 * Backend wiring:
 *   - Patient context from $_SESSION['pid'] (fallback to 1 in dev)
 *   - Provider dropdown from `users WHERE authorized = 1 AND active = 1`
 *   - Facility dropdown from `facility WHERE inactive = 0`
 *   - Last visit / open orders / care gaps from real tables (best-effort
 *     against the mock seed).
 *   - POST action=start_visit creates a `form_encounter` row and
 *     redirects to the encounter page with `?msg=visit_started`.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Session\SessionWrapperFactory;
require_once(__DIR__ . "/../../main/copilot_helpers.php");

// ---------------------------------------------------------------------
// Patient context
// ---------------------------------------------------------------------
$pid = (int)(SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 1);
$currentUserId = (int)($_SESSION['authUserID'] ?? 0);

$patient = sqlQuery(
    "SELECT pid, fname, lname, pubpid FROM patient_data WHERE pid = ? LIMIT 1",
    [$pid]
);
$patientName = $patient
    ? trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''))
    : 'Patient';
$patientMrn = (string)($patient['pubpid'] ?? '');

// ---------------------------------------------------------------------
// POST handler — start_visit creates a new encounter and redirects.
// CSRF skipped — internal mock page (per backend brief).
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'start_visit'
) {
    $providerId = (int)($_POST['provider'] ?? 0);
    $facilityId = (int)($_POST['facility_id'] ?? 0);
    $visitTypeKey = (string)($_POST['visit_type'] ?? 'office');
    $chiefComplaint = trim((string)($_POST['chief_complaint'] ?? ''));

    // Map visit-type key → calendar category id (pc_catid).
    // Office Visit (5) is the default fallback.
    $visitTypeCatMap = [
        'office'        => 5,   // Office Visit
        'telehealth'    => 5,   // No dedicated telehealth catid in seed; reuse Office Visit.
        'annual'        => 13,  // Preventive Care Services
        'followup'      => 9,   // Established Patient
        'acute'         => 5,   // Office Visit
        'procedure'     => 5,   // Office Visit (no procedure catid in seed)
    ];
    $pcCatid = $visitTypeCatMap[$visitTypeKey] ?? 5;

    // Generate a new encounter number using the standard sequence.
    $encounterNo = generate_id();

    sqlStatement(
        "INSERT INTO form_encounter "
        . "(date, reason, facility_id, pid, encounter, pc_catid, provider_id, class_code) "
        . "VALUES (NOW(), ?, ?, ?, ?, ?, ?, 'AMB')",
        [$chiefComplaint, $facilityId, $pid, $encounterNo, $pcCatid, $providerId]
    );
    $newId = (int)sqlInsert(
        "INSERT INTO forms (date, encounter, form_name, form_id, pid, user, formdir) "
        . "VALUES (NOW(), ?, 'New Patient Encounter', ?, ?, ?, 'newpatient')",
        [$encounterNo, $encounterNo, $pid, $_SESSION['authUser'] ?? 'admin']
    );

    header(
        'Location: /interface/patient_file/encounter/copilot_encounter.php'
        . '?eid=' . urlencode((string)$encounterNo)
        . '&msg=visit_started'
    );
    exit;
}

$flash = $_GET['msg'] ?? null;

// ---------------------------------------------------------------------
// Provider list (authorized + active users)
// ---------------------------------------------------------------------
$providerRows = sqlStatement(
    "SELECT id, username, fname, lname, title, facility_id "
    . "FROM users "
    . "WHERE authorized = 1 AND active = 1 "
    . "ORDER BY lname, fname"
);
$providers = [];
while ($r = sqlFetchArray($providerRows)) {
    $providers[] = $r;
}

// Pre-select: current user if they are in the list, otherwise the
// last-visit provider, otherwise the first provider.
$selectedProviderId = 0;
if ($currentUserId > 0) {
    foreach ($providers as $p) {
        if ((int)$p['id'] === $currentUserId) {
            $selectedProviderId = $currentUserId;
            break;
        }
    }
}

// ---------------------------------------------------------------------
// Facility list (active service locations)
// ---------------------------------------------------------------------
$facilityRows = sqlStatement(
    "SELECT id, name, service_location FROM facility "
    . "WHERE inactive = 0 ORDER BY name"
);
$facilities = [];
while ($r = sqlFetchArray($facilityRows)) {
    $facilities[] = $r;
}

// Pre-select the user's primary facility.
$selectedFacilityId = 0;
if ($currentUserId > 0) {
    $u = sqlQuery("SELECT facility_id FROM users WHERE id = ?", [$currentUserId]);
    $selectedFacilityId = (int)($u['facility_id'] ?? 0);
}
if ($selectedFacilityId === 0 && $facilities) {
    $selectedFacilityId = (int)$facilities[0]['id'];
}
if ($selectedProviderId === 0 && $providers) {
    $selectedProviderId = (int)$providers[0]['id'];
}

// ---------------------------------------------------------------------
// Visit-type cards: [key, iconKey, name, meta]
// Office Visit selected by default; selection is overridden by
// $_GET['visit_type'] (set when one of the cards is clicked).
// ---------------------------------------------------------------------
$visit_types = [
    ['office',     'stethoscope', 'Office Visit',     'In-person, 30 min'],
    ['telehealth', 'video',       'Telehealth',       'Video, 20 min'],
    ['annual',     'clipboard',   'Annual Physical',  'In-person, 60 min'],
    ['followup',   'refresh',     'Follow-up',        'In-person, 15 min'],
    ['acute',      'firstaid',    'Acute / Same-day', 'In-person, 20 min'],
    ['procedure',  'scalpel',     'Procedure',        'In-person, varies'],
];
$validVisitKeys = array_column($visit_types, 0);
$selectedVisitType = $_GET['visit_type'] ?? 'office';
if (!in_array($selectedVisitType, $validVisitKeys, true)) {
    $selectedVisitType = 'office';
}

// ---------------------------------------------------------------------
// Visit template (clinical-template choice).
// Hardcoded list per brief — document_templates seed is generic
// onboarding docs, not visit templates.
// ---------------------------------------------------------------------
$visit_templates = [
    'diabetes_followup' => 'Diabetes follow-up — vitals, A1C review, medication reconciliation, foot exam',
    'annual_physical'   => 'Annual Physical — full ROS, preventive screenings, immunizations',
    'acute_visit'       => 'Acute visit — focused HPI, exam, treatment plan',
    'telehealth'        => 'Telehealth — chief complaint, MDM, e-prescribe',
    'blank'             => 'Blank note — no template',
];

// ---------------------------------------------------------------------
// LAST VISIT — most recent encounter for this patient + provider.
// ---------------------------------------------------------------------
$lastVisit = sqlQuery(
    "SELECT fe.id, fe.date, fe.reason, fe.last_level_closed, "
    . "       u.fname AS u_fname, u.lname AS u_lname, u.title AS u_title, u.username AS u_username, "
    . "       opc.pc_catname "
    . "FROM form_encounter fe "
    . "LEFT JOIN users u ON u.id = fe.provider_id "
    . "LEFT JOIN openemr_postcalendar_categories opc ON opc.pc_catid = fe.pc_catid "
    . "WHERE fe.pid = ? "
    . "ORDER BY fe.date DESC LIMIT 1",
    [$pid]
);

// ---------------------------------------------------------------------
// OPEN ORDERS — procedure orders not yet completed/cancelled.
// ---------------------------------------------------------------------
$ooRows = sqlStatement(
    "SELECT procedure_order_id, date_ordered, order_status, order_priority, "
    . "       order_diagnosis, procedure_order_type "
    . "FROM procedure_order "
    . "WHERE patient_id = ? "
    . "  AND (order_status IS NULL OR order_status NOT IN ('completed','cancelled')) "
    . "ORDER BY date_ordered DESC LIMIT 3",
    [$pid]
);
$open_orders = [];
while ($r = sqlFetchArray($ooRows)) {
    $open_orders[] = $r;
}

// ---------------------------------------------------------------------
// CARE GAPS & ALERTS — patient_reminders rows that are still active.
// ---------------------------------------------------------------------
$cgRows = sqlStatement(
    "SELECT id, due_status, category, item, date_created "
    . "FROM patient_reminders "
    . "WHERE pid = ? AND active = 1 "
    . "ORDER BY FIELD(due_status, 'past_due','due','soon','not_due'), date_created DESC LIMIT 4",
    [$pid]
);
$care_gaps = [];
while ($r = sqlFetchArray($cgRows)) {
    $tone = match ($r['due_status'] ?? '') {
        'past_due' => 'warn',
        'due'      => 'info',
        default    => 'neutral',
    };
    $label = trim((string)($r['category'] ?? '') . ' · ' . (string)($r['item'] ?? ''), ' ·');
    if ($label === '') {
        $label = 'Reminder #' . (int)$r['id'];
    }
    $care_gaps[] = [$tone, $label];
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Create Visit'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header dot separator and meta light */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-pagehead .help-pill {
    border: 1px solid #E4E5E8; background: #FFFFFF; color: #4F5763;
    border-radius: 999px; padding: 6px 14px; font-size: 12px; font-weight: 500;
  }

  /* Body 2-col grid: main panel + right rail */
  .cv-shell {
    display: grid; grid-template-columns: 1fr 320px; gap: 16px;
    align-items: start;
  }

  /* Main left panel (white card containing all sections) */
  .cv-main {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 22px 24px 24px;
    display: flex; flex-direction: column; gap: 22px;
  }

  /* Section labels */
  .cv-sec-lbl {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.8px;
    margin-bottom: 12px;
  }

  /* Visit type 3x2 grid */
  .cv-vt-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .cv-vt {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 10px;
    padding: 14px 16px; display: flex; gap: 12px; align-items: flex-start;
    cursor: pointer; text-decoration: none; color: inherit;
  }
  .cv-vt.sel { border-color: #008C8C; border-width: 2px; padding: 13px 15px; background: #F2FAFA; }
  .cv-vt-icon {
    width: 34px; height: 34px; border-radius: 8px; background: #F5F6F7;
    display: inline-flex; align-items: center; justify-content: center;
    flex: 0 0 auto; color: #4F5763;
  }
  .cv-vt.sel .cv-vt-icon { background: #D6F0F0; color: #008C8C; }
  .cv-vt-icon svg { display: block; }
  .cv-vt-info { flex: 1; min-width: 0; }
  .cv-vt-info .n { font-weight: 700; font-size: 13px; color: #0D1B2A; line-height: 1.2; }
  .cv-vt-info .m { font-size: 11px; color: #8A91A1; margin-top: 4px; line-height: 1.3; }
  .cv-vt-sel-row {
    margin-top: 8px; font-size: 11px; font-weight: 600; color: #008C8C;
    display: inline-flex; align-items: center; gap: 4px;
  }

  /* Form rows */
  .cv-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .cv-form-grid.four { grid-template-columns: repeat(4, 1fr); }
  .cv-field { display: flex; flex-direction: column; gap: 6px; }
  .cv-field label {
    font-size: 11px; font-weight: 500; color: #4F5763;
  }
  .cv-input {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 8px;
    height: 38px; padding: 0 12px; font-size: 13px; color: #0D1B2A;
    outline: none; width: 100%;
  }
  .cv-input:focus { border-color: #008C8C; }
  .cv-select {
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
  .cv-textarea {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 12px; font-size: 13px; color: #0D1B2A;
    outline: none; width: 100%; min-height: 64px; resize: vertical;
    font-family: inherit;
  }

  /* Right rail */
  .cv-rail { display: flex; flex-direction: column; gap: 12px; }
  .cv-rail-card {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 12px;
    padding: 14px 16px;
  }
  .cv-rail-lbl {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.8px;
    margin-bottom: 10px;
  }
  .cv-rail-title { font-size: 13px; font-weight: 700; color: #0D1B2A; line-height: 1.3; }
  .cv-rail-meta  { font-size: 11px; color: #4F5763; margin-top: 4px; line-height: 1.4; }
  .cv-rail-notes { font-size: 11px; color: #8A91A1; margin-top: 6px; line-height: 1.4; }

  /* Open orders item */
  .cv-oo-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 0; border-top: 1px solid #F0F1F3;
  }
  .cv-oo-item:first-child { border-top: none; padding-top: 2px; }
  .cv-oo-icon {
    width: 28px; height: 28px; border-radius: 6px;
    flex: 0 0 auto;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .cv-oo-info { flex: 1; min-width: 0; }
  .cv-oo-info .n { font-size: 12px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cv-oo-info .s { font-size: 10px; color: #8A91A1; margin-top: 2px; }
  .cv-oo-dot { width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto; }
  .cv-oo-empty { font-size: 11px; color: #8A91A1; padding: 4px 0; }

  /* Care gaps row */
  .cv-cg-item {
    display: flex; align-items: center; gap: 10px;
    border-radius: 8px; padding: 10px 12px;
    font-size: 12px; font-weight: 500;
    margin-bottom: 6px;
  }
  .cv-cg-item:last-child { margin-bottom: 0; }
  .cv-cg-item.warn    { background: #FFF8EC; color: #8A5A1A; }
  .cv-cg-item.warn .cv-cg-ic { color: #FA8C33; }
  .cv-cg-item.info    { background: #F0F4F9; color: #2E5A99; }
  .cv-cg-item.info .cv-cg-ic { color: #4785D9; }
  .cv-cg-item.neutral { background: #F5F6F7; color: #4F5763; }
  .cv-cg-item.neutral .cv-cg-ic { color: #8A91A1; }
  .cv-cg-ic { font-size: 13px; line-height: 1; flex: 0 0 auto; }

  /* Co-Pilot Suggestion card */
  .cv-cp-card {
    background: #F2F6FC; border: 1px solid #D5E0F2; border-radius: 12px;
    padding: 14px 16px;
  }
  .cv-cp-card .lbl {
    font-size: 10px; font-weight: 700;
    color: #4785D9; letter-spacing: 0.6px;
    margin-bottom: 8px;
    display: inline-flex; align-items: center; gap: 5px;
  }
  .cv-cp-card .ttl { font-size: 13px; font-weight: 700; color: #0D1B2A; line-height: 1.3; }
  .cv-cp-card .body {
    font-size: 11px; color: #4F5763; margin-top: 6px; line-height: 1.45;
  }
  .cv-cp-card .open {
    margin-top: 10px;
    border: 1px solid #B6CDEC; background: #FFFFFF;
    border-radius: 999px; padding: 5px 12px;
    font-size: 11px; font-weight: 600; color: #4785D9;
    display: inline-flex; align-items: center; gap: 4px;
  }

  /* Bottom CTA bar */
  .cv-cta-bar {
    grid-column: 2 / 3;
    display: flex; gap: 10px; justify-content: flex-end;
    margin-top: 4px;
  }
  .cv-cta-bar .cp-btn { padding: 9px 18px; font-size: 13px; }
  .cv-cta-bar a.cp-btn { text-decoration: none; display: inline-flex; align-items: center; }

  /* Flash banner */
  .cv-flash {
    background: #ECF8F1; border: 1px solid #B7DEC2; color: #1F6E3A;
    border-radius: 8px; padding: 8px 12px; font-size: 12px; font-weight: 500;
    margin-bottom: 8px;
  }

  /* Page wrapper padding */
  main.cp-content.cv-page { padding: 18px 24px 28px; gap: 14px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:10px;">
      <span class="title"><?php echo xlt('Create Visit'); ?></span>
      <span class="dot">·</span>
      <span class="meta-light">
        <?php echo xlt('Start a new encounter for'); ?>
        <?php echo text($patientName); ?><?php if ($patientMrn !== ''): ?> (MRN <?php echo text($patientMrn); ?>)<?php endif; ?>
      </span>
    </div>
  </div>
  <button type="button" class="help-pill">? <?php echo xlt('Help'); ?></button>
</header>

<main class="cp-content cv-page">

  <?php if ($flash === 'visit_started'): ?>
    <div class="cv-flash"><?php echo xlt('Visit started.'); ?></div>
  <?php endif; ?>

  <form method="post" action="">
    <input type="hidden" name="action" value="start_visit">
    <input type="hidden" name="visit_type" id="cv-vt-input" value="<?php echo attr($selectedVisitType); ?>">

    <div class="cv-shell">

      <!-- LEFT: main visit-builder panel -->
      <section class="cv-main">

        <!-- VISIT TYPE -->
        <div>
          <div class="cv-sec-lbl"><?php echo xlt('VISIT TYPE'); ?></div>
          <div class="cv-vt-grid">
            <?php foreach ($visit_types as [$vtKey, $ico, $nm, $meta]):
                $sel = ($vtKey === $selectedVisitType);
                $href = '?' . http_build_query(['visit_type' => $vtKey]);
            ?>
              <a class="cv-vt<?php echo $sel ? ' sel' : ''; ?>" href="<?php echo attr($href); ?>">
                <div class="cv-vt-icon">
                  <?php if ($ico === 'stethoscope'): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v6a4 4 0 0 0 8 0V3"/><path d="M6 3H4"/><path d="M14 3h2"/><path d="M10 13v3a4 4 0 0 0 8 0v-1"/><circle cx="18" cy="13" r="2"/></svg>
                  <?php elseif ($ico === 'video'): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="14" height="12" rx="2"/><path d="m22 8-6 4 6 4V8z"/></svg>
                  <?php elseif ($ico === 'clipboard'): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9z" fill="currentColor" stroke="none"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>
                  <?php elseif ($ico === 'refresh'): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg>
                  <?php elseif ($ico === 'firstaid'): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="14" rx="2"/><path d="M9 6V4h6v2"/><path d="M12 10v6"/><path d="M9 13h6"/></svg>
                  <?php elseif ($ico === 'scalpel'): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4 4 14l3 3 10-10z"/><path d="m14 4 6-1-1 6"/></svg>
                  <?php endif; ?>
                </div>
                <div class="cv-vt-info">
                  <div class="n"><?php echo text($nm); ?></div>
                  <div class="m"><?php echo text($meta); ?></div>
                  <?php if ($sel): ?>
                    <div class="cv-vt-sel-row">&#10003; <?php echo xlt('Selected'); ?></div>
                  <?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- SCHEDULING -->
        <div>
          <div class="cv-sec-lbl"><?php echo xlt('SCHEDULING'); ?></div>
          <div class="cv-form-grid">
            <div class="cv-field">
              <label><?php echo xlt('Provider'); ?></label>
              <select class="cv-input cv-select" name="provider">
                <?php foreach ($providers as $p):
                    $name = cp_format_provider_name($p);
                ?>
                  <option value="<?php echo attr($p['id']); ?>" <?php echo ((int)$p['id'] === $selectedProviderId) ? 'selected' : ''; ?>>
                    <?php echo text($name); ?>
                  </option>
                <?php endforeach; ?>
                <?php if (empty($providers)): ?>
                  <option value="0"><?php echo xlt('No providers available'); ?></option>
                <?php endif; ?>
              </select>
            </div>
            <div class="cv-field">
              <label><?php echo xlt('Facility'); ?></label>
              <select class="cv-input cv-select" name="facility_id">
                <?php foreach ($facilities as $f): ?>
                  <option value="<?php echo attr($f['id']); ?>" <?php echo ((int)$f['id'] === $selectedFacilityId) ? 'selected' : ''; ?>>
                    <?php echo text($f['name']); ?>
                  </option>
                <?php endforeach; ?>
                <?php if (empty($facilities)): ?>
                  <option value="0"><?php echo xlt('No active facility'); ?></option>
                <?php endif; ?>
              </select>
            </div>
          </div>
          <div class="cv-form-grid four" style="margin-top:12px;">
            <div class="cv-field">
              <label><?php echo xlt('Date'); ?></label>
              <input class="cv-input" type="text" name="date" value="<?php echo attr(date('m/d/Y')); ?>">
            </div>
            <div class="cv-field">
              <label><?php echo xlt('Time'); ?></label>
              <input class="cv-input" type="text" name="time" value="<?php echo attr(date('h:i A')); ?>">
            </div>
            <div class="cv-field">
              <label><?php echo xlt('Duration'); ?></label>
              <input class="cv-input" type="text" name="duration" value="30 min">
            </div>
            <div class="cv-field">
              <label><?php echo xlt('Room'); ?></label>
              <input class="cv-input" type="text" name="room" value="">
            </div>
          </div>
        </div>

        <!-- VISIT TEMPLATE -->
        <div>
          <div class="cv-sec-lbl"><?php echo xlt('VISIT TEMPLATE'); ?></div>
          <div class="cv-field">
            <label><?php echo xlt('Apply template'); ?></label>
            <select class="cv-input cv-select" name="template">
              <?php foreach ($visit_templates as $tk => $tlabel): ?>
                <option value="<?php echo attr($tk); ?>"><?php echo text($tlabel); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- CHIEF COMPLAINT / REASON -->
        <div>
          <div class="cv-sec-lbl"><?php echo xlt('CHIEF COMPLAINT / REASON'); ?></div>
          <textarea class="cv-textarea" name="chief_complaint" placeholder="<?php echo attr(xl('Briefly describe the reason for today\'s visit')); ?>"></textarea>
        </div>

      </section>

      <!-- RIGHT: Quick Context rail -->
      <aside class="cv-rail">

        <div class="cv-rail-card">
          <div class="cv-rail-lbl"><?php echo xlt('QUICK CONTEXT'); ?></div>
          <div style="font-size:10px; font-weight:600; color:#8A91A1; letter-spacing:0.6px; margin-bottom:6px;"><?php echo xlt('LAST VISIT'); ?></div>
          <?php if ($lastVisit):
              $lvProvider = cp_format_provider_name([
                  'username' => $lastVisit['u_username'] ?? '',
                  'fname'    => $lastVisit['u_fname'] ?? '',
                  'lname'    => $lastVisit['u_lname'] ?? '',
                  'title'    => $lastVisit['u_title'] ?? '',
              ]);
              $lvDate = $lastVisit['date'] ? date('m/d/Y', strtotime((string)$lastVisit['date'])) : '';
              $lvCat = trim((string)($lastVisit['pc_catname'] ?? '')) ?: 'Visit';
              $lvStatus = ((int)($lastVisit['last_level_closed'] ?? 0) > 0) ? 'Signed' : 'In progress';
              $lvReason = trim((string)($lastVisit['reason'] ?? ''));
          ?>
            <div class="cv-rail-title"><?php echo text($lvCat . ' — ' . $lvProvider); ?></div>
            <div class="cv-rail-meta"><?php echo text($lvDate . ' · ' . $lvStatus); ?></div>
            <?php if ($lvReason !== ''): ?>
              <div class="cv-rail-notes"><?php echo text('Reason: ' . $lvReason); ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="cv-rail-meta"><?php echo xlt('No previous visits on file.'); ?></div>
          <?php endif; ?>
        </div>

        <div class="cv-rail-card">
          <div class="cv-rail-lbl"><?php echo xlt('OPEN ORDERS'); ?></div>
          <?php if (empty($open_orders)): ?>
            <div class="cv-oo-empty"><?php echo xlt('No open orders.'); ?></div>
          <?php else:
              // Tone palette per row index, to keep the visual variety from the mock.
              $palette = [
                  ['#FFE9D6', '#FA8C33'],
                  ['#FCE7E7', '#1F8C4D'],
                  ['#E8F2EC', '#4785D9'],
              ];
              foreach ($open_orders as $i => $o):
                  [$bg, $dot] = $palette[$i % count($palette)];
                  $title = trim((string)($o['order_diagnosis'] ?? '')) !== ''
                      ? (string)$o['order_diagnosis']
                      : ucfirst(str_replace('_', ' ', (string)($o['procedure_order_type'] ?? 'Order')));
                  $sub = $o['date_ordered']
                      ? ('Ordered ' . date('m/d/Y', strtotime((string)$o['date_ordered'])))
                      : (string)($o['order_status'] ?? '');
          ?>
            <div class="cv-oo-item">
              <div class="cv-oo-icon" style="background: <?php echo attr($bg); ?>;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="<?php echo attr($dot); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/></svg>
              </div>
              <div class="cv-oo-info">
                <div class="n"><?php echo text($title); ?></div>
                <div class="s"><?php echo text($sub); ?></div>
              </div>
              <div class="cv-oo-dot" style="background: <?php echo attr($dot); ?>;"></div>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <div class="cv-rail-card">
          <div class="cv-rail-lbl"><?php echo xlt('CARE GAPS & ALERTS'); ?></div>
          <?php if (empty($care_gaps)): ?>
            <!-- computed/static for mock: no patient_reminders rows in seed for this pid -->
            <div class="cv-oo-empty"><?php echo xlt('No active care gaps.'); ?></div>
          <?php else: foreach ($care_gaps as [$tone, $txt]): ?>
            <div class="cv-cg-item <?php echo attr($tone); ?>">
              <span class="cv-cg-ic">
                <?php if ($tone === 'warn'): ?>&#9888;<?php elseif ($tone === 'info'): ?>&#9432;<?php else: ?>&#9675;<?php endif; ?>
              </span>
              <span><?php echo text($txt); ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <div class="cv-cp-card">
          <!-- LLM-driven; static text for mock. -->
          <div class="lbl">&#10022; <?php echo xlt('CO-PILOT SUGGESTION'); ?></div>
          <div class="ttl"><?php echo text('Pre-visit summary ready'); ?></div>
          <div class="body"><?php echo text('Last A1C 7.9% (up from 7.2%). BP trending down. Consider GLP-1 discussion based on weight trend.'); ?></div>
          <button type="button" class="open"><?php echo xlt('Open in Co-Pilot'); ?> &rarr;</button>
        </div>

      </aside>

      <!-- BOTTOM: CTA bar (right column only) -->
      <div class="cv-cta-bar">
        <a class="cp-btn ghost" href="/interface/main/copilot_mock_index.php"><?php echo xlt('Cancel'); ?></a>
        <button type="submit" class="cp-btn primary"><?php echo xlt('Start visit'); ?> &rarr;</button>
      </div>

    </div>
  </form>

</main>

</body>
</html>
