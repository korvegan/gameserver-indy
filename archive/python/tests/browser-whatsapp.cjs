// Own fixture only; the QR and account are synthetic, sends never leave the fixture.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
  const browser = await chromium.launch({headless: true});
  const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
  const errors = []; let release;
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => dialog.accept());
  const origin = 'http://127.0.0.1:8766';
  const notice = text => page.waitForFunction(text => document.querySelector('#notice').textContent.includes(text), text);
  try {
    await page.goto(origin);
    await require('./browser-navigation.cjs').navigation(page)('WhatsApp').click();
    await page.waitForFunction(() => !document.querySelector('#wa-connect').disabled);
    await page.locator('#wa-connect').click();
    await page.locator('#wa-qr').waitFor({state: 'visible'});
    assert.ok((await page.locator('#wa-qr').getAttribute('src')).startsWith('blob:'));
    for (let i = 0; i < 3; i++) {
      await page.locator('#wa-refresh').click();
      await page.waitForFunction(() => !document.querySelector('#wa-refresh').disabled);
      if ((await page.locator('#wa-state').textContent()).includes('verbunden')) break;
    }
    assert.ok((await page.locator('#wa-state').textContent()).includes('verbunden'));
    assert.equal(await page.locator('#wa-qr').isHidden(), true);
    await page.locator('#wa-group').selectOption('123456789@g.us');
    await page.locator('[data-wa-server="enshrouded"]').check();
    // Delayed save acknowledgements must preserve newer drafts.
    let arrived;
    const seen = new Promise(resolve => { arrived = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/api/whatsapp/settings', async route => {
      const response = await route.fetch(); arrived(); await gate; await route.fulfill({response});
    });
    await page.locator('#wa-save').click(); await seen;
    await page.locator('#wa-group').selectOption('987654321@g.us');
    release(); await page.unrouteAll({behavior: 'wait'});
    await page.waitForFunction(() => !document.querySelector('#wa-save').disabled);
    assert.equal(await page.locator('#wa-group').inputValue(), '987654321@g.us');
    await page.locator('#wa-save').click(); await notice('WhatsApp-Einstellungen gespeichert');
    await page.waitForFunction(() => !document.querySelector('#wa-save').disabled);
    await page.locator('#wa-refresh').click();
    await page.waitForFunction(() => !document.querySelector('#wa-refresh').disabled);
    assert.equal(await page.locator('#wa-group').inputValue(), '987654321@g.us');
    // Losing the service must not erase a selected, unsaved group.
    await page.locator('#wa-group').selectOption('123456789@g.us');
    await page.route('**/api/whatsapp', async route => {
      const response = await route.fetch(); const data = await response.json(); data.state = 'UNAVAILABLE';
      await route.fulfill({response, json: data});
    });
    await page.locator('#wa-refresh').click();
    await page.waitForFunction(() => !document.querySelector('#wa-refresh').disabled);
    assert.equal(await page.locator('#wa-group').inputValue(), '123456789@g.us');
    await page.unroute('**/api/whatsapp');
    await page.locator('#wa-refresh').click();
    await page.waitForFunction(() => !document.querySelector('#wa-refresh').disabled);
    assert.equal(await page.locator('#wa-group').inputValue(), '123456789@g.us');
    // A second tab changing persisted settings must have an explicit recovery.
    await page.evaluate(async () => {
      const session = await (await fetch('/api/session')).json();
      const current = await (await fetch('/api/whatsapp')).json();
      const {group_id, enabled, server_ids, revision} = current.settings;
      const response = await fetch('/api/whatsapp/settings', {method: 'POST', headers: {
        'Content-Type': 'application/json', 'X-Manager-Token': session.token},
        body: JSON.stringify({group_id, enabled, server_ids, revision})});
      if (!response.ok) throw new Error('Fixture second-tab save failed');
    });
    await page.locator('#wa-refresh').click();
    await page.waitForFunction(() => !document.querySelector('#wa-refresh').disabled);
    assert.equal(await page.locator('#wa-conflict').isVisible(), true);
    assert.equal(await page.locator('#wa-save').isDisabled(), true);
    await page.locator('#wa-discard').click();
    await page.waitForFunction(() => !document.querySelector('#wa-refresh').disabled);
    assert.equal(await page.locator('#wa-conflict').isHidden(), true);
    assert.equal(await page.locator('#wa-group').inputValue(), '987654321@g.us');
    assert.equal(await page.locator('#wa-save').isDisabled(), false);
    // No automatic claim of readiness for a stopped fixture server.
    assert.equal(await page.locator('[data-wa-send="enshrouded"]').isDisabled(), true);
    await require('./browser-navigation.cjs').navigation(page)('Übersicht').click();
    await require('./browser-navigation.cjs').navigation(page)('WhatsApp').click();
    await page.waitForFunction(() => !document.querySelector('#wa-refresh').disabled);
    assert.equal(await page.locator('#wa-group').inputValue(), '987654321@g.us');
    await page.setViewportSize({width: 390, height: 844});
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    fs.mkdirSync('artifacts', {recursive: true});
    await page.screenshot({path: 'artifacts/whatsapp-mobile.png', fullPage: true});
    await page.locator('#wa-disconnect').click(); await notice('Verknüpfung getrennt');
    await page.waitForFunction(() => document.querySelector('#wa-state').textContent.includes('Noch nicht verknüpft'));
    assert.equal(await page.locator('#wa-enabled').isChecked(), false);
    assert.equal(await page.locator('#wa-group').inputValue(), '');
    // A remote owner must not see or control the personal account setup.
    await page.route('**/api/session', async route => {
      const response = await route.fetch(); const data = await response.json(); data.principal.id = 'remote-owner';
      await route.fulfill({response, json: data});
    });
    await page.reload();
    await page.waitForFunction(() => document.querySelector('#identity-label').textContent.includes('Besitzer'));
    assert.equal(await page.locator('[data-view="whatsapp"]').isHidden(), true);
    assert.deepEqual(errors, []);
    console.log('WhatsApp QA passed: synthetic QR pairing, explicit group, saved/new drafts, outage and concurrent-tab recovery, no unready send, local account boundary, disconnect, mobile.');
  } finally { release?.(); await page.unrouteAll({behavior: 'wait'}); await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
