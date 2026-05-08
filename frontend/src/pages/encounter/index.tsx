// Encounter entry — mounts the Encounter component on #cp-root inside the PHP
// wrapper at /interface/patient_file/encounter/copilot_encounter.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Encounter } from './Encounter';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Encounter entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Encounter boot={boot} />
  </StrictMode>,
);
