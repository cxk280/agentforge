<?php

/**
 * Copilot Doc Viewer — PDF.js viewer with extracted-fact bbox overlay.
 *
 * Spec requirement: "A visual PDF bounding-box overlay is required."
 * (W2 spec page 5, Citation contract.)
 *
 * Query params:
 *   docref  (int)     OpenEMR documents.id of the PDF to view
 *   bbox    (int)     Optional cp_extraction_citations.id to highlight
 *                     and scroll into view on load.
 *
 * Layout:
 *   ┌──────────────────────────────────────────────────────────┐
 *   │  ← Back     Doc title       (lab_pdf · Apr 28)           │
 *   ├────────────────────────────────────┬─────────────────────┤
 *   │                                    │  Extracted facts    │
 *   │      PDF.js page canvas            │  (rail)             │
 *   │      + bbox overlay layer          │                     │
 *   │                                    │  · HbA1c 8.2%   →   │
 *   │                                    │  · Creatinine 1.6→  │
 *   │                                    │  · ...              │
 *   └────────────────────────────────────┴─────────────────────┘
 *
 * Bbox metadata comes from the agent's GET /copilot/extractions/{patient_id}
 * route, which joins cp_extracted_facts + cp_extraction_citations. The
 * page filters that by document_id client-side and renders one
 * absolutely-positioned div per citation, scaled to the rendered page
 * viewport. Clicking a fact in the rail or a box on the canvas selects
 * the pair.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// ─── Auth ────────────────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('patients', 'docs')) {
    http_response_code(403);
    echo xlt("Not authorized");
    exit;
}

// ─── Resolve doc_id, cross-check patient ─────────────────────────────────
$docId = isset($_GET['docref']) ? (int)$_GET['docref'] : 0;
$bboxId = isset($_GET['bbox']) ? (int)$_GET['bbox'] : 0;
$activePid = (int)($_SESSION['pid'] ?? 0);

if ($docId <= 0) {
    http_response_code(400);
    echo "Missing docref query param.";
    exit;
}

$row = sqlQuery(
    "SELECT id, foreign_id, name, mimetype, date FROM documents WHERE id = ? LIMIT 1",
    [$docId]
);
if (empty($row)) {
    http_response_code(404);
    echo "Document not found.";
    exit;
}
if ($activePid && (int)$row['foreign_id'] !== $activePid) {
    http_response_code(403);
    echo "Document does not belong to the active patient.";
    exit;
}

$docTitle = $row['name'] ?: "Document #{$docId}";
$docDate  = $row['date'] ? substr($row['date'], 0, 10) : '';
$mimeType = $row['mimetype'] ?: 'application/pdf';
$ownerPid = (int)$row['foreign_id'];

// PDF source — re-use OpenEMR's existing authenticated download route.
// `controller.php?document&retrieve` already enforces ACL and serves
// the file through the OpenEMR session, so we don't need a parallel
// download path on the agent side.
$pdfUrl = "/controller.php?document&retrieve"
    . "&patient_id="   . $ownerPid
    . "&document_id="  . $docId
    . "&as_file=false"
    . "&original_file=true";

$copilotBackend = $GLOBALS['copilot_backend_url']
    ?? getenv('COPILOT_BACKEND_URL')
    ?? 'http://localhost:8400';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Document Viewer'); ?> — <?php echo text($docTitle); ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F6F7;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
  }
  /* ── Header ─────────────────────────────────────────────────────── */
  .cp-dv-head {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    height: 56px;
    padding: 0 20px;
    display: flex; align-items: center; gap: 14px;
  }
  .cp-dv-back {
    color: #1f3a68; text-decoration: none; font-weight: 600;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-dv-title { font-weight: 700; font-size: 15px; }
  .cp-dv-meta { color: #8A91A1; font-size: 12px; }
  .cp-dv-pill {
    border: 1px solid #E4E5E8; border-radius: 999px;
    padding: 4px 10px; font-size: 11px; color: #1f3a68;
  }

  /* ── Layout ─────────────────────────────────────────────────────── */
  .cp-dv-body {
    display: flex; height: calc(100vh - 56px); overflow: hidden;
  }
  .cp-dv-canvas-col {
    flex: 1; overflow: auto; padding: 16px; background: #ECEEF1;
  }
  .cp-dv-rail {
    width: 380px; min-width: 380px; max-width: 380px;
    background: #FFFFFF; border-left: 1px solid #E4E5E8;
    overflow: auto; padding: 16px;
  }
  .cp-dv-rail h2 {
    margin: 0 0 12px; font-size: 13px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .04em; color: #1f3a68;
  }
  .cp-dv-fact {
    border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 10px 12px; margin-bottom: 8px; cursor: pointer;
    background: #FAFBFC;
  }
  .cp-dv-fact:hover { border-color: #1f3a68; background: #F1F4FA; }
  .cp-dv-fact.selected {
    border-color: #1f3a68; background: #E5ECF7;
    box-shadow: 0 0 0 2px rgba(31, 58, 104, .15);
  }
  .cp-dv-fact .ft { font-size: 11px; color: #8A91A1; text-transform: uppercase; letter-spacing: .04em; }
  .cp-dv-fact .fv { font-size: 14px; font-weight: 600; margin: 2px 0; }
  .cp-dv-fact .fc {
    font-size: 11px; color: #8A91A1;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .cp-dv-conf {
    display: inline-block; margin-left: 6px; font-size: 10px;
    color: #FFFFFF; padding: 1px 6px; border-radius: 4px;
    background: #2d7a4f;
  }
  .cp-dv-conf.low { background: #b56a1f; }

  /* ── PDF page + overlay ─────────────────────────────────────────── */
  .cp-dv-page-wrap {
    position: relative; margin: 0 auto 14px;
    background: #FFFFFF;
    box-shadow: 0 1px 4px rgba(0, 0, 0, .12);
  }
  .cp-dv-page-wrap canvas { display: block; }
  .cp-dv-overlay {
    position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    pointer-events: none;
  }
  .cp-dv-bbox {
    position: absolute;
    border: 2px solid rgba(245, 166, 35, .85);
    background: rgba(245, 166, 35, .10);
    border-radius: 3px;
    pointer-events: auto;
    cursor: pointer;
    transition: background-color .12s, border-color .12s;
  }
  .cp-dv-bbox:hover {
    background: rgba(245, 166, 35, .22);
    border-color: rgba(245, 166, 35, 1);
  }
  .cp-dv-bbox.selected {
    border-color: #2d7a4f;
    background: rgba(45, 122, 79, .22);
    box-shadow: 0 0 0 3px rgba(45, 122, 79, .25);
  }
  .cp-dv-empty {
    color: #8A91A1; font-size: 13px; text-align: center; margin-top: 40px;
  }
</style>
</head>
<body>

<div class="cp-dv-head">
  <a class="cp-dv-back" href="/interface/patient_file/documents/copilot_documents.php">← <?php echo xlt('Documents'); ?></a>
  <span class="cp-dv-title"><?php echo text($docTitle); ?></span>
  <span class="cp-dv-pill"><?php echo text($mimeType); ?></span>
  <?php if ($docDate): ?>
    <span class="cp-dv-meta"><?php echo text($docDate); ?></span>
  <?php endif; ?>
  <span style="flex:1"></span>
  <span class="cp-dv-meta">doc #<?php echo (int)$docId; ?> · pid <?php echo (int)$ownerPid; ?></span>
</div>

<div class="cp-dv-body">
  <div class="cp-dv-canvas-col" id="cp-dv-canvas-col"></div>
  <aside class="cp-dv-rail" id="cp-dv-rail">
    <h2>Extracted facts</h2>
    <div id="cp-dv-fact-list">
      <div class="cp-dv-empty">Loading …</div>
    </div>
  </aside>
</div>

<script type="module">
  import * as pdfjsLib from 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.0.379/pdf.min.mjs';
  pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.0.379/pdf.worker.min.mjs';

  const PDF_URL = <?php echo json_encode($pdfUrl); ?>;
  const COPILOT_BACKEND = <?php echo json_encode(rtrim((string)$copilotBackend, '/')); ?>;
  const DOC_ID = <?php echo (int)$docId; ?>;
  const PATIENT_ID = <?php echo (int)$ownerPid; ?>;
  const SELECTED_CITATION_ID = <?php echo (int)$bboxId; ?>;

  const canvasCol = document.getElementById('cp-dv-canvas-col');
  const factList = document.getElementById('cp-dv-fact-list');

  // factsByPage[pageNum] = [{fact, citation}]
  const factsByPage = new Map();
  // citationId → DOM element of the rail card (for highlight on click)
  const citationToCard = new Map();
  // citationId → DOM element of the bbox div (for highlight on rail click)
  const citationToBbox = new Map();

  function selectCitation(citationId, scroll) {
    document.querySelectorAll('.cp-dv-bbox.selected, .cp-dv-fact.selected')
      .forEach(el => el.classList.remove('selected'));
    const card = citationToCard.get(citationId);
    const box  = citationToBbox.get(citationId);
    if (card) card.classList.add('selected');
    if (box)  box.classList.add('selected');
    if (scroll && card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (scroll && box)  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  // ── Pull extracted facts for this patient, filter by docref ──────
  async function loadFacts() {
    const url = `${COPILOT_BACKEND}/copilot/extractions/${PATIENT_ID}`;
    let resp;
    try {
      resp = await fetch(url, { credentials: 'omit' });
    } catch (err) {
      factList.innerHTML = `<div class="cp-dv-empty">Could not reach agent at ${COPILOT_BACKEND}: ${err.message}</div>`;
      return;
    }
    if (!resp.ok) {
      factList.innerHTML = `<div class="cp-dv-empty">Agent returned HTTP ${resp.status}.</div>`;
      return;
    }
    const data = await resp.json();
    const myFacts = (data.facts || []).filter(f => Number(f.document_id) === DOC_ID);

    if (myFacts.length === 0) {
      factList.innerHTML = `<div class="cp-dv-empty">No extracted facts on file for this document yet. Trigger extraction via the agent's <code>/extract</code> route.</div>`;
      return;
    }

    factList.innerHTML = '';
    for (const fact of myFacts) {
      // One rail card per fact. If the fact has multiple citations, the
      // first citation drives the click target; downstream selection
      // navigates to whichever bbox the user clicks on the canvas.
      const card = document.createElement('div');
      card.className = 'cp-dv-fact';
      const conf = (fact.confidence != null) ? Number(fact.confidence) : null;
      const confClass = (conf != null && conf < 0.5) ? 'low' : '';
      const confTag = (conf != null) ? `<span class="cp-dv-conf ${confClass}">conf ${conf.toFixed(2)}</span>` : '';
      const valueText = renderFactValue(fact);
      card.innerHTML = `
        <div class="ft">${escapeHtml(fact.fact_type)}${confTag}</div>
        <div class="fv">${escapeHtml(valueText)}</div>
        ${fact.source_quote ? `<div class="fc">“${escapeHtml(fact.source_quote)}”</div>` : ''}
      `;
      const firstCitation = (fact.citations || [])[0];
      if (firstCitation) {
        card.addEventListener('click', () => selectCitation(firstCitation.citation_id, true));
        citationToCard.set(firstCitation.citation_id, card);
        // Index every citation on the fact (for bbox→card selection on click).
        for (const cit of fact.citations) {
          citationToCard.set(cit.citation_id, card);
          const arr = factsByPage.get(cit.page) || [];
          arr.push({ fact, citation: cit });
          factsByPage.set(cit.page, arr);
        }
      }
      factList.appendChild(card);
    }
  }

  function renderFactValue(f) {
    const j = f.fact_json || {};
    if (f.fact_type === 'lab_result') {
      const unit = j.unit ? ' ' + j.unit : '';
      return `${j.test_name || ''}: ${j.value || ''}${unit}`;
    }
    if (f.fact_type === 'medication_line') {
      return `${j.medication_name || ''} ${j.dose || ''} ${j.frequency || ''}`.trim();
    }
    if ((f.fact_type || '').startsWith('demographic.')) {
      return `${(f.fact_type || '').replace('demographic.', '')}: ${j.value || ''}`;
    }
    if (j.value) return String(j.value);
    return f.fact_type;
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;');
  }

  // ── Render PDF + overlay bboxes ─────────────────────────────────
  async function renderPdf() {
    const loadingTask = pdfjsLib.getDocument({ url: PDF_URL, withCredentials: true });
    let pdf;
    try {
      pdf = await loadingTask.promise;
    } catch (err) {
      canvasCol.innerHTML = `<div class="cp-dv-empty">Failed to load PDF: ${escapeHtml(err.message || err)}</div>`;
      return;
    }
    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
      const page = await pdf.getPage(pageNum);
      const viewport = page.getViewport({ scale: 1.4 });

      const wrap = document.createElement('div');
      wrap.className = 'cp-dv-page-wrap';
      wrap.style.width  = viewport.width + 'px';
      wrap.style.height = viewport.height + 'px';
      const canvas = document.createElement('canvas');
      canvas.width  = viewport.width;
      canvas.height = viewport.height;
      wrap.appendChild(canvas);
      const overlay = document.createElement('div');
      overlay.className = 'cp-dv-overlay';
      wrap.appendChild(overlay);
      canvasCol.appendChild(wrap);

      await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

      // Draw bboxes that point at this page.
      const items = factsByPage.get(pageNum) || [];
      for (const { fact, citation } of items) {
        const b = citation.bbox || {};
        const box = document.createElement('div');
        box.className = 'cp-dv-bbox';
        box.style.left   = (b.x * viewport.width)  + 'px';
        box.style.top    = (b.y * viewport.height) + 'px';
        box.style.width  = (b.w * viewport.width)  + 'px';
        box.style.height = (b.h * viewport.height) + 'px';
        box.title = `${fact.fact_type}: ${fact.source_quote || ''}`;
        box.addEventListener('click', () => selectCitation(citation.citation_id, true));
        overlay.appendChild(box);
        citationToBbox.set(citation.citation_id, box);
      }
    }

    if (SELECTED_CITATION_ID) {
      // Auto-select the citation passed in on the URL once everything
      // is laid out.
      setTimeout(() => selectCitation(SELECTED_CITATION_ID, true), 50);
    }
  }

  // Run in order: facts first (so factsByPage is populated when the
  // PDF render emits per-page bboxes).
  await loadFacts();
  await renderPdf();
</script>
</body>
</html>
