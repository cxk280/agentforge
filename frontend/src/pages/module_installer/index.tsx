// ModuleInstaller entry — mounts the ModuleInstaller component on #cp-root
// inside the PHP wrapper at /interface/super/copilot_module_installer.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ModuleInstaller } from './ModuleInstaller';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ModuleInstaller entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <ModuleInstaller boot={boot} />
  </StrictMode>,
);
