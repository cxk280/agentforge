// Users & Groups entry — mounts the Users component on #cp-root inside
// the PHP wrapper at /interface/super/copilot_users.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Users } from './Users';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Users entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Users boot={boot} />
  </StrictMode>,
);
