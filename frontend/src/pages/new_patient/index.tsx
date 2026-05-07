// NewPatient entry — mounts the NewPatient component on #cp-root inside the
// PHP wrapper at /interface/new/copilot_new_patient.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { NewPatient } from './NewPatient';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('NewPatient entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <NewPatient boot={boot} />
  </StrictMode>,
);
