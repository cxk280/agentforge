// PatientList entry — mounts the PatientList component on #cp-root inside
// the PHP wrapper at /interface/reports/copilot_patient_list.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PatientList } from './PatientList';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PatientList entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <PatientList boot={boot} />
  </StrictMode>,
);
