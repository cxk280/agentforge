// Batch Results entry — mounts the BatchResults component on #cp-root
// inside the PHP wrapper at /interface/orders/copilot_batch_results.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BatchResults } from './BatchResults';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('BatchResults entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <BatchResults boot={boot} />
  </StrictMode>,
);
