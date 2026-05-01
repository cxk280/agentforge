<?php

/**
 * User profile / avatar menu — Screen 62.
 * The dropdown that opens from the top-right avatar in the shell nav.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Profile menu'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-stage {
    min-height: 100vh;
    background: linear-gradient(180deg, #F5F6F7 0%, #E4E5E8 100%);
    display: flex; align-items: flex-start; justify-content: flex-end;
    padding: 70px 24px 24px;
    position: relative;
  }
  /* fake top-nav strip behind the menu, to show context */
  .cp-stage::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 52px;
    background: #0D1B2A;
  }
  .cp-stage .avatar-button {
    position: absolute; top: 12px; right: 24px;
    width: 28px; height: 28px; border-radius: 999px;
    background: #1F4068; color: #FFFFFF; font-size: 11px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    border: 2px solid #008C8C;
  }
  .cp-menu {
    width: 280px;
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 12px;
    box-shadow: 0 12px 32px rgba(13,27,42,0.18);
    overflow: hidden;
  }
  .cp-menu-head {
    padding: 16px 16px 14px;
    background: linear-gradient(180deg, #F0FAFA 0%, #FFFFFF 100%);
    border-bottom: 1px solid #E4E5E8;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-menu-head .av { width: 40px; height: 40px; border-radius: 999px;
    background: #008C8C; color: #FFFFFF; font-weight: 700; font-size: 14px;
    display: inline-flex; align-items: center; justify-content: center; }
  .cp-menu-head .info .n { font-weight: 700; font-size: 13px; color: #0D1B2A; }
  .cp-menu-head .info .e { font-size: 11px; color: #8A91A1; margin-top: 2px; }
  .cp-menu-head .info .r { font-size: 10px; color: #008C8C; font-weight: 600; margin-top: 4px; }
  .cp-menu-section { padding: 6px 0; border-top: 1px solid #F0F1F3; }
  .cp-menu-section:first-of-type { border-top: 0; }
  .cp-menu-item { display: flex; align-items: center; gap: 12px;
    padding: 9px 16px; cursor: pointer;
    font-size: 13px; color: #0D1B2A;
    text-decoration: none;
  }
  .cp-menu-item:hover { background: #F5F6F7; }
  .cp-menu-item .ic { font-size: 14px; color: #4F5763; width: 18px; text-align: center; }
  .cp-menu-item .lbl { flex: 1; }
  .cp-menu-item .right { font-size: 10px; color: #8A91A1; }
  .cp-menu-item.danger { color: #D93838; }
  .cp-menu-item.danger .ic { color: #D93838; }

  .cp-menu-footer {
    background: #F5F6F7;
    padding: 10px 16px;
    font-size: 10px; color: #8A91A1;
    text-align: center;
  }
</style>
</head>
<body class="cp-arch">

<div class="cp-stage">
  <div class="avatar-button">CK</div>

  <div class="cp-menu">
    <div class="cp-menu-head">
      <div class="av">CK</div>
      <div class="info">
        <div class="n">Christopher King</div>
        <div class="e">admin@example.com</div>
        <div class="r">SITE ADMIN · Riverside Family Medicine</div>
      </div>
    </div>

    <div class="cp-menu-section">
      <a class="cp-menu-item"><span class="ic">👤</span><span class="lbl"><?php echo xlt('Your profile'); ?></span></a>
      <a class="cp-menu-item"><span class="ic">⚙</span><span class="lbl"><?php echo xlt('Preferences'); ?></span></a>
      <a class="cp-menu-item"><span class="ic">🔑</span><span class="lbl"><?php echo xlt('Change password'); ?></span></a>
      <a class="cp-menu-item"><span class="ic">📱</span><span class="lbl"><?php echo xlt('2FA & devices'); ?></span><span class="right">Enabled</span></a>
    </div>

    <div class="cp-menu-section">
      <a class="cp-menu-item"><span class="ic">🏥</span><span class="lbl"><?php echo xlt('Switch facility'); ?></span></a>
      <a class="cp-menu-item"><span class="ic">🛡</span><span class="lbl"><?php echo xlt('EPCS token'); ?></span><span class="right">Active</span></a>
      <a class="cp-menu-item"><span class="ic">⌨</span><span class="lbl"><?php echo xlt('Keyboard shortcuts'); ?></span><span class="right">⌘ /</span></a>
    </div>

    <div class="cp-menu-section">
      <a class="cp-menu-item"><span class="ic">?</span><span class="lbl"><?php echo xlt('Help & docs'); ?></span></a>
      <a class="cp-menu-item"><span class="ic">📊</span><span class="lbl"><?php echo xlt('System status'); ?></span><span class="right" style="color:#1F8C4D;">● UP</span></a>
      <a class="cp-menu-item danger"><span class="ic">⏻</span><span class="lbl"><?php echo xlt('Sign out'); ?></span></a>
    </div>

    <div class="cp-menu-footer">
      AgentForge v0.4.2 · Build 2026.05.01 · <?php echo xlt('All services up'); ?>
    </div>
  </div>
</div>

</body>
</html>
