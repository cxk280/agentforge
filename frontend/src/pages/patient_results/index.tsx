// PatientResults entry — mounts the PatientResults component on #cp-root
// inside the PHP wrapper at /interface/orders/copilot_results.php.
//
// The wrapper joins procedure_result -> procedure_report -> procedure_order
// -> users for the active patient, groups rows by month, and JSON-encodes
// the typed payload onto data-results.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PatientResults } from './PatientResults';
import type { ResultsPayload } from './PatientResults';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PatientResults entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: ResultsPayload = {
  months: [],
  counts: { all: 0, labs: 0, imaging: 0, procedures: 0, documents: 0, abnormal: 0 },
};

function parsePayload(raw: string): ResultsPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const months = Array.isArray(o['months']) ? (o['months'] as ResultsPayload['months']) : [];
    const counts = (o['counts'] && typeof o['counts'] === 'object')
      ? (o['counts'] as ResultsPayload['counts'])
      : EMPTY.counts;
    return { months, counts };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['results'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <PatientResults boot={boot} payload={payload} />
  </StrictMode>,
);
