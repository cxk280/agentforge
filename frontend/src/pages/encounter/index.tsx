// Encounter entry — mounts the Encounter component on #cp-root inside the PHP
// wrapper at /interface/patient_file/encounter/copilot_encounter.php.
//
// The wrapper queries form_encounter (preferring ?eid=N from the Visit-History
// "Open →" link, otherwise the most recent open encounter for the patient)
// and form_vitals (most recent), and JSON-encodes the typed payload onto
// data-encounter. We parse it here with a defensive shape guard.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Encounter } from './Encounter';
import type { EncounterPayload, EncounterRow, VitalsRow } from './Encounter';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Encounter entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: EncounterPayload = { encounter: null, vitals: null };

function parsePayload(raw: string): EncounterPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const encUnknown = o['encounter'];
    const vitalsUnknown = o['vitals'];
    const enc = (encUnknown !== null && typeof encUnknown === 'object')
      ? (encUnknown as EncounterRow)
      : null;
    const vitals = (vitalsUnknown !== null && typeof vitalsUnknown === 'object')
      ? (vitalsUnknown as VitalsRow)
      : null;
    return { encounter: enc, vitals };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['encounter'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <Encounter boot={boot} payload={payload} />
  </StrictMode>,
);
