// Documents entry — mounts the Documents component on #cp-root inside the
// PHP wrapper at /interface/patient_file/documents/copilot_documents.php.
//
// The wrapper queries the documents table (LEFT JOIN cp_extraction_runs)
// and JSON-encodes the result as data-live-docs. We parse it here and hand
// it to the component so the LIVE upload-and-extract row renders correctly.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Documents } from './Documents';
import type { LiveDoc } from './Documents';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Documents entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

let liveDocs: readonly LiveDoc[] = [];
try {
  const raw = mountEl.dataset['liveDocs'] ?? '[]';
  const parsed = JSON.parse(raw);
  if (Array.isArray(parsed)) liveDocs = parsed as LiveDoc[];
} catch (err) {
  console.error('Documents: failed to parse data-live-docs', err);
}
const copilotBackend = mountEl.dataset['copilotBackend'] ?? '';

createRoot(mountEl).render(
  <StrictMode>
    <Documents boot={boot} liveDocs={liveDocs} copilotBackend={copilotBackend} />
  </StrictMode>,
);
