// VisitHistory entry — mounts the VisitHistory component on #cp-root
// inside the PHP wrapper at
// /interface/patient_file/encounter/copilot_visit_history.php.
//
// The wrapper queries form_encounter (joined to users +
// openemr_postcalendar_categories) for the active patient and
// JSON-encodes the typed payload onto data-history. We parse it here
// with a defensive shape guard and hand the typed prop to the
// component — no fallback masking real data.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { VisitHistory } from './VisitHistory';
import type { VisitHistoryPayload, VisitRow, VisitTypeOpt, ProviderOpt } from './VisitHistory';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('VisitHistory entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: VisitHistoryPayload = {
  rows: [],
  totalAll: 0,
  visitTypeOpts: [],
  providerOpts: [],
};

function parsePayload(raw: string): VisitHistoryPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const rows = Array.isArray(o['rows']) ? (o['rows'] as VisitRow[]) : [];
    const totalAll = typeof o['totalAll'] === 'number' ? o['totalAll'] : 0;
    const vtypes = Array.isArray(o['visitTypeOpts']) ? (o['visitTypeOpts'] as VisitTypeOpt[]) : [];
    const provs = Array.isArray(o['providerOpts']) ? (o['providerOpts'] as ProviderOpt[]) : [];
    return { rows, totalAll, visitTypeOpts: vtypes, providerOpts: provs };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['history'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <VisitHistory boot={boot} payload={payload} />
  </StrictMode>,
);
