<?php

/**
 * Office Notes — Screen 49.
 * Internal staff notes (not part of the patient chart).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../copilot_helpers.php");

// Handle POST: add a new office note
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty(trim($_POST['body'] ?? ''))) {
    $body = trim($_POST['body']);
    $author = $_SESSION['authUser'] ?? 'admin';
    sqlInsert(
        "INSERT INTO onotes (date, body, user, groupname, activity) VALUES (NOW(), ?, ?, 'Default', 1)",
        [$body, $author]
    );
    header('Location: copilot_office_notes.php');
    exit;
}

// Fetch live notes; map user -> friendly name + initials/tone
$tonePool = ['teal','orange','purple','blue','mint','pink','green','violet'];
$notes = [];
$rows = sqlStatement("SELECT date, body, user FROM onotes WHERE activity = 1 ORDER BY date DESC LIMIT 30");
$nowTs = time();
$idx = 0;
while ($r = sqlFetchArray($rows)) {
    $u = sqlQuery("SELECT username, fname, lname, title FROM users WHERE username = ?", [$r['user']]);
    if ($u) {
        $name = cp_format_provider_name($u);
        $initials = cp_initials($u);
    } else {
        $name = $r['user']; $initials = strtoupper(substr($r['user'], 0, 2));
    }
    $diff = $nowTs - strtotime($r['date']);
    if ($diff < 60) { $when = 'just now'; }
    elseif ($diff < 3600) { $when = floor($diff / 60) . ' min ago'; }
    elseif ($diff < 86400) { $when = date('g:i A', strtotime($r['date'])); }
    elseif ($diff < 86400 * 2) { $when = 'Yesterday'; }
    elseif ($diff < 86400 * 7) { $when = floor($diff / 86400) . ' days ago'; }
    else { $when = date('M j', strtotime($r['date'])); }
    $tone = $tonePool[$idx % count($tonePool)];
    $notes[] = [$initials, $tone, $name, $when, $r['body']];
    $idx++;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Office Notes'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-on-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 18px;
    display: flex; gap: 12px;
    margin-bottom: 8px;
  }
  .cp-on-card .info { flex: 1; min-width: 0; }
  .cp-on-card .head { display: flex; gap: 8px; align-items: baseline; }
  .cp-on-card .name { font-weight: 600; color: #0D1B2A; font-size: 13px; }
  .cp-on-card .when { font-size: 11px; color: #8A91A1; }
  .cp-on-card .body { font-size: 13px; color: #0D1B2A; margin-top: 6px; line-height: 1.5; }
  .cp-on-compose {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 12px;
    padding: 14px 18px; margin-bottom: 12px;
  }
  .cp-on-compose textarea {
    width: 100%; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 10px 12px; font-size: 13px; min-height: 64px; outline: none; resize: vertical;
  }
  .cp-on-compose-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Office Notes'); ?></span>
    <span class="meta"><?php echo xlt('Internal staff notes — not part of patient chart'); ?></span>
  </div>
</header>

<main class="cp-content tight">

  <form class="cp-on-compose" method="post" action="copilot_office_notes.php">
    <textarea name="body" placeholder="<?php echo xla('Write a note for the office...'); ?>"></textarea>
    <div class="cp-on-compose-row">
      <span class="cp-status-pill neutral"><?php echo xlt('Visible to: Front Desk, Billing, Admin'); ?></span>
      <span style="flex:1;"></span>
      <button type="reset" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Cancel'); ?></button>
      <button type="submit" class="cp-btn primary"><?php echo xlt('Post note'); ?></button>
    </div>
  </form>

  <?php foreach ($notes as [$ini, $tone, $name, $when, $body]): ?>
    <div class="cp-on-card">
      <span class="cp-avatar <?php echo attr($tone); ?>"><?php echo text($ini); ?></span>
      <div class="info">
        <div class="head">
          <span class="name"><?php echo text($name); ?></span>
          <span class="when"><?php echo text($when); ?></span>
        </div>
        <div class="body"><?php echo text($body); ?></div>
      </div>
    </div>
  <?php endforeach; ?>

</main>

</body>
</html>
