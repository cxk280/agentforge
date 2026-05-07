// Admin entry — mounts the Admin component on #cp-root inside the PHP
// wrapper at /interface/super/copilot_admin.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Admin } from './Admin';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Admin entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Admin boot={boot} />
  </StrictMode>,
);
