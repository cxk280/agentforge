// Aging entry — mounts the Aging component on #cp-root inside the PHP
// wrapper at /interface/billing/copilot_aging.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Aging } from './Aging';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Aging entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Aging boot={boot} />
  </StrictMode>,
);
