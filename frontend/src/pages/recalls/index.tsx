// Recalls entry — mounts the Recalls component on #cp-root inside the PHP
// wrapper at /interface/main/messages/copilot_recalls.php.
//
// The PHP wrapper queries `medex_recalls` (joined to patient_data + users)
// and JSON-encodes the result + KPI aggregates as data-recalls on #cp-root.
// We parse that here and hand it to the component, so the table reflects
// real DB rows. Filters/search are handled client-side in <Recalls/>.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Recalls } from './Recalls';
import type { RecallsPayload } from './Recalls';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Recalls entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

let payload: RecallsPayload = {
  rows: [],
  kpi: {
    overdue: 0,
    dueWeek: 0,
    dueMonth: 0,
    scheduled: 0,
    contacted: 0,
    contactedWeek: 0,
    total: 0,
    responseRate: 0,
    avgDays: null,
  },
};
try {
  const raw = mountEl.dataset['recalls'] ?? '';
  if (raw !== '') {
    const parsed: unknown = JSON.parse(raw);
    if (
      parsed !== null
      && typeof parsed === 'object'
      && 'rows' in parsed
      && Array.isArray((parsed as { rows: unknown }).rows)
    ) {
      payload = parsed as RecallsPayload;
    }
  }
} catch (err) {
  console.error('Recalls: failed to parse data-recalls', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <Recalls boot={boot} payload={payload} />
  </StrictMode>,
);
