// External Data entry — mounts the ExternalData component on #cp-root inside
// the PHP wrapper at /interface/patient_file/external_data/copilot_external_data.php.
//
// The PHP wrapper JSON-encodes the source cards, recent-imports feed, and
// summary counts onto data-sources / data-imports / data-summary on #cp-root
// (currently hardcoded stubs — see TODO(real-data) in the wrapper). We
// parse those here and hand them to the component as props, mirroring the
// Finder pattern so the React side is consistent across pages even while
// the underlying data is still mock.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ExternalData } from './ExternalData';
import type { ExternalDataSummary, ImportRow, SourceCard } from './ExternalData';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ExternalData entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

function parseJsonArray<T>(raw: string | undefined): readonly T[] {
  if (raw === undefined || raw === '') return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as T[]) : [];
  } catch (err) {
    console.error('ExternalData: failed to parse data attribute', err);
    return [];
  }
}

function parseSummary(raw: string | undefined): ExternalDataSummary {
  if (raw === undefined || raw === '') return { connected: 0, pending: 0 };
  try {
    const parsed = JSON.parse(raw) as { connected?: unknown; pending?: unknown };
    const connected = typeof parsed.connected === 'number' ? parsed.connected : 0;
    const pending = typeof parsed.pending === 'number' ? parsed.pending : 0;
    return { connected, pending };
  } catch (err) {
    console.error('ExternalData: failed to parse data-summary', err);
    return { connected: 0, pending: 0 };
  }
}

const sources = parseJsonArray<SourceCard>(mountEl.dataset['sources']);
const imports = parseJsonArray<ImportRow>(mountEl.dataset['imports']);
const summary = parseSummary(mountEl.dataset['summary']);

createRoot(mountEl).render(
  <StrictMode>
    <ExternalData boot={boot} sources={sources} imports={imports} summary={summary} />
  </StrictMode>,
);
