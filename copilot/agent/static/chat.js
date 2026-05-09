// ── Config ────────────────────────────────────────────────────────────────
const params     = new URLSearchParams(location.search);
const PID        = params.get('pid') || '';
const SESSION_ID = 'sess-' + Math.random().toString(36).slice(2);
// Active OpenEMR user (when iframe-injected by interface/copilot/index.php).
// Surfaced to the agent so Langfuse traces attribute per-clinician (R4).
const ACTIVE_USER = params.get('user') || '';

const BACKEND = params.get('backend') || (location.origin.includes('8300')
  ? 'http://localhost:8400'
  : location.origin);

let FHIR_ID  = params.get('fhir_id') || '';
let busy     = false;
let lastQuestion = '';

const SUGGESTED_QUESTIONS = [
  'What changed since last visit?',
  'Any drug interactions?',
  'Recent CBC results?',
  'Chest pain history this year?',
];

// ── Init ─────────────────────────────────────────────────────────────────
async function init() {
  if (!PID && !FHIR_ID) {
    showFatal('No patient selected. Open a patient chart in OpenEMR first.');
    return;
  }

  try {
    if (!FHIR_ID && PID) {
      const res  = await fetch(`${BACKEND}/api/patient-fhir-id/${PID}`);
      const data = await res.json();
      if (!data.fhir_id) throw new Error(data.detail || 'Patient not found');
      FHIR_ID = data.fhir_id;
      // Build the meta line "PID 1 · DOB 1965-03-12 · M" to match the
      // Figma mock — needs DOB and sex from the resolver response.
      const metaParts = [`PID ${PID}`];
      if (data.dob) metaParts.push(`DOB ${data.dob}`);
      if (data.sex) metaParts.push(data.sex);
      setPatient(data.name || 'Patient', metaParts.join(' · '));
    } else if (FHIR_ID) {
      setPatient('Patient', FHIR_ID.slice(0, 8) + '…');
    }

    clearLoading();
    enableInput();
    appendWelcome();
  } catch (e) {
    showFatal('Could not load patient: ' + e.message);
  }
}

function setPatient(name, meta) {
  document.getElementById('patient-name').textContent = name;
  document.getElementById('patient-meta').textContent = meta;
  document.getElementById('patient-badge').hidden = false;
}

// ── UI helpers ────────────────────────────────────────────────────────────
function clearLoading() {
  document.getElementById('loading')?.remove();
}

function enableInput() {
  document.getElementById('input').disabled = false;
  document.getElementById('send').disabled  = false;
  document.getElementById('input').focus();
}

function showFatal(msg) {
  const msgs = document.getElementById('messages');
  msgs.innerHTML = `
    <div class="msg assistant">
      <div class="ai-label">Clinical Co-Pilot</div>
      <div class="bubble error"><strong>${msg}</strong></div>
    </div>`;
}

function appendWelcome() {
  const name = document.getElementById('patient-name').textContent;
  const msgs = document.getElementById('messages');
  const wrap = document.createElement('div');
  wrap.className = 'msg assistant';
  wrap.innerHTML = `
    <div class="ai-label">Clinical Co-Pilot</div>
    <div class="bubble">
      Ready for <strong>${escapeHtml(name)}</strong>. Ask me anything &mdash;
      conditions, medications, recent labs, vitals, or visit history.
    </div>
    <div class="suggested">
      <div class="suggested-label">Suggested questions</div>
      <div class="suggested-chips" id="suggested-chips"></div>
    </div>`;
  msgs.appendChild(wrap);

  const chipsEl = wrap.querySelector('#suggested-chips');
  for (const q of SUGGESTED_QUESTIONS) {
    const chip = document.createElement('button');
    chip.className = 'chip';
    chip.textContent = q;
    chip.addEventListener('click', () => {
      removeSuggested();
      send(q);
    });
    chipsEl.appendChild(chip);
  }
  scrollToBottom();
}

