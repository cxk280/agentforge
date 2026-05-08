// History entry — mounts the History component on #cp-root inside the PHP
// wrapper at /interface/patient_file/history/copilot_history.php.
//
// The PHP wrapper queries form_encounter (LEFT JOIN users for the provider
// name) for the active patient and JSON-encodes the year-grouped result as
// data-history on #cp-root. We parse that here and hand it to the component
// so the cards reflect the patient the user actually opened.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { History } from './History';
import type { HistoryData } from './History';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('History entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY_HISTORY: HistoryData = { totalCount: 0, years: [] };

let history: HistoryData = EMPTY_HISTORY;
try {
  const raw = mountEl.dataset['history'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw) as unknown;
    if (
      typeof parsed === 'object'
      && parsed !== null
      && 'years' in parsed
      && Array.isArray((parsed as { years: unknown }).years)
    ) {
      history = parsed as HistoryData;
    }
  }
} catch (err) {
  console.error('History: failed to parse data-history', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <History boot={boot} history={history} />
  </StrictMode>,
);
