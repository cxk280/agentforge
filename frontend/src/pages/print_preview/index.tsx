// PrintPreview entry — mounts the PrintPreview component on #cp-root inside
// the PHP wrapper at /interface/main/copilot_print_preview.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PrintPreview } from './PrintPreview';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('PrintPreview entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <PrintPreview boot={boot} />
  </StrictMode>,
);
