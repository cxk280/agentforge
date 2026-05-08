// Login + Header smoke — simplest possible e2e against the live Docker
// stack. Verifies that:
//   1. The OpenEMR login page renders.
//   2. admin/pass authenticates and the React Header (#cp-header) mounts
//      with at least one child.
//   3. The "More" menu opens and contains expected menuitems.
//
// Slower / patient-context flows live in their own e2e files.

import { expect, test } from '@playwright/test';

test.describe('Login + Header', () => {
  test('admin/pass logs in and React Header renders', async ({ page }) => {
    await page.goto('/');
    await page.fill('input[name="authUser"]', 'admin');
    await page.fill('input[name="clearPass"]', 'pass');
    await page.click('button[type="submit"], input[type="submit"]', { noWaitAfter: true });

    // The React Header is the canonical post-login signal — the PHP shell
    // creates an empty <div id="cp-header"> and the React bundle fills
    // it on first render.
    await page.waitForFunction(
      () => {
        const el = document.getElementById('cp-header');
        return !!el && el.children.length > 0;
      },
      { timeout: 60_000 },
    );

    // Drop the marketing modal that occasionally lands on first login.
    await page.evaluate(() => {
      document.querySelectorAll('.product-registration-modal, .modal-backdrop')
        .forEach((el) => el.remove());
      document.body.classList.remove('modal-open');
    });

    const header = page.locator('#cp-header');
    await expect(header).toBeVisible();
  });

  test('More menu surfaces the expected admin items', async ({ page }) => {
    await page.goto('/');
    await page.fill('input[name="authUser"]', 'admin');
    await page.fill('input[name="clearPass"]', 'pass');
    await page.click('button[type="submit"], input[type="submit"]', { noWaitAfter: true });
    await page.waitForFunction(
      () => {
        const el = document.getElementById('cp-header');
        return !!el && el.children.length > 0;
      },
      { timeout: 60_000 },
    );

    await page.click('#cp-header button:has-text("More")');
    const items = await page.locator('#cp-header [role="menu"] [role="menuitem"]').allTextContents();
    expect(items.map((t) => t.trim())).toEqual(
      expect.arrayContaining(['Patient Finder', 'Office Notes', 'Recalls', 'Authorizations']),
    );
  });
});
