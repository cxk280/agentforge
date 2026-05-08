// LabOverview entry — mounts the LabOverview component on #cp-root inside
// the PHP wrapper at /interface/orders/copilot_lab_overview.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { LabOverview } from './LabOverview';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('LabOverview entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <LabOverview boot={boot} />
  </StrictMode>,
);
