<?php

/**
 * Lab Overview / Trends per patient — Screen 37.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

$panels = [
    [
        'name' => 'HbA1c',
        'unit' => '%',
        'target' => '<7.0',
        'series' => [
            ['2025-04', 7.2, 'norm'],
            ['2025-08', 7.4, 'high'],
            ['2025-11', 7.2, 'high'],
            ['2026-02', 7.4, 'high'],
            ['2026-04', 7.9, 'high'],
        ],
    ],
    [
        'name' => 'LDL',
        'unit' => 'mg/dL',
        'target' => '<100',
        'series' => [
            ['2025-04', 112, 'high'],
            ['2025-11', 105, 'high'],
            ['2026-02',  98, 'norm'],
            ['2026-04',  98, 'norm'],
        ],
    ],
    [
        'name' => 'Creatinine',
        'unit' => 'mg/dL',
        'target' => '0.6–1.2',
        'series' => [
            ['2025-04', 0.92, 'norm'],
            ['2025-11', 0.98, 'norm'],
            ['2026-02', 1.02, 'norm'],
            ['2026-04', 1.04, 'norm'],
        ],
    ],
    [
        'name' => 'TSH',
        'unit' => 'mIU/L',
        'target' => '0.4–4.0',
        'series' => [
            ['2025-04', 2.1, 'norm'],
            ['2025-11', 2.3, 'norm'],
            ['2026-02', 2.5, 'norm'],
            ['2026-04', 2.4, 'norm'],
        ],
    ],
];

function spark(array $series): string
{
    $vals = array_column($series, 1);
    $min = min($vals); $max = max($vals);
    $w = 220; $h = 60;
    $n = count($series);
    $points = '';
    foreach ($series as $i => [, $v]) {
        $x = round(($i / max($n - 1, 1)) * ($w - 8) + 4, 1);
        $y = $max === $min ? $h / 2 : round($h - 8 - (($v - $min) / ($max - $min)) * ($h - 16), 1);
        $points .= "$x,$y ";
    }
    $last = end($series);
    $tone = $last[2] === 'high' ? '#FA8C33' : ($last[2] === 'low' ? '#4785D9' : '#1F8C4D');
    return "<svg width='$w' height='$h' viewBox='0 0 $w $h' xmlns='http://www.w3.org/2000/svg'>"
         . "<polyline fill='none' stroke='$tone' stroke-width='2' points='$points'/>"
         . "</svg>";
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Lab Overview'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-lab-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .cp-lab-card { display: flex; gap: 16px; }
  .cp-lab-info { flex: 0 0 130px; }
  .cp-lab-info .n { font-weight: 700; font-size: 14px; color: #0D1B2A; }
  .cp-lab-info .t { font-size: 11px; color: #8A91A1; margin-top: 2px; }
  .cp-lab-info .v { font-size: 22px; font-weight: 700; color: #FA8C33; margin-top: 6px; }
  .cp-lab-info .v.norm { color: #1F8C4D; }
  .cp-lab-info .u { font-size: 11px; color: #8A91A1; }
  .cp-lab-spark { flex: 1; min-width: 0; }
  .cp-lab-spark svg { width: 100%; height: auto; }
  .cp-lab-spark-row { display: flex; gap: 10px; font-size: 10px; color: #8A91A1; margin-top: 4px; }
  .cp-lab-spark-row span { flex: 1; text-align: center; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <span class="titleSm"><?php echo xlt('Lab Overview'); ?></span>
    <span class="meta">4 <?php echo xlt('panels trended over 12 months'); ?> • 2 <?php echo xlt('flagged'); ?></span>
  </div>
  <button type="button" class="cp-btn ghost"><?php echo xlt('Export'); ?></button>
  <button type="button" class="cp-btn primary">+ <?php echo xlt('Order panel'); ?></button>
</header>

<main class="cp-content tight">

  <div class="cp-lab-grid">
    <?php foreach ($panels as $p):
      $lastVal = end($p['series']);
      $tone = $lastVal[2] === 'high' ? '' : 'norm';
    ?>
      <div class="cp-section">
        <div class="cp-lab-card">
          <div class="cp-lab-info">
            <div class="n"><?php echo text($p['name']); ?></div>
            <div class="t"><?php echo xlt('Target'); ?> <?php echo text($p['target']); ?></div>
            <div class="v <?php echo $tone; ?>"><?php echo text($lastVal[1]); ?></div>
            <div class="u"><?php echo text($p['unit']); ?> • <?php echo text($lastVal[0]); ?></div>
          </div>
          <div class="cp-lab-spark">
            <?php echo spark($p['series']); ?>
            <div class="cp-lab-spark-row">
              <?php foreach ($p['series'] as [$d, $v]): ?><span><?php echo text($d); ?></span><?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

</main>

</body>
</html>
