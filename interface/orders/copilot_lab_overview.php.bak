<?php

/**
 * Lab Overview / Trends per patient — Screen 37.
 *
 * Patient-scoped lab trends dashboard. Renders four trended panels
 * (HbA1c, LDL, Microalbumin, Creatinine) for the active patient with
 * latest value, trend tag, reference range and an inline SVG line of
 * historical results. A time-range pill toggle (6m / 1y / 2y / 5y /
 * All) at the top filters the query window via ?range=... .
 *
 * The chrome (top nav, demographics banner, navtab strip) is rendered
 * by the parent shell — this page renders only the body.
 *
 * NOTE on table choice: the wiring brief mentioned `procedure_result`,
 * but the AgentForge demo seed (`sql/seed_clinical_data*.sql`) loads
 * lab data into `form_observation` (LOINC `ob_code`, numeric `ob_value`,
 * `category='laboratory'`). We query form_observation here so the
 * trends actually show real seeded values. Both tables model the same
 * clinical concept; if a future seed pivots to procedure_result the
 * helper below can be repointed.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Session\SessionWrapperFactory;

// Patient context — Margaret Chen seeded as pid=1 in dev; production uses
// the session-scoped patient.
$pid = (int)(SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 1);

// ----- Time-range filter --------------------------------------------------
$timeRanges = [
    '6m'  => ['INTERVAL 6 MONTH', '6m'],
    '1y'  => ['INTERVAL 1 YEAR',  '1y'],
    '2y'  => ['INTERVAL 2 YEAR',  '2y'],
    '5y'  => ['INTERVAL 5 YEAR',  '5y'],
    'all' => [null,                'All'],
];
$activeRange = strtolower((string)($_GET['range'] ?? '2y'));
if (!isset($timeRanges[$activeRange])) {
    $activeRange = '2y';
}
[$intervalSql, $activeLabel] = $timeRanges[$activeRange];

// ----- Patient name for subtitle -----------------------------------------
$patient = sqlQuery(
    "SELECT fname, mname, lname FROM patient_data WHERE pid = ?",
    [$pid]
);
$patientName = trim(
    (string)($patient['fname'] ?? '') . ' ' .
    (string)($patient['lname'] ?? '')
);
if ($patientName === '') {
    $patientName = 'Patient #' . $pid;
}

// ----- Lab panel definitions ---------------------------------------------
// Each panel is one trended LOINC concept. We list every code we know about
// for the same lab so the query picks up data regardless of which LOINC the
// lab system used.
//
// Reference ranges are static clinical reference ranges (not per-result
// `range` columns) so the displayed thresholds stay consistent even when
// the lab vendor omits one.
$panelDefs = [
    [
        'key'       => 'HbA1c',
        'name'      => 'HbA1c',
        // 4548-4 = Hemoglobin A1c/Hemoglobin.total in Blood
        // 17856-6 = Hemoglobin A1c/Hemoglobin.total in Blood by HPLC
        'codes'     => ['4548-4', '17856-6'],
        'unit'      => '%',
        'ref'       => '<7.0',
        'good_dir'  => 'down',  // lower is better
    ],
    [
        'key'       => 'LDL',
        'name'      => 'LDL',
        // 13457-7 = LDL calc, 2089-1 = LDL direct, 18262-6 = LDL direct (newer LOINC)
        'codes'     => ['13457-7', '2089-1', '18262-6'],
        'unit'      => 'mg/dL',
        'ref'       => '<100',
        'good_dir'  => 'down',
    ],
    [
        'key'       => 'Microalbumin',
        'name'      => 'Microalbumin',
        // 14959-1 = Microalbumin/Creatinine ratio, 14957-5 = urine Microalbumin
        'codes'     => ['14959-1', '14957-5'],
        'unit'      => 'mg/g',
        'ref'       => '<30',
        'good_dir'  => 'down',
    ],
    [
        'key'       => 'Creatinine',
        'name'      => 'Creatinine',
        // 2160-0 = Creatinine serum, 38483-4 = Creatinine serum/plasma alt
        'codes'     => ['2160-0', '38483-4'],
        'unit'      => 'mg/dL',
        'ref'       => '0.6-1.2',
        'good_dir'  => 'stable',
    ],
];

/**
 * Pull a chronological numeric series for one lab concept for a patient.
 *
 * Returns rows ordered ASC by date, with `date` as a YYYY-MM-DD string and
 * `value` as a float (filtering out non-numeric `ob_value` strings).
 *
 * @param int           $pid
 * @param array<string> $codes
 * @param string|null   $intervalSql  Literal SQL fragment (e.g. "INTERVAL 2 YEAR"); null = no time filter.
 * @return array<int, array{date:string, value:float}>
 */
