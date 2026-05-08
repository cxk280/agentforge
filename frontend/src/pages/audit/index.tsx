// Audit entry — mounts the Audit component on #cp-root inside the PHP
// wrapper at /interface/super/copilot_audit.php.
//
// The wrapper queries the live `log` table (most recent 50 events plus
// the 7-day total) and JSON-encodes the typed payload onto data-audit.
// We parse it here with a defensive shape guard.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Audit } from './Audit';
import type { AuditPayload, AuditRowFromServer } from './Audit';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Audit entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: AuditPayload = { rows: [], total7d: 0 };

function parsePayload(raw: string): AuditPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const rows = Array.isArray(o['rows']) ? (o['rows'] as AuditRowFromServer[]) : [];
    const total7d = typeof o['total7d'] === 'number' ? o['total7d'] : 0;
    return { rows, total7d };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['audit'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <Audit boot={boot} payload={payload} />
  </StrictMode>,
);
