// Issues entry — mounts the Issues component on #cp-root inside the PHP
// wrapper at /interface/patient_file/issues/copilot_issues.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Issues } from './Issues';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Issues entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Issues boot={boot} />
  </StrictMode>,
);
