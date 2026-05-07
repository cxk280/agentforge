// LabTrends entry — mounts the LabTrends component on #cp-root inside the PHP
// wrapper at /interface/reports/copilot_lab_trends.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { LabTrends } from './LabTrends';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('LabTrends entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <LabTrends boot={boot} />
  </StrictMode>,
);
