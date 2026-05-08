// RecordRequest entry — mounts the RecordRequest component on #cp-root
// inside the PHP wrapper at
// /interface/patient_file/transaction/copilot_record_request.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RecordRequest } from './RecordRequest';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('RecordRequest entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <RecordRequest boot={boot} />
  </StrictMode>,
);
