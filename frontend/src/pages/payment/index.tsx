// Payment entry — mounts the Payment component on #cp-root inside the PHP
// wrapper at /interface/billing/copilot_payment.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Payment } from './Payment';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Payment entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Payment boot={boot} />
  </StrictMode>,
);
