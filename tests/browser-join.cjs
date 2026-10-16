// Public join information against owned fixtures only; no real DNS or Discord requests.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
  const browser = await chromium.launch({headless: true});
  const page = await browser.newPage({viewport: {width: 1440, height: 960}});
  const errors = []; let release;
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => dialog.accept());
  const origin = 'http://127.0.0.1:8766';
  const external = page.locator('[data-scope="external"] code');
  const host = page.locator('#join-public-host');
  const save = page.locator('#save-join-public');
  const notice = text => page.waitForFunction(text => document.querySelector('#notice').textContent.includes(text), text);
  try {
    await page.goto(origin);
    await page.waitForFunction(() => !document.querySelector('#save-join-public').disabled);
    await host.fill('Group.Example.ORG'); await save.click();
    await notice('Beitrittsadresse gespeichert');
    assert.equal(await external.textContent(), 'group.example.org:15637');
    await page.reload();
    await page.waitForFunction(() => document.querySelector('[data-scope="external"] code')?.textContent === 'group.example.org:15637');
    assert.equal(await host.inputValue(), 'group.example.org');
    // Polling and a late save acknowledgement preserve newer typed input.
    let arrived;
    const seen = new Promise(resolve => { arrived = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/api/connections/settings', async route => {
      const response = await route.fetch(); arrived(); await gate; await route.fulfill({response});
    });
    await host.fill('first.example.org'); await save.click(); await seen;
    await host.fill('newer.example.org');
    await page.locator('#refresh').click();
    release(); await page.unrouteAll({behavior: 'wait'});
    await page.waitForFunction(() => !document.querySelector('#save-join-public').disabled);
    assert.equal(await host.inputValue(), 'newer.example.org');
    await save.click(); await notice('Beitrittsadresse gespeichert');
    await page.waitForFunction(() => document.querySelector('[data-scope="external"] code')?.textContent === 'newer.example.org:15637');
    // A saved target never changes with the selector while its response is delayed.
    let targetArrived;
    const targetSeen = new Promise(resolve => { targetArrived = resolve; });
    const targetGate = new Promise(resolve => { release = resolve; });
    await page.route('**/api/connections/settings', async route => {
      const response = await route.fetch(); targetArrived(); await targetGate; await route.fulfill({response});
    });
    await host.fill('final.example.org'); await save.click(); await targetSeen;
    await page.locator('#server-select').selectOption('aska');
    await page.waitForFunction(() => document.querySelector('#game-label').textContent.includes('ASKA'));
    release(); await page.unrouteAll({behavior: 'wait'});
    assert.equal(await page.locator('#server-select').inputValue(), 'aska');
    assert.equal(await page.locator('#join-public-settings').isHidden(), true);
    await page.locator('#server-select').selectOption('enshrouded');
    await page.waitForFunction(() => document.querySelector('[data-scope="external"] code')?.textContent === 'final.example.org:15637');
    const expected = (await (await page.request.get(origin + '/api/status?server=enshrouded')).json()).connection.share_message;
    await page.getByRole('button', {name: 'Discord', exact: true}).click();
    let sent;
    await page.route('**/api/discord/send', route => { sent = route.request().postDataJSON().message; return route.continue(); });
    await page.locator('#use-join-message').click();
    assert.equal(await page.locator('#discord-message').inputValue(), expected);
    assert.ok(expected.includes('final.example.org:15637'));
    assert.ok(expected.includes('Suche nach IPv4 / Domain'));
    assert.ok(!expected.includes('127.0.0.1'));
    assert.equal(sent, undefined, 'Taking over join information creates a draft only');
    // The fixture owns a fake webhook; sending must use exactly the reviewed draft.
    await page.locator('#webhook').fill('https://discord.com/api/webhooks/123/join_fixture');
    await page.locator('#discord-form button[type="submit"]').click(); await notice('Discord-Einstellungen gespeichert');
    await page.locator('#message-form button[type="submit"]').click(); await notice('Nachricht an Discord gesendet');
    assert.equal(sent, expected);
    await page.getByRole('button', {name: 'Übersicht', exact: true}).click();
    await page.setViewportSize({width: 390, height: 844});
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    fs.mkdirSync('artifacts', {recursive: true});
    await page.screenshot({path: 'artifacts/join-info-mobile.png', fullPage: true});
    await host.fill(''); await save.click(); await notice('Beitrittsadresse gespeichert');
    assert.equal(await external.textContent(), '8.8.8.8:15637');
    // Profile creation is another selection path: cancellation keeps the draft,
    // and a late creation response must preserve newly typed input.
    await page.setViewportSize({width: 1440, height: 960});
    const catalog = await (await page.request.get(origin + '/api/servers')).json();
    const directory = catalog.servers.find(server => server.id === 'aska').server_directory;
    const prepareProfile = async id => {
      await page.getByRole('button', {name: 'Serverprofile', exact: true}).click();
      await page.locator('#profile-game').selectOption('aska');
      await page.locator('#profile-id').fill(id); await page.locator('#profile-label').fill(id);
      await page.locator('#profile-directory').fill(directory + '-' + id);
      await page.locator('#profile-config').fill(''); await page.locator('#profile-launcher').fill('');
    };
    await host.fill('keep-draft.example.org'); await prepareProfile('join-extra');
    let createRequests = 0, dismissed;
    const dialogDismissed = new Promise(resolve => { dismissed = resolve; });
    page.on('request', request => { if (request.method() === 'POST' && request.url() === origin + '/api/servers') createRequests++; });
    page.removeAllListeners('dialog');
    page.on('dialog', async dialog => { await dialog.dismiss(); dismissed(); });
    await page.locator('#profile-form button[type="submit"]').click(); await dialogDismissed;
    assert.equal(createRequests, 0, 'Rejecting draft loss must cancel profile creation');
    await page.getByRole('button', {name: 'Übersicht', exact: true}).click();
    assert.equal(await host.inputValue(), 'keep-draft.example.org');
    page.removeAllListeners('dialog'); page.on('dialog', dialog => dialog.accept());
    let created;
    const profileCreated = new Promise(resolve => { created = resolve; });
    const creationGate = new Promise(resolve => { release = resolve; });
    await page.route('**/api/servers', async route => {
      if (route.request().method() !== 'POST') return route.continue();
      const response = await route.fetch(); created(); await creationGate; await route.fulfill({response});
    });
    await prepareProfile('join-extra'); await page.locator('#profile-form button[type="submit"]').click(); await profileCreated;
    await page.getByRole('button', {name: 'Übersicht', exact: true}).click();
    await host.fill('new-draft.example.org');
    release(); await page.unrouteAll({behavior: 'wait'}); await notice('Serverprofil hinzugefügt');
    assert.equal(await page.locator('#server-select').inputValue(), 'enshrouded');
    assert.equal(await host.inputValue(), 'new-draft.example.org');
    assert.equal(await page.locator('#server-select option[value="join-extra"]').count(), 1);
    await prepareProfile('join-confirmed'); await page.locator('#profile-form button[type="submit"]').click();
    await notice('Serverprofil hinzugefügt');
    assert.equal(await page.locator('#server-select').inputValue(), 'join-confirmed', 'An unchanged, confirmed draft may be discarded');
    assert.deepEqual(errors, []);
    console.log('Join information QA passed: stable DNS, persistence, draft/selection/creation races, Discord draft and fixture send, IP fallback, mobile.');
  } finally {
    release?.(); await page.unrouteAll({behavior: 'wait'}); await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
