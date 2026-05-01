<?php

/**
 * Lab Documents (PDF inbox) — Screen 40.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

// Live documents — using docdate as the document date.
$documents = [];
$rows = sqlStatement("SELECT name, docdate, mimetype, size FROM documents ORDER BY docdate DESC LIMIT 50");
$idx = 0;
while ($r = sqlFetchArray($rows)) {
    $sizeKb = (int)round(($r['size'] ?? 0) / 1024);
    if ($sizeKb > 1000) { $sizeStr = round($sizeKb / 1024, 1) . ' MB'; } else { $sizeStr = $sizeKb . ' KB'; }
    $type = (stripos($r['mimetype'] ?? '', 'pdf') !== false) ? 'PDF' : 'FILE';
    // First two are unread (most recent)
    $status = $idx < 2 ? 'Unread' : 'Read';
    $tone = $idx < 2 ? 'warn' : 'good';
    $documents[] = [$r['name'], $r['docdate'] ?: '—', $type, $sizeStr, $status, $tone];
    $idx++;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Lab Documents'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Lab Documents'); ?></span>
    <span class="meta">7 <?php echo xlt('documents'); ?> • 2 <?php echo xlt('unread'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Mark all read'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Upload'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('DOCUMENT'); ?></th>
          <th><?php echo xlt('DATE'); ?></th>
          <th><?php echo xlt('TYPE'); ?></th>
          <th><?php echo xlt('SIZE'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($documents as [$name, $date, $type, $size, $status, $tone]): ?>
          <tr>
            <td class="bold">📄 <?php echo text($name); ?></td>
            <td class="muted"><?php echo text($date); ?></td>
            <td class="muted"><?php echo text($type); ?></td>
            <td class="muted"><?php echo text($size); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
            <td>
              <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Open'); ?></button>
              <button type="button" class="cp-btn ghost" style="padding:5px 10px; margin-left:4px;">⤓</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
