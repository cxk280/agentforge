<?php

/**
 * Mock Index — navigation page for verifying every AgentForge mock.
 *
 * Lists every Co-Pilot mock screen by category with a clickable link.
 * Used during the mock-fidelity review pass — not part of the final
 * runtime navigation. Safe to delete once mocks are signed off.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$groups = [
    'Co-Pilot agent (Screens 1-5)' => [
        ['1 — Patient Ready / Active Conversation / Typing / Error / OpenEMR Integration', '/interface/copilot/index.php?pid=1'],
    ],
    'Top-nav landing (Screens 6-10)' => [
        ['6 — Calendar',         '/interface/main/calendar/copilot_calendar.php'],
        ['7 — Messages',         '/interface/main/messages/copilot_messages.php'],
        ['8 — Reports',          '/interface/reports/copilot_reports.php'],
        ['9 — Admin',            '/interface/super/copilot_admin.php'],
        ['10 — Patient Finder',  '/interface/main/finder/copilot_finder.php'],
    ],
    'Patient navtabs (Screens 11-21)' => [
        ['11 — Dashboard',       '/interface/patient_file/summary/copilot_dashboard.php'],
        ['12 — History',         '/interface/patient_file/history/copilot_history.php'],
        ['13 — Co-Pilot navtab', '/interface/copilot/index.php?pid=1'],
        ['14 — Assessments',     '/interface/patient_file/assessments/copilot_assessments.php'],
        ['15 — Report',          '/interface/patient_file/report/copilot_report.php'],
        ['16 — Documents',       '/interface/patient_file/documents/copilot_documents.php'],
        ['17 — Transactions',    '/interface/patient_file/transaction/copilot_transactions.php'],
        ['18 — Issues',          '/interface/patient_file/issues/copilot_issues.php'],
        ['19 — Ledger',          '/interface/patient_file/ledger/copilot_ledger.php'],
        ['20 — External Data',   '/interface/patient_file/external_data/copilot_external_data.php'],
        ['21 — PRO',             '/interface/easipro/copilot_pro.php'],
        ['21b — Modules',        '/interface/patient_file/modules/copilot_modules.php'],
    ],
    'Priority batch (Screens 22-28)' => [
        ['22 — New / Search Patient', '/interface/new/copilot_new_patient.php'],
        ['23 — Encounter Detail',     '/interface/patient_file/encounter/copilot_encounter.php'],
        ['24 — Fee Sheet',            '/interface/forms/fee_sheet/copilot_fee_sheet.php'],
        ['25 — e-Rx',                 '/interface/eRx/copilot_erx.php'],
        ['26 — Practice Settings (admin archetype)', '/interface/super/copilot_practice_settings.php'],
        ['27 — Billing Manager (billing archetype)', '/interface/billing/copilot_billing.php'],
        ['28 — Login',                '/interface/login/copilot_login.php'],
    ],
    'Clinical patient/visit flows' => [
        ['29 — Create Visit',                '/interface/forms/newpatient/copilot_create_visit.php'],
        ['30 — Visit History',               '/interface/patient_file/encounter/copilot_visit_history.php'],
        ['31 — Patient Record Request',      '/interface/patient_file/transaction/copilot_record_request.php'],
        ['33 — Recalls',                     '/interface/main/messages/copilot_recalls.php'],
        ['34 — Authorizations',              '/interface/patient_file/transaction/copilot_authorizations.php'],
    ],
    'Procedures / Labs' => [
        ['35 — Pending Review',         '/interface/orders/copilot_pending_review.php'],
        ['36 — Patient Results',        '/interface/orders/copilot_results.php'],
        ['37 — Lab Overview',           '/interface/orders/copilot_lab_overview.php'],
        ['38 — Batch Results',          '/interface/orders/copilot_batch_results.php'],
        ['39 — Electronic Reports',     '/interface/reports/copilot_electronic_reports.php'],
        ['40 — Lab Documents',          '/interface/patient_file/documents/copilot_lab_documents.php'],
    ],
    'eRx variants' => [
        ['41 — e-Rx Renewal',  '/interface/eRx/copilot_erx_renewal.php'],
        ['42 — e-Rx EPCS',     '/interface/eRx/copilot_erx_epcs.php'],
    ],
    'Clinical reports' => [
        ['43 — Patient List',           '/interface/reports/copilot_patient_list.php'],
        ['44 — Prescription Report',    '/interface/reports/copilot_prescription_report.php'],
        ['45 — Lab Trends',             '/interface/reports/copilot_lab_trends.php'],
        ['46 — Quality Measures (CQM)', '/interface/reports/copilot_quality_measures.php'],
        ['47 — Immunization Registry',  '/interface/patient_file/history/copilot_immunization_registry.php'],
    ],
    'Patient/chart misc' => [
        ['32 — Patient Tracker / Flow board', '/interface/main/copilot_patient_tracker.php'],
        ['48 — Patient Education',            '/interface/patient_file/education/copilot_education.php'],
        ['49 — Office Notes',                 '/interface/main/onotes/copilot_office_notes.php'],
        ['50 — Chart Tracker',                '/custom/copilot_chart_tracker.php'],
        ['51 — Provider Portal Dashboard',    '/portal/copilot_provider_portal.php'],
    ],
    'Admin sub-pages (sidebar)' => [
        ['52 — Users & Groups',       '/interface/super/copilot_users.php'],
        ['53 — ACL editor',           '/interface/super/copilot_acl.php'],
        ['54 — Facilities',           '/interface/super/copilot_facilities.php'],
        ['55 — Audit log',            '/interface/super/copilot_audit.php'],
        ['56 — System & Backup',      '/interface/super/copilot_system.php'],
        ['57 — Module Installer',     '/interface/super/copilot_module_installer.php'],
        ['+ Forms & Layouts',         '/interface/super/copilot_forms_layouts.php'],
        ['+ Templates',               '/interface/super/copilot_templates.php'],
        ['+ Coding & Lists',          '/interface/super/copilot_coding_lists.php'],
    ],
    'Billing / Financial' => [
        ['58 — Payment intake',       '/interface/billing/copilot_payment.php'],
        ['59 — Daily Cash / Aging',   '/interface/billing/copilot_aging.php'],
        ['60 — Inventory',            '/interface/billing/copilot_inventory.php'],
    ],
    'Chrome / Misc' => [
        ['61 — Print preview',        '/interface/main/copilot_print_preview.php'],
        ['62 — Avatar dropdown menu', '/interface/main/copilot_avatar_menu.php'],
    ],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('AgentForge Mock Index'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  body.cp-arch { background: #F5F6F7; padding: 32px; }
  .cp-mi-wrap { max-width: 1100px; margin: 0 auto; }
  .cp-mi-head h1 { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
  .cp-mi-head p  { font-size: 13px; color: #4F5763; margin: 0 0 24px; }
  .cp-mi-group { background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 12px;
    padding: 16px 20px; margin-bottom: 16px; }
  .cp-mi-group h2 {
    font-size: 11px; font-weight: 600; color: #8A91A1; letter-spacing: 0.6px;
    margin: 0 0 10px;
  }
  .cp-mi-list { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 16px; }
  .cp-mi-item {
    display: flex; align-items: center; padding: 8px 10px;
    border-radius: 8px;
    font-size: 13px; color: #0D1B2A;
    text-decoration: none;
    border-top: 1px solid #F0F1F3;
  }
  .cp-mi-item:hover { background: #F0FAFA; color: #008C8C; }
  .cp-mi-item .arrow { margin-left: auto; color: #8A91A1; }
  .cp-mi-item:hover .arrow { color: #008C8C; }
</style>
</head>
<body class="cp-arch">

<div class="cp-mi-wrap">
  <div class="cp-mi-head">
    <h1>AgentForge — Mock Index</h1>
    <p>Every Co-Pilot mock screen. Use this page to navigate to each mock for verification. Generated <?php echo text(date('M j, Y')); ?>.</p>
  </div>

  <?php foreach ($groups as $title => $items): ?>
    <section class="cp-mi-group">
      <h2><?php echo text(strtoupper($title)); ?></h2>
      <div class="cp-mi-list">
        <?php foreach ($items as [$lbl, $href]): ?>
          <a class="cp-mi-item" href="<?php echo attr($href); ?>" target="_top">
            <span><?php echo text($lbl); ?></span>
            <span class="arrow">→</span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

</div>

</body>
</html>
