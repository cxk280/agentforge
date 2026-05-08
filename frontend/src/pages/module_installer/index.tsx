// ModuleInstaller entry — mounts the ModuleInstaller component on #cp-root
// inside the PHP wrapper at /interface/super/copilot_module_installer.php.
//
// The wrapper queries the live `modules` table (admin scope) and JSON-encodes
// the typed payload onto data-modules. We parse it here with a defensive
// shape guard.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ModuleInstaller } from './ModuleInstaller';
import type { ModuleInstallerPayload } from './ModuleInstaller';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('ModuleInstaller entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: ModuleInstallerPayload = { modules: [], installedCount: 0 };

function parsePayload(raw: string): ModuleInstallerPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const modules = Array.isArray(o['modules']) ? (o['modules'] as ModuleInstallerPayload['modules']) : [];
    const installedCount = typeof o['installedCount'] === 'number' ? o['installedCount'] : 0;
    return { modules, installedCount };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['modules'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <ModuleInstaller boot={boot} payload={payload} />
  </StrictMode>,
);
