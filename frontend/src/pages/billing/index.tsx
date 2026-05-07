// Billing entry — mounts the Billing component on #cp-root inside the PHP
// wrapper at /interface/billing/copilot_billing.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Billing } from './Billing';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Billing entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Billing boot={boot} />
  </StrictMode>,
);
