// Authorizations entry — mounts the Authorizations component on #cp-root
// inside the PHP wrapper at
// /interface/patient_file/transaction/copilot_authorizations.php.
//
// The PHP wrapper queries the cp_authorizations table (joined to
// patient_data + users) and JSON-encodes the result as data-auth on
// #cp-root. We parse that here and hand it to the component, so the
// rendered queue reflects the live practice-wide auth queue.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Authorizations } from './Authorizations';
import type { AuthPayload } from './Authorizations';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Authorizations entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

let auth: AuthPayload | undefined;
try {
  const raw = mountEl.dataset['auth'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw) as unknown;
    if (
      parsed !== null &&
      typeof parsed === 'object' &&
      Array.isArray((parsed as { rows?: unknown }).rows) &&
      Array.isArray((parsed as { kpis?: unknown }).kpis)
    ) {
      auth = parsed as AuthPayload;
    }
  }
} catch (err) {
  console.error('Authorizations: failed to parse data-auth', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <Authorizations boot={boot} auth={auth} />
  </StrictMode>,
);
