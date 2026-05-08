// Dashboard entry — mounts the Dashboard component on #cp-root inside the
// PHP wrapper at /interface/patient_file/summary/copilot_dashboard.php.
//
// The PHP wrapper queries form_vitals, form_observation, lists, prescriptions,
// and form_encounter for the current pid and JSON-encodes the result as
// data-dashboard on #cp-root. We parse it here and hand it to the component,
// so the rendered panels match what's actually in the database for the active
// patient.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Dashboard } from './Dashboard';
import type {
  DashboardLabRow,
  DashboardListItem,
  DashboardPayload,
  DashboardVisit,
  DashboardVital,
} from './Dashboard';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Dashboard entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY_DASHBOARD: DashboardPayload = {
  vitals:      [],
  allergies:   [],
  problems:    [],
  medications: [],
  labs:        [],
  visits:      [],
};

let dashboard: DashboardPayload = EMPTY_DASHBOARD;
try {
  const raw = mountEl.dataset['dashboard'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw) as unknown;
    if (parsed !== null && typeof parsed === 'object') {
      const obj = parsed as {
        vitals?:      unknown;
        allergies?:   unknown;
        problems?:    unknown;
        medications?: unknown;
        labs?:        unknown;
        visits?:      unknown;
      };
      dashboard = {
        vitals:      Array.isArray(obj.vitals)      ? (obj.vitals      as DashboardVital[])    : [],
        allergies:   Array.isArray(obj.allergies)   ? (obj.allergies   as DashboardListItem[]) : [],
        problems:    Array.isArray(obj.problems)    ? (obj.problems    as DashboardListItem[]) : [],
        medications: Array.isArray(obj.medications) ? (obj.medications as DashboardListItem[]) : [],
        labs:        Array.isArray(obj.labs)        ? (obj.labs        as DashboardLabRow[])   : [],
        visits:      Array.isArray(obj.visits)      ? (obj.visits      as DashboardVisit[])    : [],
      };
    }
  }
} catch (err) {
  console.error('Dashboard: failed to parse data-dashboard', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <Dashboard boot={boot} dashboard={dashboard} />
  </StrictMode>,
);
