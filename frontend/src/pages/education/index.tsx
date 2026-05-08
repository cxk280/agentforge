// Education entry — mounts the Education component on #cp-root inside the
// PHP wrapper at /interface/patient_file/education/copilot_education.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Education } from './Education';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Education entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Education boot={boot} />
  </StrictMode>,
);
