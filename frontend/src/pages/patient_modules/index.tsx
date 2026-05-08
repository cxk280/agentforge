// PatientModules entry — mounts the PatientModules component on #cp-root
// inside the PHP wrapper at /interface/patient_file/modules/copilot_modules.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PatientModules } from './PatientModules';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PatientModules entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <PatientModules boot={boot} />
  </StrictMode>,
);
