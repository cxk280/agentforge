// Messages entry — mounts the Messages component on #cp-root inside the PHP
// wrapper at /interface/main/messages/copilot_messages.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Messages } from './Messages';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Messages entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Messages boot={boot} />
  </StrictMode>,
);
