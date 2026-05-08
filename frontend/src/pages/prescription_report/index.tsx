// PrescriptionReport entry — mounts the PrescriptionReport component on
// #cp-root inside the PHP wrapper at
// /interface/reports/copilot_prescription_report.php.
//
// The wrapper queries `prescriptions` (joined to `patient_data` and `users`
// for provider) for the most recent 25 rows plus the global total, and
// JSON-encodes the typed payload onto data-prescriptions. We parse it here
// with a defensive shape guard.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PrescriptionReport } from './PrescriptionReport';
import type { PrescriptionPayload } from './PrescriptionReport';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PrescriptionReport entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: PrescriptionPayload = { rows: [], total: 0 };

function parsePayload(raw: string): PrescriptionPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const rows = Array.isArray(o['rows']) ? (o['rows'] as PrescriptionPayload['rows']) : [];
    const total = typeof o['total'] === 'number' ? o['total'] : 0;
    return { rows, total };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['prescriptions'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <PrescriptionReport boot={boot} payload={payload} />
  </StrictMode>,
);
