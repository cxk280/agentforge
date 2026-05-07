// Recalls entry — mounts the Recalls component on #cp-root inside the PHP
// wrapper at /interface/main/messages/copilot_recalls.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Recalls } from './Recalls';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Recalls entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Recalls boot={boot} />
  </StrictMode>,
);
