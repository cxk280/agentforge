// PatientResults entry — mounts the PatientResults component on #cp-root
// inside the PHP wrapper at /interface/orders/copilot_results.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PatientResults } from './PatientResults';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PatientResults entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <PatientResults boot={boot} />
  </StrictMode>,
);
