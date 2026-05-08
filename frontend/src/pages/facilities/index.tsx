// Facilities entry — mounts the Facilities component on #cp-root inside the
// PHP wrapper at /interface/super/copilot_facilities.php.
//
// The wrapper queries the live `facility` table (admin scope) and JSON-encodes
// the typed payload onto data-facilities. We parse it here with a defensive
// shape guard.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Facilities } from './Facilities';
import type { FacilitiesPayload } from './Facilities';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Facilities entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: FacilitiesPayload = { facilities: [], facilityCount: 0, activeFacilityCount: 0 };

function parsePayload(raw: string): FacilitiesPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const facilities = Array.isArray(o['facilities']) ? (o['facilities'] as FacilitiesPayload['facilities']) : [];
    const facilityCount = typeof o['facilityCount'] === 'number' ? o['facilityCount'] : 0;
    const activeFacilityCount = typeof o['activeFacilityCount'] === 'number' ? o['activeFacilityCount'] : 0;
    return { facilities, facilityCount, activeFacilityCount };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['facilities'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <Facilities boot={boot} payload={payload} />
  </StrictMode>,
);
