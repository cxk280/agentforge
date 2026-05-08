// Pending Review entry — mounts the PendingReview component on #cp-root inside
// the PHP wrapper at /interface/orders/copilot_pending_review.php.
//
// The wrapper queries procedure_result -> procedure_report -> procedure_order
// for any report still in 'unreviewed'/'preliminary' state, joins
// patient_data + users for display labels, and JSON-encodes the typed payload
// onto data-pending.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PendingReview } from './PendingReview';
import type { PendingPayload } from './PendingReview';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PendingReview entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: PendingPayload = {
  queue: [],
  counts: { all: 0, lab: 0, imaging: 0, doc: 0, msg: 0, critical: 0 },
  headerSummary: 'Queue empty · 0 awaiting sign-off',
};

function parsePayload(raw: string): PendingPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const queue = Array.isArray(o['queue']) ? (o['queue'] as PendingPayload['queue']) : [];
    const counts = (o['counts'] && typeof o['counts'] === 'object')
      ? (o['counts'] as PendingPayload['counts'])
      : EMPTY.counts;
    const headerSummary = typeof o['headerSummary'] === 'string'
      ? o['headerSummary']
      : EMPTY.headerSummary;
    return { queue, counts, headerSummary };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['pending'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <PendingReview boot={boot} payload={payload} />
  </StrictMode>,
);
