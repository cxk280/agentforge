<?php

/**
 * User profile / avatar menu — Screen 62.
 *
 * The dropdown that opens from the top-right avatar in the shell nav.
 * Standalone preview renders the dropdown over a dimmed faux page so the
 * menu is visible exactly where it would appear in the live shell.
 *
 * Backend wiring (Screen 62):
 *   - Identity (name/email/NPI/DEA/title/initials) is pulled from the
 *     `users` row keyed off $_SESSION['authUserID'].
 *   - Status pill + four QUICK SETTINGS toggles are persisted in
 *     `user_settings` under cp_* keys via UserSettingsService.
 *   - POST handler at the top of the file (action=update_setting and
 *     action=update_status) does INSERT-or-UPDATE then 303-redirects so
 *     reloads don't re-submit (POST/redirect/GET).
 *   - Menu items link to real OpenEMR endpoints (user_info,
 *     mfa_registrations, calendar setup, logout, etc.).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_helpers.php");

use OpenEMR\Services\Globals\UserSettingsService;

// ---------------------------------------------------------------------------
// Identity — pull the logged-in user from the `users` table.
// ---------------------------------------------------------------------------
$authUserId = (int)($_SESSION['authUserID'] ?? 0);
$user = null;
if ($authUserId > 0) {
    // `federaldrugid` is the OpenEMR canonical column for the prescriber's
    // DEA registration. (Some forks renamed it `drug_license_number`; this
    // schema uses `federaldrugid` per `SHOW COLUMNS FROM users`.)
    $user = sqlQuery(
        "SELECT id, username, fname, mname, lname, title, npi,
                federaldrugid, email, specialty, physician_type
         FROM users
         WHERE id = ?",
        [$authUserId]
    );
}

// ---------------------------------------------------------------------------
// POST handler — update_setting and update_status.
// CSRF skipped — internal mock page, scoped to the logged-in user only.
// ---------------------------------------------------------------------------
$cpStatusKey        = 'cp_status';
$cpToggleKeys       = ['cp_notifications', 'cp_suggestions', 'cp_quiet_hours', 'cp_theme'];
$cpToggleBoolKeys   = ['cp_notifications', 'cp_suggestions', 'cp_quiet_hours'];
$cpThemeAllowed     = ['light', 'dark'];
$cpStatusAllowed    = ['available', 'busy', 'out'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $authUserId > 0) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_setting') {
        $key = (string)($_POST['key'] ?? '');
        $val = (string)($_POST['value'] ?? '');
        if (in_array($key, $cpToggleBoolKeys, true)) {
            $val = ($val === '1') ? '1' : '0';
            UserSettingsService::setUserSetting($key, $val, $authUserId, false, true);
        } elseif ($key === 'cp_theme') {
            $val = in_array($val, $cpThemeAllowed, true) ? $val : 'dark';
            UserSettingsService::setUserSetting($key, $val, $authUserId, false, true);
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=saved', true, 303);
        exit;
    }

    if ($action === 'update_status') {
        $val = strtolower((string)($_POST['value'] ?? ''));
        if (in_array($val, $cpStatusAllowed, true)) {
            UserSettingsService::setUserSetting($cpStatusKey, $val, $authUserId, false, true);
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=status', true, 303);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Read current settings (with sane defaults).
// ---------------------------------------------------------------------------
function cp_setting_bool(string $key, bool $default, int $userId): bool
{
    $raw = UserSettingsService::getUserSetting($key, $userId, $userId);
    if ($raw === null) {
        return $default;
    }
    return ($raw === '1' || $raw === 1 || $raw === true);
}

function cp_setting_str(string $key, string $default, int $userId): string
{
    $raw = UserSettingsService::getUserSetting($key, $userId, $userId);
    if ($raw === null || $raw === '') {
        return $default;
    }
    return (string)$raw;
}

$status         = $authUserId > 0 ? cp_setting_str($cpStatusKey, 'available', $authUserId) : 'available';
$tNotifications = $authUserId > 0 ? cp_setting_bool('cp_notifications', true,  $authUserId) : true;
$tSuggestions   = $authUserId > 0 ? cp_setting_bool('cp_suggestions',   true,  $authUserId) : true;
$tQuietHours    = $authUserId > 0 ? cp_setting_bool('cp_quiet_hours',   false, $authUserId) : false;
$tLightTheme    = $authUserId > 0 ? (cp_setting_str('cp_theme', 'dark', $authUserId) === 'light') : false;

// ---------------------------------------------------------------------------
// Compute display fields.
// ---------------------------------------------------------------------------
$displayName = $user ? cp_format_provider_name($user) : 'Unknown user';
$initials    = $user ? cp_initials($user) : '??';
$emailAddr   = trim((string)($user['email'] ?? ''));
$npi         = trim((string)($user['npi'] ?? ''));
$dea         = trim((string)($user['federaldrugid'] ?? ''));
$titleStr    = trim((string)($user['title'] ?? ''));

// Status pill copy + color — keys map to a (label, hex) pair.
$statusMap = [
    'available' => ['label' => xl('Available') . ' — ' . xl('accepting'), 'color' => '#33A666'],
    'busy'      => ['label' => xl('Busy') . ' — ' . xl('do not disturb'), 'color' => '#D98F33'],
    'out'       => ['label' => xl('Out of office'),                       'color' => '#8A91A1'],
];
if (!isset($statusMap[$status])) {
    $status = 'available';
}
$statusLabel = $statusMap[$status]['label'];
$statusColor = $statusMap[$status]['color'];

$flash = (string)($_GET['msg'] ?? '');

// Quick-toggle helper — emits a small inline form so the toggle is a real
// POST that flips the value.
function cp_toggle_form(string $key, bool $isOn, string $newValue, string $offValue): string
{
    $self = (string)$_SERVER['PHP_SELF'];
    $next = $isOn ? $offValue : $newValue;
    $cls  = $isOn ? 'cp-tog' : 'cp-tog off';
    return '<form method="post" action="' . attr($self) . '" style="margin:0;">'
        . '<input type="hidden" name="action" value="update_setting">'
        . '<input type="hidden" name="key" value="' . attr($key) . '">'
        . '<input type="hidden" name="value" value="' . attr($next) . '">'
        . '<button type="submit" class="' . attr($cls) . '" aria-pressed="' . ($isOn ? 'true' : 'false') . '"'
        . ' aria-label="' . attr($isOn ? xl('On') : xl('Off')) . '"></button>'
        . '</form>';
}

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
  body.cp-arch { background: #F5F6F7; }

  /* Faux page (skeleton cards) sits underneath the scrim + dropdown */
  .cp-stage {
    position: relative;
    min-height: 100vh;
    background: #F5F6F7;
    overflow: hidden;
  }

  /* Demographics banner stripe (the parent shell renders the dark
     navy nav above this; in standalone preview we just show the
     banner so the dropdown lands on a visible page edge). */
  .cp-banner {
    height: 56px;
    background: #152A42;
    display: flex; align-items: center;
    padding: 0 24px;
    gap: 16px;
  }
  .cp-banner .av {
    width: 36px; height: 36px; border-radius: 999px;
    background: #C7CBD2;
  }
  .cp-banner .nm {
    font-size: 16px; font-weight: 600; color: #FFFFFF;
  }

  /* Skeleton row of small cards */
  .cp-fake { padding: 40px 24px 24px; display: flex; flex-direction: column; gap: 16px; }
  .cp-fake .row4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; }
  .cp-fake .card-sm {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 108px;
    padding: 15px 17px;
    display: flex; flex-direction: column; gap: 11px;
  }
  .cp-fake .card-sm .b1 { width: 80px; height: 11px; background: #F5F6F7; border-radius: 3px; }
  .cp-fake .card-sm .b2 { width: 128px; height: 28px; background: #F5F6F7; border-radius: 4px; }
  .cp-fake .card-sm .b3 { width: 90px; height: 11px; background: #F5F6F7; border-radius: 3px; }

  .cp-fake .card-lg {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 240px;
    padding: 17px;
    display: flex; flex-direction: column; gap: 8px;
  }
  .cp-fake .card-lg .hdr { width: 180px; height: 11px; background: #F5F6F7; border-radius: 3px; margin-bottom: 12px; }
  .cp-fake .card-lg .ln  { height: 28px; background: #F7F7FA; border-radius: 4px; }

  /* Dim scrim covering the page behind the menu */
  .cp-scrim {
    position: absolute; inset: 0;
    background: rgba(10,15,26,0.20);
    pointer-events: none;
  }

  /* Dropdown */
  .cp-menu {
    position: absolute;
    top: 16px;
    right: 26px;
    width: 304px;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.20);
    overflow: hidden;
    font-family: 'Inter', sans-serif;
  }

  /* Header (avatar + identity) */
  .cp-mh { padding: 16px 16px 12px; position: relative; height: 104px; }
  .cp-mh .av {
    position: absolute; left: 16px; top: 16px;
    width: 56px; height: 56px; border-radius: 999px;
    background: #008C8C; color: #FFFFFF;
    font-weight: 700; font-size: 18px;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .cp-mh .nm { position: absolute; left: 88px; top: 18px;
    font-size: 15px; font-weight: 600; color: #181D26; }
  .cp-mh .em { position: absolute; left: 88px; top: 38px;
    font-size: 11px; color: #4F5763; }
  .cp-mh .stat { position: absolute; left: 88px; top: 56px;
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 500; }
  .cp-mh .stat .dotmini { width: 8px; height: 8px;
    border-radius: 50%; display: inline-block; }
  .cp-mh .npi { position: absolute; left: 88px; top: 76px;
    font-size: 10px; color: #8A91A1; }

  .cp-divider { height: 1px; background: #E4E5E8; margin: 0 16px; }

  /* Section labels */
  .cp-mlbl {
    font-size: 9px; font-weight: 600; color: #8A91A1;
    letter-spacing: 0.5px;
    padding: 16px 16px 6px;
  }

  /* Status select (real <form>) */
  .cp-mstat {
    margin: 0 16px;
    background: #F5F6F7;
    border-radius: 6px;
    height: 32px;
    display: flex; align-items: center;
    padding: 0 12px;
    gap: 8px;
    font-size: 12px; font-weight: 500; color: #181D26;
    position: relative;
  }
  .cp-mstat .dot { width: 8px; height: 8px; border-radius: 50%; }
  .cp-mstat .label { flex: 1; }
  .cp-mstat .caret { font-size: 11px; color: #8A91A1; }
  .cp-mstat select {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    opacity: 0; cursor: pointer;
    border: 0; padding: 0; margin: 0;
    font: inherit;
  }

  /* Quick settings rows */
  .cp-qrow {
    display: flex; align-items: center;
    padding: 8px 16px;
    font-size: 12px; font-weight: 500; color: #181D26;
  }
  .cp-qrow .lbl { flex: 1; display: inline-flex; align-items: center; gap: 8px; }
  .cp-qrow .ic { font-size: 13px; line-height: 1; }
  .cp-tog {
    width: 32px; height: 18px;
    background: #008C8C;
    border-radius: 999px;
    position: relative;
    flex: 0 0 auto;
    border: 0; padding: 0; cursor: pointer;
  }
  .cp-tog::after {
    content: ''; position: absolute;
    top: 2px; left: 16px;
    width: 14px; height: 14px;
    background: #FFFFFF; border-radius: 50%;
  }
  .cp-tog.off { background: #C7CBD2; }
  .cp-tog.off::after { left: 2px; }

  /* Item rows (My profile, etc.) */
  .cp-item {
    display: flex; align-items: center;
    padding: 8px 12px;
    margin: 0 4px;
    border-radius: 6px;
    font-size: 12px;
    color: #181D26;
    text-decoration: none;
    cursor: pointer;
    height: 32px;
  }
  .cp-item:hover { background: #F5F6F7; }
  .cp-item .ic { width: 22px; font-size: 14px; flex: 0 0 auto; line-height: 1; }
  .cp-item .ttl { font-weight: 500; flex: 1; }
  .cp-item .meta { font-size: 11px; color: #8A91A1; font-weight: 400; margin-right: 8px; }
  .cp-item .chev { color: #8A91A1; font-size: 12px; font-weight: 500; }

  /* Sign out (red) */
  .cp-item.danger { padding: 7px 12px; height: 28px;
    background: transparent; border: 0; width: calc(100% - 8px); text-align: left; }
  .cp-item.danger .ic { color: #D93838; font-size: 13px; font-weight: 600; }
  .cp-item.danger .ttl { color: #D93838; font-weight: 600; }
  .cp-item.danger .kbd { font-size: 11px; color: #8A91A1; font-weight: 400; }

  .cp-mfoot { padding: 4px 0; }

  /* Tiny flash banner */
  .cp-flash {
    position: absolute; top: 8px; left: 50%;
    transform: translateX(-50%);
    background: #181D26; color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: 11px; font-weight: 500;
    padding: 4px 10px; border-radius: 999px;
    opacity: 0.92;
    z-index: 10;
  }
</style>
</head>
<body class="cp-arch">

<div class="cp-stage">

  <?php if ($flash !== '') { ?>
    <div class="cp-flash">
      <?php
        // map known flash codes to copy
        $flashMap = [
            'saved'  => xl('Setting saved'),
            'status' => xl('Status updated'),
        ];
        echo text($flashMap[$flash] ?? '');
      ?>
    </div>
  <?php } ?>

  <!-- demographics banner stripe -->
  <div class="cp-banner">
    <div class="av"></div>
    <div class="nm">Margaret Chen</div>
  </div>

  <!-- faux page skeleton -->
  <div class="cp-fake">
    <div class="row4">
      <div class="card-sm"><div class="b1"></div><div class="b2"></div><div class="b3"></div></div>
      <div class="card-sm"><div class="b1"></div><div class="b2"></div><div class="b3"></div></div>
      <div class="card-sm"><div class="b1"></div><div class="b2"></div><div class="b3"></div></div>
      <div class="card-sm"><div class="b1"></div><div class="b2"></div><div class="b3"></div></div>
    </div>
    <div class="card-lg">
      <div class="hdr"></div>
      <div class="ln"></div>
      <div class="ln"></div>
      <div class="ln"></div>
      <div class="ln"></div>
      <div class="ln"></div>
    </div>
    <div class="card-lg">
      <div class="hdr"></div>
      <div class="ln"></div>
      <div class="ln"></div>
      <div class="ln"></div>
      <div class="ln"></div>
      <div class="ln"></div>
    </div>
  </div>

  <!-- dim scrim -->
  <div class="cp-scrim"></div>

  <!-- the dropdown -->
  <div class="cp-menu" role="menu" aria-label="<?php echo xla('User menu'); ?>">

    <div class="cp-mh">
      <div class="av"><?php echo text($initials); ?></div>
      <div class="nm"><?php echo text($displayName); ?></div>
      <div class="em"><?php echo text($emailAddr !== '' ? $emailAddr : '—'); ?></div>
      <div class="stat" style="color: <?php echo attr($statusColor); ?>;">
        <span class="dotmini" style="background: <?php echo attr($statusColor); ?>;"></span>
        <?php echo text($statusLabel); ?>
      </div>
      <div class="npi">
        <?php
          $idLine = [];
          if ($npi !== '') { $idLine[] = 'NPI ' . $npi; }
          if ($dea !== '') { $idLine[] = xl('DEA registered'); }
          echo text($idLine === [] ? xl('No NPI on file') : implode(' · ', $idLine));
        ?>
      </div>
    </div>

    <div class="cp-divider"></div>

    <div class="cp-mlbl"><?php echo xlt('STATUS'); ?></div>
    <div class="cp-mstat">
      <span class="dot" style="background: <?php echo attr($statusColor); ?>;"></span>
      <span class="label"><?php echo text($statusLabel); ?></span>
      <span class="caret">&#9662;</span>
      <form method="post" action="<?php echo attr($_SERVER['PHP_SELF']); ?>" id="cp-status-form" style="margin:0;">
        <input type="hidden" name="action" value="update_status">
        <select name="value" onchange="document.getElementById('cp-status-form').submit();">
          <?php foreach ($statusMap as $key => $meta) { ?>
            <option value="<?php echo attr($key); ?>" <?php echo $key === $status ? 'selected' : ''; ?>>
              <?php echo text($meta['label']); ?>
            </option>
          <?php } ?>
        </select>
      </form>
    </div>

    <div class="cp-mlbl"><?php echo xlt('QUICK SETTINGS'); ?></div>

    <div class="cp-qrow">
      <span class="lbl"><span class="ic">&#128276;</span><?php echo xlt('Notifications'); ?></span>
      <?php echo cp_toggle_form('cp_notifications', $tNotifications, '1', '0'); ?>
    </div>
    <div class="cp-qrow">
      <span class="lbl"><span class="ic">&#128172;</span><?php echo xlt('Co-pilot suggestions'); ?></span>
      <?php echo cp_toggle_form('cp_suggestions', $tSuggestions, '1', '0'); ?>
    </div>
    <div class="cp-qrow">
      <span class="lbl"><span class="ic">&#128263;</span><?php echo xlt('Quiet hours (after 6 PM)'); ?></span>
      <?php echo cp_toggle_form('cp_quiet_hours', $tQuietHours, '1', '0'); ?>
    </div>
    <div class="cp-qrow">
      <span class="lbl"><span class="ic">&#9728;</span><?php echo xlt('Light theme'); ?></span>
      <?php echo cp_toggle_form('cp_theme', $tLightTheme, 'light', 'dark'); ?>
    </div>

    <div class="cp-divider" style="margin-top: 10px;"></div>

    <div class="cp-mfoot" style="padding-top: 6px;">
      <a class="cp-item" href="<?php echo attr($GLOBALS['webroot'] ?? ''); ?>/interface/usergroup/user_info.php">
        <span class="ic">&#128100;</span>
        <span class="ttl"><?php echo xlt('My profile'); ?></span>
        <span class="meta"><?php echo xlt('Personal info, signature'); ?></span>
        <span class="chev">&rsaquo;</span>
      </a>
      <a class="cp-item" href="<?php echo attr($GLOBALS['webroot'] ?? ''); ?>/interface/usergroup/mfa_registrations.php">
        <span class="ic">&#128274;</span>
        <span class="ttl"><?php echo xlt('Security & MFA'); ?></span>
        <span class="meta"><?php echo xlt('Yubikey · 2FA enabled'); ?></span>
        <span class="chev">&rsaquo;</span>
      </a>
      <a class="cp-item" href="<?php echo attr($GLOBALS['webroot'] ?? ''); ?>/interface/super/edit_globals.php?section=Calendar">
        <span class="ic">&#128197;</span>
        <span class="ttl"><?php echo xlt('My schedule preferences'); ?></span>
        <span class="chev">&rsaquo;</span>
      </a>
      <a class="cp-item" href="<?php echo attr($GLOBALS['webroot'] ?? ''); ?>/interface/super/manage_site_files.php">
        <span class="ic">&#128203;</span>
        <span class="ttl"><?php echo xlt('My templates'); ?></span>
        <span class="meta"><?php echo xlt('Template manager'); ?></span>
        <span class="chev">&rsaquo;</span>
      </a>
      <a class="cp-item" href="<?php echo attr($GLOBALS['webroot'] ?? ''); ?>/interface/main/copilot_settings.php">
        <span class="ic">&#128172;</span>
        <span class="ttl"><?php echo xlt('Co-Pilot preferences'); ?></span>
        <span class="chev">&rsaquo;</span>
      </a>
      <a class="cp-item" href="<?php echo attr($GLOBALS['webroot'] ?? ''); ?>/interface/main/copilot_keyboard_shortcuts.php">
        <span class="ic">&#10067;</span>
        <span class="ttl"><?php echo xlt('Keyboard shortcuts'); ?></span>
        <span class="meta">&#8984;K to open</span>
        <span class="chev">&rsaquo;</span>
      </a>
      <a class="cp-item" href="<?php echo attr($GLOBALS['webroot'] ?? ''); ?>/interface/main/help.php" target="_blank" rel="noopener">
        <span class="ic">&#10067;</span>
        <span class="ttl"><?php echo xlt('Help center'); ?></span>
        <span class="chev">&rsaquo;</span>
      </a>
    </div>

    <div class="cp-divider"></div>

    <form method="post" action="<?php echo attr(($GLOBALS['webroot'] ?? '') . '/interface/logout.php'); ?>" style="margin:0;">
      <button type="submit" class="cp-item danger" style="margin-top: 6px;">
        <span class="ic">&#9211;</span>
        <span class="ttl"><?php echo xlt('Sign out'); ?></span>
        <span class="kbd">&#8679;&#8984;Q</span>
      </button>
    </form>

  </div>

</div>

</body>
</html>
