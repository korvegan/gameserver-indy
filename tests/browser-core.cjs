// The family UI acceptance: real isolated PHP server; no crash or timing simulations.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
(async()=>{
  const origin=process.env.INDY_BROWSER_ORIGIN;
  assert.ok(origin,'Use the owned PHP fixture runner.');
  const browser=await chromium.launch({headless:true});
  try{
    const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];
    page.on('pageerror',e=>errors.push(e.message));await page.goto(origin);
    await page.waitForSelector('#world-list [data-select-server="aska"]');
    const select=async game=>{
      await page.locator(`[data-select-server="${game}"]`).click();
      const download = await page.locator('#export-logs').getAttribute('href');
      assert.ok(!download || new URL(download,origin).searchParams.get('server')===game);
      await page.waitForFunction(async game=>{const {state}=await import('/state.js');return state.selectedServer===game&&state.available&&!state.refreshing;},game);
      await page.locator('#game-banner-art').evaluate(image=>image.decode());
      assert.equal(await page.locator('#game-banner-art').getAttribute('src'),`/artwork/${game}-hero.jpg`);
    };
    for(const game of ['aska','enshrouded']){
      await select(game);
      for(const name of ['Übersicht','Einstellungen','Protokoll & Aufträge','Sicherungen','Updates','Mitteilungen']){
        await page.getByRole('tab',{name,exact:true}).click();
        assert.equal(await page.locator('#server-select').inputValue(),game);
        assert.equal(await page.locator('#start').isEnabled(),true);
      }
    }
    await page.getByRole('tab',{name:'Einstellungen',exact:true}).click();
    await page.getByLabel('Servername',{exact:true}).fill('Familienentwurf');
    await page.getByRole('tab',{name:'Updates',exact:true}).click();
    await page.getByRole('tab',{name:'Einstellungen',exact:true}).click();
    assert.equal(await page.getByLabel('Servername',{exact:true}).inputValue(),'Familienentwurf');
    page.once('dialog',d=>d.dismiss());await page.locator('[data-select-server="aska"]').click();
    assert.equal(await page.locator('#server-select').inputValue(),'enshrouded');
    assert.equal(await page.locator('#server-select').isVisible(),false);
    for(const view of ['games','community','access']) {
      await page.locator(`.sidebar [data-view="${view}"]`).click();
      assert.equal(await page.locator('#server-context').isVisible(),true);
      await page.locator('#server-context').click();
      assert.equal(await page.locator('#world-list').isVisible(),true);
    }
    await fs.mkdir('artifacts',{recursive:true});
    await page.getByRole('tab',{name:'Übersicht',exact:true}).click();
    await page.getByText('Leistung & Ruckler',{exact:true}).click();
    await page.locator('#performance-chart svg').waitFor();
    await page.locator('#performance-note').fill('Eigene Browserfixture');
    await page.locator('#mark-stutter').click();
    await page.waitForFunction(()=>document.querySelector('#performance-markers').textContent.includes('Eigene Browserfixture'));
    await page.getByText('Leistung & Ruckler',{exact:true}).click();
    const shader = 'Trying to access a shader but no shaders were included in the build because Dedicated Server Optimizations is enabled.';
    let launcherLine = 'Own launcher line';
    await page.route('**/api/logs?*', route => route.fulfill({json:{file:'own-fixture.log',updated_at:'2026-10-15T15:00:00Z',
      text:new URL(route.request().url()).searchParams.get('source')==='launcher' ? launcherLine :
        `Connected to Steam successfully\n${Array.from({length:250},()=>`2026-10-15T14:59:38Z ${shader}`).join('\n')}\nBuilt-in Resource Error: visible\nThe session is now open!`}}));
    await page.getByRole('tab',{name:'Protokoll & Aufträge',exact:true}).click();
    await page.getByText('Shader-Hinweis · 250 Wiederholungen', {exact:false}).click();
    assert.match(await page.locator('#log-content').innerText(),/Built-in Resource Error: visible/);
    await page.locator('#log-raw').check(); assert.match(await page.locator('#log-content').innerText(),/Connected to Steam successfully/);
    await page.locator('#log-raw').uncheck(); await page.locator('#log-source').selectOption('launcher');
    await page.waitForFunction(()=>document.querySelector('#log-content').textContent==='Own launcher line');
    await page.locator('#log-search').fill('no match'); assert.match(await page.locator('#log-content').innerText(),/Keine passenden/);
    await page.locator('#reset-log-filters').click(); assert.equal(await page.locator('#log-content').innerText(),'Own launcher line');
    await page.locator('#pause-logs').click();
    const beforePause = await page.locator('#log-content').innerText();
    launcherLine = 'Updated launcher line';
    await page.evaluate(async()=>{const logs=await import('/views/logs.js');await logs.loadLogs();});
    assert.equal(await page.locator('#log-content').innerText(),beforePause);
    assert.match(await page.locator('#log-span').innerText(),/PAUSIERT/);
    await page.locator('#pause-logs').click();
    await page.waitForFunction(()=>document.querySelector('#log-content').textContent==='Updated launcher line');
    await page.setViewportSize({width:390,height:1000});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    await page.screenshot({path:'artifacts/symfony-logs-390.png',fullPage:true});
    await page.unrouteAll({behavior:'wait'});
    page.once('dialog', d=>d.accept()); await select('aska'); await select('enshrouded');
    for(const width of [1440,390]){
      await page.setViewportSize({width,height:1000});
      await page.getByRole('tab',{name:'Übersicht',exact:true}).click();
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
      assert.equal(await page.locator('#quick-join-value').isVisible(),true);
      await page.screenshot({path:`artifacts/symfony-core-${width}.png`,fullPage:true});
      if (width===390) {
        await page.getByText('Leistung & Ruckler',{exact:true}).click(); await page.locator('#performance-chart svg').waitFor();
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
        await page.screenshot({path:'artifacts/symfony-performance-390.png',fullPage:true});
        await page.getByText('Leistung & Ruckler',{exact:true}).click();
      }
    }
    assert.deepEqual(errors,[]);console.log('Family UI passed: both games, server tabs, drafts, join details and desktop/mobile.');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
