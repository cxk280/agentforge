// CreateVisit entry — mounts the CreateVisit component on #cp-root inside the
// PHP wrapper at /interface/forms/newpatient/copilot_create_visit.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { CreateVisit } from './CreateVisit';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('CreateVisit entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <CreateVisit boot={boot} />
  </StrictMode>,
);
