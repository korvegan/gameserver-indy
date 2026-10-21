// Targeted architecture acceptance, manual: no real games or message transports.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const {setTimeout: delay} = require('node:timers/promises');
(async () => {
  const origin = process.env.INDY_BROWSER_ORIGIN; assert.ok(origin, 'Use the owned fixture runner.');
  const browser = await chromium.launch({headless:true});
  try {
    const page = await browser.newPage(); const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { const url = new URL(request.url()); if (request.method()==='GET') requests.push(url); });
    let attempts = 0;
    await page.route('**/api/job-events*', route => ++attempts===1 ? route.fulfill({status:503,body:'{}'}) : route.continue());
    await page.goto(origin); await page.waitForFunction(() => document.querySelector('#job-live-state').textContent==='Live verbunden');
    await page.evaluate(async()=>{window.eventTestState=(await import('/state.js')).state;});
    await page.waitForFunction(() => eventTestState.available && !eventTestState.refreshing && !eventTestState.pending.size);
    await delay(1000); requests.length = 0; await delay(8500);
    assert.deepEqual(requests.filter(url=>['/api/status','/api/jobs'].includes(url.pathname)).map(url=>url.pathname+url.search), [], 'Idle dashboard must not poll status or jobs.');
    await page.locator('#start').click();
    await page.waitForFunction(() => eventTestState.snapshot?.status.running && eventTestState.latestJobs.some(job=>job.operation==='start' && job.state==='succeeded'));
    await page.evaluate(() => { Object.defineProperty(document,'hidden',{configurable:true,get:()=>true}); document.dispatchEvent(new Event('visibilitychange')); });
    await page.waitForFunction(() => document.querySelector('#job-live-state').textContent==='Live pausiert');
    const accepted = page.waitForResponse(response=>new URL(response.url()).pathname==='/api/jobs' && response.request().method()==='POST');
    page.once('dialog',dialog=>dialog.accept()); await page.locator('#stop').click();
    const {job} = await (await accepted).json();
    for (let i=0; ; ++i) {
      const result = await (await page.request.get(origin+'/api/jobs?id='+job.id)).json();
      if (result.job.state==='succeeded') break;
      assert.ok(i<40, 'Fixture stop did not finish.'); await delay(100);
    }
    await delay(2200);
    assert.equal(await page.evaluate(()=>eventTestState.pending.has(eventTestState.selectedServer)),true,'Offline UI waits for live reconciliation instead of individual job polls.');
    await page.evaluate(() => { Object.defineProperty(document,'hidden',{configurable:true,get:()=>false}); document.dispatchEvent(new Event('visibilitychange')); });
    await page.waitForFunction(() => document.querySelector('#job-live-state').textContent==='Live verbunden');
    await page.waitForFunction(() => eventTestState.snapshot?.status.running===false && !eventTestState.pending.has(eventTestState.selectedServer) && !eventTestState.refreshing);
    assert.deepEqual(requests.filter(url=>url.pathname==='/api/jobs' && url.searchParams.has('id')).map(url=>url.href), [], 'UI must never poll individual jobs.');
    assert.ok(attempts>=3, 'Initial failure and visibility reconnect both exercised.');
    assert.deepEqual(errors, []);
    // Reproduce the review finding: streaming must leave PHP capacity for normal commands.
    await page.close();
    const controllers = Array.from({length:7},()=>new AbortController());
    try {
      const streams = await Promise.all(controllers.map(controller=>fetch(origin+'/api/job-events', {signal:controller.signal})));
      const first = await Promise.all(streams.map(async response=>new TextDecoder().decode((await response.body.getReader().read()).value)));
      assert.ok(first.filter(chunk=>chunk.includes('event: capacity')).length>=3, 'Extra live channels must be explicitly limited.');
      const session = await fetch(origin+'/api/session', {signal:AbortSignal.timeout(2000)});
      assert.equal(session.status,200, 'Normal API requests stay reachable with excess live tabs.');
    } finally { for (const controller of controllers) controller.abort(); }
    console.log('Event UI passed: idle has zero status/job requests, live completion, offline pending state and snapshot recovery; no individual job polling.');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
