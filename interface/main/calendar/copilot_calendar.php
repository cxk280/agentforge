<?php

/**
 * Calendar landing page — implements Screen 6 of the AgentForge mockups.
 *
 * Renders the mockup's month-grid view with a page header (current month
 * label, prev/next nav, Today, Day/Week/Month toggle, New Appointment).
 * The actual scheduling functionality remains at /interface/main/main_info.php.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

// Static demo data matching Figma Screen 6 — November 2026, today is the 12th.
// Format: day-of-month => [event-color-hex, "9:00 LastName"]
$todayDate    = 12;
$daysInMonth  = 30;
$startCol     = 0; // Nov 1, 2026 falls on a Sunday
$totalCells   = 35;
$events       = [
    4  => ['#5FD0D0', '9:00 Smith'],
    10 => ['#5FD0D0', '9:00 Chen'],
    11 => ['#D93838', '9:00 Annual'],
    13 => ['#5FD0D0', '9:00 Lopez'],
    14 => ['#008C8C', '9:00 Patel'],
    17 => ['#008C8C', '9:00 Brown'],
    18 => ['#D93838', '9:00 Urgent'],
    20 => ['#5FD0D0', '9:00 Davis'],
    22 => ['#008C8C', '9:00 Wilson'],
    24 => ['#FA8C33', '9:00 Reviews'],
    26 => ['#5FD0D0', '9:00 Miller'],
    28 => ['#008C8C', '9:00 Garcia'],
    30 => ['#5FD0D0', '9:00 Wong'],
];

$dayLabels = ['SUN','MON','TUE','WED','THU','FRI','SAT'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Calendar'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F7F8;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
  }
  button { font-family: inherit; }

  /* ── Page header (full width — matches the Figma intent and the user's
   *    standing rule that headers span full width edge to edge) ── */
  .cp-cal-header {
    width: 100%;
    height: 64px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 16px;
  }
  .cp-cal-title-block { display: flex; align-items: center; gap: 14px; }
  .cp-cal-title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-cal-nav { display: flex; align-items: center; gap: 4px; }
  .cp-cal-nav-btn {
    width: 32px; height: 32px;
    border-radius: 50%;
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    color: #4F5662;
    font-size: 16px; font-weight: 700;
    line-height: 1;
    display: flex; align-items: center; justify-content: center;
    padding: 0;
    cursor: pointer;
  }
  .cp-cal-nav-btn:hover { background: #F5F7F8; }
  .cp-cal-today {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 13px; font-weight: 500;
    color: #0D1B2A;
    line-height: 1;
    cursor: pointer;
  }
  .cp-cal-today:hover { background: #F5F7F8; }
  .cp-cal-spacer { flex: 1; }

  .cp-cal-view {
    display: inline-flex;
    background: #F5F7F8;
    border-radius: 999px;
    padding: 4px;
  }
  .cp-cal-view-item {
    padding: 4px 14px;
    border-radius: 999px;
    font-size: 12px; font-weight: 500;
    color: #4F5662;
    line-height: 1.2;
    cursor: pointer;
    border: none;
    background: transparent;
  }
  .cp-cal-view-item.active {
    background: #FFFFFF;
    color: #008C8C;
    box-shadow: 0 1px 2px rgba(13, 27, 42, 0.05);
  }

  .cp-cal-new {
    display: inline-flex; align-items: center; gap: 6px;
    background: #008C8C;
    color: #FFFFFF;
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 13px; font-weight: 600;
    border: none;
    cursor: pointer;
    line-height: 1;
  }
  .cp-cal-new:hover { background: #006F6F; }
  .cp-cal-new-plus { font-size: 16px; font-weight: 700; line-height: 1; }

  /* ── Calendar grid wrapper — 24px gutter on all sides ── */
  .cp-cal-wrap { padding: 24px; }
  .cp-cal-grid {
    width: 100%;
    background: #E4E5E8;            /* line color shows in 1px gaps */
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    grid-template-rows: 36px repeat(5, 135.8px);
    gap: 1px;
  }
  .cp-cal-day-label {
    background: #F5F7F8;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600;
    color: #8A91A0;
    letter-spacing: 0.6px;
  }
  .cp-cal-cell {
    background: #FFFFFF;
    padding: 8px 10px;
    position: relative;
  }
  /* Today: 5% teal on white, pre-mixed to #F2F8F8 to stay opaque over the
   * gray gap-color background */
  .cp-cal-cell.today { background: #F2F8F8; }

  .cp-cal-cell-date {
    display: inline-block;
    font-size: 12px; font-weight: 500;
    color: #0D1B2A;
    line-height: 1;
  }
  .cp-cal-cell.today .cp-cal-cell-date {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: #008C8C;
    color: #FFFFFF;
    font-weight: 600;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px;
    line-height: 1;
  }

  .cp-cal-event {
    display: flex;
    align-items: center;
    margin-top: 4px;
    height: 22px;
    padding: 0 8px;
    border-radius: 6px;
    color: #FFFFFF;
    font-size: 10px; font-weight: 500;
    opacity: 0.92;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
</style>
</head>
<body>

<header class="cp-cal-header">
  <div class="cp-cal-title-block">
    <div class="cp-cal-title"><?php echo xlt('November 2026'); ?></div>
    <div class="cp-cal-nav">
      <button class="cp-cal-nav-btn" type="button" aria-label="<?php echo xla('Previous month'); ?>">‹</button>
      <button class="cp-cal-nav-btn" type="button" aria-label="<?php echo xla('Next month'); ?>">›</button>
    </div>
    <button class="cp-cal-today" type="button"><?php echo xlt('Today'); ?></button>
  </div>
  <div class="cp-cal-spacer"></div>
  <div class="cp-cal-view" role="tablist">
    <button class="cp-cal-view-item" role="tab" type="button"><?php echo xlt('Day'); ?></button>
    <button class="cp-cal-view-item" role="tab" type="button"><?php echo xlt('Week'); ?></button>
    <button class="cp-cal-view-item active" role="tab" type="button" aria-selected="true"><?php echo xlt('Month'); ?></button>
  </div>
  <button class="cp-cal-new" type="button">
    <span class="cp-cal-new-plus">+</span>
    <span><?php echo xlt('New Appointment'); ?></span>
  </button>
</header>

<div class="cp-cal-wrap">
  <div class="cp-cal-grid">
    <?php foreach ($dayLabels as $d): ?>
      <div class="cp-cal-day-label"><?php echo text($d); ?></div>
    <?php endforeach; ?>
    <?php for ($i = 0; $i < $totalCells; $i++):
      $date    = $i - $startCol + 1;
      $inMonth = ($date >= 1 && $date <= $daysInMonth);
      $isToday = $inMonth && $date === $todayDate;
      $event   = ($inMonth && isset($events[$date])) ? $events[$date] : null;
      ?>
      <div class="cp-cal-cell<?php echo $isToday ? ' today' : ''; ?>">
        <?php if ($inMonth): ?>
          <span class="cp-cal-cell-date"><?php echo (int)$date; ?></span>
          <?php if ($event): ?>
            <div class="cp-cal-event" style="background-color: <?php echo attr($event[0]); ?>;">
              <?php echo text($event[1]); ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

</body>
</html>
