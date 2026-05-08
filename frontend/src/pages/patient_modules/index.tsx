// PatientModules entry — mounts the PatientModules component on #cp-root
// inside the PHP wrapper at /interface/patient_file/modules/copilot_modules.php.
//
// The PHP wrapper JSON-encodes the active-clinical and recommended-available
// module rows and the summary counts onto data-active-clinical /
// data-available / data-summary on #cp-root (currently hardcoded stubs —
// see TODO(real-data) in the wrapper). We parse those here and hand them
// to the component as props, mirroring the Finder + External Data pattern
// so swapping in a real SQL pull is a server-side-only change.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PatientModules } from './PatientModules';
import type { PatientModule, PatientModulesSummary } from './PatientModules';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PatientModules entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

function parseJsonArray<T>(raw: string | undefined): readonly T[] {
  if (raw === undefined || raw === '') return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as T[]) : [];
  } catch (err) {
    console.error('PatientModules: failed to parse data attribute', err);
    return [];
  }
}

function parseSummary(raw: string | undefined): PatientModulesSummary {
  if (raw === undefined || raw === '') return { active: 0, available: 0 };
  try {
    const parsed = JSON.parse(raw) as { active?: unknown; available?: unknown };
    const active = typeof parsed.active === 'number' ? parsed.active : 0;
    const available = typeof parsed.available === 'number' ? parsed.available : 0;
    return { active, available };
  } catch (err) {
    console.error('PatientModules: failed to parse data-summary', err);
    return { active: 0, available: 0 };
  }
}

const activeClinical = parseJsonArray<PatientModule>(mountEl.dataset['activeClinical']);
const available = parseJsonArray<PatientModule>(mountEl.dataset['available']);
const summary = parseSummary(mountEl.dataset['summary']);

createRoot(mountEl).render(
  <StrictMode>
    <PatientModules
      boot={boot}
      activeClinical={activeClinical}
      available={available}
      summary={summary}
    />
  </StrictMode>,
);
