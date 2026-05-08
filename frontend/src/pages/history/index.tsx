// History entry — mounts the History component on #cp-root inside the PHP
// wrapper at /interface/patient_file/history/copilot_history.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { History } from './History';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('History entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <History boot={boot} />
  </StrictMode>,
);
