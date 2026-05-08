// PatientList entry — mounts the PatientList component on #cp-root inside
// the PHP wrapper at /interface/reports/copilot_patient_list.php.
//
// The wrapper queries patient_data joined to insurance_data (primary) +
// insurance_companies + users (provider) plus subqueries for last visit
// and primary diagnosis, and JSON-encodes the typed payload onto
// data-patient-list. We parse it here with a defensive shape guard.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PatientList } from './PatientList';
import type { PatientListPayload } from './PatientList';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PatientList entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: PatientListPayload = { rows: [], total: 0 };

function parsePayload(raw: string): PatientListPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const rows = Array.isArray(o['rows']) ? (o['rows'] as PatientListPayload['rows']) : [];
    const total = typeof o['total'] === 'number' ? o['total'] : 0;
    return { rows, total };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['patientList'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <PatientList boot={boot} payload={payload} />
  </StrictMode>,
);
