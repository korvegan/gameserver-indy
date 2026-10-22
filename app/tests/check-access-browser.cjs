const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { default: AxeBuilder } = require('@axe-core/playwright');

async function main() {
  const url = process.argv[2];
  assert.match(url || '', /^http:\/\/127\.0\.0\.1:[0-9]+$/);
  const health = await (await fetch(`${url}/health`)).json();
  assert.equal(health.mode, 'fixture');
  assert.equal(health.native_enabled, false);
  assert.match(health.instance, /^indy-next-[a-f0-9]{24}$/);
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 1080 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
    await page.goto(`${url}/access`);
    assert.equal(await page.title(), 'Zugänge – Sturmklingen');
    assert.equal(await page.getByRole('heading', { name: 'Zugänge', exact: true }).count(), 1);
    for (const width of [1280, 390]) {
      await page.setViewportSize({ width, height: 1080 });
      const login = `Browser-${width}-${Date.now()}@example.org`;
      const form = page.getByRole('region', { name: 'Person freigeben' });
      await form.getByLabel('Identität', { exact: true }).fill(login);
      await form.getByLabel('Rolle', { exact: true }).selectOption('family');
      await form.getByRole('button', { name: 'Freigeben', exact: true }).click();
      await page.getByRole('status').waitFor();
      const row = page.getByRole('row').filter({ hasText: login.toLowerCase() });
      assert.match(await row.innerText(), /Familie/);
      await row.getByRole('button', { name: 'Sperren', exact: true }).click();
      await page.getByRole('status').waitFor();
      assert.match(await row.innerText(), /Gesperrt/);
      await row.getByRole('button', { name: 'Freigeben', exact: true }).click();
      await page.getByRole('status').waitFor();
      assert.match(await row.innerText(), /Freigegeben/);
      await row.locator('summary').click();
      await row.getByRole('combobox').selectOption('administrator');
      await row.getByRole('button', { name: 'Speichern', exact: true }).click();
      await page.getByRole('status').waitFor();
      assert.match(await row.innerText(), /Administrator/);
      const owner = page.getByRole('row').filter({ hasText: 'Dein Zugang' });
      assert.equal(await owner.getByRole('button').count(), 0);
    }
    for (const width of [1280, 390, 320]) {
      await page.setViewportSize({ width, height: 1080 });
      await page.goto(`${url}/access`);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow at ${width}`);
      const result = await new AxeBuilder({ page }).analyze();
      assert.deepEqual(result.violations.map((v) => ({ id: v.id, impact: v.impact })), [], `Axe at ${width}`);
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('link', { name: 'Serverzentrale', exact: true }).focus();
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement?.tagName), 'SUMMARY');
    assert.ok(await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle !== 'none'), 'Visible keyboard focus');
    assert.deepEqual(errors, [], 'Browser console/page errors');
    console.log('PASS: desktop/mobile access forms, grant/block/role, own access protection, keyboard focus, no overflow at 1280/390/320, Axe and console');
    await context.close();
  } finally { await browser.close(); }
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
