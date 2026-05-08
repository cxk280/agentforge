// Finder entry — mounts the Finder component on #cp-root inside the PHP
// wrapper at /interface/main/finder/copilot_finder.php.
//
// The PHP wrapper queries patient_data + JOINs (same query the pre-React
// page used) and JSON-encodes the result as data-roster on #cp-root. We
// parse that here and hand it to the component, so PIDs the user clicks
// on actually map to real patients in the database.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Finder } from './Finder';
import { readBootContext } from '../../shared/lib/bootContext';
import type { RosterPatient } from './Finder';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Finder entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

let roster: readonly RosterPatient[] = [];
try {
  const raw = mountEl.dataset['roster'] ?? '[]';
  const parsed = JSON.parse(raw);
  if (Array.isArray(parsed)) {
    roster = parsed as RosterPatient[];
  }
} catch (err) {
  console.error('Finder: failed to parse data-roster', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <Finder boot={boot} roster={roster} />
  </StrictMode>,
);
