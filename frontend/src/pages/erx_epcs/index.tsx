// ErxEpcs entry — mounts the ErxEpcs component on #cp-root inside the PHP
// wrapper at /interface/eRx/copilot_erx_epcs.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ErxEpcs } from './ErxEpcs';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ErxEpcs entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <ErxEpcs boot={boot} />
  </StrictMode>,
);