function lab_overview_fetch_series(int $pid, array $codes, ?string $intervalSql): array
{
    if ($codes === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $params = array_merge([$pid], $codes);

    // $intervalSql is a literal whitelisted fragment (chosen from a static map),
    // never user input — it is safe to interpolate.
    $dateClause = $intervalSql !== null ? "AND date >= NOW() - $intervalSql" : '';

    $sql = "SELECT date, ob_value
            FROM form_observation
            WHERE pid = ?
              AND ob_code IN ($placeholders)
              AND ob_value IS NOT NULL
              AND ob_value <> ''
              $dateClause
            ORDER BY date ASC";

    $out = [];
    $rs = sqlStatement($sql, $params);
    while ($r = sqlFetchArray($rs)) {
        $raw = trim((string)$r['ob_value']);
        // Strip leading <, > or other non-numeric chars sometimes used on
        // boundary values like "<5".
        $clean = preg_replace('/^[<>≤≥]+/', '', $raw);
        if (!is_numeric($clean)) {
            continue;
        }
        $out[] = [
            'date'  => (string)$r['date'],
            'value' => (float)$clean,
        ];
    }
    return $out;
}

/**
 * Format a numeric lab value for display, dropping pointless trailing zeros
 * but keeping clinically meaningful precision.
 */
function lab_overview_format_value(float $v): string
{
    // 1 decimal for values < 10 (e.g. HbA1c, Creatinine), int for larger.
    if (abs($v) < 10) {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
    return (string)(int)round($v);
}

/**
 * Compute trend tag + tone from a series.
 *
 * @param array<int, array{date:string, value:float}> $series
 * @return array{tag:string, tagTn:string, tone:string}
 */
function lab_overview_trend(array $series, string $goodDir): array
{
    $n = count($series);
    if ($n < 2) {
        return ['tag' => 'insufficient data', 'tagTn' => 'good', 'tone' => 'good'];
    }
    $last = $series[$n - 1]['value'];
    $prev = $series[$n - 2]['value'];
    // Ignore noise below ~2% relative change.
    $denom = $prev != 0.0 ? abs($prev) : 1.0;
    $rel = ($last - $prev) / $denom;

    if (abs($rel) < 0.02) {
        return ['tag' => '→ stable', 'tagTn' => 'good', 'tone' => 'good'];
    }
    if ($rel > 0) {
        // Rising. Bad if lower-is-better; warn if "stable" target.
        if ($goodDir === 'down') {
            return ['tag' => '↑ rising', 'tagTn' => 'danger', 'tone' => 'danger'];
        }
        if ($goodDir === 'stable') {
            return ['tag' => 'trending up', 'tagTn' => 'warn', 'tone' => 'warn'];
        }
        return ['tag' => '↑ rising', 'tagTn' => 'good', 'tone' => 'good'];
    }
    // Falling. Good if lower-is-better; warn if stable-target.
    if ($goodDir === 'down') {
        return ['tag' => '↓ improving', 'tagTn' => 'good', 'tone' => 'good'];
    }
    if ($goodDir === 'stable') {
        return ['tag' => 'trending down', 'tagTn' => 'warn', 'tone' => 'warn'];
    }
    return ['tag' => '↓ falling', 'tagTn' => 'danger', 'tone' => 'danger'];
}

// ----- Build panels ------------------------------------------------------
$panels = [];
$globalDates = [];   // collect all dates across panels for the x-axis ticks
foreach ($panelDefs as $def) {
    $series = lab_overview_fetch_series($pid, $def['codes'], $intervalSql);
    $valuesOnly = array_map(static fn($p) => $p['value'], $series);

    $latest = $series === [] ? null : end($series)['value'];
    $trend = lab_overview_trend($series, $def['good_dir']);

    $panels[] = [
        'name'      => $def['name'],
        'tag'       => $trend['tag'],
        'tagTn'     => $trend['tagTn'],
        'latest'    => $latest === null ? '—' : lab_overview_format_value($latest),
        'unit'      => $def['unit'],
        'ref'       => $def['ref'],   // static clinical reference range
        'tone'      => $trend['tone'],
        'series'    => $valuesOnly,
        'dates'     => array_map(static fn($p) => $p['date'], $series),
    ];

    foreach ($series as $row) {
        $globalDates[] = $row['date'];
    }
}

// ----- 9 X-axis ticks computed from the data range -----------------------
// Pick min/max across every panel's data, then spread 9 evenly-spaced ticks.
// Format MM/YY.
$xLabels = [];
if ($globalDates !== []) {
    $tsList = [];
    foreach ($globalDates as $d) {
        $t = strtotime($d);
        if ($t !== false) {
            $tsList[] = $t;
        }
    }
    if ($tsList !== []) {
        $tMin = min($tsList);
        $tMax = max($tsList);
        if ($tMax === $tMin) {
            $tMin -= 86400 * 30;  // pad a month either side
            $tMax += 86400 * 30;
        }
        for ($i = 0; $i < 9; $i++) {
            $t = $tMin + (int)round(($tMax - $tMin) * ($i / 8));
            $xLabels[] = date('m/y', $t);
        }
    }
}
// If we have nothing in DB, fall back to a 9-tick blank axis so the cards
// still render visually.
if ($xLabels === []) {
    $xLabels = array_fill(0, 9, '—');
}

/**
 * Render an inline SVG line chart for a panel's series.
 *
 * Computes a polyline path and dot markers from numeric series; if the
 * series is empty we render a flat baseline placeholder so the card body
 * isn't blank.
 *
 * @param array<int, float|int> $series
 */
function lab_chart(array $series, string $tone): string
{
    $w = 700;
    $h = 220;
    $padL = 4;
    $padR = 16;
    $padT = 14;
    $padB = 14;

    $colors = [
        'danger' => '#D93838',
        'good'   => '#1F8C4D',
        'warn'   => '#FA8C33',
        'info'   => '#4785D9',
    ];
    $color = $colors[$tone] ?? '#1F8C4D';

    $innerW = $w - $padL - $padR;
    $innerH = $h - $padT - $padB;

    // Background horizontal grid lines (rendered for every state).
    $grid = '';
    for ($g = 1; $g <= 3; $g++) {
        $gy = $padT + ($g / 4) * $innerH;
        $grid .= sprintf(
            "<line x1='%.1f' y1='%.1f' x2='%.1f' y2='%.1f' stroke='#F0F1F3' stroke-width='1'/>",
            $padL,
            $gy,
            $w - $padR,
            $gy
        );
    }

    if (count($series) < 1) {
        $cx = $padL + $innerW / 2;
        $cy = $padT + $innerH / 2;
        return "<svg viewBox='0 0 {$w} {$h}' xmlns='http://www.w3.org/2000/svg' preserveAspectRatio='none' style='width:100%;height:100%;display:block;'>"
            . $grid
            . "<text x='{$cx}' y='{$cy}' text-anchor='middle' fill='#8A91A1' font-size='12' font-family='Inter'>No results in selected range</text>"
            . "</svg>";
    }

    $min = min($series);
    $max = max($series);
    $n   = count($series);

    // Add ~10% headroom so the line never touches the top/bottom edge.
    $range = $max - $min;
    if ($range == 0) {
        $min -= 1;
        $max += 1;
        $range = 2;
    }
    $min -= $range * 0.10;
    $max += $range * 0.10;
    $range = $max - $min;

    $points = [];
    foreach ($series as $i => $v) {
        $x = $padL + ($n > 1 ? ($i / ($n - 1)) * $innerW : $innerW / 2);
        $y = $padT + (1 - (($v - $min) / $range)) * $innerH;
        $points[] = [round($x, 1), round($y, 1)];
    }

    // Polyline path — d="M..." form per the brief.
    $first = array_shift($points);
    $d = "M{$first[0]},{$first[1]}";
    foreach ($points as $p) {
        $d .= " L{$p[0]},{$p[1]}";
    }
    $allPoints = array_merge([$first], $points);

    $line = "<path fill='none' stroke='{$color}' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' d='{$d}'/>";

    // Marker dots at each tick. Last dot is larger to highlight the latest value.
    $dots = '';
    foreach ($allPoints as $i => [$x, $y]) {
        $isLast = ($i === count($allPoints) - 1);
        $r = $isLast ? 5 : 2.6;
        $dots .= "<circle cx='{$x}' cy='{$y}' r='{$r}' fill='{$color}'/>";
    }

    return "<svg viewBox='0 0 {$w} {$h}' xmlns='http://www.w3.org/2000/svg' preserveAspectRatio='none' style='width:100%;height:100%;display:block;'>"
        . $grid
        . $line
        . $dots
        . "</svg>";
}

// Help link target — points at OpenEMR's wiki page on lab results display.
$helpHref = 'https://www.open-emr.org/wiki/index.php/Laboratory_Module';

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
  /* Header — title + subtitle + right-aligned range pills */
  .cp-pagehead .titleLg { font-size: 20px; font-weight: 700; color: #0D1B2A; line-height: 1.1; }
  .cp-pagehead .sub-meta { color: #4F5763; font-size: 12px; line-height: 1; }
  .cp-pagehead .info { gap: 6px; }
  .cp-pagehead .head-row { display: flex; align-items: baseline; gap: 10px; }
  .cp-pagehead .dot { color: #8A91A1; font-size: 12px; }

  /* Time-range segmented control (rounded pill group) */
  .cp-range-toggle {
    display: inline-flex; align-items: center;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 3px;
    gap: 2px;
  }
  .cp-range-toggle a {
    border: 0; background: transparent;
    border-radius: 999px;
    padding: 5px 14px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    line-height: 1;
    text-decoration: none;
  }
  .cp-range-toggle a.active {
    background: #0D1B2A;
    color: #FFFFFF;
    font-weight: 600;
  }
  .cp-help-pill {
    display: inline-flex; align-items: center;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    gap: 4px;
    margin-left: 8px;
    text-decoration: none;
  }
  .cp-help-pill:hover { color: #0D1B2A; }

  /* 2x2 trend card grid */
  .cp-lab-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }

  .cp-trend-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px 22px 12px;
    display: flex; flex-direction: column;
    gap: 10px;
    min-height: 290px;
  }

  .cp-trend-head {
    display: flex; align-items: flex-start;
    gap: 12px;
  }
  .cp-trend-name {
    font-size: 16px; font-weight: 700;
    color: #0D1B2A;
    line-height: 1.1;
  }
  .cp-trend-tag {
    font-size: 12px; font-weight: 500;
    line-height: 1.1;
    margin-top: 2px;
  }
  .cp-trend-tag.danger { color: #D93838; }
  .cp-trend-tag.good   { color: #1F8C4D; }
  .cp-trend-tag.warn   { color: #FA8C33; }

  .cp-trend-headLeft  {
    display: flex; align-items: baseline;
    gap: 10px;
    flex: 1;
  }
  .cp-trend-headRight {
    display: flex; align-items: flex-start;
    gap: 18px;
  }
  .cp-trend-stat {
    text-align: right;
    line-height: 1.1;
  }
  .cp-trend-stat .lbl {
    font-size: 11px; color: #8A91A1; font-weight: 500;
    display: block;
    margin-bottom: 4px;
  }
  .cp-trend-stat .val {
    font-size: 22px; font-weight: 700;
    line-height: 1;
  }
  .cp-trend-stat .val.danger { color: #D93838; }
  .cp-trend-stat .val.good   { color: #1F8C4D; }
  .cp-trend-stat .val.warn   { color: #FA8C33; }
  .cp-trend-stat .unit {
    font-size: 11px; color: #8A91A1; font-weight: 500;
    margin-left: 2px;
  }
  .cp-trend-stat .ref {
    font-size: 13px; font-weight: 600;
    color: #0D1B2A;
    line-height: 1;
  }

  /* Chart body */
  .cp-trend-chart {
    flex: 1 1 auto;
    min-height: 170px;
    position: relative;
  }
  .cp-trend-xaxis {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: #8A91A1;
    padding: 4px 0 0;
    line-height: 1;
  }
  .cp-trend-xaxis span { flex: 1; text-align: center; }
  .cp-trend-xaxis span:first-child { text-align: left; }
  .cp-trend-xaxis span:last-child  { text-align: right; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div class="head-row">
      <span class="titleLg"><?php echo xlt('Lab Trends'); ?></span>
      <span class="sub-meta"><?php echo xlt('Visualize key labs over time'); ?> <span class="dot">·</span> <?php echo text($patientName); ?></span>
    </div>
  </div>
  <div class="cp-range-toggle">
    <?php foreach ($timeRanges as $key => [$_sql, $label]): ?>
      <a href="?range=<?php echo attr($key); ?>" class="<?php echo $key === $activeRange ? 'active' : ''; ?>"><?php echo text($label); ?></a>
    <?php endforeach; ?>
  </div>
  <a class="cp-help-pill" href="<?php echo attr($helpHref); ?>" target="_blank" rel="noopener">? <?php echo xlt('Help'); ?></a>
</header>

<main class="cp-content tight">

  <div class="cp-lab-grid">
    <?php foreach ($panels as $p): ?>
      <div class="cp-trend-card">
        <div class="cp-trend-head">
          <div class="cp-trend-headLeft">
            <span class="cp-trend-name"><?php echo text($p['name']); ?></span>
            <span class="cp-trend-tag <?php echo attr($p['tagTn']); ?>"><?php echo text($p['tag']); ?></span>
          </div>
          <div class="cp-trend-headRight">
            <div class="cp-trend-stat">
              <span class="lbl"><?php echo xlt('Latest'); ?></span>
              <span class="val <?php echo attr($p['tone']); ?>"><?php echo text($p['latest']); ?></span><span class="unit"><?php echo text($p['unit']); ?></span>
            </div>
            <div class="cp-trend-stat">
              <span class="lbl"><?php echo xlt('Ref'); ?></span>
              <span class="ref"><?php echo text($p['ref']); ?></span>
            </div>
          </div>
        </div>

        <div class="cp-trend-chart">
          <?php echo lab_chart($p['series'], $p['tone']); ?>
        </div>

        <div class="cp-trend-xaxis">
          <?php foreach ($xLabels as $lbl): ?>
            <span><?php echo text($lbl); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

</main>

</body>
</html>
