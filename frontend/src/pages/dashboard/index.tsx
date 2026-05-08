// Dashboard entry — mounts the Dashboard component on #cp-root inside the
// PHP wrapper at /interface/patient_file/summary/copilot_dashboard.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Dashboard } from './Dashboard';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Dashboard entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Dashboard boot={boot} />
  </StrictMode>,
);
