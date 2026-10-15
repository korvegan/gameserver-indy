// Only the disposable fixture, including its fake world and process bridge.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
  try {
    const page = await browser.newPage({viewport: {width: 1440, height: 960}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const origin = 'http://127.0.0.1:8766';
    const session = await (await page.request.get(origin + '/api/session')).json();
    const headers = {'X-Manager-Token': session.token};
    const post = (path, data = {}) => page.request.post(origin + path, {headers, data: {...data, server_id: 'enshrouded'}});
    await post('/api/stop');
    await post('/api/setup');
    await page.goto(origin);
    await page.waitForFunction(() => !document.querySelector('#server-select').disabled);
    await page.locator('#server-select').selectOption('enshrouded');
    await page.getByRole('button', {name: 'Weltsicherungen', exact: true}).click();
    await page.waitForFunction(() => document.querySelector('#backup-target').textContent.includes('savegame'));
    await page.waitForFunction(() => !document.querySelector('#backup-create').disabled);
    await page.locator('#backup-create').click();
    await page.waitForFunction(() => document.querySelectorAll('.backup-entry').length === 1);
    await page.waitForFunction(() => !document.querySelector('#backup-create').disabled);
    assert.match(await page.locator('#backup-last').textContent(), /Letzte verifizierte Sicherung/);
    await page.getByRole('button', {name: 'Anheften', exact: true}).click();
    await page.waitForFunction(() => document.querySelector('.backup-entry').textContent.includes('Angeheftet'));
    await page.waitForFunction(() => !document.querySelector('#backup-create').disabled);
    await page.locator('#backup-auto').check();
    await page.waitForFunction(() => document.querySelector('#notice').textContent.includes('aktiviert'));
    await page.waitForFunction(() => !document.querySelector('#backup-auto').disabled);
    assert.equal((await (await page.request.get(origin + '/api/backups?server=enshrouded')).json()).automatic_after_stop, true);
    // Cancel is harmless; confirmation restores exact raw config and creates a pinned return path.
    const original = (await (await page.request.get(origin + '/api/state?server=enshrouded')).json()).config;
    const modified = {...original, name: 'Späterer Testabend'};
    assert.equal((await post('/api/config', {config: modified})).status(), 200);
    await Promise.all([page.waitForEvent('dialog').then(dialog => dialog.dismiss()),
      page.getByRole('button', {name: 'Wiederherstellen', exact: true}).click()]);
    assert.equal((await (await page.request.get(origin + '/api/backups?server=enshrouded')).json()).backups.length, 1);
    await Promise.all([page.waitForEvent('dialog').then(dialog => dialog.accept('WIEDERHERSTELLEN')),
      page.getByRole('button', {name: 'Wiederherstellen', exact: true}).click()]);
    await page.waitForFunction(() => document.querySelector('#notice').textContent.includes('Server bleibt gestoppt'));
    assert.equal((await (await page.request.get(origin + '/api/state?server=enshrouded')).json()).config.name, original.name);
    assert.equal(await page.locator('.backup-entry').count(), 2);
    assert.match(await page.locator('.backup-entry').first().textContent(), /Rückweg vor Wiederherstellung/);
    assert.equal((await (await page.request.get(origin + '/api/status?server=enshrouded')).json()).status.running, false);
    // A late preview cannot open a destructive confirmation after navigation.
    let releasePreview, previewArrived;
    const previewGate = new Promise(resolve => { releasePreview = resolve; });
    const previewSeen = new Promise(resolve => { previewArrived = resolve; });
    await page.route('**/api/backups?id=*', async route => { const response = await route.fetch(); previewArrived(); await previewGate; await route.fulfill({response}); });
    let unexpectedDialog = false;
    const dismiss = dialog => { unexpectedDialog = true; dialog.dismiss(); };
    page.on('dialog', dismiss);
    await page.getByRole('button', {name: 'Wiederherstellen', exact: true}).first().click();
    await previewSeen;
    await page.getByRole('button', {name: 'Übersicht', exact: true}).click();
    await page.getByRole('button', {name: 'Weltsicherungen', exact: true}).click();
    releasePreview(); await page.waitForTimeout(100);
    assert.equal(unexpectedDialog, false);
    await page.unroute('**/api/backups?id=*'); page.off('dialog', dismiss);
    // A late list never paints the previous server's snapshots into another profile.
    let releaseList, listArrived;
    const listGate = new Promise(resolve => { releaseList = resolve; });
    const listSeen = new Promise(resolve => { listArrived = resolve; });
    await page.route('**/api/backups?server=enshrouded', async route => { const response = await route.fetch(); listArrived(); await listGate; await route.fulfill({response}); });
    await page.locator('#backup-refresh').click(); await listSeen;
    await page.locator('#server-select').selectOption('aska');
    await page.waitForFunction(() => document.querySelector('#backup-target').textContent.includes('Save-ID'));
    releaseList(); await page.waitForTimeout(100);
    assert.equal(await page.locator('.backup-entry').count(), 0);
    await page.unroute('**/api/backups?server=enshrouded');
    await page.locator('#server-select').selectOption('enshrouded');
    await page.waitForFunction(() => document.querySelectorAll('.backup-entry').length === 2);
    for (const width of [1440, 390, 320]) {
      await page.setViewportSize({width, height: 960});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      fs.mkdirSync('artifacts', {recursive: true});
      await page.screenshot({path: `artifacts/backups-${width}.png`, fullPage: true});
    }
    // Keep fixture state independent for any later suite.
    await post('/api/jobs', {operation: 'backup_settings', automatic_after_stop: false});
    await page.route('**/api/status?server=enshrouded', async route => {
      const response = await route.fetch(), body = await response.json(); body.status.recovery_required = true;
      await route.fulfill({response, json: body});
    });
    await page.getByRole('button', {name: 'Übersicht', exact: true}).click();
    await page.locator('#refresh').click();
    await page.waitForFunction(() => document.querySelector('#server-state').textContent === 'Wiederherstellung offen');
    assert.equal(await page.locator('#start').isDisabled(), true);
    assert.match(await page.locator('#process-note').textContent(), /Weltsicherungen/);
    await page.unroute('**/api/status?server=enshrouded');
    assert.deepEqual(errors, []);
    const family = await browser.newContext({extraHTTPHeaders: {'X-QA-Identity': 'family'}});
    try {
      const restricted = await family.newPage(); await restricted.goto(origin);
      await restricted.waitForFunction(() => document.querySelector('#identity-label').textContent === 'Familienbediener');
      assert.equal(await restricted.getByRole('button', {name: 'Weltsicherungen', exact: true}).count(), 0);
      assert.equal((await restricted.request.get(origin + '/api/backups?server=aska')).status(), 403);
    } finally { await family.close(); }
    console.log('World backup QA passed: verified copy, pin, opt-in automation, typed restore, rollback snapshot, late responses, family denial, 1440/390/320px.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
