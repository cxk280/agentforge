// Office Notes entry — mounts the OfficeNotes component on #cp-root inside
// the PHP wrapper at /interface/main/onotes/copilot_office_notes.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { OfficeNotes } from './OfficeNotes';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('OfficeNotes entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <OfficeNotes boot={boot} />
  </StrictMode>,
);
