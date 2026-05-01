<?php

/**
 * Patient List Report — Screen 43.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$rows = [
    ['Margaret Chen',  '04821', '03/14/1958', 'F', 'Dr. Rivera', 'Active', '04/12/2026'],
    ['Ted Shaw',       '00001', '03/12/1965', 'M', 'Dr. Rivera', 'Active', '04/12/2026'],
    ['Linda Martinez', '03918', '11/02/1947', 'F', 'Dr. Patel',  'Active', '03/22/2026'],
    ['David Kim',      '06102', '06/18/1981', 'M', 'Dr. Lee',    'Active', '04/11/2026'],
    ['Allison Park',   '02745', '09/30/1973', 'F', 'Dr. Rivera', 'Active', '04/11/2026'],
    ['Robert Hayes',   '04488', '12/02/1960', 'M', 'Dr. Rivera', 'Active', '04/10/2026'],
    ['Carol Bennett',  '05521', '07/14/1955', 'F', 'Dr. Patel',  'Active', '04/10/2026'],
    ['James Wong',     '06882', '02/19/1970', 'M', 'Dr. Lee',    'Active', '04/09/2026'],
    ['Lin Kim',        '08019', '08/22/1989', 'F', 'Dr. Rivera', 'Inactive','03/15/2026'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient List'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Patient List Report'); ?></span>
    <span class="meta">12,408 <?php echo xlt('patients'); ?> • <?php echo xlt('Showing 1-9 of filtered results'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export CSV'); ?></button>
  <button type="button" class="cp-btn ghost">⎙ <?php echo xlt('Print'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-filter">
    <div class="search">🔍 <input type="text" placeholder="<?php echo xla('Search patient list...'); ?>"></div>
    <div class="pills">
      <button type="button" class="active"><?php echo xlt('All'); ?></button>
      <button type="button"><?php echo xlt('Active'); ?></button>
      <button type="button"><?php echo xlt('Inactive'); ?></button>
      <button type="button"><?php echo xlt('Last 30 days'); ?></button>
    </div>
    <span class="cp-util util">📅 <?php echo xlt('Custom date range'); ?></span>
  </div>

  <div class="cp-tbl">
    <table>
      <thead>
        <tr>
          <th><?php echo xlt('NAME'); ?></th>
          <th><?php echo xlt('MRN'); ?></th>
          <th><?php echo xlt('DOB'); ?></th>
          <th><?php echo xlt('SEX'); ?></th>
          <th><?php echo xlt('PROVIDER'); ?></th>
          <th><?php echo xlt('STATUS'); ?></th>
          <th><?php echo xlt('LAST VISIT'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as [$n, $mrn, $dob, $sex, $prov, $status, $last]): ?>
          <tr>
            <td class="bold"><?php echo text($n); ?></td>
            <td class="muted">#<?php echo text($mrn); ?></td>
            <td class="muted"><?php echo text($dob); ?></td>
            <td class="muted"><?php echo text($sex); ?></td>
            <td class="muted"><?php echo text($prov); ?></td>
            <td><span class="cp-status-pill <?php echo $status === 'Active' ? 'good' : 'neutral'; ?>"><?php echo text($status); ?></span></td>
            <td class="muted"><?php echo text($last); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
