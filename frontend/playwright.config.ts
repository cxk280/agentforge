// Playwright configuration for the AgentForge e2e suite.
//
// e2e/ contains Playwright-Test-style files that drive the live Docker
// stack on http://localhost:8300/ — the same stack as
// frontend/.fidelity-references/*.mjs ad-hoc verifies, but managed via
// the test runner so we get retries, parallel workers, the HTML
// reporter, and CI-friendly exit codes.

import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env['CP_BASE_URL'] ?? 'http://localhost:8300';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false, // OpenEMR shares a single admin session across tests
  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] ? 2 : 0,
  workers: 1, // serialize so login state and pid context stay coherent
  reporter: [['list'], ['html', { open: 'never' }]],
  timeout: 180_000,
  expect: { timeout: 30_000 },
  use: {
    baseURL: BASE_URL,
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    viewport: { width: 1440, height: 900 },
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
