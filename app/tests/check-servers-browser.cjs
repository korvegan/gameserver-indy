const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const { default: AxeBuilder } = require('@axe-core/playwright');

async function main() {
  const [url, familyPort, fixture] = process.argv.slice(2);
  assert.match(url || '', /^http:\/\/127\.0\.0\.1:[0-9]+$/);
  assert.match(familyPort || '', /^[0-9]+$/);
  const health = await (await fetch(url+'/health')).json();
  assert.equal(health.mode,'fixture'); assert.equal(health.native_enabled,false);
  assert.equal(path.dirname(fixture),fs.realpathSync(os.tmpdir()));
  assert.equal(path.basename(fixture),health.instance);
  assert.equal(fs.readFileSync(path.join(fixture,'fixture'),'utf8'),'indy-next-v1\n');
  const runtimeFile=path.join(fixture,'runtime.json');
  const originalRuntime=fs.readFileSync(runtimeFile);
  const effects=(id) => fs.existsSync(path.join(fixture,`effects-${id}.txt`)) ? fs.readFileSync(path.join(fixture,`effects-${id}.txt`),'utf8').trim().split('\n') : [];
  const scenario=(value) => fs.writeFileSync(path.join(fixture,'scenario.json'),JSON.stringify(value));
  const sql=(statement) => execFileSync(process.env.SLICE_PHP || 'php', ['-r',
    'require $argv[1]; new \\IndyNext\\FixtureStore($argv[2]); $db=new PDO("sqlite:".$argv[2]."/state.sqlite"); $db->exec($argv[3]);',
    path.resolve(__dirname,'../src/Foundation.php'),fixture,statement]);
  const session=await (await fetch(url+'/api/session')).json();
  const post=(route,data) => fetch(url+route,{method:'POST',redirect:'manual',headers:{'Content-Type':'application/x-www-form-urlencoded',Origin:url},body:new URLSearchParams({csrf:session.csrf,...data})});
  const browser=await chromium.launch({headless:true});
  let proxy;
  try {
    scenario({});
    const context=await browser.newContext({viewport:{width:1280,height:1080}});
    const page=await context.newPage();
    const errors=[];
    let expectedNetworkFailure=false;
    page.on('pageerror',(e)=>errors.push(e.message));
    page.on('console',(m)=>{if(m.type()==='error' && !expectedNetworkFailure)errors.push(m.text());});
    const card=(p,id)=>p.locator(`[data-game="${id}"]`);
    const enabled=async(p,id,action)=>p.waitForFunction(({id,action})=>{
      const b=document.querySelector(`[data-game="${id}"] [data-action="${action}"]`);return b && !b.disabled;
    },{id,action},{timeout:15000});
    const stop=async(p,id)=>{
      await enabled(p,id,'stop'); await card(p,id).getByRole('button',{name:'Stoppen',exact:true}).click();
      await p.getByRole('dialog').getByRole('button',{name:'Server stoppen',exact:true}).click();
      await enabled(p,id,'start');
    };
    const axe=async(p,label)=>assert.deepEqual((await new AxeBuilder({page:p}).analyze()).violations.map((v)=>({id:v.id,impact:v.impact})),[],label);
    for (const width of [1280,390]) {
      await page.setViewportSize({width,height:1080}); await page.goto(url);
      assert.equal(await page.title(),'Serverzentrale – Sturmklingen');
      assert.equal(await page.getByRole('heading',{name:'Serverzentrale',exact:true}).count(),1);
      await page.locator('.hero').first().evaluate((img)=>img.decode());
      for (const id of ['enshrouded','aska']) {
        await page.waitForFunction((id)=>{const b=document.querySelector(`[data-game="${id}"] [data-action="start"]`);const s=document.querySelector(`[data-game="${id}"] [data-action="stop"]`);return !b.disabled || !s.disabled;},id);
        if (await card(page,id).getByRole('button',{name:'Stoppen',exact:true}).isEnabled()) await stop(page,id);
        const before=effects(id).length;
        await card(page,id).getByRole('button',{name:'Starten',exact:true}).evaluate((b)=>{b.click();b.click();});
        await enabled(page,id,'stop');
        assert.equal(effects(id).length,before+1,'Double click has one effect');
        assert.match(await card(page,id).innerText(),/Spielbereitschaft unbekannt/);
        await card(page,id).getByRole('link',{name:'Server öffnen',exact:true}).click();
        assert.equal(new URL(page.url()).pathname,`/servers/${id}`);
        assert.equal(await page.getByRole('heading',{name:id==='aska'?'ASKA':'Enshrouded',exact:true}).count(),1);
        assert.equal(await page.getByRole('button',{name:'Kopieren',exact:true}).isDisabled(),true);
        await axe(page,`Detail ${id} at ${width}`);
        await page.getByRole('link',{name:'Serverzentrale',exact:true}).click();
        await enabled(page,id,'stop');
        const trigger=card(page,id).getByRole('button',{name:'Stoppen',exact:true});
        await trigger.click();
        const dialog=page.getByRole('dialog'); const cancel=dialog.getByRole('button',{name:'Abbrechen',exact:true});
        const confirm=dialog.getByRole('button',{name:'Server stoppen',exact:true});
        assert.equal(await cancel.evaluate((el)=>el===document.activeElement),true,'Cancel initially focused');
        await page.keyboard.press('Shift+Tab'); assert.equal(await confirm.evaluate((el)=>el===document.activeElement),true);
        await page.keyboard.press('Tab'); assert.equal(await cancel.evaluate((el)=>el===document.activeElement),true);
        if(id==='enshrouded') await axe(page,`Dialog at ${width}`);
        await page.keyboard.press('Escape'); assert.equal(await dialog.isVisible(),false);
        assert.equal(await trigger.evaluate((el)=>el===document.activeElement),true,'Focus restored after Escape');
        assert.equal(effects(id).length,before+1,'Cancel sends no stop');
        await stop(page,id);
      }
      await axe(page,`Dashboard at ${width}`);
      assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`Overflow at ${width}`);
    }
    await page.setViewportSize({width:320,height:844}); await page.goto(url);
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'320px overflow');
    await page.setViewportSize({width:1280,height:1080});
    scenario({enshrouded:{outcome:'unknown'}});
    await enabled(page,'enshrouded','start');
    const beforeUnknown=effects('enshrouded').length;
    await card(page,'enshrouded').getByRole('button',{name:'Starten',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-game="enshrouded"] [data-status]').textContent==='Ausgang unklar');
    assert.equal(await card(page,'enshrouded').getByRole('button',{name:'Stoppen',exact:true}).isDisabled(),true);
    await card(page,'enshrouded').getByRole('button',{name:'Status prüfen',exact:true}).click();
    assert.equal(effects('enshrouded').length,beforeUnknown+1,'Status check never repeats action');
    scenario({enshrouded:{reconcile:true}}); await enabled(page,'enshrouded','stop'); scenario({}); await stop(page,'enshrouded');
    scenario({enshrouded:{outcome:'failed'}});
    await card(page,'enshrouded').getByRole('button',{name:'Starten',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-game="enshrouded"] [data-status]').textContent==='Start fehlgeschlagen');
    await enabled(page,'enshrouded','start'); scenario({});
    const runtime=JSON.parse(originalRuntime); runtime.connections.enshrouded='fixture.example.org:15637';
    fs.writeFileSync(runtimeFile,JSON.stringify(runtime));
    await card(page,'enshrouded').getByRole('button',{name:'Starten',exact:true}).click(); await enabled(page,'enshrouded','stop');
    await card(page,'enshrouded').getByRole('link',{name:'Server öffnen',exact:true}).click();
    await page.waitForFunction(()=>!document.querySelector('[data-copy]').disabled);
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw new Error('fixture denied');}}}));
    await page.getByRole('button',{name:'Kopieren',exact:true}).click();
    assert.match(await page.locator('[data-notice]').innerText(),/Kopieren nicht möglich/);
    assert.equal(await page.locator('[data-connection]').inputValue(),'fixture.example.org:15637');
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async(value)=>{window.fixtureCopied=value;}}}));
    await page.getByRole('button',{name:'Kopieren',exact:true}).click();
    assert.equal(await page.evaluate(()=>window.fixtureCopied),'fixture.example.org:15637');
    await stop(page,'enshrouded'); await page.goto(url);
    const beforeLost=effects('aska').length;
    expectedNetworkFailure=true;
    await page.route('**/api/actions',(route)=>route.fulfill({status:503,contentType:'text/plain',body:'fixture unavailable'}));
    await enabled(page,'aska','start'); await card(page,'aska').getByRole('button',{name:'Starten',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-notice]').textContent.includes('Die Antwort konnte nicht bestätigt werden'));
    assert.equal(effects('aska').length,beforeLost,'Unconfirmed response sends no extra action');
    await page.unroute('**/api/actions');
    await page.route('**/api/actions',async(route)=>{await route.fetch();await route.abort('failed');});
    await enabled(page,'aska','start'); await card(page,'aska').getByRole('button',{name:'Starten',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('[data-notice]').textContent.includes('Antwort fehlt'));
    await enabled(page,'aska','stop'); await page.unroute('**/api/actions'); expectedNetworkFailure=false;
    assert.equal(effects('aska').length,beforeLost+1,'Lost response never automatically replays');
    await stop(page,'aska');
    await page.route('**/api/refresh',(route)=>route.fulfill({status:202,contentType:'application/json',body:'{"accepted":true}'}));
    sql("UPDATE servers SET observed_at='2020-01-01T00:00:00.000000Z' WHERE id='aska'");
    await page.waitForFunction(()=>document.querySelector('[data-game="aska"] [data-status]').textContent==='Status veraltet');
    assert.equal(await card(page,'aska').getByRole('button',{name:'Starten',exact:true}).isDisabled(),true);
    await page.unroute('**/api/refresh');

    const login=`browser-family-${Date.now()}@example.org`;
    assert.equal((await post('/access',{action:'create',login,role:'family'})).status,303);
    // Test-only Serve proxy: identity is fixed here, never a production listener bypass.
    proxy=http.createServer((req,res)=>{
      const headers={...req.headers,host:'fixture.tailnet.ts.net','tailscale-user-login':login};
      if(headers.origin===`http://127.0.0.1:${proxy.address().port}`)headers.origin='https://fixture.tailnet.ts.net';
      const upstream=http.request({host:'127.0.0.1',port:Number(familyPort),path:req.url,method:req.method,headers},(reply)=>{res.writeHead(reply.statusCode,reply.headers);reply.pipe(res);});
      upstream.on('error',()=>{res.writeHead(502);res.end();});req.pipe(upstream);
    });
    await new Promise((resolve)=>proxy.listen(0,'127.0.0.1',resolve));
    const familyUrl=`http://127.0.0.1:${proxy.address().port}`;
    const familyContext=await browser.newContext({viewport:{width:390,height:844}});
    const familyPage=await familyContext.newPage(); await familyPage.goto(familyUrl);
    assert.equal(await familyPage.getByRole('link',{name:'Zugänge',exact:true}).count(),0);
    for(const id of ['enshrouded','aska']){
      await enabled(familyPage,id,'start');await card(familyPage,id).getByRole('button',{name:'Starten',exact:true}).click();await enabled(familyPage,id,'stop');await stop(familyPage,id);
    }
    const snapshot=await (await familyPage.request.get(familyUrl+'/api/status')).json();
    await familyPage.route('**/api/status',(route)=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(snapshot)}));
    const grants=(await (await fetch(url+'/api/access')).json()).grants;
    const grant=grants.find((g)=>g.login===login); const beforeRevoke=effects('aska').length;
    assert.equal((await post('/access',{action:'toggle',id:grant.id,revision:grant.revision,enabled:'0'})).status,303);
    await card(familyPage,'aska').getByRole('button',{name:'Starten',exact:true}).click();
    await familyPage.getByRole('heading',{name:'Zugriff gesperrt',exact:true}).waitFor();
    assert.equal(effects('aska').length,beforeRevoke,'Previously opened family page cannot act after revocation');
    await axe(familyPage,'Revoked family page');
    assert.deepEqual(errors,[],'Unexpected browser errors');
    await familyContext.close();await context.close();
    console.log('PASS: desktop/mobile game actions, stop dialog/focus/cancel, details, Axe, truthful failure/stale/unknown states, copy fallback, lost reply and revoked family page');
  } finally {
    fs.writeFileSync(runtimeFile,originalRuntime);scenario({});
    if(proxy)await new Promise((resolve)=>proxy.close(resolve));
    await browser.close();
  }
}
main().catch((error)=>{console.error(error);process.exitCode=1;});
