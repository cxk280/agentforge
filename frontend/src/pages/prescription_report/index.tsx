// PrescriptionReport entry — mounts the PrescriptionReport component on
// #cp-root inside the PHP wrapper at
// /interface/reports/copilot_prescription_report.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PrescriptionReport } from './PrescriptionReport';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PrescriptionReport entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <PrescriptionReport boot={boot} />
  </StrictMode>,
);
