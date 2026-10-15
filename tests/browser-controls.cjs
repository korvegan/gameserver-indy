const {chromium} = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
  try {
    const page = await browser.newPage({viewport: {width: 390, height: 844}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const origin = 'http://127.0.0.1:8766';
    const session = await (await page.request.get(origin + '/api/session')).json();
    const headers = {'X-Manager-Token': session.token};
    await page.request.post(origin + '/api/stop', {headers, data: {server_id: 'enshrouded'}});
    await page.request.post(origin + '/api/setup', {headers, data: {server_id: 'enshrouded'}});
    await page.goto(origin);
    await page.waitForFunction(() => !document.querySelector('#start').disabled);
    await page.locator('#server-select').selectOption('enshrouded');
    await page.waitForFunction(() => !document.querySelector('#start').disabled);
    for (const id of ['start', 'stop', 'restart']) assert.equal(await page.locator('#' + id).isEnabled(), true);
    let releaseStatus, statusArrived;
    const gate = new Promise(resolve => { releaseStatus = resolve; });
    const seen = new Promise(resolve => { statusArrived = resolve; });
    await page.route('**/api/status?server=enshrouded', async route => { const response = await route.fetch(); statusArrived(); await gate; await route.fulfill({response}); });
    await page.locator('#refresh').click(); await seen;
    for (const id of ['start', 'stop', 'restart']) assert.equal(await page.locator('#' + id).isEnabled(), true, 'Status refresh does not lock commands');
    const started = page.waitForResponse(response => response.url().endsWith('/api/jobs') && response.request().method() === 'POST');
    await page.locator('#start').click();
    const first = await started;
    assert.equal(first.status(), 202);
    const firstJob = (await first.json()).job;
    const statusDone = page.waitForResponse(response => response.url().endsWith('/api/status?server=enshrouded'));
    releaseStatus(); await statusDone;
    await page.unroute('**/api/status?server=enshrouded');
    await page.waitForFunction(() => !document.querySelector('#stop').disabled);
    const stopped = page.waitForResponse(response => response.url().endsWith('/api/jobs') && response.request().method() === 'POST');
    await Promise.all([page.waitForEvent('dialog').then(dialog => dialog.accept()), page.locator('#stop').click()]);
    const second = await stopped;
    assert.equal(second.status(), 202);
    const secondJob = (await second.json()).job;
    assert.equal(secondJob.state, 'queued');
    assert.equal(secondJob.queue_position, 1);
    const done = async id => {
      for (let attempt = 0; attempt < 100; attempt++) {
        const job = (await (await page.request.get(origin + '/api/jobs?id=' + id)).json()).job;
        if (!['queued', 'running'].includes(job.state)) { assert.equal(job.state, 'succeeded'); return; }
        await page.waitForTimeout(50);
      }
      assert.fail('fixture job timed out');
    };
    await done(firstJob.id); await done(secondJob.id);
    assert.equal((await (await page.request.get(origin + '/api/status?server=enshrouded')).json()).status.running, false);
    await page.waitForFunction(() => !document.querySelector('#start').disabled);
    // Recovery renews the token but never replays the rejected POST automatically.
    let posts = 0;
    await page.route('**/api/jobs', async route => {
      if (route.request().method() !== 'POST') return route.continue();
      posts++;
      if (posts === 1) return route.fulfill({status: 403, contentType: 'application/json', body: JSON.stringify({code: 'session_expired', error: 'expired test session'})});
      await route.continue();
    });
    await page.locator('#start').click();
    await page.waitForFunction(() => document.querySelector('#notice').textContent.includes('Sitzung aktualisiert'));
    assert.equal(posts, 1);
    assert.equal(await page.locator('#overview').isVisible(), true);
    assert.equal((await (await page.request.get(origin + '/api/status?server=enshrouded')).json()).status.running, false);
    const accepted = page.waitForResponse(response => response.url().endsWith('/api/jobs') && response.request().method() === 'POST');
    await page.locator('#start').click();
    const fresh = await accepted; assert.equal(fresh.status(), 202);
    await done((await fresh.json()).job.id);
    assert.equal(posts, 2);
    await page.unroute('**/api/jobs');
    await page.request.post(origin + '/api/stop', {headers, data: {server_id: 'enshrouded'}});
    assert.deepEqual(errors, []);
    console.log('Control QA passed: commands during blocked status, FIFO stop after start, no automatic replay on session renewal, explicit next command, mobile.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
