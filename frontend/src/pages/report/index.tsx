// Report entry — mounts the Report component on #cp-root inside the PHP
// wrapper at /interface/patient_file/report/copilot_report.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Report } from './Report';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Report entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Report boot={boot} />
  </StrictMode>,
);
