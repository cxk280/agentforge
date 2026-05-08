<?php

/**
 * Patient Documents — implements Screen 15 of the AgentForge mockups.
 *
 * Renders the "Documents" navtab content: header bar, left category
 * sidebar, four "Recent" thumbnail cards, and an "Earlier" list.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;

$_cpSession = SessionWrapperFactory::getInstance()->getActiveSession();

// ─── Live data: real documents.* rows for the active patient ────────────
// Surfaces W2 uploads (and any other real documents) above the static W1
// mock sections, each linked to the bbox-overlay viewer
// (copilot_doc_viewer.php). When the agent's /extract has been run on a
// document, its citations are visible there.
//
// pid resolution: prefer the OpenEMR session, then ?pid=… on the URL
// (covers direct navigation outside OpenEMR's frame chrome — e.g. the
// headless verifier and the demo recording).
// Read the active patient via OpenEMR's session wrapper. Modern
// OpenEMR uses Symfony's namespaced session — `$_SESSION['pid']` is
// always null because the real pid lives at `$_SESSION['OpenEMR']['pid']`.
// Going through SessionWrapperFactory keeps this code working
// regardless of whether the session storage is the namespaced bag,
// the raw $_SESSION fallback, or any future replacement.
$_cpDocSession = SessionWrapperFactory::getInstance()->getActiveSession();
$activePid = (int)($_cpDocSession->get('pid') ?? 0);
if ($activePid <= 0 && isset($_GET['pid'])) {
    $candidate = (int)$_GET['pid'];
    if ($candidate > 0) {
        // Confirm it's a real patient before trusting the URL param.
        $exists = sqlQuery("SELECT pid FROM patient_data WHERE pid = ?", [$candidate]);
        if ($exists) {
            $activePid = $candidate;
            // Use OpenEMR's standard pid-setter so the namespaced
            // session writes through correctly (and any side-effects
            // like clearing the encounter on a pid change still fire).
            setpid($candidate);
        }
    }
}
$liveDocs = [];
if ($activePid > 0) {
    // Left-join cp_extraction_runs to surface whether each document
    // already has a successful extraction. The Extract button uses
    // this flag to render in its terminal "Extraction complete"
    // state across page navigations — no JS-side cache needed because
    // the DB is the persistent source of truth.
    $rs = sqlStatement(
        "SELECT d.id, d.name, d.mimetype, d.date,
                MAX(CASE WHEN r.status = 'success' THEN 1 ELSE 0 END) AS has_extracted
           FROM documents d
           LEFT JOIN cp_extraction_runs r ON r.document_id = d.id
          WHERE d.foreign_id = ? AND d.deleted = 0
          GROUP BY d.id, d.name, d.mimetype, d.date
          ORDER BY d.date DESC, d.id DESC
          LIMIT 12",
        [$activePid]
    );
    while ($r = sqlFetchArray($rs)) {
        $liveDocs[] = [
            'id'            => (int)$r['id'],
            'name'          => (string)($r['name'] ?? "Document #{$r['id']}"),
            'mime'          => (string)($r['mimetype'] ?? 'application/octet-stream'),
            'date'          => $r['date'] ? substr($r['date'], 0, 10) : '',
            'has_extracted' => (int)($r['has_extracted'] ?? 0) === 1,
        ];
    }
}

// [icon, label, count, active]
$categories = [
    ['📁', 'All Documents',    47, true],
    ['🩺', 'Clinical Notes',   18, false],
    ['🧪', 'Lab Reports',      12, false],
    ['📋', 'Imaging',           5, false],
    ['💊', 'Prescriptions',     4, false],
    ['🏥', 'Referrals',         3, false],
    ['📄', 'Insurance / ID',    3, false],
    ['✏',  'Patient Forms',     2, false],
];

// Recent thumbnail cards
$recent = [
    ['cat' => 'Lab Report',    'cat_kind' => 'lab',      'title' => 'CMP + CBC Results',     'meta' => 'Apr 12, 2026 • 248 KB • LabCorp'],
    ['cat' => 'Clinical Note', 'cat_kind' => 'clinical', 'title' => 'Annual Physical Note',  'meta' => 'Feb 18, 2026 • 32 KB • Dr. Rivera'],
    ['cat' => 'Imaging',       'cat_kind' => 'imaging',  'title' => 'Echocardiogram Report', 'meta' => 'Feb 18, 2026 • 1.2 MB • Riverside Imaging'],
    ['cat' => 'Referral',      'cat_kind' => 'referral', 'title' => 'Endocrinology Referral','meta' => 'Nov 20, 2025 • 18 KB • Dr. Rivera'],
];

// Earlier list rows
$earlier = [
    ['icon' => '🧪', 'title' => 'A1C + Lipid Panel',           'cat' => 'Lab Report',     'src' => 'LabCorp',         'date' => 'Nov 5, 2025',  'size' => '184 KB'],
    ['icon' => '📋', 'title' => 'Knee X-Ray (Bilateral)',      'cat' => 'Imaging',        'src' => 'Riverside Imaging','date' => 'Oct 22, 2025', 'size' => '2.4 MB'],
    ['icon' => '🩺', 'title' => 'Telehealth Note — Lab Review','cat' => 'Clinical Note',  'src' => 'Dr. S. Chen',     'date' => 'Aug 22, 2025', 'size' => '24 KB'],
    ['icon' => '📄', 'title' => 'Insurance Card (front)',      'cat' => 'Insurance / ID', 'src' => 'Patient upload',  'date' => 'Jul 15, 2025', 'size' => '1.1 MB'],
    ['icon' => '💊', 'title' => 'Rx — Lisinopril 5mg → 10mg',  'cat' => 'Prescription',   'src' => 'Dr. Rivera',      'date' => 'Apr 1, 2025',  'size' => '18 KB'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Documents'); ?></title>
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

  /* ── Header bar ──────────────────────────────────────────────────────── */
  .cp-doc-head {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    height: 60px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-doc-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-doc-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-doc-meta { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-doc-spacer { flex: 1; }
  .cp-doc-pill {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 7px 12px;
    font-size: 12px;
    color: #4F5763;
    line-height: 1;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-doc-pill .icon { color: #8A91A1; font-size: 12px; }
  .cp-doc-pill:hover { background: #F5F6F7; }
  .cp-doc-upload {
    background: #008C8C;
    color: #FFFFFF;
    border: none;
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-doc-upload:hover { background: #00787A; }

  /* ── Two-column body ─────────────────────────────────────────────────── */
  .cp-doc-body { display: flex; align-items: stretch; min-height: calc(100vh - 60px); }
  .cp-doc-side {
    width: 220px;
    flex: 0 0 auto;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    border-bottom: 1px solid #E4E5E8;
    padding: 16px 0 24px;
  }
  .cp-side-head {
    padding: 8px 24px 12px;
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    line-height: 1;
  }
  .cp-cat {
    height: 38px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 10px;
    cursor: pointer;
  }
  .cp-cat .icon { font-size: 14px; line-height: 1; flex: 0 0 14px; }
  .cp-cat .label {
    flex: 1;
    font-size: 13px; font-weight: 500;
    color: #4F5763;
    line-height: 1;
  }
  .cp-cat .count {
    font-size: 11px; font-weight: 500;
    color: #8A91A1;
    line-height: 1;
  }
  .cp-cat:hover { background: #FAFBFC; }
  .cp-cat.active {
    background: rgba(0, 140, 140, 0.08);
  }
  .cp-cat.active .label { color: #008C8C; font-weight: 600; }
  .cp-cat.active .count { color: #008C8C; }

  /* ── Right column ────────────────────────────────────────────────────── */
  .cp-doc-main { flex: 1; padding: 20px 24px 32px; min-width: 0; display: flex; flex-direction: column; gap: 20px; }
  .cp-section-label {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    line-height: 1;
  }

  /* Recent grid (4 cards) */
  .cp-recent-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }
  .cp-recent-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    overflow: hidden;
    display: flex; flex-direction: column;
  }
  .cp-thumb {
    height: 96px;
    background: #F5F6F7;
    position: relative;
    display: flex; align-items: center; justify-content: center;
  }
  .cp-thumb .lines {
    width: 64%;
    display: flex; flex-direction: column; gap: 6px;
  }
  .cp-thumb .lines span {
    height: 4px; border-radius: 2px;
    background: #E4E5E8;
    display: block;
  }
  .cp-thumb .lines span:nth-child(2) { width: 90%; }
  .cp-thumb .lines span:nth-child(3) { width: 78%; }
  .cp-cat-pill {
    position: absolute;
    bottom: 8px; left: 12px;
    background: #FFFFFF;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 9px; font-weight: 600;
    line-height: 1.4;
    letter-spacing: 0.2px;
  }
  .cp-cat-pill.lab      { color: #33A666; }
  .cp-cat-pill.clinical { color: #008C8C; }
  .cp-cat-pill.imaging  { color: #4785D9; }
  .cp-cat-pill.referral { color: #FA8C33; }
  .cp-recent-card .body {
    padding: 12px 14px 14px;
    display: flex; flex-direction: column; gap: 6px;
  }
  .cp-recent-card .ttl {
    font-size: 13px; font-weight: 600;
    color: #0D1B2A;
    line-height: 1.2;
  }
  .cp-recent-card .met {
    font-size: 10px; color: #8A91A1;
    line-height: 1.4;
  }

  /* Earlier list */
  .cp-earlier-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    overflow: hidden;
    display: flex; flex-direction: column;
  }
  .cp-earl-row {
    display: flex; align-items: center; gap: 12px;
    height: 56px;
    padding: 0 16px;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-earl-row:last-child { border-bottom: none; }
  .cp-earl-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    background: #F5F6F7;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex: 0 0 auto;
  }
  .cp-earl-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
  .cp-earl-title {
    font-size: 13px; font-weight: 600;
    color: #0D1B2A;
    line-height: 1.2;
  }
  .cp-earl-sub {
    font-size: 10px; color: #8A91A1;
    line-height: 1.4;
    display: flex; align-items: center; gap: 6px;
  }
  .cp-earl-cat-pill {
    background: #F5F6F7;
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 9px; font-weight: 600;
    color: #4F5763;
    line-height: 1.4;
  }
  .cp-earl-spacer { flex: 1; }
  .cp-earl-date { font-size: 12px; color: #4F5763; line-height: 1; }
  .cp-earl-size { font-size: 11px; color: #8A91A1; line-height: 1; min-width: 56px; text-align: right; }
  .cp-earl-kebab {
    color: #8A91A1; font-size: 18px;
    line-height: 1;
    padding: 4px 8px;
    cursor: pointer;
    user-select: none;
  }
  .cp-earl-kebab:hover { color: #4F5763; }
</style>
</head>
<body>

<header class="cp-doc-head">
  <div class="cp-doc-title"><?php echo xlt('Documents'); ?></div>
  <div class="cp-doc-bullet">•</div>
  <div class="cp-doc-meta">47 files in 6 categories</div>
  <div class="cp-doc-spacer"></div>
  <button type="button" class="cp-doc-pill"><span class="icon">🔍</span><span><?php echo xlt('Search documents'); ?></span></button>
  <button type="button" class="cp-doc-pill"><span class="icon">⇅</span><span><?php echo xlt('Recent first'); ?></span></button>
  <button type="button" class="cp-doc-upload" id="cp-doc-upload-btn"><span>⬆</span><span><?php echo xlt('Upload'); ?></span></button>
  <input type="file" id="cp-doc-upload-input" accept=".pdf,application/pdf,image/*" style="display:none">
</header>
<?php if ($activePid > 0): ?>
<script>
(function () {
  const PATIENT_ID = <?php echo (int)$activePid; ?>;
  const CSRF = <?php echo json_encode(CsrfUtils::collectCsrfToken(session: $_cpSession)); ?>;
  const btn = document.getElementById('cp-doc-upload-btn');
  const input = document.getElementById('cp-doc-upload-input');
  if (!btn || !input) return;

  btn.addEventListener('click', () => input.click());
  input.addEventListener('change', async () => {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span>⬆</span><span>Uploading…</span>';

    const fd = new FormData();
    fd.append('file', file);
    fd.append('patient_id', String(PATIENT_ID));
    fd.append('category_id', '1');
    fd.append('csrf_token_form', CSRF);

    try {
      const resp = await fetch('./copilot_documents_upload.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      let data;
      try { data = await resp.json(); } catch { data = { ok: false, error: 'Bad JSON from server' }; }
      if (!resp.ok || !data.ok) {
        throw new Error(data.error || ('HTTP ' + resp.status));
      }
      btn.innerHTML = '<span>✓</span><span>Uploaded #' + data.doc_id + '</span>';
      setTimeout(() => { window.location.reload(); }, 600);
    } catch (err) {
      btn.disabled = false;
      btn.innerHTML = orig;
      alert('Upload failed: ' + (err.message || err));
    }
  });
})();
</script>
<?php else: ?>
<script>
(function () {
  const btn = document.getElementById('cp-doc-upload-btn');
  if (btn) btn.addEventListener('click', () => alert('Open a patient chart first — uploads are filed against an active patient.'));
})();
</script>
<?php endif; ?>

<div class="cp-doc-body">
  <aside class="cp-doc-side">
    <div class="cp-side-head"><?php echo xlt('CATEGORIES'); ?></div>
    <?php foreach ($categories as [$icon, $label, $count, $active]): ?>
      <div class="cp-cat<?php echo $active ? ' active' : ''; ?>">
        <span class="icon"><?php echo text($icon); ?></span>
        <span class="label"><?php echo text($label); ?></span>
        <span class="count"><?php echo text((string)$count); ?></span>
      </div>
    <?php endforeach; ?>
  </aside>

  <main class="cp-doc-main">
    <?php if (!empty($liveDocs)):
        // Agent backend URL — same source order as interface/copilot/index.php.
        $copilotBackend = $GLOBALS['copilot_backend_url']
            ?? (getenv('COPILOT_BACKEND_URL') ?: 'http://localhost:8400');
        $copilotBackend = rtrim((string)$copilotBackend, '/');
    ?>
      <div class="cp-section-label" style="display:flex;align-items:center;gap:8px">
        <?php echo xlt('LIVE — uploaded on this chart'); ?>
        <span style="background:#E5ECF7;color:#1f3a68;font-size:9px;font-weight:700;
                     padding:2px 6px;border-radius:4px;letter-spacing:.04em">FHIR</span>
      </div>
      <div class="cp-earlier-card" style="margin-bottom:16px">
        <?php foreach ($liveDocs as $d):
            $isPdf = stripos($d['mime'], 'pdf') !== false;
            $href = $isPdf
                ? "/interface/patient_file/documents/copilot_doc_viewer.php?docref=" . (int)$d['id']
                : "/controller.php?document&retrieve&patient_id=" . $activePid
                  . "&document_id=" . (int)$d['id']
                  . "&as_file=true&original_file=true";
            // Inline detection of doc_type from filename hints — overridable
            // via the dropdown on each row when the guess is wrong.
            $nameLower = strtolower($d['name']);
            $guessType = (strpos($nameLower, 'lab') !== false || strpos($nameLower, 'cmp') !== false ||
                          strpos($nameLower, 'panel') !== false || strpos($nameLower, 'a1c') !== false)
                ? 'lab_pdf'
                : ((strpos($nameLower, 'intake') !== false || strpos($nameLower, 'history') !== false)
                    ? 'intake_form'
                    : ((strpos($nameLower, 'med') !== false || strpos($nameLower, 'rx') !== false)
                        ? 'medication_list'
                        : 'lab_pdf'));
        ?>
          <div class="cp-earl-row" style="display:flex;align-items:center;gap:12px">
            <a href="<?php echo attr($href); ?>"
               target="<?php echo $isPdf ? '_self' : '_blank'; ?>"
               style="display:flex;flex:1;align-items:center;gap:12px;text-decoration:none;color:inherit;min-width:0">
              <div class="cp-earl-icon"><?php echo $isPdf ? '📄' : '📎'; ?></div>
              <div class="cp-earl-info">
                <div class="cp-earl-title"><?php echo text($d['name']); ?></div>
                <div class="cp-earl-sub">
                  <span class="cp-earl-cat-pill"><?php echo text($d['mime']); ?></span>
                  <span>doc #<?php echo (int)$d['id']; ?></span>
                  <?php if ($isPdf): ?>
                    <span style="color:#1f3a68;font-weight:600">→ open with bbox viewer</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="cp-earl-spacer"></div>
              <div class="cp-earl-date"><?php echo text($d['date']); ?></div>
            </a>
            <?php if ($isPdf): ?>
              <?php if (!empty($d['has_extracted'])): ?>
                <button type="button" class="cp-extract-btn cp-extract-done" disabled
                        title="<?php echo xla('Extraction already complete for this document.'); ?>"
                        style="background:#2d7a4f;color:#FFFFFF;border:none;
                               border-radius:999px;padding:6px 12px;font-size:11px;
                               font-weight:600;cursor:default;opacity:.95">
                  ✓ <?php echo xlt('Extraction complete'); ?>
                </button>
              <?php else: ?>
                <button type="button" class="cp-extract-btn"
                        data-docid="<?php echo (int)$d['id']; ?>"
                        data-doctype="<?php echo attr($guessType); ?>"
                        style="background:#008C8C;color:#FFFFFF;border:none;
                               border-radius:999px;padding:6px 12px;font-size:11px;
                               font-weight:600;cursor:pointer">
                  <?php echo xlt('Extract'); ?>
                </button>
              <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="cp-doc-delete-btn"
                    data-docid="<?php echo (int)$d['id']; ?>"
                    data-name="<?php echo attr($d['name']); ?>"
                    title="<?php echo xla('Delete this document and any extracted facts derived from it. Cannot be undone.'); ?>"
                    style="background:#FFFFFF;color:#a01d1d;border:1px solid #d6a3a3;
                           border-radius:999px;padding:6px 12px;font-size:11px;
                           font-weight:600;cursor:pointer">
              <?php echo xlt('Delete'); ?>
            </button>
          </div>
        <?php endforeach; ?>
      </div>
      <script>
        (function () {
          const BACKEND = <?php echo json_encode($copilotBackend); ?>;
          const PATIENT_ID = <?php echo (int)$activePid; ?>;
          // CSRF token is also emitted in the upload-button IIFE above
          // (line ~344). That one's locally-scoped to its IIFE so we
          // can't reach into it; just emit a fresh one here for our
          // own POSTs.
          const CSRF = <?php echo json_encode(CsrfUtils::collectCsrfToken(session: $_cpSession)); ?>;

          // ── Extract ──────────────────────────────────────────────
          document.querySelectorAll('.cp-extract-btn:not(.cp-extract-done)').forEach(btn => {
            btn.addEventListener('click', async () => {
              const docId = parseInt(btn.dataset.docid, 10);
              // doc_type comes from the server-side filename guess (see
              // $guessType in copilot_documents.php). The user-facing
              // dropdown that used to override it was removed — the
              // filename heuristic is reliable for the demo's naming
              // conventions, and showing a raw enum picker next to a
              // friendly Extract button looked like leaked dev plumbing.
              const docType = btn.dataset.doctype || 'lab_pdf';
              const orig = btn.textContent;
              btn.disabled = true; btn.textContent = 'Extracting…';
              try {
                const resp = await fetch(BACKEND + '/extract', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    patient_id: PATIENT_ID,
                    doc_type: docType,
                    document_id: docId,
                  }),
                });
                const data = await resp.json();
                if (!resp.ok) throw new Error(data.detail || ('HTTP ' + resp.status));
                // Match the upload-button UX: briefly flash a success
                // count, then reload. The reloaded page reads the new
                // `cp_extraction_runs` row via the LEFT JOIN at the
                // top of this file and renders the persistent
                // "Extraction complete" disabled button — same
                // mechanism that survives navigating away and back.
                //
                // (We previously tried a JS-only DOM mutation here
                // — class swap + textContent + inline styles — and
                // it tested green in headless, but in the real
                // browser the visible button stayed on "Extracting…"
                // until the user manually refreshed. The reload-after
                // pattern is what the upload button already does, so
                // reusing it gives identical UX with no surprises.)
                btn.textContent = `✓ ${data.fact_count} facts extracted`;
                btn.style.background = '#2d7a4f';
                setTimeout(() => { window.location.reload(); }, 700);
              } catch (err) {
                btn.disabled = false;
                btn.textContent = orig;
                alert('Extraction failed: ' + (err.message || err));
              }
            });
          });

          // ── Delete ───────────────────────────────────────────────
          document.querySelectorAll('.cp-doc-delete-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
              const docId = parseInt(btn.dataset.docid, 10);
              const name = btn.dataset.name || `Document #${docId}`;
              if (!confirm(`Delete "${name}" and any extracted facts derived from it?\n\nThis cannot be undone.`)) {
                return;
              }
              const orig = btn.textContent;
              btn.disabled = true; btn.textContent = 'Deleting…';
              try {
                const fd = new FormData();
                fd.append('docref', String(docId));
                fd.append('csrf_token_form', CSRF);
                const resp = await fetch('./copilot_documents_delete.php', {
                  method: 'POST', body: fd, credentials: 'same-origin',
                });
                let data;
                try { data = await resp.json(); } catch { data = { ok: false, error: 'Bad JSON from server' }; }
                if (!resp.ok || !data.ok) {
                  throw new Error(data.error || ('HTTP ' + resp.status));
                }
                // Remove the row from the UI; reload to refresh the
                // empty-state messaging if this was the last LIVE doc.
                const row = btn.closest('.cp-earl-row');
                if (row) row.remove();
                if (!document.querySelector('.cp-earlier-card .cp-earl-row')) {
                  window.location.reload();
                }
              } catch (err) {
                btn.disabled = false;
                btn.textContent = orig;
                alert('Delete failed: ' + (err.message || err));
              }
            });
          });
        })();
      </script>
    <?php endif; ?>

    <div class="cp-section-label"><?php echo xlt('RECENT'); ?></div>
    <div class="cp-recent-grid">
      <?php foreach ($recent as $r): ?>
        <article class="cp-recent-card">
          <div class="cp-thumb">
            <div class="lines"><span></span><span></span><span></span></div>
            <span class="cp-cat-pill <?php echo attr($r['cat_kind']); ?>"><?php echo text($r['cat']); ?></span>
          </div>
          <div class="body">
            <div class="ttl"><?php echo text($r['title']); ?></div>
            <div class="met"><?php echo text($r['meta']); ?></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="cp-section-label"><?php echo xlt('EARLIER'); ?></div>
    <div class="cp-earlier-card">
      <?php foreach ($earlier as $e): ?>
        <div class="cp-earl-row">
          <div class="cp-earl-icon"><?php echo text($e['icon']); ?></div>
          <div class="cp-earl-info">
            <div class="cp-earl-title"><?php echo text($e['title']); ?></div>
            <div class="cp-earl-sub">
              <span class="cp-earl-cat-pill"><?php echo text($e['cat']); ?></span>
              <span><?php echo text($e['src']); ?></span>
            </div>
          </div>
          <div class="cp-earl-spacer"></div>
          <div class="cp-earl-date"><?php echo text($e['date']); ?></div>
          <div class="cp-earl-size"><?php echo text($e['size']); ?></div>
          <div class="cp-earl-kebab">⋯</div>
        </div>
      <?php endforeach; ?>
    </div>
  </main>
</div>

</body>
</html>
