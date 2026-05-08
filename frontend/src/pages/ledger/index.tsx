// Ledger entry — mounts the Ledger component on #cp-root inside the PHP
// wrapper at /interface/patient_file/ledger/copilot_ledger.php.
//
// The PHP wrapper composes charges + payments / adjustments from the
// billing, ar_activity, and ar_session tables and JSON-encodes the result
// into data-ledger. We parse that here and hand it to the component, so
// the rendered ledger reflects real activity on the active patient.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Ledger } from './Ledger';
import type { LedgerPayload } from './Ledger';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Ledger entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

let ledger: LedgerPayload | undefined;
try {
  const raw = mountEl.dataset['ledger'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw) as unknown;
    if (parsed !== null && typeof parsed === 'object' && Array.isArray((parsed as { rows?: unknown }).rows)) {
      ledger = parsed as LedgerPayload;
    }
  }
} catch (err) {
  console.error('Ledger: failed to parse data-ledger', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <Ledger boot={boot} ledger={ledger} />
  </StrictMode>,
);
