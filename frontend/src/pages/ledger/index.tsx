// Ledger entry — mounts the Ledger component on #cp-root inside the PHP
// wrapper at /interface/patient_file/ledger/copilot_ledger.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Ledger } from './Ledger';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Ledger entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Ledger boot={boot} />
  </StrictMode>,
);
