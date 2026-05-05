<?php

/**
 * Copilot Calendar — live month-grid view backed by openemr_postcalendar_events.
 *
 * Replaces the W1 static mock. Reads events for the current logged-in
 * user (pc_aid = $_SESSION['authUserID']) with optional ?ym=YYYY-MM
 * navigation. CRUD lands through copilot_calendar_api.php so the modal
 * can hit a tiny AJAX surface rather than wrestling with the legacy
 * add_edit_event.php frame stack.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('patients', 'appt')) {
    http_response_code(403);
    echo xlt("Not authorized");
    exit;
}

// ─── Resolve month being displayed (?ym=YYYY-MM) ─────────────────────────
$ym = $_GET['ym'] ?? '';
if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
    $year  = (int)date('Y');
    $month = (int)date('n');
} else {
    $year  = (int)$m[1];
    $month = (int)$m[2];
}
$firstOfMonth = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth  = (int)date('t', strtotime($firstOfMonth));
$startCol     = (int)date('w', strtotime($firstOfMonth));   // 0 = Sun
$totalCells   = (int)ceil(($startCol + $daysInMonth) / 7) * 7;
$today        = date('Y-m-d');
$todayY       = (int)date('Y');
$todayM       = (int)date('n');
$todayD       = (int)date('j');
$isCurrentMonth = ($year === $todayY && $month === $todayM);

$prevYM = date('Y-m', strtotime("$firstOfMonth -1 month"));
$nextYM = date('Y-m', strtotime("$firstOfMonth +1 month"));

$activeUserId = (int)($_SESSION['authUserID'] ?? 0);
$activeUserName = '';
if ($activeUserId) {
    $r = sqlQuery("SELECT fname, lname, username FROM users WHERE id = ?", [$activeUserId]);
    if ($r) {
        $activeUserName = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''))
            ?: (string)($r['username'] ?? '');
    }
}

// ─── Pull events for the active user across this month ──────────────────
//
// Filter: pc_aid = active user. (Memory note: currently all logins
// resolve to Administrator, so all users see the same set today; the
// multi-user-login task lifts that.)
$rangeStart = $firstOfMonth;
$rangeEnd   = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

$rs = sqlStatement(
    "SELECT e.pc_eid, e.pc_title, e.pc_eventDate, e.pc_startTime, e.pc_endTime,
            e.pc_apptstatus, e.pc_pid, e.pc_aid, e.pc_hometext,
            e.pc_catid,
            COALESCE(c.pc_catcolor, '#5FD0D0') AS color,
            COALESCE(c.pc_catname,  'Visit')   AS catname,
            p.fname AS pat_fname, p.lname AS pat_lname
       FROM openemr_postcalendar_events e
  LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = e.pc_catid
  LEFT JOIN patient_data p
              ON (p.pid IS NOT NULL AND e.pc_pid IS NOT NULL
                  AND e.pc_pid != '' AND p.pid = e.pc_pid)
      WHERE e.pc_eventDate BETWEEN ? AND ?
        AND (e.pc_aid = ? OR ? = 0)
      ORDER BY e.pc_eventDate, e.pc_startTime",
    [$rangeStart, $rangeEnd, $activeUserId, $activeUserId]
);

// Group by day-of-month for the grid render. Each cell can stack up to
// `MAX_PER_CELL` events visually; the rest collapse into a "+N more".
$MAX_PER_CELL = 3;
$eventsByDay = [];
$allEvents = []; // for the JS modal lookup
while ($r = sqlFetchArray($rs)) {
    $day = (int)substr($r['pc_eventDate'] ?? '', 8, 2);
    if (!isset($eventsByDay[$day])) {
        $eventsByDay[$day] = [];
    }
    $patName = trim(($r['pat_fname'] ?? '') . ' ' . ($r['pat_lname'] ?? ''));
    $startTime = $r['pc_startTime'] ? substr($r['pc_startTime'], 0, 5) : '';
    $title = $r['pc_title'] ?: $r['catname'];
    $label = $startTime ? ($startTime . ' ' . ($patName ?: $title)) : ($patName ?: $title);
    $row = [
        'eid'   => (int)$r['pc_eid'],
        'date'  => $r['pc_eventDate'],
        'start' => $startTime,
        'end'   => $r['pc_endTime'] ? substr($r['pc_endTime'], 0, 5) : '',
        'title' => $title,
        'patient'    => $patName,
        'patient_id' => $r['pc_pid'],
        'color' => $r['color'] ?: '#5FD0D0',
        'status'=> $r['pc_apptstatus'],
        'notes' => $r['pc_hometext'] ?: '',
        'label' => $label,
        'aid'   => (int)$r['pc_aid'],
    ];
    $eventsByDay[$day][] = $row;
    $allEvents[(int)$r['pc_eid']] = $row;
}

$dayLabels = ['SUN','MON','TUE','WED','THU','FRI','SAT'];
$monthLabel = date('F Y', strtotime($firstOfMonth));
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
    overflow-x: hidden;
  }
  button { font-family: inherit; }

  /* ── Header (full-width per the standing rule) ─────────────────────── */
  .cp-cal-header {
    width: 100%; height: 64px; background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    display: flex; align-items: center; padding: 0 24px; gap: 16px;
  }
  .cp-cal-title-block { display: flex; align-items: center; gap: 14px; }
  .cp-cal-title { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-cal-nav { display: flex; align-items: center; gap: 4px; }
  .cp-cal-nav-btn {
    width: 32px; height: 32px; border-radius: 50%;
    border: 1px solid #E4E5E8; background: #FFFFFF;
    color: #4F5662; font-size: 16px; font-weight: 700;
    line-height: 1; display: flex; align-items: center; justify-content: center;
    padding: 0; cursor: pointer;
    text-decoration: none;
  }
  .cp-cal-nav-btn:hover { background: #F5F7F8; }
  .cp-cal-today {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 999px;
    padding: 6px 14px; font-size: 13px; font-weight: 500; color: #0D1B2A;
    line-height: 1; cursor: pointer; text-decoration: none;
  }
  .cp-cal-today:hover { background: #F5F7F8; }
  .cp-cal-spacer { flex: 1; }
  .cp-cal-userpill {
    background: #F2F8F8; border: 1px solid #C7E5E5;
    color: #006F6F; font-size: 12px; padding: 4px 10px; border-radius: 999px;
  }
  .cp-cal-new {
    display: inline-flex; align-items: center; gap: 6px;
    background: #008C8C; color: #FFFFFF; border-radius: 999px;
    padding: 8px 16px; font-size: 13px; font-weight: 600;
    border: none; cursor: pointer; line-height: 1;
  }
  .cp-cal-new:hover { background: #006F6F; }
  .cp-cal-new-plus { font-size: 16px; font-weight: 700; line-height: 1; }

  /* ── Grid ──────────────────────────────────────────────────────────── */
  .cp-cal-wrap { padding: 24px; }
  .cp-cal-grid {
    width: 100%; background: #E4E5E8;
    border: 1px solid #E4E5E8; border-radius: 12px;
    overflow: hidden; display: grid; grid-template-columns: repeat(7, 1fr);
    grid-auto-rows: 135px; gap: 1px;
  }
  .cp-cal-day-label {
    background: #F5F7F8; height: 36px;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600; color: #8A91A0; letter-spacing: .6px;
  }
  .cp-cal-cell {
    background: #FFFFFF; padding: 8px 10px; position: relative;
    overflow: hidden; cursor: pointer;
  }
  .cp-cal-cell:hover { background: #FAFBFC; }
  .cp-cal-cell.empty { background: #F8F9FA; cursor: default; }
  .cp-cal-cell.today { background: #F2F8F8; }
  .cp-cal-cell-date {
    display: inline-block; font-size: 12px; font-weight: 500;
    color: #0D1B2A; line-height: 1;
  }
  .cp-cal-cell.today .cp-cal-cell-date {
    width: 22px; height: 22px; border-radius: 50%;
    background: #008C8C; color: #FFFFFF; font-weight: 600;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; line-height: 1;
  }
  .cp-cal-event {
    display: flex; align-items: center; margin-top: 4px;
    height: 22px; padding: 0 8px; border-radius: 6px;
    color: #FFFFFF; font-size: 10px; font-weight: 500; opacity: .92;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .cp-cal-event:hover { opacity: 1; }
  .cp-cal-more {
    margin-top: 4px; font-size: 10px; color: #4F5662;
  }

  /* ── Modal ─────────────────────────────────────────────────────────── */
  .cp-cal-modal-bg {
    position: fixed; inset: 0; background: rgba(13, 27, 42, .35);
    display: none; align-items: center; justify-content: center;
    z-index: 50;
  }
  .cp-cal-modal-bg.open { display: flex; }
  .cp-cal-modal {
    background: #FFFFFF; border-radius: 14px;
    width: 460px; max-width: 90vw;
    box-shadow: 0 16px 48px rgba(13, 27, 42, .25);
    padding: 22px 24px;
  }
  .cp-cal-modal h2 {
    margin: 0 0 12px; font-size: 17px; font-weight: 700;
  }
  .cp-cal-row { margin-bottom: 12px; display: flex; gap: 8px; }
  .cp-cal-field { flex: 1; display: flex; flex-direction: column; gap: 4px; }
  .cp-cal-field label {
    font-size: 11px; font-weight: 600; color: #4F5662;
    text-transform: uppercase; letter-spacing: .04em;
  }
  .cp-cal-field input,
  .cp-cal-field textarea,
  .cp-cal-field select {
    border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 8px 10px; font-size: 13px; font-family: inherit;
  }
  .cp-cal-field textarea { resize: vertical; min-height: 60px; }
  .cp-cal-actions {
    display: flex; align-items: center; gap: 8px; margin-top: 16px;
  }
  .cp-cal-actions .spacer { flex: 1; }
  .cp-cal-btn {
    border: 1px solid #E4E5E8; background: #FFFFFF; color: #0D1B2A;
    border-radius: 999px; padding: 8px 16px; font-size: 13px; cursor: pointer;
  }
  .cp-cal-btn.primary { background: #008C8C; color: #FFFFFF; border-color: #008C8C; }
  .cp-cal-btn.primary:hover { background: #006F6F; }
  .cp-cal-btn.danger  { color: #B7432A; border-color: #ECC8BE; }
  .cp-cal-btn.danger:hover  { background: #FBEFEB; }
  .cp-cal-error {
    background: #FBEFEB; border: 1px solid #ECC8BE; color: #B7432A;
    padding: 8px 10px; border-radius: 8px; font-size: 12px;
    margin: 0 0 12px; display: none;
  }
  .cp-cal-error.show { display: block; }
</style>
</head>
<body>

<header class="cp-cal-header">
  <div class="cp-cal-title-block">
    <div class="cp-cal-title"><?php echo text($monthLabel); ?></div>
    <div class="cp-cal-nav">
      <a class="cp-cal-nav-btn" href="?ym=<?php echo urlencode($prevYM); ?>"
         aria-label="<?php echo xla('Previous month'); ?>">‹</a>
      <a class="cp-cal-nav-btn" href="?ym=<?php echo urlencode($nextYM); ?>"
         aria-label="<?php echo xla('Next month'); ?>">›</a>
    </div>
    <a class="cp-cal-today" href="?ym=<?php echo urlencode(date('Y-m')); ?>">
      <?php echo xlt('Today'); ?>
    </a>
  </div>
  <div class="cp-cal-spacer"></div>
  <span class="cp-cal-userpill">
    <?php echo xlt('Showing'); ?>:
    <?php echo text($activeUserName ?: 'all users'); ?>
  </span>
  <button class="cp-cal-new" type="button" id="cp-cal-new-btn">
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
      $isToday = $inMonth && $isCurrentMonth && $date === $todayD;
      $cellDate = $inMonth ? sprintf('%04d-%02d-%02d', $year, $month, $date) : '';
      $cellEvents = $eventsByDay[$date] ?? [];
      ?>
      <div class="cp-cal-cell<?php echo $inMonth ? '' : ' empty'; ?><?php echo $isToday ? ' today' : ''; ?>"
           data-date="<?php echo attr($cellDate); ?>"
           <?php echo $inMonth ? 'role="button" tabindex="0"' : ''; ?>>
        <?php if ($inMonth): ?>
          <span class="cp-cal-cell-date"><?php echo (int)$date; ?></span>
          <?php
          $shown = 0;
          foreach ($cellEvents as $ev):
              if ($shown >= $MAX_PER_CELL) break;
              $shown++;
          ?>
            <div class="cp-cal-event"
                 style="background-color: <?php echo attr($ev['color']); ?>;"
                 data-eid="<?php echo (int)$ev['eid']; ?>"
                 title="<?php echo attr($ev['label']); ?>">
              <?php echo text($ev['label']); ?>
            </div>
          <?php endforeach; ?>
          <?php if (count($cellEvents) > $MAX_PER_CELL): ?>
            <div class="cp-cal-more">+ <?php echo (int)(count($cellEvents) - $MAX_PER_CELL); ?> <?php echo xlt('more'); ?></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

<!-- ── Modal ──────────────────────────────────────────────────────── -->
<div class="cp-cal-modal-bg" id="cp-cal-modal-bg" role="dialog" aria-modal="true">
  <form class="cp-cal-modal" id="cp-cal-modal">
    <h2 id="cp-cal-modal-title"><?php echo xlt('New Appointment'); ?></h2>
    <div class="cp-cal-error" id="cp-cal-error"></div>
    <input type="hidden" name="eid" id="cp-cal-eid" value="">
    <div class="cp-cal-row">
      <div class="cp-cal-field">
        <label for="cp-cal-titlein"><?php echo xlt('Title'); ?></label>
        <input type="text" id="cp-cal-titlein" name="title" required maxlength="150"
               placeholder="<?php echo xla('e.g. Annual physical'); ?>">
      </div>
    </div>
    <div class="cp-cal-row">
      <div class="cp-cal-field">
        <label for="cp-cal-datein"><?php echo xlt('Date'); ?></label>
        <input type="date" id="cp-cal-datein" name="event_date" required>
      </div>
      <div class="cp-cal-field">
        <label for="cp-cal-startin"><?php echo xlt('Start'); ?></label>
        <input type="time" id="cp-cal-startin" name="start_time" required>
      </div>
      <div class="cp-cal-field">
        <label for="cp-cal-endin"><?php echo xlt('End'); ?></label>
        <input type="time" id="cp-cal-endin" name="end_time" required>
      </div>
    </div>
    <div class="cp-cal-row">
      <div class="cp-cal-field">
        <label for="cp-cal-pidin"><?php echo xlt('Patient (PID, optional)'); ?></label>
        <input type="number" id="cp-cal-pidin" name="patient_id" min="0"
               placeholder="<?php echo xla('e.g. 1 for Ted Shaw'); ?>">
      </div>
      <div class="cp-cal-field">
        <label for="cp-cal-statusin"><?php echo xlt('Status'); ?></label>
        <select id="cp-cal-statusin" name="status">
          <option value="-">— Scheduled</option>
          <option value="@">@ Arrived</option>
          <option value="~">~ Arrived late</option>
          <option value=">">&gt; Checked in</option>
          <option value="$">$ Charges entered</option>
          <option value="x">x No show</option>
          <option value="?">? Reschedule</option>
        </select>
      </div>
    </div>
    <div class="cp-cal-row">
      <div class="cp-cal-field">
        <label for="cp-cal-notesin"><?php echo xlt('Notes'); ?></label>
        <textarea id="cp-cal-notesin" name="notes" maxlength="2000"></textarea>
      </div>
    </div>
    <div class="cp-cal-actions">
      <button type="button" class="cp-cal-btn danger" id="cp-cal-delete-btn" style="display:none">
        <?php echo xlt('Delete'); ?>
      </button>
      <span class="spacer"></span>
      <button type="button" class="cp-cal-btn" id="cp-cal-cancel-btn"><?php echo xlt('Cancel'); ?></button>
      <button type="submit" class="cp-cal-btn primary" id="cp-cal-save-btn"><?php echo xlt('Save'); ?></button>
    </div>
  </form>
</div>

<script>
(function () {
  const API = './copilot_calendar_api.php';
  const events = <?php echo json_encode($allEvents, JSON_UNESCAPED_SLASHES); ?>;
  const modalBg = document.getElementById('cp-cal-modal-bg');
  const form = document.getElementById('cp-cal-modal');
  const eidIn = document.getElementById('cp-cal-eid');
  const titleIn = document.getElementById('cp-cal-titlein');
  const dateIn = document.getElementById('cp-cal-datein');
  const startIn = document.getElementById('cp-cal-startin');
  const endIn = document.getElementById('cp-cal-endin');
  const pidIn = document.getElementById('cp-cal-pidin');
  const statusIn = document.getElementById('cp-cal-statusin');
  const notesIn = document.getElementById('cp-cal-notesin');
  const titleEl = document.getElementById('cp-cal-modal-title');
  const errorEl = document.getElementById('cp-cal-error');
  const deleteBtn = document.getElementById('cp-cal-delete-btn');

  function clearError() { errorEl.textContent = ''; errorEl.classList.remove('show'); }
  function showError(msg) { errorEl.textContent = msg; errorEl.classList.add('show'); }

  function openModal({ eid, date }) {
    clearError();
    if (eid && events[eid]) {
      const e = events[eid];
      titleEl.textContent = 'Edit appointment';
      eidIn.value = eid;
      titleIn.value = e.title || '';
      dateIn.value  = e.date  || '';
      startIn.value = e.start || '09:00';
      endIn.value   = e.end   || '09:30';
      pidIn.value   = e.patient_id || '';
      statusIn.value = e.status || '-';
      notesIn.value = e.notes || '';
      deleteBtn.style.display = '';
    } else {
      titleEl.textContent = 'New appointment';
      eidIn.value = '';
      titleIn.value = '';
      dateIn.value  = date || new Date().toISOString().slice(0, 10);
      startIn.value = '09:00';
      endIn.value   = '09:30';
      pidIn.value   = '';
      statusIn.value = '-';
      notesIn.value = '';
      deleteBtn.style.display = 'none';
    }
    modalBg.classList.add('open');
    setTimeout(() => titleIn.focus(), 50);
  }
  function closeModal() {
    modalBg.classList.remove('open');
  }

  // Cell click → new event for that date.
  document.querySelectorAll('.cp-cal-cell:not(.empty)').forEach(cell => {
    cell.addEventListener('click', evt => {
      // Event chip clicks bubble through, but their handler ran first
      // and may have already opened the editor.
      if (evt.target.closest('.cp-cal-event')) return;
      openModal({ eid: null, date: cell.dataset.date });
    });
  });
  document.querySelectorAll('.cp-cal-event').forEach(chip => {
    chip.addEventListener('click', evt => {
      evt.stopPropagation();
      openModal({ eid: parseInt(chip.dataset.eid, 10) });
    });
  });
  document.getElementById('cp-cal-new-btn').addEventListener('click',
    () => openModal({ eid: null, date: null }));
  document.getElementById('cp-cal-cancel-btn').addEventListener('click', closeModal);
  modalBg.addEventListener('click', evt => { if (evt.target === modalBg) closeModal(); });

  async function postJson(payload) {
    const fd = new FormData();
    Object.entries(payload).forEach(([k, v]) => fd.append(k, v ?? ''));
    const resp = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
    let body;
    try { body = await resp.json(); } catch { body = { ok: false, error: 'Bad JSON from server' }; }
    if (!resp.ok || !body.ok) {
      throw new Error(body.error || ('HTTP ' + resp.status));
    }
    return body;
  }

  form.addEventListener('submit', async evt => {
    evt.preventDefault();
    clearError();
    try {
      await postJson({
        action: eidIn.value ? 'update' : 'create',
        eid: eidIn.value,
        title: titleIn.value,
        event_date: dateIn.value,
        start_time: startIn.value,
        end_time: endIn.value,
        patient_id: pidIn.value,
        status: statusIn.value,
        notes: notesIn.value,
      });
      window.location.reload();
    } catch (err) {
      showError(err.message || String(err));
    }
  });

  deleteBtn.addEventListener('click', async () => {
    if (!eidIn.value) return;
    if (!confirm('Delete this appointment?')) return;
    clearError();
    try {
      await postJson({ action: 'delete', eid: eidIn.value });
      window.location.reload();
    } catch (err) {
      showError(err.message || String(err));
    }
  });

  // Esc to close.
  document.addEventListener('keydown', evt => {
    if (evt.key === 'Escape' && modalBg.classList.contains('open')) closeModal();
  });
})();
</script>

</body>
</html>
