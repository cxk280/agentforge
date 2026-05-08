// Pending Review entry — mounts the PendingReview component on #cp-root inside
// the PHP wrapper at /interface/orders/copilot_pending_review.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PendingReview } from './PendingReview';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PendingReview entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <PendingReview boot={boot} />
  </StrictMode>,
);
