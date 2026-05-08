<?php

/**
 * Patient Reported Outcomes — implements Screen 20 of the AgentForge mockups.
 *
 * Renders the "PRO" navtab content: 4 instrument summary cards with
 * mini bar charts, plus a Submission History table of past submissions.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

// 4 instrument cards. tone -> color (violet/info/warn/teal).
// `bars` is an array of integer percent heights (0..100) for the mini chart.
$instruments = [
    [
        'name' => 'PHQ-9',
        'tone' => 'violet',
        'topic' => 'Depression',
        'score' => '8',
        'max'   => '27',
        'sev'   => 'Mild',
        'sev_tone' => 'mild',
        'arrow' => '↓',
        'arrow_tone' => 'good',
        'change' => 'from 12',
        'bars' => [80, 75, 70, 60, 55, 50, 45],
    ],
    [
        'name' => 'GAD-7',
        'tone' => 'info',
        'topic' => 'Anxiety',
        'score' => '5',
        'max'   => '21',
        'sev'   => 'Mild',
        'sev_tone' => 'mild',
        'arrow' => '↓',
        'arrow_tone' => 'good',
        'change' => 'from 7',
        'bars' => [85, 78, 70, 62, 55, 50, 38],
    ],
    [
        'name' => 'PROMIS-29',
        'tone' => 'warn',
        'topic' => 'Pain Intensity',
        'score' => '5',
        'max'   => '10',
        'sev'   => 'Moderate',
        'sev_tone' => 'moderate',
        'arrow' => '↑',
        'arrow_tone' => 'bad',
        'change' => 'from 4',
        'bars' => [40, 45, 50, 55, 60, 65, 75],
    ],
    [
        'name' => 'DDS-17',
        'tone' => 'teal',
        'topic' => 'Diabetes Distress',
        'score' => '32',
        'max'   => '102',
        'sev'   => 'Moderate',
        'sev_tone' => 'moderate',
        'arrow' => '↓',
        'arrow_tone' => 'good',
        'change' => 'from 38',
        'bars' => [80, 78, 75, 70, 60, 55, 45],
    ],
];

$rows = [
    ['date'=>'04/12/2026','inst'=>'PHQ-9',          'score'=>'8 / 27',  'interp'=>'Mild depression', 'change'=>'↓ 1 from prev','change_tone'=>'good','via'=>'Patient Portal'],
    ['date'=>'04/12/2026','inst'=>'GAD-7',          'score'=>'5 / 21',  'interp'=>'Mild anxiety',    'change'=>'↓ 1 from prev','change_tone'=>'good','via'=>'Patient Portal'],
    ['date'=>'04/12/2026','inst'=>'PROMIS-29 Pain', 'score'=>'5 / 10',  'interp'=>'Moderate',        'change'=>'↑ 1 from prev','change_tone'=>'bad', 'via'=>'Patient Portal'],
    ['date'=>'02/18/2026','inst'=>'PHQ-9',          'score'=>'9 / 27',  'interp'=>'Mild depression', 'change'=>'↓ 1 from prev','change_tone'=>'good','via'=>'In-clinic tablet'],
    ['date'=>'02/18/2026','inst'=>'DDS-17',         'score'=>'32 / 102','interp'=>'Moderate distress','change'=>'↓ 3 from prev','change_tone'=>'good','via'=>'In-clinic tablet'],
    ['date'=>'11/15/2025','inst'=>'PHQ-9',          'score'=>'10 / 27', 'interp'=>'Mild depression', 'change'=>'↓ 1 from prev','change_tone'=>'good','via'=>'Patient Portal'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Reported Outcomes'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F6F7;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }
  button { font-family: inherit; cursor: pointer; }

  /* ── Header ──────────────────────────────────────────────────────────── */
  .cp-pro-head {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    height: 60px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-pro-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-pro-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-pro-meta { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-pro-spacer { flex: 1; }
  .cp-pro-cta {
    background: #008C8C;
    color: #FFFFFF;
    border: none;
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-pro-cta:hover { background: #00787A; }

  /* ── Body ────────────────────────────────────────────────────────────── */
  .cp-pro-body { padding: 16px 24px 32px; display: flex; flex-direction: column; gap: 16px; }

  .cp-inst-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
  }
  .cp-inst-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px 18px 18px;
    display: flex; flex-direction: column; gap: 8px;
  }
  .cp-inst-titlerow { display: flex; align-items: center; gap: 8px; }
  .cp-inst-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
  }
  .cp-inst-dot.violet { background: #8561C7; }
  .cp-inst-dot.info   { background: #4785D9; }
  .cp-inst-dot.warn   { background: #FA8C33; }
  .cp-inst-dot.teal   { background: #008C8C; }
  .cp-inst-name { font-size: 13px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-inst-bullet { color: #8A91A1; font-size: 13px; line-height: 1; }
  .cp-inst-topic { font-size: 12px; color: #8A91A1; line-height: 1; }

  .cp-inst-score {
    display: flex; align-items: baseline; gap: 4px;
    margin-top: 2px;
  }
  .cp-inst-score .num { font-size: 26px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-inst-score .max { font-size: 14px; color: #8A91A1; line-height: 1; }

  .cp-inst-meta { display: flex; align-items: center; gap: 8px; }
  .cp-sev-pill {
    border-radius: 4px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 600;
    line-height: 1.2;
  }
  .cp-sev-pill.mild     { background: rgba(250, 140, 51, 0.14); color: #FA8C33; }
  .cp-sev-pill.moderate { background: rgba(250, 140, 51, 0.14); color: #FA8C33; }
  .cp-arrow {
    font-size: 12px; font-weight: 600;
    line-height: 1;
  }
  .cp-arrow.good { color: #1F8C4D; }
  .cp-arrow.bad  { color: #D93838; }
  .cp-change { font-size: 11px; color: #8A91A1; line-height: 1; }

  .cp-bars {
    margin-top: 6px;
    background: #F5F6F7;
    border-radius: 8px;
    padding: 10px;
    height: 56px;
    display: flex; align-items: flex-end;
    gap: 6px;
  }
  .cp-bars .bar {
    flex: 1;
    border-radius: 3px;
  }
  .cp-bars.violet .bar { background: rgba(133, 97, 199, 0.55); }
  .cp-bars.info   .bar { background: rgba(71, 133, 217, 0.55); }
  .cp-bars.warn   .bar { background: rgba(250, 140, 51, 0.55); }
  .cp-bars.teal   .bar { background: rgba(0, 140, 140, 0.55); }

  /* ── Submission history table ────────────────────────────────────────── */
  .cp-sub-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
  }
  .cp-sub-head {
    height: 48px;
    padding: 0 22px;
    border-bottom: 1px solid #E4E5E8;
    display: flex; align-items: center;
  }
  .cp-sub-title { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1; }
  .cp-sub-spacer { flex: 1; }
  .cp-sub-link {
    font-size: 11px; font-weight: 500;
    color: #008C8C;
    line-height: 1;
    cursor: pointer;
  }

  .cp-sub-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
  }
  .cp-sub-table thead th {
    background: #F5F6F7;
    color: #8A91A1;
    font-size: 10px; font-weight: 600;
    letter-spacing: 0.6px;
    text-align: left;
    padding: 10px 14px;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-sub-table tbody td {
    padding: 14px 14px;
    border-bottom: 0.5px solid #E4E5E8;
    font-size: 12px;
    color: #0D1B2A;
    vertical-align: middle;
  }
  .cp-sub-table tbody tr:last-child td { border-bottom: none; }
  .cp-sub-table .col-date { width: 96px; color: #4F5763; font-weight: 500; }
  .cp-sub-table .col-inst { width: 140px; font-weight: 600; }
  .cp-sub-table .col-score { width: 100px; }
  .cp-sub-table .col-change.good { color: #1F8C4D; font-weight: 500; }
  .cp-sub-table .col-change.bad  { color: #D93838; font-weight: 500; }
  .cp-sub-table .col-via { color: #4F5763; }

  .cp-sub-action {
    background: #FFFFFF;
    color: #0D1B2A;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 11px; font-weight: 500;
    line-height: 1;
    margin-right: 6px;
  }
  .cp-sub-action:hover { background: #F5F6F7; }
</style>
</head>
<body>

<header class="cp-pro-head">
  <div class="cp-pro-title"><?php echo xlt('Patient Reported Outcomes'); ?></div>
  <div class="cp-pro-bullet">•</div>
  <div class="cp-pro-meta">EasiPRO connected • 6 instruments tracked</div>
  <div class="cp-pro-spacer"></div>
  <button type="button" class="cp-pro-cta">
    <span>📨</span>
    <span><?php echo xlt('Send to Patient Portal'); ?></span>
  </button>
</header>

<main class="cp-pro-body">
  <section class="cp-inst-grid">
    <?php foreach ($instruments as $i): ?>
      <article class="cp-inst-card">
        <div class="cp-inst-titlerow">
          <span class="cp-inst-dot <?php echo attr($i['tone']); ?>"></span>
          <span class="cp-inst-name"><?php echo text($i['name']); ?></span>
          <span class="cp-inst-bullet">•</span>
          <span class="cp-inst-topic"><?php echo text($i['topic']); ?></span>
        </div>
        <div class="cp-inst-score">
          <span class="num"><?php echo text($i['score']); ?></span>
          <span class="max">/ <?php echo text($i['max']); ?></span>
        </div>
        <div class="cp-inst-meta">
          <span class="cp-sev-pill <?php echo attr($i['sev_tone']); ?>"><?php echo text($i['sev']); ?></span>
          <span class="cp-arrow <?php echo attr($i['arrow_tone']); ?>"><?php echo text($i['arrow']); ?></span>
          <span class="cp-change"><?php echo text($i['change']); ?></span>
        </div>
        <div class="cp-bars <?php echo attr($i['tone']); ?>">
          <?php foreach ($i['bars'] as $h): ?>
            <span class="bar" style="height: <?php echo (int)$h; ?>%;"></span>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="cp-sub-card">
    <header class="cp-sub-head">
      <div class="cp-sub-title"><?php echo xlt('Submission History'); ?></div>
      <div class="cp-sub-spacer"></div>
      <span class="cp-sub-link"><?php echo xlt('Export CSV'); ?></span>
    </header>
    <table class="cp-sub-table">
      <thead>
        <tr>
          <th class="col-date"><?php echo xlt('DATE'); ?></th>
          <th class="col-inst"><?php echo xlt('INSTRUMENT'); ?></th>
          <th class="col-score"><?php echo xlt('SCORE'); ?></th>
          <th><?php echo xlt('INTERPRETATION'); ?></th>
          <th><?php echo xlt('CHANGE'); ?></th>
          <th class="col-via"><?php echo xlt('ADMINISTERED VIA'); ?></th>
          <th><?php echo xlt('ACTIONS'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="col-date"><?php echo text($r['date']); ?></td>
            <td class="col-inst"><?php echo text($r['inst']); ?></td>
            <td class="col-score"><?php echo text($r['score']); ?></td>
            <td><?php echo text($r['interp']); ?></td>
            <td class="col-change <?php echo attr($r['change_tone']); ?>"><?php echo text($r['change']); ?></td>
            <td class="col-via"><?php echo text($r['via']); ?></td>
            <td>
              <button type="button" class="cp-sub-action"><?php echo xlt('View'); ?></button>
              <button type="button" class="cp-sub-action"><?php echo xlt('Compare'); ?></button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</main>

</body>
</html>
