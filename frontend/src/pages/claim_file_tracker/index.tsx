// ClaimFileTracker entry — mounts the ClaimFileTracker component on #cp-root
// inside the PHP wrapper at /interface/billing/copilot_claim_file_tracker.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ClaimFileTracker } from './ClaimFileTracker';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ClaimFileTracker entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <ClaimFileTracker boot={boot} />
  </StrictMode>,
);
