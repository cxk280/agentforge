// CodingLists entry — mounts the CodingLists component on #cp-root inside the
// PHP wrapper at /interface/super/copilot_coding_lists.php.
//
// The wrapper composes a curated set of external code-system rows (ICD-10,
// CPT, SNOMED, RxNorm, LOINC) with live in-house list rows pulled from
// list_options (using list_id='lists' as the meta-list of all lists, with
// COUNT(*) per list_id), and JSON-encodes the typed payload onto
// data-coding-lists. We parse it here with a defensive shape guard.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { CodingLists } from './CodingLists';
import type { CodingListsPayload } from './CodingLists';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('CodingLists entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: CodingListsPayload = { codeSystems: [], lists: [], totalLists: 0 };

function parsePayload(raw: string): CodingListsPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const codeSystems = Array.isArray(o['codeSystems']) ? (o['codeSystems'] as CodingListsPayload['codeSystems']) : [];
    const lists = Array.isArray(o['lists']) ? (o['lists'] as CodingListsPayload['lists']) : [];
    const totalLists = typeof o['totalLists'] === 'number' ? o['totalLists'] : 0;
    return { codeSystems, lists, totalLists };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['codingLists'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <CodingLists boot={boot} payload={payload} />
  </StrictMode>,
);
