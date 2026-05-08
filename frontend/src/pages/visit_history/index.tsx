// VisitHistory entry — mounts the VisitHistory component on #cp-root
// inside the PHP wrapper at
// /interface/patient_file/encounter/copilot_visit_history.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { VisitHistory } from './VisitHistory';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('VisitHistory entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <VisitHistory boot={boot} />
  </StrictMode>,
);
