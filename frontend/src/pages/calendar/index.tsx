// Calendar entry — mounts the Calendar component on #cp-root inside the PHP
// wrapper at /interface/main/calendar/copilot_calendar.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Calendar } from './Calendar';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Calendar entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Calendar boot={boot} />
  </StrictMode>,
);
