<?php

/**
 * Inventory (Drug + Destroyed) — Screen 60, billing/operational archetype.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

// CRUD: add drug.
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_drug' && !empty($_POST['name'])) {
    sqlInsert(
        "INSERT INTO drugs (name, ndc_number, reorder_point, max_level, form, size, route, active, dispensable) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)",
        [$_POST['name'], $_POST['ndc'] ?? '', (float)($_POST['reorder'] ?? 10),
         (float)($_POST['max'] ?? 100), $_POST['form'] ?? 'tablet',
         $_POST['size'] ?? '', $_POST['route'] ?? 'oral']
    );
    $flash = 'Drug added: ' . $_POST['name'];
    header('Location: copilot_inventory.php?msg=' . urlencode($flash));
    exit;
}
$flash = $_GET['msg'] ?? null;

// Live inventory from `drugs` table.
$items = [];
$onHandValue = 0.0;
$rows = sqlStatement("SELECT drug_id, name, form, size, reorder_point, max_level, route, related_code FROM drugs WHERE active = 1 ORDER BY name ASC");
$prices = [
    'Lisinopril' => 0.18, 'Metformin' => 0.22, 'Levothyroxine' => 0.34, 'Atorvastatin' => 0.27,
    'Penicillin' => 0.41, 'Insulin' => 74.00, 'Albuterol' => 32.00, 'Sertraline' => 0.50, 'Adderall' => 1.85,
];
$idx = 0;
while ($r = sqlFetchArray($rows)) {
    $unit = trim(($r['form'] ?? '') . ($r['size'] ? ', ' . $r['size'] : ''));
    if (!$unit) { $unit = '—'; }
    // Synthesize on-hand qty + cost based on max_level.
    $qty = max(8, (int)($r['max_level'] * (0.4 + ($idx % 5) * 0.13)));
    // Find a price by first-word match
    $first = strtok($r['name'], ' ');
    $cost = $prices[$first] ?? 0.50;
    $value = $cost * $qty;
    $onHandValue += $value;
    // Status based on reorder
    if ($qty < $r['reorder_point']) {
        $status = 'Reorder'; $tone = 'warn';
    } elseif ($qty < $r['reorder_point'] * 1.5) {
        $status = 'Low stock'; $tone = 'warn';
    } else {
        $status = 'In stock'; $tone = 'good';
    }
    if (stripos($r['name'], 'Adderall') !== false) {
        $status = 'Locked'; $tone = 'info';
    }
    // Synthetic expiry
    $exp = date('Y-m-d', strtotime("+" . (8 + $idx * 5) . " months"));
    $items[] = [$r['name'], $unit, $qty, '$' . number_format($cost, 2), '$' . number_format($value, 2), $exp, $status, $tone];
    $idx++;
}

$kpis = [
    ['Total SKUs',     (string)count($items),                 'Active inventory items',     '#0D1B2A'],
    ['On hand value',  '$' . number_format($onHandValue, 0),  'Avg cost basis',             '#1F8C4D'],
    ['Expiring 30d',   '0',                                    'No imminent expirations',   '#33A68C'],
    ['Destroyed (mo)', '0',                                    'DEA-222 ledger empty',       '#0D1B2A'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Inventory'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="title"><?php echo xlt('Inventory'); ?></span>
    <span class="meta"><?php echo xlt('Drug stock + destruction log (DEA-222)'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Destroy / log'); ?></button>
  <button type="button" class="cp-btn primary" onclick="document.getElementById('cp-drug-form').style.display='block';">+ <?php echo xlt('Add drug'); ?></button>
</header>

<?php if ($flash): ?>
  <div style="background:#EBF8F0; border:1px solid #B6E0C5; padding:10px 24px; color:#1F8C4D; font-size:13px;"><?php echo text($flash); ?></div>
<?php endif; ?>

<div id="cp-drug-form" style="display:none; background:#FFFFFF; border-bottom:1px solid #E4E5E8; padding:14px 24px;">
  <form method="post" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
    <input type="hidden" name="action" value="add_drug">
    <div><label style="font-size:11px;color:#4F5763;">Name</label><br><input class="cp-input" name="name" required style="width:240px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">NDC</label><br><input class="cp-input" name="ndc" style="width:140px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">Form</label><br><input class="cp-input" name="form" value="tablet" style="width:100px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">Size</label><br><input class="cp-input" name="size" placeholder="90 ct" style="width:90px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">Reorder</label><br><input class="cp-input" name="reorder" type="number" value="10" style="width:80px;"></div>
    <div><label style="font-size:11px;color:#4F5763;">Max</label><br><input class="cp-input" name="max" type="number" value="100" style="width:80px;"></div>
    <button type="submit" class="cp-btn primary"><?php echo xlt('Add'); ?></button>
    <button type="button" class="cp-btn ghost" onclick="document.getElementById('cp-drug-form').style.display='none';">Cancel</button>
  </form>
</div>

<main class="cp-content tight">

  <div class="cp-kpi-grid">
    <?php foreach ($kpis as [$lbl, $val, $sub, $color]): ?>
      <div class="cp-kpi">
        <div class="lbl"><?php echo text($lbl); ?></div>
        <div class="val" style="color: <?php echo attr($color); ?>;"><?php echo text($val); ?></div>
        <div class="sub"><?php echo text($sub); ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cp-filter">
    <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search by name, NDC, or class...'); ?>"></div>
    <div class="pills">
      <button type="button" class="active"><?php echo xlt('All'); ?></button>
      <button type="button"><?php echo xlt('Low stock'); ?></button>
      <button type="button"><?php echo xlt('Reorder'); ?></button>
      <button type="button"><?php echo xlt('Expiring'); ?></button>
      <button type="button"><?php echo xlt('Controlled'); ?></button>
      <button type="button"><?php echo xlt('Destroyed'); ?></button>
    </div>
  </div>

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('ITEM'); ?></th>
          <th><?php echo xlt('UNIT'); ?></th>
          <th><?php echo xlt('ON HAND'); ?></th>
          <th><?php echo xlt('UNIT COST'); ?></th>
          <th><?php echo xlt('VALUE'); ?></th>
          <th><?php echo xlt('EXPIRES'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as [$name, $unit, $qty, $cost, $val, $exp, $status, $tone]): ?>
          <tr>
            <td class="bold"><?php echo text($name); ?></td>
            <td class="muted"><?php echo text($unit); ?></td>
            <td class="muted"><?php echo text($qty); ?></td>
            <td class="muted"><?php echo text($cost); ?></td>
            <td class="bold"><?php echo text($val); ?></td>
            <td class="muted"><?php echo text($exp); ?></td>
            <td><span class="cp-status-pill <?php echo attr($tone); ?>"><?php echo text($status); ?></span></td>
            <td>
              <button type="button" class="cp-btn ghost" style="padding:5px 10px;"><?php echo xlt('Adjust'); ?></button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
