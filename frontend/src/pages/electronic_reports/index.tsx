// ElectronicReports entry — mounts the ElectronicReports component on
// #cp-root inside the PHP wrapper at
// /interface/reports/copilot_electronic_reports.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ElectronicReports } from './ElectronicReports';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ElectronicReports entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <ElectronicReports boot={boot} />
  </StrictMode>,
);
