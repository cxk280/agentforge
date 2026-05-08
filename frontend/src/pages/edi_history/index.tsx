// EdiHistory entry — mounts the EdiHistory component on #cp-root inside the
// PHP wrapper at /interface/billing/copilot_edi_history.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { EdiHistory } from './EdiHistory';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('EdiHistory entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <EdiHistory boot={boot} />
  </StrictMode>,
);
