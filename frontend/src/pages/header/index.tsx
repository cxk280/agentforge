// Header entry — mounts the navy top nav on #cp-header inside the
// outer shell at /interface/main/tabs/main.php (NOT inside an iframe).
//
// Boot data is parsed from data attributes on the mount node, plus a
// JSON-encoded `data-nav` attribute that lists primary + more items.
// PHP enumerates them server-side from the existing OpenEMR menu so
// role visibility, URLs, and tab targets stay consistent.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Header } from './Header';
import { readBootContext } from '../../shared/lib/bootContext';

type NavItem = {
  readonly label: string;
  readonly target: string;
  readonly url: string;
};

type NavPayload = {
  readonly primary: readonly NavItem[];
  readonly more: readonly NavItem[];
  readonly finderUrl: string;
};

const mountEl = document.getElementById('cp-header');
if (!mountEl) {
  throw new Error('Header entry: #cp-header mount node not found');
}

const boot = readBootContext(mountEl);
const userName = mountEl.dataset['userName'] ?? '';
const avatarMenuUrl = mountEl.dataset['avatarMenuUrl'] ?? '';
const initialActiveTarget = mountEl.dataset['activeTarget'] ?? '';
const navRaw = mountEl.dataset['nav'] ?? '{"primary":[],"more":[],"finderUrl":""}';
const nav = JSON.parse(navRaw) as NavPayload;

createRoot(mountEl).render(
  <StrictMode>
    <Header
      boot={boot}
      userName={userName}
      avatarMenuUrl={avatarMenuUrl}
      initialActiveTarget={initialActiveTarget}
      primaryItems={nav.primary}
      moreItems={nav.more}
      finderUrl={nav.finderUrl}
    />
  </StrictMode>,
);
