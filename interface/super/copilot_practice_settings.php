<?php

/**
 * Practice Settings — implements Screen 26 of the AgentForge mockups.
 *
 * Admin sub-page archetype: ADMIN sidebar (Overview, Practice Settings
 * active, Users & Groups, ACL, Facilities, Forms & Layouts, Templates,
 * Coding & Lists, Modules, System, Logs & Audit) + page header bar with
 * "Reset to defaults" / "Save changes" CTAs + content sections (General,
 * Patient Encounters, Security) of form fields and toggle switches.
 *
 * This page is the canonical "admin sub-page" archetype — copy this as
 * a starting point when creating any other admin landing page.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$categories = [
    ['Overview',          false, '/interface/super/copilot_admin.php'],
    ['Practice Settings', true,  '/interface/super/copilot_practice_settings.php'],
    ['Users & Groups',    false, '/interface/super/copilot_users.php'],
    ['ACL',               false, '/interface/super/copilot_acl.php'],
    ['Facilities',        false, '/interface/super/copilot_facilities.php'],
    ['Forms & Layouts',   false, '/interface/super/copilot_forms_layouts.php'],
    ['Templates',         false, '/interface/super/copilot_templates.php'],
    ['Coding & Lists',    false, '/interface/super/copilot_coding_lists.php'],
    ['Modules',           false, '/interface/super/copilot_modules_admin.php'],
    ['System',            false, '/interface/super/copilot_system.php'],
    ['Logs & Audit',      false, '/interface/super/copilot_logs.php'],
];

// Section definitions: each section = title + sub + array of fields.
// Field types: 'text', 'select', 'toggle'.
$sections = [
    [
        'title' => 'General',
        'sub'   => 'Practice identity, time zone, and locale',
        'fields' => [
            ['Practice name', 'text',   'Riverside Family Medicine'],
            ['Time zone',     'select', 'America/Chicago (CDT)'],
            ['Locale',        'select', 'English (US)'],
            ['Date format',   'select', 'MM/DD/YYYY'],
        ],
    ],
    [
        'title' => 'Patient Encounters',
        'sub'   => '',
        'fields' => [
            ['Default encounter type',  'select', 'Office Visit'],
            ['Auto-lock after sign',    'toggle', 'on'],
            ['Require diagnosis on sign', 'toggle', 'on'],
            ['Show Co-Pilot ✦ inline',  'toggle', 'on'],
        ],
    ],
    [
        'title' => 'Security',
        'sub'   => '',
        'fields' => [
            ['Session timeout',    'select', '15 minutes'],
            ['Require 2FA',        'toggle', 'on'],
            ['Password rotation',  'select', 'Every 90 days'],
            ['Audit log retention','select', '7 years'],
        ],
    ],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Practice Settings'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F6F7;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    display: flex; flex-direction: column;
    min-height: 100vh;
  }
  button { font-family: inherit; cursor: pointer; }
  input, select { font-family: inherit; }

  /* Page header */
  .cp-adm-pagehead {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 14px 24px;
    display: flex; align-items: center; gap: 12px;
    flex: 0 0 auto;
  }
  .cp-adm-pagehead .title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-adm-pagehead .sub {
    font-size: 12px; color: #8A91A1;
    line-height: 1; margin-top: 4px;
  }
  .cp-adm-pagehead-info { flex: 1; display: flex; flex-direction: column; gap: 0; }
  .cp-adm-btn {
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    border: 1px solid transparent;
  }
  .cp-adm-btn.ghost { background: #FFFFFF; color: #4F5763; border-color: #E4E5E8; font-weight: 500; }
  .cp-adm-btn.ghost:hover { background: #F5F6F7; }
  .cp-adm-btn.primary { background: #008C8C; color: #FFFFFF; }
  .cp-adm-btn.primary:hover { background: #00787A; }

  /* Two-column shell */
  .cp-adm-shell {
    flex: 1 1 auto;
    display: flex;
    min-height: 0;
  }
  .cp-adm-sidebar {
    flex: 0 0 240px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 16px 0;
  }
  .cp-adm-side-lbl {
    font-size: 10px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.6px;
    padding: 0 24px 8px;
  }
  .cp-adm-cat {
    position: relative;
    display: block;
    width: 100%;
    height: 36px;
    padding: 0 24px;
    line-height: 36px;
    font-size: 13px; font-weight: 500;
    color: #4F5763;
    text-decoration: none;
    background: transparent; border: 0;
    text-align: left;
  }
  .cp-adm-cat:hover { background: #F5F7F8; color: #0D1B2A; }
  .cp-adm-cat.active {
    background: rgba(0, 140, 140, 0.08);
    color: #008C8C;
    font-weight: 600;
  }
  .cp-adm-cat.active::before {
    content: ''; position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px; background: #008C8C;
  }

  /* Content */
  .cp-adm-content {
    flex: 1 1 auto;
    padding: 24px 32px 40px;
    overflow-y: auto;
    display: flex; flex-direction: column; gap: 16px;
  }

  /* Section card */
  .cp-section {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px 22px 20px;
  }
  .cp-section h2 {
    font-size: 14px; font-weight: 700;
    color: #0D1B2A;
    margin: 0 0 4px;
    line-height: 1.2;
  }
  .cp-section .desc {
    font-size: 12px; color: #8A91A1;
    margin-bottom: 16px;
    line-height: 1.4;
  }
  .cp-section .desc:empty { margin-bottom: 8px; }

  .cp-row {
    display: grid;
    grid-template-columns: 200px 1fr;
    align-items: center;
    gap: 14px;
    padding: 10px 0;
    border-top: 1px solid #F0F1F3;
  }
  .cp-row:first-of-type { border-top: none; padding-top: 4px; }
  .cp-row label {
    font-size: 12px; color: #4F5763; font-weight: 500;
    line-height: 1.3;
  }
  .cp-row .ctrl { display: flex; align-items: center; gap: 8px; }
  .cp-input {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 36px;
    padding: 0 12px;
    font-size: 13px; color: #0D1B2A;
    outline: none;
    width: 100%;
  }
  .cp-input:focus { border-color: #008C8C; }
  .cp-select {
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

  /* Toggle */
  .cp-toggle {
    width: 38px; height: 22px;
    background: #008C8C;
    border-radius: 999px;
    position: relative;
    cursor: pointer;
    flex: 0 0 auto;
  }
  .cp-toggle.off { background: #C7CBD2; }
  .cp-toggle::after {
    content: '';
    position: absolute;
    top: 2px; left: 18px;
    width: 18px; height: 18px;
    background: #FFFFFF;
    border-radius: 50%;
    transition: left 0.15s;
  }
  .cp-toggle.off::after { left: 2px; }
</style>
</head>
<body>

<header class="cp-adm-pagehead">
  <div class="cp-adm-pagehead-info">
    <span class="title"><?php echo xlt('Practice Settings'); ?></span>
    <span class="sub"><?php echo xlt('Site-wide configuration'); ?> • <?php echo xlt('Saved 04/12/2026 09:14 AM by Admin'); ?></span>
  </div>
  <button type="button" class="cp-adm-btn ghost"><?php echo xlt('Reset to defaults'); ?></button>
  <button type="button" class="cp-adm-btn primary"><?php echo xlt('Save changes'); ?></button>
</header>

<div class="cp-adm-shell">

  <aside class="cp-adm-sidebar">
    <div class="cp-adm-side-lbl"><?php echo xlt('ADMIN'); ?></div>
    <?php foreach ($categories as [$lbl, $active, $href]): ?>
      <a class="cp-adm-cat <?php echo $active ? 'active' : ''; ?>" href="<?php echo attr($href); ?>">
        <?php echo text($lbl); ?>
      </a>
    <?php endforeach; ?>
  </aside>

  <main class="cp-adm-content">
    <?php foreach ($sections as $sec): ?>
      <section class="cp-section">
        <h2><?php echo text($sec['title']); ?></h2>
        <?php if ($sec['sub']): ?><div class="desc"><?php echo text($sec['sub']); ?></div><?php endif; ?>
        <?php foreach ($sec['fields'] as [$name, $type, $val]): ?>
          <div class="cp-row">
            <label><?php echo text($name); ?></label>
            <div class="ctrl">
              <?php if ($type === 'toggle'): ?>
                <span class="cp-toggle <?php echo $val === 'on' ? '' : 'off'; ?>"></span>
              <?php elseif ($type === 'select'): ?>
                <select class="cp-input cp-select"><option><?php echo text($val); ?></option></select>
              <?php else: ?>
                <input class="cp-input" type="text" value="<?php echo attr($val); ?>">
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </main>

</div>

</body>
</html>