function removeSuggested() {
  document.querySelector('.suggested')?.remove();
}

function appendUser(text) {
  const msgs = document.getElementById('messages');
  const div  = document.createElement('div');
  div.className = 'msg user';
  div.innerHTML = `<div class="bubble">${escapeHtml(text)}</div>`;
  msgs.appendChild(div);
  scrollToBottom();
}

function appendAssistant(text) {
  const { body, sources } = splitSources(text);
  const msgs = document.getElementById('messages');
  const div  = document.createElement('div');
  div.className = 'msg assistant';

  const sourcesHtml = sources.length
    ? `<div class="sources">
        <span class="sources-label">Sources:</span>
        ${sources.map(s => `<span class="source-chip">${escapeHtml(s)}</span>`).join('')}
       </div>`
    : '';

  div.innerHTML = `
    <div class="ai-label">Clinical Co-Pilot</div>
    <div class="bubble">${renderMarkdown(body)}${sourcesHtml}</div>`;
  msgs.appendChild(div);
  scrollToBottom();
  return div;
}

// Streaming helpers — used by the NDJSON consumer in send().
function appendAssistantStreaming() {
  const msgs = document.getElementById('messages');
  const div  = document.createElement('div');
  div.className = 'msg assistant';
  div.innerHTML = `
    <div class="ai-label">Clinical Co-Pilot</div>
    <div class="bubble"><div class="content"></div></div>`;
  msgs.appendChild(div);
  scrollToBottom();
  return div;
}

function updateAssistantStreaming(div, fullText) {
  const { body } = splitSources(fullText);
  const content = div.querySelector('.content');
  content.innerHTML = renderMarkdown(body);
  scrollToBottom();
}

function finalizeAssistantStreaming(div, fullText) {
  const { body, sources } = splitSources(fullText);
  const bubble = div.querySelector('.bubble');
  const sourcesHtml = sources.length
    ? `<div class="sources">
        <span class="sources-label">Sources:</span>
        ${sources.map(s => `<span class="source-chip">${escapeHtml(s)}</span>`).join('')}
       </div>`
    : '';
  bubble.innerHTML = renderMarkdown(body) + sourcesHtml;
  scrollToBottom();
}

function setTypingStatus(text) {
  const typing = document.getElementById('typing');
  if (!typing) return;
  let status = typing.querySelector('.typing-status');
  if (!status) {
    status = document.createElement('div');
    status.className = 'typing-status';
    typing.appendChild(status);
  }
  status.textContent = text;
}

const TOOL_LABELS = {
  get_patient_summary: 'patient summary',
  get_medications:     'medications',
  get_recent_labs:     'recent labs',
  get_vitals:          'vitals',
  get_visit_history:   'visit history',
  get_conditions:      'conditions',
  search_guidelines:   'guidelines',
  get_extracted_facts: 'document facts',
};
function toolLabel(name) { return TOOL_LABELS[name] || name; }

// Render an inline "retrieval card" so the dense+sparse hybrid stack is
// visible in the demo. Triggered by tool_end (W1 single-loop path) and
// retrieval_hit (W2 graph path).
function appendRetrievalCard(meta) {
  if (!meta) return;
  const msgs = document.getElementById('messages');
  const div  = document.createElement('div');
  div.className = 'msg assistant retrieval-card';

  const mode = meta.retrieval_mode || (meta.dense_enabled ? 'hybrid_sparse_dense' : 'sparse_only');
  const modeLabel = mode === 'hybrid_sparse_dense' ? 'Hybrid · sparse + dense' : 'Sparse only';
  const sparse = meta.sparse_model || 'bm25-okapi';
  const dense  = meta.dense_model  || (meta.dense_enabled ? 'voyage-3' : 'disabled');
  const fusion = meta.fusion === 'rrf' ? `RRF (k=${meta.rrf_k ?? 60})` : '—';
  const rerank = meta.rerank_enabled ? 'Cohere rerank' : 'no rerank';
  const contributors = (meta.contributors && meta.contributors.length)
    ? meta.contributors.join(' + ')
    : (meta.dense_enabled ? 'sparse + dense' : 'sparse');

  div.innerHTML = `
    <div class="ai-label">Retrieval</div>
    <div class="bubble retrieval-bubble">
      <div class="retrieval-row">
        <span class="retrieval-pill mode-${escapeHtml(mode)}">${escapeHtml(modeLabel)}</span>
        <span class="retrieval-pill">sparse: ${escapeHtml(sparse)}</span>
        <span class="retrieval-pill">dense: ${escapeHtml(dense)}</span>
        <span class="retrieval-pill">fusion: ${escapeHtml(fusion)}</span>
        <span class="retrieval-pill">${escapeHtml(rerank)}</span>
      </div>
      <div class="retrieval-sub">contributors: ${escapeHtml(contributors)} · corpus ${meta.corpus_size ?? '?'} chunks</div>
    </div>`;
  msgs.appendChild(div);
  scrollToBottom();
}

