// ProviderPortal entry — mounts on #cp-root inside the PHP wrapper at
// /portal/copilot_provider_portal.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ProviderPortal } from './ProviderPortal';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ProviderPortal entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <ProviderPortal boot={boot} />
  </StrictMode>,
);
