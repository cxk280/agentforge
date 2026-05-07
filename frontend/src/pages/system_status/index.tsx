// SystemStatus entry — mounts the SystemStatus component on #cp-root inside
// the PHP wrapper at /interface/super/copilot_system.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { SystemStatus } from './SystemStatus';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('SystemStatus entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <SystemStatus boot={boot} />
  </StrictMode>,
);
