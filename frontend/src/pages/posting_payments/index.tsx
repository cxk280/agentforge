// PostingPayments entry — mounts the PostingPayments component on #cp-root
// inside the PHP wrapper at /interface/billing/copilot_posting_payments.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PostingPayments } from './PostingPayments';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PostingPayments entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <PostingPayments boot={boot} />
  </StrictMode>,
);
