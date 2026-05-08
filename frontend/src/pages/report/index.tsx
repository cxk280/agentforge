// Report entry — mounts the Report component on #cp-root inside the PHP
// wrapper at /interface/patient_file/report/copilot_report.php.
//
// The PHP wrapper queries patient_data + insurance_data + lists +
// prescriptions + form_encounter + immunizations and JSON-encodes the
// combined payload onto data-report. We parse it here at the JS-side
// boundary and hand the typed result to the component, so the rendered
// report reflects the active patient.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Report, EMPTY_REPORT } from './Report';
import type { ReportData } from './Report';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Report entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

let report: ReportData = EMPTY_REPORT;
try {
  const raw = mountEl.dataset['report'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw);
    if (parsed !== null && typeof parsed === 'object') {
      report = parsed as ReportData;
    }
  }
} catch (err) {
  console.error('Report: failed to parse data-report', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <Report boot={boot} report={report} />
  </StrictMode>,
);
