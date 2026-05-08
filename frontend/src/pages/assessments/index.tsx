// Assessments entry — mounts the Assessments component on #cp-root inside the
// PHP wrapper at /interface/patient_file/assessments/copilot_assessments.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Assessments } from './Assessments';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Assessments entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Assessments boot={boot} />
  </StrictMode>,
);
