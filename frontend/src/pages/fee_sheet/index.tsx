// FeeSheet entry — mounts the FeeSheet component on #cp-root inside the PHP
// wrapper at /interface/forms/fee_sheet/copilot_fee_sheet.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { FeeSheet } from './FeeSheet';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('FeeSheet entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <FeeSheet boot={boot} />
  </StrictMode>,
);
