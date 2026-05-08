// Transactions entry — mounts the Transactions component on #cp-root inside
// the PHP wrapper at /interface/patient_file/transaction/copilot_transactions.php.
//
// The PHP wrapper queries billing + ar_activity + ar_session for the current
// pid, aggregates the four KPI tile totals, and JSON-encodes a {kpis, rows}
// payload onto data-tx. We parse that here and hand it to the component.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Transactions } from './Transactions';
import type { Kpi, TxRow } from './Transactions';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Transactions entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

let kpis: readonly Kpi[] = [];
let rows: readonly TxRow[] = [];
try {
  const raw = mountEl.dataset['tx'] ?? '{}';
  const parsed = JSON.parse(raw) as { kpis?: unknown; rows?: unknown };
  if (Array.isArray(parsed.kpis)) {
    kpis = parsed.kpis as Kpi[];
  }
  if (Array.isArray(parsed.rows)) {
    rows = parsed.rows as TxRow[];
  }
} catch (err) {
  console.error('Transactions: failed to parse data-tx', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <Transactions boot={boot} kpis={kpis} rows={rows} />
  </StrictMode>,
);
