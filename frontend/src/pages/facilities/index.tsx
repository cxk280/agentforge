// Facilities entry — mounts the Facilities component on #cp-root inside the
// PHP wrapper at /interface/super/copilot_facilities.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Facilities } from './Facilities';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Facilities entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Facilities boot={boot} />
  </StrictMode>,
);
