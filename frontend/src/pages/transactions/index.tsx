// Transactions entry — mounts the Transactions component on #cp-root inside
// the PHP wrapper at /interface/patient_file/transaction/copilot_transactions.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Transactions } from './Transactions';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Transactions entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Transactions boot={boot} />
  </StrictMode>,
);
