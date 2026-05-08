// External Data entry — mounts the ExternalData component on #cp-root inside
// the PHP wrapper at /interface/patient_file/external_data/copilot_external_data.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ExternalData } from './ExternalData';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ExternalData entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <ExternalData boot={boot} />
  </StrictMode>,
);
