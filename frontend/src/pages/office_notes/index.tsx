// Office Notes entry — mounts the OfficeNotes component on #cp-root inside
// the PHP wrapper at /interface/main/onotes/copilot_office_notes.php.
//
// The PHP wrapper queries the `onotes` table (with cp_pinned / cp_category /
// cp_parent_id extension columns) and JSON-encodes the result as data-notes
// on #cp-root. We parse that here and hand it to the component.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { OfficeNotes } from './OfficeNotes';
import type { OfficeNotesPayload } from './OfficeNotes';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('OfficeNotes entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY_PAYLOAD: OfficeNotesPayload = {
  notes: [],
  counts: {
    all: 0,
    pinned: 0,
    pharmacy: 0,
    clinical: 0,
    front_desk: 0,
    billing: 0,
    maintenance: 0,
  },
};

let payload: OfficeNotesPayload = EMPTY_PAYLOAD;
try {
  const raw = mountEl.dataset['notes'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw) as unknown;
    if (
      parsed !== null
      && typeof parsed === 'object'
      && 'notes' in parsed
      && 'counts' in parsed
    ) {
      payload = parsed as OfficeNotesPayload;
    }
  }
} catch (err) {
  console.error('OfficeNotes: failed to parse data-notes', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <OfficeNotes boot={boot} payload={payload} />
  </StrictMode>,
);
