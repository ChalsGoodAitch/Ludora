const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const AxeBuilder = require('@axe-core/playwright').default;

// This suite temporarily enables offline WooCommerce checkout. Run exclusively.
test.describe('native Checkout Blocks and HPOS guest journey', () => {
  test.skip(process.env.LUDORA_WC_BROWSER !== '1', 'Exclusive fixture: set LUDORA_WC_BROWSER=1');
  test.describe.configure({ mode: 'serial' });
  let fixture;
  const observations = [];
  const php = (...args) => execFileSync('docker', ['compose', 'exec', '-T', 'wordpress', 'php', ...args], { encoding: 'utf8' });
  test.beforeAll(() => { fixture = JSON.parse(php('ludora-tests/woocommerce/browser-setup.php')); });
  test.afterAll(() => {
    fs.writeFileSync('tests/artifacts/checkout-blocks-results.json', JSON.stringify(observations, null, 2));
    php('ludora-tests/woocommerce/browser-cleanup.php');
  });
  for (const width of [360, 1440]) {
    test('guest books and places offline BACS order at ' + width + 'px', async ({page}) => {
      test.setTimeout(90000);
      const errors = [];
      const consoleErrors = [];
      page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
      page.on('pageerror', error => errors.push(error.message));
      await page.setViewportSize({ width, height: 1000 });
      await page.goto('/booking/?room=' + fixture.room_id);
      await page.locator('[name=date]').fill(fixture.date);
      await page.locator('[name=start]').fill(width === 360 ? '10:00' : '14:00');
      await page.locator('[data-quote]').click();
      await expect(page.locator('.ludora-summary table')).toBeVisible();
      await page.locator('[name=name]').fill('Blocks Browser Test');
      await page.locator('[name=email]').fill('blocks-browser@example.test');
      await page.locator('[name=terms]').check();
      let reserve;
      await page.route('**/ludora/v1/reserve', async route => {
        const response = await route.fetch();
        reserve = await response.json();
        await route.fulfill({response});
      });
      await page.locator('.ludora-form [type=submit]').click();
      await expect.poll(() => reserve?.state).toBe('hold');
      expect(reserve.state).toBe('hold');
      expect(reserve.payment_state).toBe('pending');
      await page.waitForURL('**' + fixture.checkout_path + '**');
      await expect(page.locator('.wp-block-woocommerce-checkout')).toBeVisible();
      await expect(page.getByRole('button', {name: /Place order|Realizar el pedido/i})).toBeVisible();
      await page.locator('#email').fill('blocks-browser@example.test');
      await page.locator('#billing-first_name').fill('Blocks');
      await page.locator('#billing-last_name').fill('Browser');
      await page.locator('#billing-address_1').fill('Local test street 1');
      await page.locator('#billing-city').fill('Madrid');
      await page.locator('#billing-postcode').fill('28001');
      const phone = page.locator('#billing-phone');
      if (await phone.count()) await phone.fill('600000000');
      await expect(page.getByText('Local test bank transfer', {exact: true})).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
      const audit = await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
      const violations = audit.violations.map(v => ({id: v.id, targets: v.nodes.map(n => n.target)}));
      await page.screenshot({ path: 'tests/artifacts/checkout-blocks-' + width + '.png', fullPage: true });
      const checkoutResponse = page.waitForResponse(response => response.url().includes('/wc/store/v1/checkout') && response.request().method() === 'POST');
      await page.getByRole('button', {name: /Place order|Realizar el pedido/i}).click();
      const response = await checkoutResponse;
      const checkout = await response.json();
      expect(response.status()).toBe(200);
      await page.waitForURL('**/order-received/**');
      await expect(page.locator('main')).toContainText(/Thank you|Gracias/);
      await page.screenshot({ path: 'tests/artifacts/checkout-received-' + width + '.png', fullPage: true });
      const database = JSON.parse(php('ludora-tests/woocommerce/browser-setup.php', '--inspect', String(checkout.order_id)));
      expect(database).toMatchObject({ booking_state: 'hold', payment_state: 'pending', order_status: 'on-hold', total: '90.00', currency: 'EUR', item_count: 1, payment_method: 'bacs' });
      expect(database.data_store).toContain('OrdersTableDataStore');
      observations.push({width, checkoutStatus: response.status(), database, pageErrors: errors, consoleErrors, accessibilityViolations: violations});
      expect(errors).toEqual([]);
      expect(consoleErrors).toEqual([]);
      expect(violations).toEqual([]);
    });
  }
});