function appendError(text, retryHandler) {
  const msgs = document.getElementById('messages');
  const div  = document.createElement('div');
  div.className = 'msg assistant';
  div.innerHTML = `
    <div class="ai-label">Clinical Co-Pilot</div>
    <div class="bubble error">
      <strong>Something went wrong.</strong>
      <p>${escapeHtml(text)}</p>
      ${retryHandler ? `<button class="retry-btn" type="button">Retry</button>` : ''}
    </div>`;
  msgs.appendChild(div);
  if (retryHandler) {
    div.querySelector('.retry-btn').addEventListener('click', () => {
      div.remove();
      retryHandler();
    });
  }
  scrollToBottom();
}

function appendTyping(statusText) {
  const msgs = document.getElementById('messages');
  const div  = document.createElement('div');
  div.id = 'typing';
  div.className = 'msg assistant';
  div.innerHTML = `
    <div class="ai-label">Clinical Co-Pilot</div>
    <div class="bubble typing-bubble">
      <span class="dot"></span><span class="dot"></span><span class="dot"></span>
    </div>
    ${statusText ? `<div class="typing-status">${escapeHtml(statusText)}</div>` : ''}`;
  msgs.appendChild(div);
  scrollToBottom();
  return div;
}

function removeTyping() {
  document.getElementById('typing')?.remove();
}

function scrollToBottom() {
  const msgs = document.getElementById('messages');
  msgs.scrollTop = msgs.scrollHeight;
}

function escapeHtml(str) {
  return str.replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
}

// Pull a trailing "Sources: a, b, c" line out of the response so we can render it as chips.
function splitSources(text) {
  const re = /\n\s*\*{0,2}sources?\*{0,2}\s*:\s*([^\n]+)\s*$/i;
  const match = text.match(re);
  if (!match) return { body: text, sources: [] };
  const sources = match[1]
    .split(/[,;]/)
    .map(s => s.trim().replace(/^\*+|\*+$/g, ''))
    .filter(Boolean);
  return { body: text.slice(0, match.index).trimEnd(), sources };
}

