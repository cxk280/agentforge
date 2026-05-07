// PracticeSettings entry — mounts the PracticeSettings component on
// #cp-root inside the PHP wrapper at
// /interface/super/copilot_practice_settings.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PracticeSettings } from './PracticeSettings';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PracticeSettings entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <PracticeSettings boot={boot} />
  </StrictMode>,
);
