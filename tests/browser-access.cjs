// Real FamilyServer behind a Serve-shaped test proxy; no production identities.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
  const page = await browser.newPage({viewport: {width: 1440, height: 960}}), errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => dialog.accept());
  try {
    await page.goto('http://127.0.0.1:8766');
    await page.getByRole('button', {name: 'Familienzugang', exact: true}).click();
    await page.waitForFunction(() => document.querySelector('#access').getAttribute('aria-busy') === 'false');
    await page.locator('#access-user-name').fill('Schwester im Test');
    await page.locator('#access-user-login').fill('qa-family@example.test');
    await page.locator('#access-user-role').selectOption('family');
    await page.locator('#access-server-choice input[value="aska"]').check();
    await page.locator('#family-hostname').fill('draft.example.ts.net');
    const saved = page.waitForResponse(response => response.url().endsWith('/api/access/users') && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Freigabe speichern', exact: true}).click();
    assert.equal((await saved).status(), 200);
    await page.locator('#access-users .access-user').waitFor();
    assert.ok((await page.locator('#access-users').textContent()).includes('Schwester im Test'));
    assert.equal(await page.locator('#family-hostname').inputValue(), 'draft.example.ts.net', 'Saving a person preserves connection draft.');
    // Drafts survive failed requests, and leaving requires an explicit discard.
    await page.locator('#access-user-name').fill('Entwurf bleibt');
    page.removeAllListeners('dialog'); page.once('dialog', dialog => dialog.dismiss());
    await require('./browser-navigation.cjs').navigation(page)('Übersicht').click();
    assert.equal(await page.locator('#access').isVisible(), true);
    assert.equal(await page.locator('#access-user-name').inputValue(), 'Entwurf bleibt');
    assert.equal(await page.evaluate(() => { const event = new Event('beforeunload', {cancelable: true}); window.dispatchEvent(event); return event.defaultPrevented; }), true);
    page.on('dialog', dialog => dialog.accept());
    await page.locator('#access-user-cancel').click();
    assert.equal(await page.locator('#family-hostname').inputValue(), 'draft.example.ts.net');
    await page.locator('#access-refresh').click();
    await page.waitForFunction(() => document.querySelector('#access').getAttribute('aria-busy') === 'false');
    // A completed grant is adopted after reselecting the active view or returning.
    for (const leave of [false, true]) {
      const name = leave ? 'Rückkehr nach Speichern' : 'Gleiche Ansicht während Speichern';
      await page.locator('#access-user-name').fill(name);
      await page.locator('#access-user-login').fill(`qa-late-${leave}@example.test`);
      let release, arrived;
      const gate = new Promise(resolve => { release = resolve; });
      const seen = new Promise(resolve => { arrived = resolve; });
      await page.route('**/api/access/users', async route => {
        const response = await route.fetch(); arrived(); await gate; await route.fulfill({response});
      });
      await page.getByRole('button', {name: 'Freigabe speichern', exact: true}).click(); await seen;
      if (leave) await require('./browser-navigation.cjs').navigation(page)('Übersicht').click();
      await page.getByRole('button', {name: 'Familienzugang', exact: true}).click();
      release();
      await page.waitForFunction(name => document.querySelector('#access-users').textContent.includes(name), name);
      assert.equal(await page.locator('#access-user-name').inputValue(), '');
      await page.unroute('**/api/access/users');
    }
    const family = await browser.newContext({extraHTTPHeaders: {'X-QA-Login': 'qa-family@example.test'}, viewport: {width: 390, height: 844}});
    try {
      const remote = await family.newPage(); remote.on('pageerror', error => errors.push(error.message));
      await remote.goto('http://127.0.0.1:8768');
      await remote.waitForFunction(() => document.querySelector('#identity-label').textContent === 'Familienbediener');
      assert.equal(await remote.locator('#location-label').textContent(), 'Privater Familienzugang');
      await remote.waitForFunction(() => document.querySelector('#server-select option')?.value === 'aska');
      assert.equal(await remote.locator('#server-select option').count(), 1);
      for (const name of ['Einstellungen', 'Mitteilungen']) assert.equal(await remote.getByRole('tab', {name, exact: true}).count(), 0);
      await remote.getByRole('tab', {name: 'Protokoll & Aufträge', exact: true}).click();
      assert.equal(await remote.locator('#job-list').isVisible(), true);
      assert.equal(await remote.locator('#logs .log-window').isVisible(), false);
      await require('./browser-navigation.cjs').navigation(remote)('Übersicht').click();
      assert.equal(await remote.getByRole('button', {name: 'Familienzugang', exact: true}).count(), 0);
      assert.equal(await remote.locator('#stop').isDisabled(), true); assert.equal(await remote.locator('#restart').isDisabled(), true);
      const session = await (await remote.request.get('http://127.0.0.1:8768/api/session')).json();
      assert.equal(session.principal.role, 'family');
      for (const route of ['/api/config?server=aska', '/api/access', '/api/status?server=enshrouded', '/api/community/export']) {
        assert.equal((await remote.request.get('http://127.0.0.1:8768' + route)).status(), 403);
      }
      assert.equal((await remote.request.get('http://127.0.0.1:8768/api/session', {headers: {'Tailscale-User-Login': 'forged-owner@example.test', 'X-Role': 'owner'}})).status(), 200);
      const ownerSession = await (await page.request.get('http://127.0.0.1:8766/api/session')).json();
      const current = await (await page.request.get('http://127.0.0.1:8766/api/status?server=aska')).json();
      if (current.status.running) {
        const stopped = await page.request.post('http://127.0.0.1:8766/api/stop', {data: {server_id: 'aska'}, headers: {'X-Manager-Token': ownerSession.token}});
        assert.equal(stopped.status(), 200);
      }
      await remote.locator('#refresh').click(); await remote.waitForFunction(() => !document.querySelector('#start').disabled);
      await remote.locator('#start').click();
      await remote.waitForFunction(() => document.querySelector('#server-state').textContent.includes('Prozess läuft'));
      assert.equal(await remote.locator('#stop').isDisabled(), true);
      const denied = await remote.request.post('http://127.0.0.1:8768/api/jobs', {data: {server_id: 'aska', operation: 'stop'}, headers: {'X-Manager-Token': session.token}});
      assert.equal(denied.status(), 403);
      assert.equal((await remote.request.get('http://127.0.0.1:18767/api/session')).status(), 403, 'Direct test listener never grants local owner.');
      fs.mkdirSync('artifacts', {recursive: true});
      await remote.screenshot({path: 'artifacts/family-mobile.png', fullPage: true});
      assert.equal(await remote.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      // Revocation takes effect even with an already loaded browser and CSRF token.
      await page.locator('#access-users .access-user').filter({hasText: 'Schwester im Test'}).getByRole('button', {name: 'Freigabe bearbeiten', exact: true}).click();
      await page.locator('#access-user-enabled').uncheck();
      const revoked = page.waitForResponse(response => response.url().endsWith('/api/access/users') && response.request().method() === 'POST');
      await page.getByRole('button', {name: 'Freigabe speichern', exact: true}).click();
      assert.equal((await revoked).status(), 200);
      assert.equal((await remote.request.get('http://127.0.0.1:8768/api/session')).status(), 403);
      assert.equal((await remote.request.post('http://127.0.0.1:8768/api/jobs', {data: {server_id: 'aska', operation: 'start'}, headers: {'X-Manager-Token': session.token}})).status(), 403);
    } finally { await family.close(); }
    await page.locator('#access-refresh').click();
    await page.waitForFunction(() => document.querySelector('#access-users').textContent.includes('gesperrt'));
    assert.ok((await page.locator('#access-audit').textContent()).includes('Schwester im Test'));
    for (const width of [1440, 390, 320]) {
      await page.setViewportSize({width, height: 960});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      await page.screenshot({path: `artifacts/access-${width}.png`, fullPage: true});
    }
    assert.deepEqual(errors, []);
    console.log('Family access QA passed: owner grants, private identity, scoped family start, direct/header bypass denied, CSRF, revoke, audit, drafts, 1440/390/320px.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
