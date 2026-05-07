// Reports entry — mounts the Reports component on #cp-root inside the PHP
// wrapper at /interface/reports/copilot_reports.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Reports } from './Reports';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Reports entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Reports boot={boot} />
  </StrictMode>,
);
