// Aging entry — mounts the Aging component on #cp-root inside the PHP
// wrapper at /interface/billing/copilot_aging.php.
//
// The wrapper computes per-bucket aging (0-30, 31-60, 61-90, 91-120,
// >120) by reconciling each billing row against ar_activity, then
// JSON-encodes the typed payload onto data-aging.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Aging } from './Aging';
import type { AgingPayload } from './Aging';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Aging entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: AgingPayload = {
  kpis: [], bucketTiles: [], bucketBarPcts: [], accounts: [], totalAR: 0,
};

function parsePayload(raw: string): AgingPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    return {
      kpis: Array.isArray(o['kpis']) ? (o['kpis'] as AgingPayload['kpis']) : [],
      bucketTiles: Array.isArray(o['bucketTiles']) ? (o['bucketTiles'] as AgingPayload['bucketTiles']) : [],
      bucketBarPcts: Array.isArray(o['bucketBarPcts']) ? (o['bucketBarPcts'] as AgingPayload['bucketBarPcts']) : [],
      accounts: Array.isArray(o['accounts']) ? (o['accounts'] as AgingPayload['accounts']) : [],
      totalAR: typeof o['totalAR'] === 'number' ? o['totalAR'] : 0,
    };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['aging'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <Aging boot={boot} payload={payload} />
  </StrictMode>,
);
