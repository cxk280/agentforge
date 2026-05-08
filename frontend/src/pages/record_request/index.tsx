// RecordRequest entry — mounts the RecordRequest component on #cp-root
// inside the PHP wrapper at
// /interface/patient_file/transaction/copilot_record_request.php.
//
// The PHP wrapper queries the records-release queue (transactions + lbt_data)
// and the recipient list (pharmacies / procedure_providers), JSON-encodes the
// result onto data-records, and we parse it here before handing the typed
// payload to the component. Empty payload → empty state renders cleanly.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RecordRequest } from './RecordRequest';
import type { RecordRequestPayload } from './RecordRequest';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('RecordRequest entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: RecordRequestPayload = {
  patientName: '',
  recipients: [],
  queue: [],
  kpis: { active: 0, awaiting: 0, completed30: 0, failed: 0 },
};

let records: RecordRequestPayload = EMPTY;
try {
  const raw = mountEl.dataset['records'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw) as Partial<RecordRequestPayload>;
    records = {
      patientName: parsed.patientName ?? '',
      recipients: Array.isArray(parsed.recipients) ? parsed.recipients : [],
      queue: Array.isArray(parsed.queue) ? parsed.queue : [],
      kpis: {
        active:      parsed.kpis?.active      ?? 0,
        awaiting:    parsed.kpis?.awaiting    ?? 0,
        completed30: parsed.kpis?.completed30 ?? 0,
        failed:      parsed.kpis?.failed      ?? 0,
      },
    };
  }
} catch (err) {
  console.error('RecordRequest: failed to parse data-records', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <RecordRequest boot={boot} records={records} />
  </StrictMode>,
);
