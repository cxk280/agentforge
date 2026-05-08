// Users & Groups entry — mounts the Users component on #cp-root inside
// the PHP wrapper at /interface/super/copilot_users.php.
//
// The wrapper queries `users` (joined to `groups` and `log` for last-login)
// plus a separate `groups` aggregation, and JSON-encodes the typed payload
// onto data-users. We parse it here with a defensive shape guard.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Users } from './Users';
import type { UsersPayload } from './Users';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Users entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: UsersPayload = { users: [], groups: [], activeCount: 0, serviceCount: 0 };

function parsePayload(raw: string): UsersPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const users = Array.isArray(o['users']) ? (o['users'] as UsersPayload['users']) : [];
    const groups = Array.isArray(o['groups']) ? (o['groups'] as UsersPayload['groups']) : [];
    const activeCount = typeof o['activeCount'] === 'number' ? o['activeCount'] : 0;
    const serviceCount = typeof o['serviceCount'] === 'number' ? o['serviceCount'] : 0;
    return { users, groups, activeCount, serviceCount };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['users'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <Users boot={boot} payload={payload} />
  </StrictMode>,
);
