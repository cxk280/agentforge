<?php

/**
 * Lab Trends Report — Screen 45.
 * Population-level lab trending across the panel.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$panels = [
    ['HbA1c — diabetic panel',      '7.6 %',  '↓ 0.2 from Q1',  'Median across 184 patients', '#1F8C4D'],
    ['LDL — at-risk panel',         '108 mg/dL','↑ 4 from Q1',  'Median across 312 patients', '#FA8C33'],
    ['Blood Pressure — HTN panel',  '132/84', '↓ 3 from Q1',    'Median across 248 patients', '#1F8C4D'],
    ['eGFR — CKD panel',            '64',     '↓ 2 from Q1',    'Median across 92 patients',  '#FA8C33'],
];

$bench = [
    ['Diabetes (Type 2) — A1C <7.0',   '52%',  'Target 70%',  'Below'],
    ['Hypertension — BP <140/90',      '78%',  'Target 75%',  'Above'],
    ['Statin Rx — qualifying pts',     '88%',  'Target 85%',  'Above'],
    ['Annual eye exam — diabetics',    '64%',  'Target 80%',  'Below'],
    ['Mammogram — eligible',           '74%',  'Target 75%',  'On target'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Lab Trends'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-bench-bar { width: 100%; height: 8px; background: #F0F1F3; border-radius: 999px; overflow: hidden; }
  .cp-bench-bar > span { display: block; height: 100%; border-radius: 999px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Lab Trends'); ?></span>
    <span class="meta"><?php echo xlt('Population trends'); ?> • Q1 2026 vs Q4 2025</span>
  </div>
  <button type="button" class="cp-btn ghost">⤓ <?php echo xlt('Export'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-kpi-grid">
    <?php foreach ($panels as [$lbl, $val, $delta, $sub, $color]): ?>
      <div class="cp-kpi">
        <div class="lbl"><?php echo text($lbl); ?></div>
        <div class="val" style="color: <?php echo attr($color); ?>;"><?php echo text($val); ?></div>
        <div class="sub"><?php echo text($delta); ?> · <?php echo text($sub); ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <section class="cp-panel flush">
    <div class="cp-panel-head">
      <h3><?php echo xlt('Quality Benchmarks'); ?></h3>
      <span class="cnt"><?php echo count($bench); ?></span>
    </div>
    <div class="cp-tbl" style="border:none; border-radius:0;">
      <table>
        <thead>
          <tr>
            <th><?php echo xlt('MEASURE'); ?></th>
            <th><?php echo xlt('CURRENT'); ?></th>
            <th><?php echo xlt('TARGET'); ?></th>
            <th><?php echo xlt('STATUS'); ?></th>
            <th style="width: 240px;"><?php echo xlt('PROGRESS'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bench as [$measure, $cur, $target, $status]):
            $pct = (int)$cur;
            $tone = $status === 'Above' ? '#1F8C4D' : ($status === 'Below' ? '#D93838' : '#FA8C33');
            $pill = $status === 'Above' ? 'good' : ($status === 'Below' ? 'danger' : 'warn');
          ?>
            <tr>
              <td class="bold"><?php echo text($measure); ?></td>
              <td class="bold" style="color: <?php echo attr($tone); ?>;"><?php echo text($cur); ?></td>
              <td class="muted"><?php echo text($target); ?></td>
              <td><span class="cp-status-pill <?php echo attr($pill); ?>"><?php echo text($status); ?></span></td>
              <td>
                <div class="cp-bench-bar"><span style="width: <?php echo $pct; ?>%; background: <?php echo $tone; ?>;"></span></div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

</main>

</body>
</html>