// Lightweight Markdown renderer (headers, bold, italic, code, lists, tables, hr, blockquote)
function renderMarkdown(text) {
  let s = escapeHtml(text);

  // Tables
  s = s.replace(/^(\|.+)\n(\|[-| :]+)\n((?:\|.+\n?)+)/gm, (_, head, _sep, body) => {
    const th = head.trim().split('|').filter(c => c.trim()).map(c => `<th>${c.trim()}</th>`).join('');
    const rows = body.trim().split('\n').map(row => {
      const tds = row.trim().split('|').filter(c => c.trim()).map(c => `<td>${c.trim()}</td>`).join('');
      return `<tr>${tds}</tr>`;
    }).join('');
    return `<table><thead><tr>${th}</tr></thead><tbody>${rows}</tbody></table>`;
  });

  // Blockquotes (single-line)
  s = s.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
  // Horizontal rule
  s = s.replace(/^---+$/gm, '<hr>');
  // Headers
  s = s.replace(/^#{2,3} (.+)$/gm, '<h3>$1</h3>');
  // Bold + italic
  s = s.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
  s = s.replace(/\*\*(.+?)\*\*/g,     '<strong>$1</strong>');
  s = s.replace(/(?<!\*)\*(?!\*)([^\*\n]+)\*(?!\*)/g, '<em>$1</em>');
  // Code
  s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
  // Bulleted + numbered lists
  s = s.replace(/^[\-\*] (.+)$/gm, '<li>$1</li>');
  s = s.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
  s = s.replace(/(<li>.*?<\/li>\n?)+/gs, m => `<ul>${m}</ul>`);
  // Paragraphs / line breaks
  s = s.replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>');
  if (!/^\s*<(h\d|ul|ol|table|blockquote|hr|p)/.test(s)) s = `<p>${s}</p>`;

  return s;
}

// ── Send ─────────────────────────────────────────────────────────────────
async function send(text) {
  const input = document.getElementById('input');
  const message = (text ?? input.value).trim();
  if (!message || busy || !FHIR_ID) return;

  busy = true;
  document.getElementById('send').disabled = true;
  input.value = ''; input.style.height = 'auto';

  removeSuggested();
  appendUser(message);
  lastQuestion = message;
  appendTyping();

  let assistantDiv = null;
  let assistantText = '';
  let activeTools = 0;

  function ensureAssistantBubble() {
    if (assistantDiv) return;
    removeTyping();
    assistantDiv = appendAssistantStreaming();
  }

  try {
    const res = await fetch(`${BACKEND}/chat/graph`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        session_id: SESSION_ID,
        patient_id: FHIR_ID,
        message,
        active_user: ACTIVE_USER || null,
      }),
    });

    if (!res.ok) {
      const err = await res.json().catch(() => ({ detail: res.statusText }));
      throw new Error(err.detail || 'Server error');
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let streamError = null;

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop();
      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed) continue;
        let evt;
        try { evt = JSON.parse(trimmed); } catch { continue; }
        if (evt.type === 'tool_start') {
          activeTools++;
          setTypingStatus(`Looking up ${toolLabel(evt.name)}…`);
        } else if (evt.type === 'tool_end') {
          activeTools = Math.max(0, activeTools - 1);
          if (activeTools === 0) setTypingStatus('Reading results…');
          // W1 path: search_guidelines tool returns retrieval metadata.
          if (evt.name === 'search_guidelines' && evt.retrieval) {
            appendRetrievalCard(evt.retrieval);
          }
        } else if (evt.type === 'retrieval_hit') {
          // W2 graph path: evidence_retriever_node emits retrieval_hit
          // directly with the same metadata shape.
          appendRetrievalCard(evt);
        } else if (evt.type === 'delta') {
          ensureAssistantBubble();
          assistantText += evt.text;
          updateAssistantStreaming(assistantDiv, assistantText);
        } else if (evt.type === 'done') {
          ensureAssistantBubble();
          finalizeAssistantStreaming(assistantDiv, assistantText);
        } else if (evt.type === 'error') {
          streamError = evt.detail || 'Server error';
        }
      }
    }
    if (streamError) throw new Error(streamError);
    if (!assistantDiv) {
      removeTyping();
      throw new Error('Empty response');
    }
  } catch (e) {
    removeTyping();
    if (assistantDiv) assistantDiv.remove();
    appendError(e.message, () => send(lastQuestion));
  } finally {
    busy = false;
    document.getElementById('send').disabled = false;
    input.focus();
  }
}

// ── Event listeners ───────────────────────────────────────────────────────
document.getElementById('send').addEventListener('click', () => send());
document.getElementById('input').addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
});
document.getElementById('input').addEventListener('input', function() {
  this.style.height = 'auto';
  this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});

init();
