// ImmunizationRegistry entry — mounts the ImmunizationRegistry component
// on #cp-root inside the PHP wrapper at
// /interface/patient_file/history/copilot_immunization_registry.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ImmunizationRegistry } from './ImmunizationRegistry';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ImmunizationRegistry entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <ImmunizationRegistry boot={boot} />
  </StrictMode>,
);
