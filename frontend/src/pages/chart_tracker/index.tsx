// Chart Tracker entry — mounts the ChartTracker component on #cp-root
// inside the PHP wrapper at /custom/copilot_chart_tracker.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ChartTracker } from './ChartTracker';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ChartTracker entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <ChartTracker boot={boot} />
  </StrictMode>,
);
