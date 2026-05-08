// Pro entry — mounts the Pro component on #cp-root inside the PHP wrapper at
// /interface/easipro/copilot_pro.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Pro } from './Pro';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Pro entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Pro boot={boot} />
  </StrictMode>,
);
