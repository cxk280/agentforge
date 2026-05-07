// Finder entry — mounts the Finder component on #cp-root inside the PHP
// wrapper at /interface/main/finder/copilot_finder.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Finder } from './Finder';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Finder entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Finder boot={boot} />
  </StrictMode>,
);
