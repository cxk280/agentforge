// Inventory entry — mounts the Inventory component on #cp-root inside the
// PHP wrapper at /interface/billing/copilot_inventory.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Inventory } from './Inventory';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Inventory entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <Inventory boot={boot} />
  </StrictMode>,
);
