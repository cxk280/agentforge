// ImmunizationRegistry entry — mounts the ImmunizationRegistry component
// on #cp-root inside the PHP wrapper at
// /interface/patient_file/history/copilot_immunization_registry.php.
//
// The PHP wrapper computes coverage tiles, the patients-due queue, monthly
// admins counts, and sync card metrics from `immunizations` + `patient_data`
// + `extended_log` and JSON-encodes the result onto data-imm. We parse
// that here and hand it to the component so the React tree always reads
// from a typed object.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ImmunizationRegistry } from './ImmunizationRegistry';
import type { ImmunizationRegistryPayload } from './ImmunizationRegistry';
import { readBootContext } from '../../shared/lib/bootContext';

const EMPTY_PAYLOAD: ImmunizationRegistryPayload = {
  coverage: [],
  dueRows: [],
  tabCounts: { all: 0, flu: 0, covid: 0, tdap: 0, shingrix: 0 },
  months: [],
  sync: { lastSyncLabel: 'never', pushedQuarter: 0, pending: 0, errors: 0 },
  peakTotal: 0,
  totalAdmins: 0,
};

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ImmunizationRegistry entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

let payload: ImmunizationRegistryPayload = EMPTY_PAYLOAD;
try {
  const raw = mountEl.dataset['imm'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw) as Partial<ImmunizationRegistryPayload>;
    if (parsed && typeof parsed === 'object') {
      payload = {
        coverage:    Array.isArray(parsed.coverage)   ? parsed.coverage   : EMPTY_PAYLOAD.coverage,
        dueRows:     Array.isArray(parsed.dueRows)    ? parsed.dueRows    : EMPTY_PAYLOAD.dueRows,
        tabCounts:   parsed.tabCounts   ?? EMPTY_PAYLOAD.tabCounts,
        months:      Array.isArray(parsed.months)     ? parsed.months     : EMPTY_PAYLOAD.months,
        sync:        parsed.sync        ?? EMPTY_PAYLOAD.sync,
        peakTotal:   typeof parsed.peakTotal === 'number'   ? parsed.peakTotal   : 0,
        totalAdmins: typeof parsed.totalAdmins === 'number' ? parsed.totalAdmins : 0,
      };
    }
  }
} catch (err) {
  console.error('ImmunizationRegistry: failed to parse data-imm', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <ImmunizationRegistry boot={boot} payload={payload} />
  </StrictMode>,
);
