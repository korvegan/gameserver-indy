const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const {once} = require('node:events');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const {setTimeout: delay} = require('node:timers/promises');

(async () => {
  const project = path.resolve(__dirname,'../..'), root = await fs.mkdtemp(path.join(os.tmpdir(),'indy-symfony-dashboard-'));
  const origin = 'http://127.0.0.1:18876', privateOrigin = 'http://127.0.0.1:18878';
  const fixture = spawn(process.env.PYTHON || 'python3',['symfony/tools/run_dashboard_fixture.py','--root',root],{cwd:project,stdio:['ignore','pipe','pipe']});
  let output = '', browser, proxy; fixture.stdout.on('data',data => { output += data; }); fixture.stderr.on('data',data => { output += data; });
  fixture.on('error',error => { output += error.message; });
  try {
    for (let attempt = 0; ; attempt++) {
      if (fixture.exitCode !== null || attempt >= 200) throw new Error('Fixture startup: '+output.slice(-6000));
      try { if ((await fetch(origin+'/api/session')).ok) break; } catch {}
      await delay(100);
    }
    browser = await chromium.launch({headless:true});
    const page = await browser.newPage({viewport:{width:1440,height:1000}}), errors = [];
    page.on('pageerror',error => errors.push(error.message));
    const nav = name => page.getByRole('button',{name,exact:true});
    const terminal = async id => {
      for (let attempt = 0; attempt < 200; attempt++) {
        const {job} = await (await page.request.get(origin+'/api/jobs?id='+id)).json();
        if (!['queued','running'].includes(job.state)) return job;
        await delay(100);
      }
      throw new Error('Fixture job did not terminate: '+id);
    };
    const accepted = (route='/api/jobs') => page.waitForResponse(response => new URL(response.url()).pathname === route && response.request().method() === 'POST');
    const session = await (await page.request.get(origin+'/api/session')).json();
    const post = (route,data) => page.request.post(origin+route,{headers:{Origin:origin,'X-Manager-Token':session.token},data});
    let interrupted = 0;
    await page.route('**/api/job-events*',route => ++interrupted === 1 ? route.fulfill({status:503,contentType:'application/json',body:'{}'}) : route.continue());
    await page.goto(origin);
    await page.waitForFunction(() => document.querySelector('#job-live-state').textContent === 'Live verbunden');
    assert.ok(interrupted >= 2,'SSE recovers from HTTP 503');
    await page.unrouteAll({behavior:'wait'});
    await page.waitForFunction(() => document.querySelector('#server-name').textContent === 'Symfony Indy');
    for (const id of ['start','stop','restart']) assert.equal(await page.locator('#'+id).isEnabled(),true);
    const sidebar = await page.locator('#server-select').boundingBox();
    for (const name of ['Sturmklingen','Spiele / Mods','Übersicht']) {
      await nav(name).click(); assert.equal(await page.locator('#server-select').isVisible(),true);
      const box = await page.locator('#server-select').boundingBox(); assert.equal(box.y,sidebar.y);
    }
    // Commands remain available while a stale status response is held.
    let releaseStatus, statusSeen;
    const gate = new Promise(resolve => { releaseStatus = resolve; }), seen = new Promise(resolve => { statusSeen = resolve; });
    await page.route('**/api/status?server=enshrouded',async route => { const response = await route.fetch(); statusSeen(); await gate; await route.fulfill({response}); });
    await page.locator('#refresh').click(); await seen;
    let response = accepted(); await page.locator('#start').click(); const first = (await (await response).json()).job;
    page.once('dialog',dialog => dialog.accept()); response = accepted(); await page.locator('#stop').click(); const second = (await (await response).json()).job;
    assert.ok(second.sequence > first.sequence); releaseStatus(); await page.unrouteAll({behavior:'wait'});
    assert.equal((await terminal(first.id)).state,'succeeded'); assert.equal((await terminal(second.id)).state,'succeeded');
    await page.waitForFunction(() => document.querySelector('#server-state').textContent.includes('Gestoppt'));
    // Streamed terminal revisions also win over a delayed queued HTTP acceptance.
    let releaseAcceptance, acceptanceSeen, delayedJob;
    const acceptanceGate = new Promise(resolve => { releaseAcceptance = resolve; });
    const acceptanceArrival = new Promise(resolve => { acceptanceSeen = resolve; });
    await page.route('**/api/jobs',async route => {
      if (route.request().method() !== 'POST') return route.continue();
      const result = await route.fetch(); delayedJob = (await result.json()).job; acceptanceSeen();
      await acceptanceGate; await route.fulfill({response:result});
    });
    page.once('dialog',dialog => dialog.accept()); await page.locator('#stop').click(); await acceptanceArrival;
    assert.equal((await terminal(delayedJob.id)).state,'succeeded');
    await page.locator(`[data-job-id="${delayedJob.id}"][data-state="succeeded"]`).waitFor();
    releaseAcceptance(); await page.unrouteAll({behavior:'wait'});
    await page.waitForFunction(() => !document.querySelector('#start').disabled);
    assert.equal(await page.locator(`[data-job-id="${delayedJob.id}"]`).getAttribute('data-state'),'succeeded');
    // Async configuration clears a successful draft and retains a rejected draft.
    await nav('Einstellungen').click(); await page.getByLabel('Servername',{exact:true}).fill('Erster Entwurf');
    await fs.writeFile(path.join(root,'pause-native'),'hold');
    response = accepted('/api/config'); await page.getByRole('button',{name:'Einstellungen speichern',exact:true}).click(); const configJob = (await (await response).json()).job;
    // Inputs are locked only during this profile's configuration transaction.
    await fs.unlink(path.join(root,'pause-native')); assert.equal((await terminal(configJob.id)).state,'succeeded');
    await page.waitForFunction(() => document.querySelector('#unsaved').hidden);
    await page.getByLabel('Servername',{exact:true}).fill('Unverlorener Entwurf');
    await fs.writeFile(path.join(root,'unknown-status'),'unknown');
    response = accepted('/api/config'); await page.getByRole('button',{name:'Einstellungen speichern',exact:true}).click(); const failed = (await (await response).json()).job;
    assert.equal((await terminal(failed.id)).state,'failed');
    assert.equal(await page.getByLabel('Servername',{exact:true}).inputValue(),'Unverlorener Entwurf');
    assert.equal(await page.locator('#unsaved').isVisible(),true); await fs.unlink(path.join(root,'unknown-status'));
    page.once('dialog',dialog => dialog.dismiss()); await page.locator('#server-select').selectOption('aska');
    assert.equal(await page.locator('#server-select').inputValue(),'enshrouded');
    page.once('dialog',dialog => dialog.accept()); await page.locator('#server-select').selectOption('aska');
    await page.waitForFunction(() => document.querySelector('#game-label').textContent.startsWith('ASKA'));
    await page.locator('#server-select').selectOption('enshrouded'); await nav('Übersicht').click();
    // An invalid configuration keeps the catalog and the other profile usable.
    const configPath = path.join(root,'library/steamapps/common/EnshroudedServer/enshrouded_server.json'), original = await fs.readFile(configPath);
    await fs.writeFile(configPath,'broken'); await page.locator('#refresh').click();
    await page.waitForFunction(() => document.querySelector('#configuration-check').textContent.includes('nicht lesbar'));
    await page.locator('#server-select').selectOption('aska'); await page.waitForFunction(() => document.querySelector('#game-label').textContent.startsWith('ASKA'));
    await fs.writeFile(configPath,original); await page.locator('#server-select').selectOption('enshrouded');
    await page.waitForFunction(() => document.querySelector('#server-name').textContent === 'Erster Entwurf');
    // Backups use the real queue/archive services and an own, stopped fixture world.
    await nav('Weltsicherungen').click(); await page.locator('#backup-create').click();
    await page.waitForFunction(() => document.querySelectorAll('.backup-entry').length === 1);
    await page.getByRole('button',{name:'Anheften',exact:true}).click();
    await page.waitForFunction(() => document.querySelector('.backup-entry').textContent.includes('Angeheftet'));
    await fs.writeFile(path.join(root,'library/steamapps/common/EnshroudedServer/savegame/world0.ksd'),'later-own-world');
    page.once('dialog',dialog => dialog.accept('WIEDERHERSTELLEN'));
    await page.getByRole('button',{name:'Wiederherstellen',exact:true}).click();
    await page.waitForFunction(() => document.querySelectorAll('.backup-entry').length === 2);
    assert.equal(await fs.readFile(path.join(root,'library/steamapps/common/EnshroudedServer/savegame/world0.ksd'),'utf8'),'own-browser-world');
    await nav('Übersicht').click(); response = accepted('/api/updates/check'); await page.locator('#check-updates').click();
    assert.equal((await terminal((await (await response).json()).job.id)).state,'succeeded');
    // Personal WhatsApp has a synthetic QR/account; no message is sent.
    await nav('WhatsApp').click(); await page.locator('#wa-connect').click();
    await page.locator('#wa-qr').waitFor({state:'visible'});
    assert.match(await page.locator('#wa-qr').getAttribute('src'),/^blob:/);
    await page.locator('#wa-refresh').click();
    await page.waitForFunction(() => document.querySelector('#wa-state').textContent.includes('verbunden'));
    await page.locator('#wa-group').selectOption('123456789@g.us'); await page.locator('[data-wa-server="enshrouded"]').check();
    await page.locator('#wa-save').click(); await page.waitForFunction(() => document.querySelector('#notice').textContent.includes('WhatsApp-Einstellungen gespeichert'));
    // Session renewal acknowledges failure, without silently replaying any command.
    await nav('Übersicht').click(); let posts = 0;
    await page.route('**/api/jobs',route => {
      if (route.request().method() !== 'POST') return route.continue(); posts++;
      return route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({code:'session_expired',error:'fixture token'})});
    });
    await page.locator('#start').click(); await page.waitForFunction(() => document.querySelector('#notice').textContent.includes('Sitzung aktualisiert'));
    assert.equal(posts,1); await page.unrouteAll({behavior:'wait'});
    // Real private listener, with an isolated Serve-like proxy and persisted owner/family grants.
    const privateHost = 'dashboard.test.ts.net';
    assert.equal((await post('/api/access/settings',{enabled:true,hostname:privateHost,port:18877})).status(),200);
    assert.equal((await post('/api/access/users',{login:'remote@example.test',name:'Remote owner',role:'owner',server_ids:null,enabled:true})).status(),200);
    const grant = await post('/api/access/users',{login:'family@example.test',name:'Scoped family',role:'family',server_ids:['aska'],enabled:true}); assert.equal(grant.status(),200);
    const familyGrant = await grant.json();
    proxy = http.createServer((request,response) => {
      const headers = {...request.headers,host:privateHost,'tailscale-user-login':request.headers['x-qa-identity'] === 'family' ? 'family@example.test' : 'remote@example.test'};
      delete headers['x-qa-identity']; if (headers.origin === privateOrigin) headers.origin = 'https://'+privateHost;
      const upstream = http.request({hostname:'127.0.0.1',port:18877,path:request.url,method:request.method,headers},result => { response.writeHead(result.statusCode,result.headers); result.pipe(response); });
      upstream.on('error',() => { if (!response.headersSent) response.writeHead(503); response.end(); });
      response.on('close',() => upstream.destroy()); request.pipe(upstream);
    });
    await new Promise((resolve,reject) => { proxy.once('error',reject); proxy.listen(18878,'127.0.0.1',resolve); });
    const remote = await browser.newPage({viewport:{width:390,height:844}}); await remote.goto(privateOrigin);
    await remote.waitForFunction(() => document.querySelector('#identity-label').textContent === 'Besitzer · privat');
    assert.equal(await remote.getByRole('button',{name:'WhatsApp',exact:true}).count(),0);
    for (const id of ['start','stop','restart']) assert.equal(await remote.locator('#'+id).isEnabled(),true);
    assert.equal((await remote.request.get(privateOrigin+'/api/whatsapp')).status(),403);
    assert.equal(await remote.evaluate(() => document.documentElement.scrollWidth > innerWidth),false);
    await fs.mkdir(path.join(project,'artifacts'),{recursive:true}); await remote.screenshot({path:path.join(project,'artifacts/symfony-dashboard-mobile.png'),fullPage:true});
    await remote.close(); assert.deepEqual(errors,[]);
    const familyContext = await browser.newContext({extraHTTPHeaders:{'X-QA-Identity':'family'}}), familyPage = await familyContext.newPage();
    let firstStreamSeen;
    const firstStream = new Promise(resolve => { firstStreamSeen = resolve; });
    await familyPage.route('**/api/job-events*',route => { firstStreamSeen(); return route.fulfill({status:503,contentType:'application/json',body:'{}'}); });
    await familyPage.goto(privateOrigin); await firstStream;
    // Change only profile scope, leaving the public id/role/permissions identical, during an SSE gap.
    assert.equal((await post('/api/access/users',{id:familyGrant.id,revision:1,login:'family@example.test',name:'Scoped family',role:'family',server_ids:['enshrouded'],enabled:true})).status(),200);
    await familyPage.waitForFunction(() => document.querySelector('#job-live-state').textContent === 'Freigabe geändert');
    assert.equal(await familyPage.locator('#start').isDisabled(),true); await familyContext.close();
    // Restore the original scope required by the shared chronicle permission regression.
    assert.equal((await post('/api/access/users',{id:familyGrant.id,revision:2,login:'family@example.test',name:'Scoped family',role:'family',server_ids:['aska'],enabled:true})).status(),200);
    // The existing extensive chronicle and tapestry regressions also run against Symfony.
    for (const script of ['tests/browser-chronicle.cjs','tests/browser-story.cjs']) {
      const child = spawn(process.execPath,[script],{cwd:project,stdio:'inherit',env:{...process.env,INDY_BROWSER_ORIGIN:origin,INDY_BROWSER_FAMILY_ORIGIN:privateOrigin}});
      const [code] = await once(child,'exit'); assert.equal(code,0,script);
    }
    console.log('Symfony dashboard QA passed: Twig, full navigation, SSE recovery, FIFO controls, async config/drafts, broken profiles, backups/restore, updates, synthetic WhatsApp, private owner/mobile and unchanged chronicle/tapestry regressions.');
  } catch (error) { console.error(output.slice(-6000)); throw error; }
  finally {
    await browser?.close(); if (proxy) { proxy.closeAllConnections(); await new Promise(resolve => proxy.close(resolve)); }
    if (fixture.exitCode === null && fixture.signalCode === null) { const done = once(fixture,'exit'); fixture.kill('SIGTERM'); await done; }
    await fs.rm(root,{recursive:true,force:true});
    if (process.exitCode) console.error(output.slice(-5000));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
