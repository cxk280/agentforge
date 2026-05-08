<?php

/**
 * Login screen — implements Screen 28 of the AgentForge mockups.
 *
 * Centered sign-in card with username/password/facility, primary
 * "Sign in" CTA, and an "Sign in with SSO (SAML)" alternate path.
 * Dark navy/teal gradient background with the Clinical Co-Pilot logo
 * above the card.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Login page is intentionally chrome-less — no globals.php include since
// it must be reachable pre-auth.
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Clinical Co-Pilot</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background:
      radial-gradient(ellipse at 30% 20%, rgba(0, 140, 140, 0.22), transparent 60%),
      radial-gradient(ellipse at 80% 90%, rgba(0, 140, 140, 0.10), transparent 55%),
      linear-gradient(180deg, #0B1626 0%, #0D1B2A 100%);
    color: #0D1B2A;
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 40px 20px;
  }
  button { font-family: inherit; cursor: pointer; }

  /* Logo block */
  .cp-logo-block {
    display: flex; flex-direction: column; align-items: center;
    gap: 4px;
    margin-bottom: 32px;
  }
  .cp-logo-row { display: inline-flex; align-items: center; gap: 10px; }
  .cp-logo-mark {
    width: 30px; height: 30px;
    background: #008C8C;
    border-radius: 7px;
  }
  .cp-logo-text { font-size: 22px; font-weight: 700; color: #FFFFFF; line-height: 1; }
  .cp-logo-text .a { color: #FFFFFF; }
  .cp-logo-text .b { color: #FFFFFF; opacity: 0.92; }
  .cp-logo-sub { font-size: 11px; color: #8A91A1; letter-spacing: 0.3px; }

  /* Card */
  .cp-card {
    background: #FFFFFF;
    width: 100%;
    max-width: 380px;
    border-radius: 16px;
    box-shadow:
      0 1px 2px rgba(0, 0, 0, 0.10),
      0 16px 40px rgba(0, 0, 0, 0.30);
    padding: 28px 28px 24px;
  }
  .cp-card h1 {
    font-size: 18px; font-weight: 700; color: #0D1B2A;
    margin: 0 0 6px;
    line-height: 1.2;
  }
  .cp-card .cp-card-sub {
    font-size: 12px; color: #4F5763;
    margin-bottom: 20px;
    line-height: 1.4;
  }

  /* Field */
  .cp-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
  .cp-field-row { display: flex; align-items: center; justify-content: space-between; }
  .cp-field label {
    font-size: 11px; font-weight: 500; color: #4F5763;
    line-height: 1;
  }
  .cp-field a.cp-forgot {
    font-size: 11px; font-weight: 600; color: #008C8C;
    text-decoration: none;
  }
  .cp-input-wrap {
    position: relative;
    display: flex; align-items: center;
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 0 12px;
    height: 38px;
  }
  .cp-input-wrap:focus-within {
    border-color: #008C8C;
    background: #FFFFFF;
  }
  .cp-input-icon {
    color: #8A91A1; font-size: 13px;
    margin-right: 8px;
    flex: 0 0 auto;
  }
  .cp-input-wrap input {
    border: none; background: transparent; outline: none;
    flex: 1; min-width: 0;
    font-family: inherit; font-size: 13px; color: #0D1B2A;
    padding: 0;
  }
  .cp-input-wrap input::placeholder { color: #8A91A1; }
  .cp-input-eye {
    color: #8A91A1; font-size: 14px;
    background: none; border: none; padding: 4px;
    cursor: pointer;
  }
  .cp-input-eye:hover { color: #4F5763; }

  /* Buttons */
  .cp-btn-primary {
    width: 100%;
    background: #008C8C; color: #FFFFFF;
    border: none; border-radius: 999px;
    height: 40px;
    font-size: 13px; font-weight: 600;
    margin-top: 6px;
  }
  .cp-btn-primary:hover { background: #00787A; }

  .cp-or {
    display: flex; align-items: center; gap: 10px;
    margin: 18px 0 14px;
    color: #8A91A1; font-size: 11px; font-weight: 500;
  }
  .cp-or::before, .cp-or::after {
    content: ''; flex: 1; height: 1px; background: #E4E5E8;
  }

  .cp-btn-sso {
    width: 100%;
    background: #FFFFFF; color: #0D1B2A;
    border: 1px solid #E4E5E8; border-radius: 999px;
    height: 40px;
    font-size: 13px; font-weight: 500;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  }
  .cp-btn-sso:hover { background: #F5F6F7; }
  .cp-btn-sso .ic { color: #4F5763; font-size: 13px; }

  /* Footer */
  .cp-foot {
    margin-top: 24px;
    color: #8A91A1; font-size: 11px;
    display: inline-flex; align-items: center; gap: 10px;
  }
  .cp-foot a { color: #8A91A1; text-decoration: none; }
  .cp-foot a:hover { color: #FFFFFF; }
  .cp-foot .b { color: #4F5763; }
  .cp-foot .ok-dot {
    display: inline-block; width: 6px; height: 6px; border-radius: 50%;
    background: #33A666; margin-right: 4px;
    vertical-align: middle;
  }
</style>
</head>
<body>

<div class="cp-logo-block">
  <div class="cp-logo-row">
    <span class="cp-logo-mark" aria-hidden="true"></span>
    <span class="cp-logo-text">Clinical <span class="b">Co-Pilot</span></span>
  </div>
  <div class="cp-logo-sub">Embedded in OpenEMR</div>
</div>

<form class="cp-card" method="post" action="login.php" autocomplete="off">
  <h1>Sign in</h1>
  <div class="cp-card-sub">Welcome back. Please sign in with your OpenEMR credentials.</div>

  <div class="cp-field">
    <label for="cp-user">Username</label>
    <div class="cp-input-wrap">
      <span class="cp-input-icon">👤</span>
      <input id="cp-user" name="authUser" type="text" value="erivera" autocomplete="username">
    </div>
  </div>

  <div class="cp-field">
    <div class="cp-field-row">
      <label for="cp-pass">Password</label>
      <a class="cp-forgot" href="#">Forgot?</a>
    </div>
    <div class="cp-input-wrap">
      <span class="cp-input-icon">🔒</span>
      <input id="cp-pass" name="clearPass" type="password" value="************" autocomplete="current-password">
      <button type="button" class="cp-input-eye" aria-label="Show password">👁</button>
    </div>
  </div>

  <div class="cp-field">
    <label for="cp-fac">Facility</label>
    <div class="cp-input-wrap">
      <span class="cp-input-icon">🏥</span>
      <input id="cp-fac" name="facility" type="text" value="Riverside Family Medicine">
    </div>
  </div>

  <button type="submit" class="cp-btn-primary">Sign in &nbsp;→</button>

  <div class="cp-or">OR</div>

  <button type="button" class="cp-btn-sso">
    <span class="ic">🛡</span>
    Sign in with SSO (SAML)
  </button>
</form>

<div class="cp-foot">
  <span>AgentForge v0.4.2</span>
  <span class="b">•</span>
  <a href="#">Help</a>
  <span class="b">•</span>
  <span><span class="ok-dot"></span>System status • All services up</span>
</div>

</body>
</html>
