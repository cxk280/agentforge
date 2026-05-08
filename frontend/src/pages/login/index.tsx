// Login entry — mounts the Login component on #cp-root inside the PHP
// wrapper at /interface/login/copilot_login.php.
//
// PRE-AUTH: the wrapper does NOT include globals.php and does NOT have a
// real CSRF token to bind. We extend the standard BootContext with a
// data-form-action attribute that carries the login form's POST target
// (defaults to "login.php" — the original PHP's action) so the wrapper can
// rewrite it without touching the React bundle.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Login } from './Login';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Login entry: #cp-root mount node not found');
}

const baseBoot = readBootContext(mountEl);
const formAction = mountEl.dataset['formAction'] ?? 'login.php';

createRoot(mountEl).render(
  <StrictMode>
    <Login boot={{ ...baseBoot, formAction }} />
  </StrictMode>,
);
