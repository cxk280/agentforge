// Inventory entry — mounts the Inventory component on #cp-root inside the
// PHP wrapper at /interface/billing/copilot_inventory.php.
//
// The wrapper queries drug_inventory joined to drugs and JSON-encodes the
// typed payload onto data-inventory.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Inventory } from './Inventory';
import type { InventoryPayload } from './Inventory';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Inventory entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: InventoryPayload = { rows: [] };

function parsePayload(raw: string): InventoryPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const rows = Array.isArray(o['rows']) ? (o['rows'] as InventoryPayload['rows']) : [];
    return { rows };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['inventory'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <Inventory boot={boot} payload={payload} />
  </StrictMode>,
);
