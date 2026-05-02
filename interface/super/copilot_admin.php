<?php

/**
 * Admin landing page — implements Screen 9 of the AgentForge mockups.
 *
 * Sidebar (categories) + content with stat cards, two action panels
 * (Quick Actions / System), and a Recent Admin Activity feed. Replaces
 * the legacy Admin dropdown-only menu structure.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

// Sidebar categories. Active flag is hardcoded for the mockup; future
// work would route based on a query param.
$categories = [
    ['Overview',          true,  '/interface/super/copilot_admin.php'],
    ['Practice Settings', false, '/interface/super/copilot_practice_settings.php'],
    ['Users & Groups',    false, '/interface/super/copilot_users.php'],
    ['ACL',               false, '/interface/super/copilot_acl.php'],
    ['Facilities',        false, '/interface/super/copilot_facilities.php'],
    ['Forms & Layouts',   false, '/interface/super/copilot_forms_layouts.php'],
    ['Templates',         false, '/interface/super/copilot_templates.php'],
    ['Coding & Lists',    false, '/interface/super/copilot_coding_lists.php'],
    ['Modules',           false, '/interface/super/copilot_module_installer.php'],
    ['System',            false, '/interface/super/copilot_system.php'],
    ['Logs & Audit',      false, '/interface/super/copilot_audit.php'],
];

// Top-line stats.
$stats = [
    ['Active Users',      '47',     '+3 this week',         '#008C8C'],
    ['Patients',          '12,408', '+182 this month',      '#4885D9'],
    ['Encounters Today',  '62',     '8 awaiting sign-off',  '#FA8C33'],
    ['System Health',     'OK',     'All services up',      '#26A65B'],
];

// Two action panels.
$quickActions = [
    ['👤', 'Add new user',       'Provision a clinical or admin account',  '/interface/super/copilot_users.php'],
    ['📋', 'Create form layout', 'Add a custom intake or note form',       '/interface/super/copilot_forms_layouts.php'],
    ['🏥', 'Add facility',       'Register a new clinic or location',      '/interface/super/copilot_facilities.php'],
    ['🔐', 'Update ACL roles',   'Adjust permissions for an existing role','/interface/super/copilot_acl.php'],
];
$systemActions = [
    ['🔄', 'Run backup now',     'Database snapshot to local + S3',        '/interface/super/copilot_system.php'],
    ['📜', 'View audit log',     'Access events for last 24 hours',        '/interface/super/copilot_audit.php'],
    ['🌐', 'Manage modules',     'Enable / disable installed modules',     '/interface/super/copilot_module_installer.php'],
    ['⚙',  'Site preferences',   'Globals.php and feature flags',          '/interface/super/copilot_practice_settings.php'],
];

// Recent admin activity (mock-faithful).
$activity = [
    ['09:42 AM', 'user.created',      'Dr. Allison Park added by Admin'],
    ['09:18 AM', 'module.enabled',    'Carecoordination module enabled'],
    ['08:51 AM', 'acl.updated',       'Nurse role granted patients/notes write'],
    ['Yesterday','backup.completed',  'Daily backup uploaded to S3 (842 MB)'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Admin'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F7F8;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
  }

  /* ── Page header ─────────────────────────────────────────────────────── */
  .cp-adm-header {
    flex: 0 0 auto;
    width: 100%;
    height: 64px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 14px;
  }
  .cp-adm-title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-adm-bcrumb {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    line-height: 1;
  }
  .cp-adm-bcrumb-sep { color: #8A91A0; }
  .cp-adm-bcrumb-mid { color: #4F5662; }
  .cp-adm-bcrumb-end { color: #0D1B2A; font-weight: 500; }

  /* ── Two-column layout ───────────────────────────────────────────────── */
  .cp-adm-shell {
    flex: 1 1 auto;
    display: flex;
    min-height: 0;
  }

  /* Sidebar */
  .cp-adm-sidebar {
    flex: 0 0 240px;
    width: 240px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 16px 0;
    overflow-y: auto;
  }
  .cp-adm-cat {
    position: relative;
    display: block;
    width: 100%;
    height: 40px;
    padding: 0 24px;
    line-height: 40px;
    font-size: 13px; font-weight: 500;
    color: #4F5662;
    text-decoration: none;
    background: transparent;
    border: 0;
    text-align: left;
    cursor: pointer;
  }
  .cp-adm-cat:hover { background: #F5F7F8; color: #0D1B2A; }
  .cp-adm-cat.active {
    background: rgba(0, 140, 140, 0.08);
    color: #008C8C;
    font-weight: 600;
  }
  .cp-adm-cat.active::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: #008C8C;
  }

  /* Right content */
  .cp-adm-content {
    flex: 1 1 auto;
    padding: 24px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  /* Stats row */
  .cp-adm-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
  }
  .cp-adm-stat {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 18px;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .cp-adm-stat-label {
    font-size: 11px; font-weight: 500;
    color: #8A91A0;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    line-height: 1.2;
  }
  .cp-adm-stat-value {
    font-size: 24px; font-weight: 700;
    color: #0D1B2A;
    line-height: 1.2;
  }
  .cp-adm-stat-sub {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; color: #8A91A0;
    line-height: 1.2;
  }
  .cp-adm-stat-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    flex: 0 0 auto;
  }

  /* Action panels (two side-by-side cards) */
  .cp-adm-panels {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }
  .cp-adm-panel {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .cp-adm-panel-head {
    height: 52px;
    padding: 0 22px;
    display: flex;
    align-items: center;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-adm-panel-title { font-size: 14px; font-weight: 600; color: #0D1B2A; line-height: 1; }
  .cp-adm-action {
    display: flex;
    align-items: center;
    gap: 14px;
    height: 82px;
    padding: 0 22px;
    border-bottom: 1px solid #E4E5E8;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
  }
  .cp-adm-action:last-child { border-bottom: 0; }
  .cp-adm-action:hover { background: #F5F7F8; }
  .cp-adm-action-icon {
    width: 36px; height: 36px;
    border-radius: 8px;
    background: rgba(0, 140, 140, 0.12);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex: 0 0 auto;
  }
  .cp-adm-action-text { flex: 1 1 auto; min-width: 0; }
  .cp-adm-action-title { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cp-adm-action-desc {
    font-size: 12px; color: #8A91A0;
    line-height: 1.3;
    margin-top: 2px;
  }
  .cp-adm-action-arrow { font-size: 14px; color: #008C8C; flex: 0 0 auto; }

  /* Recent Admin Activity */
  .cp-adm-activity {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
  }
  .cp-adm-activity-head {
    height: 48px;
    padding: 0 22px;
    display: flex;
    align-items: center;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-adm-activity-title {
    font-size: 14px; font-weight: 600; color: #0D1B2A;
    line-height: 1;
  }
  .cp-adm-activity-spacer { flex: 1; }
  .cp-adm-activity-link {
    font-size: 12px; font-weight: 500; color: #008C8C;
    text-decoration: none;
  }
  .cp-adm-activity-link:hover { text-decoration: underline; }
  .cp-adm-activity-row {
    display: flex;
    align-items: center;
    gap: 16px;
    height: 44px;
    padding: 0 22px;
  }
  .cp-adm-activity-time {
    font-size: 11px; font-weight: 500; color: #8A91A0;
    line-height: 1.2;
    flex: 0 0 70px;
  }
  .cp-adm-activity-tag {
    background: #F5F7F8;
    border-radius: 4px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 500;
    color: #4F5662;
    letter-spacing: 0.4px;
    line-height: 1.2;
    flex: 0 0 auto;
  }
  .cp-adm-activity-desc {
    font-size: 13px; color: #0D1B2A;
    line-height: 1.3;
  }
</style>
</head>
<body>

<header class="cp-adm-header">
  <div class="cp-adm-title"><?php echo xlt('Admin'); ?></div>
  <div class="cp-adm-bcrumb">
    <span class="cp-adm-bcrumb-sep">/</span>
    <span class="cp-adm-bcrumb-mid"><?php echo xlt('System'); ?></span>
    <span class="cp-adm-bcrumb-sep">/</span>
    <span class="cp-adm-bcrumb-end"><?php echo xlt('Overview'); ?></span>
  </div>
</header>

<div class="cp-adm-shell">

  <aside class="cp-adm-sidebar">
    <?php foreach ($categories as $c): ?>
      <a class="cp-adm-cat<?php echo $c[1] ? ' active' : ''; ?>" href="<?php echo attr($c[2]); ?>" target="_self">
        <?php echo text($c[0]); ?>
      </a>
    <?php endforeach; ?>
  </aside>

  <main class="cp-adm-content">

    <!-- Stats -->
    <section class="cp-adm-stats">
      <?php foreach ($stats as $s): ?>
        <div class="cp-adm-stat">
          <div class="cp-adm-stat-label"><?php echo text($s[0]); ?></div>
          <div class="cp-adm-stat-value"><?php echo text($s[1]); ?></div>
          <div class="cp-adm-stat-sub">
            <span class="cp-adm-stat-dot" style="background-color: <?php echo attr($s[3]); ?>;"></span>
            <span><?php echo text($s[2]); ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </section>

    <!-- Action panels -->
    <section class="cp-adm-panels">

      <div class="cp-adm-panel">
        <div class="cp-adm-panel-head">
          <div class="cp-adm-panel-title"><?php echo xlt('Quick Actions'); ?></div>
        </div>
        <?php foreach ($quickActions as $a): ?>
          <a class="cp-adm-action" href="<?php echo attr($a[3]); ?>" target="_self">
            <span class="cp-adm-action-icon"><?php echo $a[0]; ?></span>
            <div class="cp-adm-action-text">
              <div class="cp-adm-action-title"><?php echo text($a[1]); ?></div>
              <div class="cp-adm-action-desc"><?php echo text($a[2]); ?></div>
            </div>
            <span class="cp-adm-action-arrow">→</span>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="cp-adm-panel">
        <div class="cp-adm-panel-head">
          <div class="cp-adm-panel-title"><?php echo xlt('System'); ?></div>
        </div>
        <?php foreach ($systemActions as $a): ?>
          <a class="cp-adm-action" href="<?php echo attr($a[3]); ?>" target="_self">
            <span class="cp-adm-action-icon"><?php echo $a[0]; ?></span>
            <div class="cp-adm-action-text">
              <div class="cp-adm-action-title"><?php echo text($a[1]); ?></div>
              <div class="cp-adm-action-desc"><?php echo text($a[2]); ?></div>
            </div>
            <span class="cp-adm-action-arrow">→</span>
          </a>
        <?php endforeach; ?>
      </div>

    </section>

    <!-- Recent Admin Activity -->
    <section class="cp-adm-activity">
      <div class="cp-adm-activity-head">
        <div class="cp-adm-activity-title"><?php echo xlt('Recent Admin Activity'); ?></div>
        <div class="cp-adm-activity-spacer"></div>
        <a class="cp-adm-activity-link" href="/interface/super/copilot_audit.php" target="_self"><?php echo xlt('View all →'); ?></a>
      </div>
      <?php foreach ($activity as $row): ?>
        <div class="cp-adm-activity-row">
          <div class="cp-adm-activity-time"><?php echo text($row[0]); ?></div>
          <div class="cp-adm-activity-tag"><?php echo text($row[1]); ?></div>
          <div class="cp-adm-activity-desc"><?php echo text($row[2]); ?></div>
        </div>
      <?php endforeach; ?>
    </section>

  </main>

</div>

</body>
</html>
