// FormsLayouts entry — mounts the FormsLayouts component on #cp-root inside
// the PHP wrapper at /interface/super/copilot_forms_layouts.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { FormsLayouts } from './FormsLayouts';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('FormsLayouts entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <FormsLayouts boot={boot} />
  </StrictMode>,
);
