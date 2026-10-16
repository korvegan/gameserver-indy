// A readable failed command must remain reachable without permission to read game logs.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const origin = process.env.INDY_BROWSER_FAMILY_ORIGIN || 'http://127.0.0.1:8766';
  const browser = await chromium.launch({headless:true});
  try {
    for (const role of ['family','viewer']) {
      const context = await browser.newContext({extraHTTPHeaders:{'X-QA-Identity':role},viewport:{width:390,height:844}});
      try {
        const page = await context.newPage(), errors = [];
        page.on('pageerror',error=>errors.push(error.message));
        await page.route('**/api/jobs*',route => {
          const url = new URL(route.request().url());
          if (route.request().method() !== 'GET' || url.searchParams.get('server') !== 'aska') return route.continue();
          const time = new Date().toISOString();
          return route.fulfill({contentType:'application/json',body:JSON.stringify({jobs:[{id:'workspace-permission-failure',server_id:'aska',operation:'start',state:'failed',message:'Isolierter Startfehler bleibt sichtbar.',created_at:time,updated_at:time,revision:1}]})});
        });
        await page.goto(origin);
        await page.waitForFunction(() => document.querySelector('#job-list').textContent.includes('Isolierter Startfehler'));
        const session = await (await page.request.get(origin+'/api/session')).json();
        assert.equal(session.principal.role,role);
        assert.equal((await page.request.get(origin+'/api/jobs?server=aska')).status(),200);
        await page.getByRole('tab',{name:'Protokoll & Aufträge',exact:true}).click();
        assert.equal(await page.locator('#job-list').isVisible(),true);
        assert.match(await page.locator('#job-list').innerText(),/Isolierter Startfehler bleibt sichtbar/);
        assert.equal(await page.locator('#logs .log-window').isVisible(),false);
        assert.equal(await page.locator('#refresh-logs').isVisible(),false);
        assert.equal((await page.request.get(origin+'/api/logs?server=aska')).status(),403);
        assert.equal((await page.request.get(origin+'/api/logs/export?server=aska')).status(),403);
        assert.equal((await page.request.get(origin+'/api/status?server=enshrouded')).status(),403);
        assert.equal(await page.locator('#start').isEnabled(),role === 'family');
        assert.equal(await page.locator('#stop').isEnabled(),false);
        assert.equal(await page.locator('#server-select option').count(),1);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth>innerWidth),false);
        assert.deepEqual(errors,[]);
      } finally { await context.close(); }
    }
    console.log('Serverzentrale access QA passed: family/viewer can read failed commands, game logs remain hidden/denied, profile scope and action permissions remain enforced.');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
