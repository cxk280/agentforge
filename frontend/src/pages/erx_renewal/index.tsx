// ErxRenewal entry — mounts the ErxRenewal component on #cp-root inside the
// PHP wrapper at /interface/eRx/copilot_erx_renewal.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ErxRenewal } from './ErxRenewal';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ErxRenewal entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <ErxRenewal boot={boot} />
  </StrictMode>,
);
