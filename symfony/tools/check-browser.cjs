const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const {once} = require('node:events');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {setTimeout: delay} = require('node:timers/promises');

(async () => {
  const root = path.resolve(__dirname, '..');
  const state = await fs.mkdtemp(path.join(os.tmpdir(), 'indy-symfony-browser-'));
  const children = [];
  let output = '';
  let browser;
  async function start(role, port, driver) {
    await new Promise((resolve, reject) => {
      const probe = net.createServer(); probe.once('error', reject);
      probe.listen(port, '127.0.0.1', () => probe.close(resolve));
    });
    const child = spawn(process.env.PYTHON || 'python3', ['tools/run_slice.py', '--port', String(port), '--role', role,
      '--driver', driver, '--state-dir', path.join(state, role)], {cwd: root, stdio: ['ignore', 'pipe', 'pipe']});
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
    let owner = await start('owner', 18866, driver);
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
    await stop(owner); owner = await start('owner', 18866, driver);
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
    await page.goto('http://127.0.0.1:18867');
    for (const id of ['start', 'stop', 'restart']) assert.equal(await page.locator('#' + id).isEnabled(), false);
    assert.deepEqual(errors, []);
    console.log(`Symfony browser QA passed (${driver}): HTTP202, FIFO, real SSE, commands during blocked status, stable token across process restart, no POST replay, snapshot/acceptance/event races, reader role, desktop/mobile.`);
  } finally {
    if (browser) {
      for (const context of browser.contexts()) for (const page of context.pages()) await page.unrouteAll({behavior: 'ignoreErrors'});
      await browser.close();
    }
    for (const child of children.reverse()) await stop(child);
    await fs.rm(state, {recursive: true, force: true});
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
