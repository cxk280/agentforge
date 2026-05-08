// Erx entry — mounts the Erx component on #cp-root inside the PHP wrapper
// at /interface/eRx/copilot_erx.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Erx } from './Erx';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Erx entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Erx boot={boot} />
  </StrictMode>,
);
