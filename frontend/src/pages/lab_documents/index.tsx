// LabDocuments entry — mounts the LabDocuments component on #cp-root inside
// the PHP wrapper at /interface/patient_file/documents/copilot_lab_documents.php.
//
// The wrapper queries `documents` joined to categories + patient_data and
// JSON-encodes the typed payload onto data-docs. The component renders
// rows straight from that payload — no hardcoded inbox.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { LabDocuments } from './LabDocuments';
import type { DocsPayload } from './LabDocuments';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('LabDocuments entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: DocsPayload = {
  docs: [],
  counts: { all: 0, unmatched: 0, lab: 0, imaging: 0, discharge: 0, other: 0 },
};

function parsePayload(raw: string): DocsPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const docs = Array.isArray(o['docs']) ? (o['docs'] as DocsPayload['docs']) : [];
    const counts = (o['counts'] && typeof o['counts'] === 'object')
      ? (o['counts'] as DocsPayload['counts'])
      : EMPTY.counts;
    return { docs, counts };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['docs'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <LabDocuments boot={boot} payload={payload} />
  </StrictMode>,
);
