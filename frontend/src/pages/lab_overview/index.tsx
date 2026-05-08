// LabOverview entry — mounts the LabOverview component on #cp-root inside
// the PHP wrapper at /interface/orders/copilot_lab_overview.php.
//
// The wrapper queries procedure_result -> procedure_report -> procedure_order
// for the active patient, builds one panel per known LOINC concept, and
// JSON-encodes the typed payload onto data-overview.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { LabOverview } from './LabOverview';
import type { OverviewPayload } from './LabOverview';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('LabOverview entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: OverviewPayload = {
  patientName: '',
  panels: [],
  xLabels: [],
};

function parsePayload(raw: string): OverviewPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    return {
      patientName: typeof o['patientName'] === 'string' ? o['patientName'] : '',
      panels: Array.isArray(o['panels']) ? (o['panels'] as OverviewPayload['panels']) : [],
      xLabels: Array.isArray(o['xLabels']) ? (o['xLabels'] as readonly string[]) : [],
    };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['overview'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <LabOverview boot={boot} payload={payload} />
  </StrictMode>,
);
