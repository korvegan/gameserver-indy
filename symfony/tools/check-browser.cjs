const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const {once} = require('node:events');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const http = require('node:http');
const {setTimeout: delay} = require('node:timers/promises');

(async () => {
  const root = path.resolve(__dirname, '..');
  const state = await fs.mkdtemp(path.join(os.tmpdir(), 'indy-symfony-browser-'));
  const children = [];
  let output = '';
  let browser, familyProxy;
  async function start(role, port, driver, familyPort) {
    await new Promise((resolve, reject) => {
      const probe = net.createServer(); probe.once('error', reject);
      probe.listen(port, '127.0.0.1', () => probe.close(resolve));
    });
    const child = spawn(process.env.PYTHON || 'python3', ['tools/run_slice.py', '--port', String(port), '--role', role,
      '--driver', driver, '--state-dir', path.join(state, role), ...(familyPort ? ['--family-port', String(familyPort)] : [])], {cwd: root, stdio: ['ignore', 'pipe', 'pipe']});
    children.push(child);
    child.stdout.on('data', data => { output += data; }); child.stderr.on('data', data => { output += data; });
    child.on('error', error => { output += error.message; });
    for (let attempt = 0; attempt < 200; attempt++) {
      if (child.exitCode !== null) throw new Error('Slice fixture exited: ' + output.slice(-5000));
      try { const response = await fetch(`http://127.0.0.1:${port}/api/session`); if (response.ok) return child; } catch {}
      await delay(100);
    }
    throw new Error('Slice fixture did not start: ' + output.slice(-5000));
  }
  async function stop(child) {
    if (child.exitCode === null && child.signalCode === null) {
      const done = once(child, 'exit'); child.kill('SIGTERM'); await done;
    }
  }
  try {
    const driver = process.env.SLICE_BROWSER_DRIVER || 'simulated';
    let owner = await start('owner', 18866, driver, 18868);
    await start('viewer', 18867, 'simulated');
    browser = await chromium.launch({headless: true});
    const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const origin = 'http://127.0.0.1:18866';
    await page.goto(origin);
    await page.waitForFunction(() => document.querySelector('#live-state').textContent === 'Live verbunden');
    await page.waitForFunction(() => document.querySelector('#server-state').textContent === 'Gestoppt');
    const oldToken = await page.locator('meta[name="manager-token"]').getAttribute('content');
    let releaseStatus, arrived;
    const gate = new Promise(resolve => { releaseStatus = resolve; });
    const seen = new Promise(resolve => { arrived = resolve; });
    await page.route('**/api/status?server=demo-a', async route => {
      const response = await route.fetch(); arrived(); await gate; await route.fulfill({response});
    });
    await page.locator('#refresh').click(); await seen;
    for (const id of ['start', 'stop', 'restart']) assert.equal(await page.locator('#' + id).isEnabled(), true);
    const firstResponse = page.waitForResponse(response => response.url().endsWith('/api/jobs') && response.request().method() === 'POST');
    await page.locator('#start').click();
    const first = await firstResponse; assert.equal(first.status(), 202);
    const firstJob = (await first.json()).job;
    const secondResponse = page.waitForResponse(response => response.url().endsWith('/api/jobs') && response.request().method() === 'POST');
    await page.locator('#stop').click();
    const second = await secondResponse; assert.equal(second.status(), 202);
    const secondJob = (await second.json()).job;
    assert.ok(secondJob.sequence > firstJob.sequence);
    releaseStatus(); await page.unrouteAll({behavior: 'wait'});
    await page.locator(`[data-job-id="${firstJob.id}"][data-state="succeeded"]`).waitFor();
    await page.locator(`[data-job-id="${secondJob.id}"][data-state="succeeded"]`).waitFor();
    await page.waitForFunction(() => document.querySelector('#server-state').textContent === 'Gestoppt');
    // A fresh process uses the same private key and persisted ledger.
    await stop(owner); owner = await start('owner', 18866, driver, 18868);
    const accepted = await page.request.post(origin + '/api/jobs', {headers: {'Origin': origin, 'X-Manager-Token': oldToken},
      data: {server_id: 'demo-a', operation: 'stop', request_id: 'f'.repeat(32)}});
    assert.equal(accepted.status(), 202, 'Open browser token survives a real application/worker restart');
    await page.reload();
    await page.locator(`[data-job-id="${firstJob.id}"][data-state="succeeded"]`).waitFor();
    await page.setViewportSize({width: 390, height: 844});
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    const countBefore = (await (await page.request.get(origin + '/api/jobs')).json()).jobs.length;
    let posts = 0;
    await page.route('**/api/jobs', async route => {
      if (route.request().method() !== 'POST') return route.continue();
      posts++;
      await route.fulfill({status: 403, contentType: 'application/json', body: JSON.stringify({code: 'session_expired', error: 'expired'})});
    });
    await page.locator('#start').click();
    await page.waitForFunction(() => document.querySelector('#notice').textContent.includes('Sitzung aktualisiert'));
    assert.equal(posts, 1);
    assert.equal((await (await page.request.get(origin + '/api/jobs')).json()).jobs.length, countBefore);
    await page.unroute('**/api/jobs');
    await page.screenshot({path: path.join(root, '../artifacts/symfony-mobile.png'), fullPage: true});
    async function terminal(jobId) {
      for (let attempt = 0; attempt < 200; attempt++) {
        const {job} = await (await page.request.get(origin + '/api/jobs?id=' + jobId)).json();
        if (job.state === 'succeeded') return job;
        assert.ok(['queued', 'running'].includes(job.state), JSON.stringify(job));
        await delay(100);
      }
      throw new Error('Job did not finish');
    }
    // The first snapshot can be captured before or after an accepted command finishes.
    for (const newerSnapshot of [true, false]) {
      const race = await browser.newPage();
      let snapshotArrived, releaseSnapshot;
      const arrived = new Promise(resolve => { snapshotArrived = resolve; });
      const gate = new Promise(resolve => { releaseSnapshot = resolve; });
      await race.route('**/api/jobs', async route => {
        if (route.request().method() !== 'GET') return route.continue();
        let response;
        if (!newerSnapshot) response = await route.fetch();
        snapshotArrived(); await gate;
        if (newerSnapshot) response = await route.fetch();
        await route.fulfill({response});
      });
      await race.goto(origin); await arrived;
      const initialState = newerSnapshot ? 'Gestoppt' : 'Prozess läuft';
      await race.waitForFunction(expected => document.querySelector('#server-state').textContent.includes(expected), initialState);
      const response = race.waitForResponse(response => response.url().endsWith('/api/jobs') && response.request().method() === 'POST');
      await race.locator(newerSnapshot ? '#start' : '#stop').click();
      const {job} = await (await response).json();
      await terminal(job.id); releaseSnapshot();
      await race.locator(`[data-job-id="${job.id}"][data-state="succeeded"]`).waitFor();
      await race.waitForFunction(expected => document.querySelector('#server-state').textContent.includes(expected), newerSnapshot ? 'Prozess läuft' : 'Gestoppt');
      await race.unrouteAll({behavior: 'wait'}); await race.close();
    }
    // A late queued acceptance must not replace an already streamed terminal revision.
    await page.reload();
    await page.waitForFunction(() => document.querySelector('#live-state').textContent === 'Live verbunden');
    let acceptanceArrived, releaseAcceptance, acceptedJob;
    const acceptanceSeen = new Promise(resolve => { acceptanceArrived = resolve; });
    const acceptanceGate = new Promise(resolve => { releaseAcceptance = resolve; });
    await page.route('**/api/jobs', async route => {
      if (route.request().method() !== 'POST') return route.continue();
      const response = await route.fetch(); acceptedJob = (await response.json()).job;
      acceptanceArrived(); await acceptanceGate; await route.fulfill({response});
    });
    await page.locator('#stop').click(); await acceptanceSeen;
    await page.locator(`[data-job-id="${acceptedJob.id}"][data-state="succeeded"]`).waitFor();
    releaseAcceptance(); await page.unrouteAll({behavior: 'wait'});
    await page.waitForFunction(() => document.querySelector('#notice').textContent.includes('angenommen'));
    assert.equal(await page.locator(`[data-job-id="${acceptedJob.id}"]`).getAttribute('data-state'), 'succeeded');
    // This mock represents Serve's identity injection only. Actual external HTTPS is a separate acceptance.
    const privateHost = 'family.test.ts.net', gateway = 'http://127.0.0.1:18869';
    let identity = 'family@example.test';
    familyProxy = http.createServer((request, response) => {
      const headers = {...request.headers, host: privateHost, 'tailscale-user-login': identity};
      if (headers.origin === gateway) headers.origin = 'https://' + privateHost;
      const upstream = http.request({hostname: '127.0.0.1', port: 18868, path: request.url, method: request.method, headers}, result => {
        response.writeHead(result.statusCode, result.headers); result.pipe(response);
      });
      upstream.on('error', () => { if (!response.destroyed) { if (!response.headersSent) response.writeHead(503); response.end(); } });
      response.on('close', () => upstream.destroy()); request.pipe(upstream);
    });
    await new Promise((resolve, reject) => {
      familyProxy.once('error', reject); familyProxy.listen(18869, '127.0.0.1', resolve);
    });
    const localSession = await (await page.request.get(origin + '/api/session')).json();
    const localPost = (route, data) => page.request.post(origin + route, {headers: {'Origin': origin,
      'X-Manager-Token': localSession.token}, data});
    assert.equal((await localPost('/api/access/settings', {enabled: true, hostname: privateHost, port: 18868})).status(), 200);
    const grant = await localPost('/api/access/users', {login: identity, name: 'Testfamilie', role: 'family', server_ids: ['demo-a'], enabled: true});
    assert.equal(grant.status(), 200); const userId = (await grant.json()).id;
    assert.equal((await localPost('/api/access/users', {login: 'owner@example.test', name: 'Entfernter Testbesitzer', role: 'owner', server_ids: null, enabled: true})).status(), 200);
    // The actual FrankenPHP private site must refuse spoofing across listeners.
    assert.equal((await fetch('http://127.0.0.1:18868/api/session', {headers: {Host: '127.0.0.1:18866',
      'Tailscale-User-Login': identity, 'Indy-Listener-Port': '18866', 'X-Forwarded-Port': '18866'}})).status, 403);
    const privatePage = await browser.newPage({viewport: {width: 390, height: 844}});
    privatePage.on('pageerror', error => errors.push(error.message));
    await privatePage.goto(gateway);
    await privatePage.waitForFunction(() => document.querySelector('#live-state').textContent === 'Live verbunden');
    assert.deepEqual(await privatePage.locator('#server-select option').evaluateAll(options => options.map(option => option.value)), ['demo-a']);
    assert.equal(await privatePage.locator('#start').isEnabled(), true);
    assert.equal(await privatePage.locator('#stop').isEnabled(), false);
    assert.equal(await privatePage.locator('#restart').isEnabled(), false);
    assert.equal((await privatePage.request.get(gateway + '/api/access')).status(), 403);
    assert.equal((await privatePage.request.get(gateway + '/api/status?server=demo-b')).status(), 403);
    const privateAccepted = privatePage.waitForResponse(response => response.url().endsWith('/api/jobs') && response.request().method() === 'POST');
    await privatePage.locator('#start').click();
    const privateResponse = await privateAccepted; assert.equal(privateResponse.status(), 202);
    const privateJob = (await privateResponse.json()).job;
    await privatePage.locator(`[data-job-id="${privateJob.id}"][data-state="succeeded"]`).waitFor();
    const otherJobResponse = await localPost('/api/jobs', {server_id: 'demo-b', operation: 'stop', request_id: '5'.repeat(32)});
    assert.equal(otherJobResponse.status(), 202); const otherJob = (await otherJobResponse.json()).job; await terminal(otherJob.id);
    assert.equal((await privatePage.request.get(gateway + '/api/jobs?id=' + otherJob.id)).status(), 403);
    assert.equal(await privatePage.locator(`[data-job-id="${otherJob.id}"]`).count(), 0);
    const revoke = await localPost('/api/access/users', {id: userId, revision: 1, login: identity, name: 'Testfamilie',
      role: 'family', server_ids: ['demo-a'], enabled: false});
    assert.equal(revoke.status(), 200);
    await privatePage.waitForFunction(() => document.querySelector('#live-state').textContent === 'Freigabe geändert');
    assert.equal(await privatePage.locator('#start').isEnabled(), false);
    assert.equal((await privatePage.request.get(gateway + '/api/session')).status(), 403);
    identity = 'owner@example.test';
    await privatePage.goto(gateway);
    await privatePage.waitForFunction(() => document.querySelector('#live-state').textContent === 'Live verbunden');
    for (const id of ['start', 'stop', 'restart']) assert.equal(await privatePage.locator('#' + id).isEnabled(), true);
    assert.equal(await privatePage.locator('#server-select option').count(), 2);
    const privateToken = (await (await privatePage.request.get(gateway + '/api/session')).json()).token;
    await stop(owner); owner = await start('owner', 18866, driver, 18868);
    const survived = await privatePage.request.post(gateway + '/api/jobs', {headers: {'Origin': gateway, 'X-Manager-Token': privateToken},
      data: {server_id: 'demo-a', operation: 'stop', request_id: '6'.repeat(32)}});
    assert.equal(survived.status(), 202, 'Private token/grant survives application restart');
    await terminal((await survived.json()).job.id);
    await privatePage.waitForFunction(() => document.querySelector('#live-state').textContent === 'Live verbunden');
    await privatePage.screenshot({path: path.join(root, '../artifacts/symfony-access.png'), fullPage: true});
    // A grant can disappear between two bounded streams, so no revocation event can arrive.
    await privatePage.route('**/api/job-events*', route => route.fulfill({status: 403, contentType: 'application/json', body: '{}'}));
    await privatePage.route('**/api/session', route => route.fulfill({status: 403, contentType: 'application/json', body: '{}'}));
    await privatePage.reload();
    await privatePage.waitForFunction(() => document.querySelector('#live-state').textContent === 'Freigabe geändert');
    assert.equal(await privatePage.locator('#start').isEnabled(), false);
    await privatePage.unrouteAll({behavior: 'wait'});
    await privatePage.reload();
    await privatePage.waitForFunction(() => document.querySelector('#live-state').textContent === 'Live verbunden');
    // A grant may also disappear after HTML but before the very first snapshot/stream.
    let rejectedSnapshots = 0;
    await privatePage.route('**/api/jobs', route => { rejectedSnapshots++; return route.fulfill({status: 403, contentType: 'application/json', body: '{}'}); });
    await privatePage.route('**/api/session', route => route.fulfill({status: 403, contentType: 'application/json', body: '{}'}));
    await privatePage.reload();
    await privatePage.waitForFunction(() => document.querySelector('#live-state').textContent === 'Freigabe geändert');
    for (const id of ['start', 'stop', 'restart']) assert.equal(await privatePage.locator('#' + id).isEnabled(), false);
    assert.equal(rejectedSnapshots, 1, 'A rejected initial snapshot does not start a retry loop');
    await privatePage.unrouteAll({behavior: 'wait'});
    await privatePage.reload();
    await privatePage.waitForFunction(() => document.querySelector('#live-state').textContent === 'Live verbunden');
    assert.equal((await localPost('/api/access/settings', {enabled: false, hostname: privateHost, port: 18868})).status(), 200);
    await privatePage.waitForFunction(() => document.querySelector('#live-state').textContent === 'Freigabe geändert');
    for (const id of ['start', 'stop', 'restart']) assert.equal(await privatePage.locator('#' + id).isEnabled(), false);
    assert.equal((await page.request.get(origin + '/api/session')).status(), 200);
    await privatePage.close();
    await page.goto('http://127.0.0.1:18867');
    for (const id of ['start', 'stop', 'restart']) assert.equal(await page.locator('#' + id).isEnabled(), false);
    assert.deepEqual(errors, []);
    console.log(`Symfony browser QA passed (${driver}): HTTP202, FIFO, real SSE, commands during blocked status, stable local/private tokens across process restart, no POST replay, snapshot/acceptance/event races, scoped family, owner grants, live revocation, protected listener, desktop/mobile.`);
  } finally {
    if (browser) {
      for (const context of browser.contexts()) for (const page of context.pages()) await page.unrouteAll({behavior: 'ignoreErrors'});
      await browser.close();
    }
    if (familyProxy) { familyProxy.closeAllConnections(); await new Promise(resolve => familyProxy.close(resolve)); }
    for (const child of children.reverse()) await stop(child);
    await fs.rm(state, {recursive: true, force: true});
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
