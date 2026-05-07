// Audit entry — mounts the Audit component on #cp-root inside the PHP
// wrapper at /interface/super/copilot_audit.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Audit } from './Audit';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Audit entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Audit boot={boot} />
  </StrictMode>,
);
