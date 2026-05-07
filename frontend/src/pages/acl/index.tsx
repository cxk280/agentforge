// ACL Editor entry — mounts the Acl component on #cp-root inside the PHP
// wrapper at /interface/super/copilot_acl.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Acl } from './Acl';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Acl entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Acl boot={boot} />
  </StrictMode>,
);
