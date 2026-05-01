<?php

/**
 * Facilities — Screen 54, AgentForge admin sub-page archetype.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/copilot_admin_sidebar.php");

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add' && !empty($_POST['name'])) {
    sqlInsert(
        "INSERT INTO facility (name, phone, street, city, state, postal_code, country_code, service_location, billing_location, accepts_assignment, color) VALUES (?, ?, ?, ?, ?, ?, 'US', 1, 1, 1, ?)",
        [
            $_POST['name'], $_POST['phone'] ?? '', $_POST['street'] ?? '',
            $_POST['city'] ?? 'Austin', $_POST['state'] ?? 'TX', $_POST['postal_code'] ?? '78701',
            $_POST['color'] ?? '#008C8C',
        ]
    );
    header('Location: copilot_facilities.php?msg=' . urlencode('Facility added: ' . $_POST['name']));
    exit;
}
$flash = $_GET['msg'] ?? null;

$facilities = [];
$rows = sqlStatement("SELECT name, street, city, state, postal_code, phone FROM facility WHERE service_location = 1 ORDER BY id ASC");
while ($r = sqlFetchArray($rows)) {
    $addr = trim($r['street']);
    if ($r['city']) { $addr .= ($addr ? ', ' : '') . $r['city'] . ', ' . $r['state'] . ' ' . $r['postal_code']; }
    if (!$addr) { $addr = '—'; }
    // Type inference from name
    $name = $r['name'];
    $type = 'Branch';
    if (stripos($name, 'family') !== false || stripos($name, 'main') !== false || stripos($name, 'riverside') !== false) {
        $type = 'Primary';
    } elseif (stripos($name, 'surgery') !== false) {
        $type = 'Specialty';
    } elseif (stripos($name, 'telehealth') !== false || stripos($name, 'virtual') !== false) {
        $type = 'Virtual';
    } elseif (stripos($name, 'clinic') !== false) {
        $type = 'Primary name here';
    }
    $facilities[] = [$name, $addr, $r['phone'] ?: 'N/A', $type, '—', 'Active'];
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Facilities'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Facilities'); ?></span>
    <span class="meta"><?php echo text(count($facilities)); ?> <?php echo xlt('facilities'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export'); ?></button>
  <button type="button" class="cp-btn primary" onclick="document.getElementById('cp-fac-form').style.display='block';">+ <?php echo xlt('Add facility'); ?></button>
</header>

<?php if ($flash): ?>
  <div style="background:#EBF8F0; border:1px solid #B6E0C5; padding:10px 24px; color:#1F8C4D; font-size:13px;"><?php echo text($flash); ?></div>
<?php endif; ?>

<div id="cp-fac-form" style="display:none; background:#FFFFFF; border-bottom:1px solid #E4E5E8; padding:14px 24px;">
  <form method="post" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
    <input type="hidden" name="action" value="add">
    <div><label style="font-size:11px;color:#4F5763;">Name</label><br><input class="cp-input" name="name" required style="width:220px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">Phone</label><br><input class="cp-input" name="phone" style="width:140px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">Street</label><br><input class="cp-input" name="street" style="width:240px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">City</label><br><input class="cp-input" name="city" value="Austin" style="width:120px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">State</label><br><input class="cp-input" name="state" value="TX" style="width:60px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">ZIP</label><br><input class="cp-input" name="postal_code" value="78701" style="width:80px;"></div>
    <button type="submit" class="cp-btn primary"><?php echo xlt('Save'); ?></button>
    <button type="button" class="cp-btn ghost" onclick="document.getElementById('cp-fac-form').style.display='none';">Cancel</button>
  </form>
</div>

<div class="cp-shell">
  <?php echo cp_admin_sidebar('facilities'); ?>

  <main class="cp-content tight">
    <div class="cp-tbl">
      <table>
        <thead>
          <tr>
            <th><?php echo xlt('NAME'); ?></th>
            <th><?php echo xlt('ADDRESS'); ?></th>
            <th><?php echo xlt('PHONE'); ?></th>
            <th><?php echo xlt('TYPE'); ?></th>
            <th><?php echo xlt('STAFF'); ?></th>
            <th><?php echo xlt('STATUS'); ?></th>
            <th><?php echo xlt('ACTIONS'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($facilities as [$name, $addr, $phone, $type, $staff, $status]): ?>
            <tr>
              <td class="bold"><?php echo text($name); ?></td>
              <td class="muted"><?php echo text($addr); ?></td>
              <td class="muted"><?php echo text($phone); ?></td>
              <td class="muted"><?php echo text($type); ?></td>
              <td class="muted"><?php echo text($staff); ?></td>
              <td>
                <span class="cp-status-pill <?php echo $status === 'Active' ? 'good' : 'neutral'; ?>"><?php echo text($status); ?></span>
              </td>
              <td>
                <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Edit'); ?></button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

</body>
</html>
