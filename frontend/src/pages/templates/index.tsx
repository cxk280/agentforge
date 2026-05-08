// Templates entry — mounts the Templates component on #cp-root inside the
// PHP wrapper at /interface/super/copilot_templates.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Templates } from './Templates';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Templates entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Templates boot={boot} />
  </StrictMode>,
);
