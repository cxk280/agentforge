<?php

/**
 * Patient Assessments — implements Screen 13 of the AgentForge mockups.
 *
 * Renders the "Assessments" navtab content: header row, left category
 * sidebar, and a list of assessment cards (due / completed / scheduled).
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$categories = [
    ['All',                 12, true],
    ['Due Now',              4, false],
    ['Behavioral Health',    6, false],
    ['Social Determinants',  2, false],
    ['Functional',           3, false],
    ['Risk Screening',       1, false],
    ['Wellness',             0, false],
];

// status: due | done | scheduled
$assessments = [
    [
        'status' => 'due',
        'title'  => 'PHQ-9 — Patient Health Questionnaire',
        'cat'    => 'Behavioral Health',
        'desc'   => '9-item depression screening instrument',
        'meta'   => 'Last taken 90 days ago',
    ],
    [
        'status' => 'due',
        'title'  => 'GAD-7 — Generalized Anxiety Disorder',
        'cat'    => 'Behavioral Health',
        'desc'   => '7-item anxiety screening',
        'meta'   => 'Never administered',
    ],
    [
        'status' => 'due',
        'title'  => 'AUDIT-C — Alcohol Use Disorders',
        'cat'    => 'Behavioral Health',
        'desc'   => '3-item alcohol use screening',
        'meta'   => 'Last taken 12 months ago',
    ],
    [
        'status' => 'due',
        'title'  => 'SDOH Assessment — PRAPARE',
        'cat'    => 'Social Determinants',
        'desc'   => '21 questions covering housing, food, transportation, employment',
        'meta'   => 'Never administered',
    ],
    [
        'status' => 'done',
        'title'  => 'Diabetes Distress Scale (DDS-17)',
        'cat'    => 'Behavioral Health',
        'desc'   => 'Screens for emotional distress related to diabetes management',
        'meta'   => 'Score 32 — moderate distress • 02/18/2026',
        'score_label' => 'SCORE',
        'score_value' => '32 / 102',
    ],
    [
        'status' => 'done',
        'title'  => 'Falls Risk Assessment (Stop-BANG)',
        'cat'    => 'Risk Screening',
        'desc'   => 'Identifies fall risk in older adults',
        'meta'   => 'Low risk • 02/18/2026',
        'score_label' => 'SCORE',
        'score_value' => 'Low',
    ],
    [
        'status' => 'scheduled',
        'title'  => 'Functional Status (Barthel Index)',
        'cat'    => 'Functional',
        'desc'   => 'Activities of Daily Living assessment',
        'meta'   => 'Sent to patient portal • Due 11/20',
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Assessments'); ?></title>
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

  /* ── Page header bar ─────────────────────────────────────────────────── */
  .cp-asmt-head {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    height: 60px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-asmt-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-asmt-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-asmt-meta  { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-asmt-spacer { flex: 1; }
  .cp-asmt-cta {
    height: 32px;
    padding: 0 16px;
    border-radius: 999px;
    background: #008C8C;
    color: #FFFFFF;
    border: none;
    font-size: 12px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 6px;
    line-height: 1;
  }
  .cp-asmt-cta:hover { background: #00787A; }
  .cp-asmt-cta .plus { font-size: 14px; font-weight: 700; line-height: 1; }

  /* ── Two-column body ─────────────────────────────────────────────────── */
  .cp-asmt-body { display: flex; align-items: flex-start; min-height: calc(100vh - 60px); }

  .cp-asmt-side {
    width: 240px;
    flex: 0 0 auto;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    border-bottom: 1px solid #E4E5E8;
    padding: 16px 0;
    align-self: stretch;
  }
  .cp-cat {
    height: 38px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 8px;
    cursor: pointer;
  }
  .cp-cat .label {
    flex: 1;
    font-size: 13px;
    font-weight: 500;
    color: #4F5763;
    line-height: 1;
  }
  .cp-cat .count {
    font-size: 11px;
    font-weight: 500;
    color: #8A91A1;
    line-height: 1;
  }
  .cp-cat:hover { background: #FAFBFC; }
  .cp-cat.active {
    background: rgba(0, 140, 140, 0.08);
  }
  .cp-cat.active .label {
    color: #008C8C;
    font-weight: 600;
  }
  .cp-cat.active .count {
    color: #008C8C;
  }

  /* ── Right side cards ────────────────────────────────────────────────── */
  .cp-asmt-list {
    flex: 1;
    padding: 20px 24px;
    display: flex; flex-direction: column; gap: 12px;
    min-width: 0;
  }
  .cp-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    height: 88px;
    padding: 0 20px;
    display: flex; align-items: center; gap: 16px;
  }
  .cp-avatar {
    width: 44px; height: 44px;
    border-radius: 22px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 18px; font-weight: 700;
    flex: 0 0 auto;
  }
  .cp-avatar.due       { background: rgba(250, 140, 51, 0.15); color: #FA8C33; }
  .cp-avatar.done      { background: rgba(51, 166, 102, 0.15); color: #33A666; }
  .cp-avatar.scheduled { background: rgba(71, 133, 217, 0.15); color: #4785D9; }

  .cp-card-info { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
  .cp-card-titlerow { display: inline-flex; align-items: center; gap: 8px; }
  .cp-card-title {
    font-size: 14px; font-weight: 600;
    color: #0D1B2A; line-height: 1;
    white-space: nowrap;
  }
  .cp-card-pill {
    background: #F5F6F7;
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 10px; font-weight: 500;
    color: #4F5763;
    line-height: 1.4;
    white-space: nowrap;
  }
  .cp-card-desc {
    font-size: 12px; color: #8A91A1;
    line-height: 1; font-weight: 400;
  }
  .cp-card-meta {
    font-size: 11px; color: #4F5763;
    line-height: 1; font-weight: 500;
  }

  .cp-card-spacer { flex: 1; }

  .cp-status-pill {
    border-radius: 999px;
    border: 1px solid;
    padding: 4px 10px;
    font-size: 10px; font-weight: 700;
    letter-spacing: 0.5px;
    line-height: 1;
    flex: 0 0 auto;
    display: inline-flex; align-items: center;
  }
  .cp-status-pill.due {
    background: rgba(250, 140, 51, 0.14);
    border-color: rgba(250, 140, 51, 0.4);
    color: #FA8C33;
  }
  .cp-status-pill.scheduled {
    background: rgba(71, 133, 217, 0.14);
    border-color: rgba(71, 133, 217, 0.4);
    color: #4785D9;
  }

  .cp-score {
    display: flex; flex-direction: column; align-items: flex-end; gap: 4px;
    flex: 0 0 auto;
  }
  .cp-score .label {
    font-size: 9px; font-weight: 600;
    color: #8A91A1;
    letter-spacing: 0.4px;
    line-height: 1;
  }
  .cp-score .value {
    font-size: 16px; font-weight: 700;
    color: #33A666;
    line-height: 1;
  }

  .cp-action {
    border-radius: 999px;
    padding: 8px 18px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    border: 1px solid transparent;
    flex: 0 0 auto;
    display: inline-flex; align-items: center;
  }
  .cp-action.primary {
    background: #008C8C;
    color: #FFFFFF;
  }
  .cp-action.primary:hover { background: #00787A; }
  .cp-action.secondary {
    background: #FFFFFF;
    color: #0D1B2A;
    border-color: #E4E5E8;
  }
  .cp-action.secondary:hover { background: #F5F6F7; }
</style>
</head>
<body>

<header class="cp-asmt-head">
  <div class="cp-asmt-title"><?php echo xlt('Assessments'); ?></div>
  <div class="cp-asmt-bullet">•</div>
  <div class="cp-asmt-meta">4 due, 8 completed</div>
  <div class="cp-asmt-spacer"></div>
  <button type="button" class="cp-asmt-cta">
    <span class="plus">+</span>
    <span><?php echo xlt('Assign Assessment'); ?></span>
  </button>
</header>

<div class="cp-asmt-body">
  <aside class="cp-asmt-side">
    <?php foreach ($categories as [$label, $count, $active]): ?>
      <div class="cp-cat<?php echo $active ? ' active' : ''; ?>">
        <span class="label"><?php echo text($label); ?></span>
        <span class="count"><?php echo text((string)$count); ?></span>
      </div>
    <?php endforeach; ?>
  </aside>

  <main class="cp-asmt-list">
    <?php foreach ($assessments as $a): ?>
      <article class="cp-card">
        <div class="cp-avatar <?php echo attr($a['status']); ?>">
          <?php
          echo $a['status'] === 'due'
              ? '!'
              : ($a['status'] === 'done' ? '✓' : '🕒');
          ?>
        </div>
        <div class="cp-card-info">
          <div class="cp-card-titlerow">
            <span class="cp-card-title"><?php echo text($a['title']); ?></span>
            <span class="cp-card-pill"><?php echo text($a['cat']); ?></span>
          </div>
          <div class="cp-card-desc"><?php echo text($a['desc']); ?></div>
          <div class="cp-card-meta"><?php echo text($a['meta']); ?></div>
        </div>
        <div class="cp-card-spacer"></div>
        <?php if ($a['status'] === 'due'): ?>
          <span class="cp-status-pill due"><?php echo xlt('DUE NOW'); ?></span>
          <button type="button" class="cp-action primary"><?php echo xlt('Begin'); ?> →</button>
        <?php elseif ($a['status'] === 'scheduled'): ?>
          <span class="cp-status-pill scheduled"><?php echo xlt('SCHEDULED'); ?></span>
          <button type="button" class="cp-action secondary"><?php echo xlt('Resend'); ?></button>
        <?php else: /* done */ ?>
          <div class="cp-score">
            <span class="label"><?php echo xlt('SCORE'); ?></span>
            <span class="value"><?php echo text($a['score_value']); ?></span>
          </div>
          <button type="button" class="cp-action secondary"><?php echo xlt('View'); ?></button>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </main>
</div>

</body>
</html>
