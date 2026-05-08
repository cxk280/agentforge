// Login — Figma "Screen 28 — Login". 1:1 port of the static-HTML mock previously
// at /interface/login/copilot_login.php.
//
// PRE-AUTH PAGE. The wrapper does not include OpenEMR's globals.php, so this
// component never receives a real CSRF token (the original mock had none) and
// no userId/patientId. The boot context is therefore minimal — just the page
// id, the form action, and the (empty) CSRF token slot.
//
// Behaviors preserved from the original PHP:
//   - method=post, action= the same endpoint the PHP form posted to
//     (defaults to "login.php"), target unset (no _top), autocomplete="off"
//   - input names: authUser (text), clearPass (password), facility (text)
//   - inputs are EMPTY by default — the original PHP shipped placeholder values
//     ("erivera", "************", "Riverside Family Medicine") that were
//     visual-only mock filler. Real auth requires empty inputs the user types
//     into, so we drop the prefill.
//   - "Show password" eye toggles password type between password/text
//   - "Sign in with SSO (SAML)" is a non-submitting visual button (type=button)
//
// The "Forgot?" link and the SSO button are wired but inert (the original PHP
// also rendered them as href="#" / type="button" stubs).

import { useState, type FormEvent } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Login.module.css';

type LoginBootContext = BootContext & {
  readonly formAction: string;
};

type LoginProps = {
  readonly boot: LoginBootContext;
};

type FieldErrors = {
  authUser?: string | undefined;
  clearPass?: string | undefined;
};

export function Login({ boot }: LoginProps): JSX.Element {
  const [authUser, setAuthUser] = useState('');
  const [clearPass, setClearPass] = useState('');
  const [facility, setFacility] = useState('');
  const [showPass, setShowPass] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});

  // Mirror the OpenEMR login flow: client-side we only require username +
  // password to be non-empty; everything else (bad credentials, locked-out
  // accounts, facility-not-permitted, etc.) is reported by the server after
  // POST. If validation fails we prevent submission and surface inline errors.
  function handleSubmit(e: FormEvent<HTMLFormElement>): void {
    const next: FieldErrors = {};
    if (authUser.trim() === '') {
      next.authUser = 'Username is required';
    }
    if (clearPass === '') {
      next.clearPass = 'Password is required';
    }
    if (next.authUser !== undefined || next.clearPass !== undefined) {
      e.preventDefault();
      setErrors(next);
      return;
    }
    setErrors({});
    // Allow native form POST to proceed.
  }

  return (
    <div className={styles.page}>
      <div className={styles.logoBlock}>
        <div className={styles.logoRow}>
          <span className={styles.logoMark} aria-hidden="true" />
          <span className={styles.logoText}>
            Clinical <span className={styles.logoTextAccent}>Co-Pilot</span>
          </span>
        </div>
        <div className={styles.logoSub}>Embedded in OpenEMR</div>
      </div>

      <form
        className={styles.card}
        method="post"
        action={boot.formAction}
        autoComplete="off"
        onSubmit={handleSubmit}
        noValidate
      >
        <h1 className={styles.title}>Sign in</h1>
        <div className={styles.subtitle}>
          Welcome back. Please sign in with your OpenEMR credentials.
        </div>

        {/* CSRF token — the original mock did not emit one. We render the
            hidden input only when the wrapper provides a non-empty value so
            future hardening (when copilot_login graduates past static-mock)
            slots in without a code change here. */}
        {boot.csrf !== '' && (
          <input type="hidden" name="csrf_token_form" value={boot.csrf} />
        )}

        <div className={styles.field}>
          <div className={styles.fieldRow}>
            <label className={styles.label} htmlFor="cp-user">Username</label>
          </div>
          <div className={`${styles.inputWrap} ${errors.authUser !== undefined ? styles.inputWrapError : ''}`}>
            <span className={styles.inputIcon} aria-hidden="true">👤</span>
            <input
              id="cp-user"
              name="authUser"
              type="text"
              value={authUser}
              onChange={(e) => setAuthUser(e.target.value)}
              autoComplete="username"
              aria-invalid={errors.authUser !== undefined}
              aria-describedby={errors.authUser !== undefined ? 'cp-user-err' : undefined}
            />
          </div>
          {errors.authUser !== undefined && (
            <div id="cp-user-err" className={styles.fieldError} role="alert">
              {errors.authUser}
            </div>
          )}
        </div>

        <div className={styles.field}>
          <div className={styles.fieldRow}>
            <label className={styles.label} htmlFor="cp-pass">Password</label>
            <a className={styles.forgot} href="#">Forgot?</a>
          </div>
          <div className={`${styles.inputWrap} ${errors.clearPass !== undefined ? styles.inputWrapError : ''}`}>
            <span className={styles.inputIcon} aria-hidden="true">🔒</span>
            <input
              id="cp-pass"
              name="clearPass"
              type={showPass ? 'text' : 'password'}
              value={clearPass}
              onChange={(e) => setClearPass(e.target.value)}
              autoComplete="current-password"
              aria-invalid={errors.clearPass !== undefined}
              aria-describedby={errors.clearPass !== undefined ? 'cp-pass-err' : undefined}
            />
            <button
              type="button"
              className={styles.inputEye}
              aria-label={showPass ? 'Hide password' : 'Show password'}
              aria-pressed={showPass}
              onClick={() => setShowPass((v) => !v)}
            >
              👁
            </button>
          </div>
          {errors.clearPass !== undefined && (
            <div id="cp-pass-err" className={styles.fieldError} role="alert">
              {errors.clearPass}
            </div>
          )}
        </div>

        <div className={styles.field}>
          <div className={styles.fieldRow}>
            <label className={styles.label} htmlFor="cp-fac">Facility</label>
          </div>
          <div className={styles.inputWrap}>
            <span className={styles.inputIcon} aria-hidden="true">🏥</span>
            <input
              id="cp-fac"
              name="facility"
              type="text"
              value={facility}
              onChange={(e) => setFacility(e.target.value)}
              placeholder="My default facility"
            />
            <span className={styles.inputCaret} aria-hidden="true">▾</span>
          </div>
        </div>

        <button type="submit" className={styles.btnPrimary}>
          Sign in&nbsp;→
        </button>

        <div className={styles.or}>OR</div>

        <button type="button" className={styles.btnSso}>
          <span className={styles.ssoIcon} aria-hidden="true">🪪</span>
          Sign in with SSO (SAML)
        </button>
      </form>

      <div className={styles.foot}>
        <span>AgentForge v0.4.2</span>
        <span className={styles.footDot}>•</span>
        <a href="#">Help</a>
        <span className={styles.footDot}>•</span>
        <span>
          <span className={styles.okDot} aria-hidden="true" />
          System status • All services up
        </span>
      </div>
    </div>
  );
}
