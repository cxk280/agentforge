// CodingLists entry — mounts the CodingLists component on #cp-root inside the
// PHP wrapper at /interface/super/copilot_coding_lists.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { CodingLists } from './CodingLists';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('CodingLists entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <CodingLists boot={boot} />
  </StrictMode>,
);
