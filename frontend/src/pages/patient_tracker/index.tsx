// Patient Tracker entry — mounts the PatientTracker component on #cp-root
// inside the PHP wrapper at /interface/main/copilot_patient_tracker.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PatientTracker } from './PatientTracker';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PatientTracker entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <PatientTracker boot={boot} />
  </StrictMode>,
);
