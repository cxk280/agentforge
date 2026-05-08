// Authorizations entry — mounts the Authorizations component on #cp-root
// inside the PHP wrapper at
// /interface/patient_file/transaction/copilot_authorizations.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Authorizations } from './Authorizations';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Authorizations entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Authorizations boot={boot} />
  </StrictMode>,
);
