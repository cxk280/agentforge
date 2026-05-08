// LabDocuments entry — mounts the LabDocuments component on #cp-root inside
// the PHP wrapper at /interface/patient_file/documents/copilot_lab_documents.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { LabDocuments } from './LabDocuments';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('LabDocuments entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <LabDocuments boot={boot} />
  </StrictMode>,
);
