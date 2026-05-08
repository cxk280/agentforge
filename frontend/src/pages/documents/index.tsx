// Documents entry — mounts the Documents component on #cp-root inside the
// PHP wrapper at /interface/patient_file/documents/copilot_documents.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Documents } from './Documents';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Documents entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Documents boot={boot} />
  </StrictMode>,
);
