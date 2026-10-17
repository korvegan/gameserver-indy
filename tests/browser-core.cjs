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
    // A synthetic fixture transport creates/edits locally; no Discord network access.
    await page.evaluate(async()=>{const {api}=await import('/api.js');await api('/api/discord/settings',{webhook_url:'https://discord.com/api/webhooks/123/synthetic',auto_notify:false},'enshrouded');});
    await page.getByRole('tab',{name:'Mitteilungen',exact:true}).click();
    await page.locator('#create-discord-card:not([disabled])').waitFor();
    page.once('dialog',d=>d.accept()); await page.locator('#create-discord-card').click();
    await page.waitForFunction(()=>document.querySelector('#discord-card-state').textContent.includes('Statuskarte aktiv'));
    await page.locator('#disable-discord-card').click();
    await page.waitForFunction(()=>document.querySelector('#discord-card-state').textContent.includes('Aktualisierung aus'));
    await page.getByRole('tab',{name:'Sicherungen',exact:true}).click();
    const worlds=page.locator('#world-management'); await worlds.getByText('Neue Kampagne oder Testkopie vorbereiten',{exact:true}).click();
    await worlds.getByLabel('Weltname',{exact:true}).fill('Eigene Browser-Kopie'); await worlds.getByLabel('Art',{exact:true}).selectOption('clone');
    page.once('dialog',d=>d.accept()); await worlds.getByRole('button',{name:'Separat vorbereiten'}).click();
    await worlds.locator('p').filter({hasText:/^Eigene Browser-Kopie/}).getByRole('button',{name:'Welt auswählen'}).waitFor();
    page.once('dialog',d=>d.accept()); await worlds.locator('p').filter({hasText:/^Eigene Browser-Kopie/}).getByRole('button',{name:'Welt auswählen'}).click();
    await page.waitForFunction(()=>document.querySelector('#world-management').textContent.includes('Eigene Browser-Kopie · ausgewählt'));
    page.once('dialog',d=>d.accept()); await worlds.locator('p').filter({hasText:/^Bestandswelt/}).getByRole('button',{name:'Welt auswählen'}).click();
    await page.waitForFunction(()=>document.querySelector('#world-management').textContent.includes('Bestandswelt · ausgewählt'));
    await page.getByRole('tab',{name:'Updates',exact:true}).click();
    await page.getByRole('button',{name:'Wartung vormerken'}).click();
    await page.locator('#maintenance-plan').getByText('Neustart: wartet auf Besitzerfreigabe.',{exact:true}).waitFor();
    page.once('dialog',d=>d.accept()); await page.getByRole('button',{name:'Trotz unbekannter Belegung freigeben'}).click();
    await page.waitForFunction(()=>document.querySelector('#maintenance-plan').textContent.includes('frühestens'));
    await page.getByRole('button',{name:'Wartungsplan abbrechen'}).click();
    await page.locator('#maintenance-plan').getByText('Neustart: abgebrochen.',{exact:true}).waitFor();
    await page.setViewportSize({width:390,height:1000});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    await page.setViewportSize({width:1440,height:1000});
    await page.locator('.sidebar [data-view="community"]').click();
    await page.locator('#community-members summary').click();
    await page.locator('#member-accounts option').first().waitFor({state:'attached'});
    await page.locator('#member-name').fill('Eigene Browserperson');
    await page.locator('#member-accounts').selectOption(['local-owner']);
    await page.locator('#member-form button[type="submit"]').click();
    await page.waitForFunction(()=>document.querySelector('#community-member-list').textContent.includes('Eigene Browserperson'));
    await page.locator('#new-moment').click();
    await page.locator('#moment-title').fill('Gemeinsamer Profil-Moment');
    await page.locator('#moment-participants input').check();
    await page.locator('#moment-badge').fill('Unser gemeinsamer Anfang');
    const savedMoment = page.waitForResponse(response=>new URL(response.url()).pathname === '/api/community' && response.request().method() === 'POST');
    await page.locator('#save-moment').click();
    const moment = await (await savedMoment).json();
    await page.locator(`#scene-${moment.event_id}`).click();
    await page.waitForFunction(()=>document.querySelector('#moment-detail').open);
    assert.match(await page.locator('#moment-detail-body').innerText(),/Eigene Browserperson/);
    await page.getByRole('button',{name:'In Discord teilen',exact:true}).click();
    await page.locator('#community-webhook').fill('https://discord.com/api/webhooks/456/community');
    await page.locator('#community-discord-form button').click();
    await page.waitForFunction(()=>document.querySelector('#community-discord-state').textContent.includes('eingerichtet. Jede'));
    page.once('dialog',d=>d.accept()); await page.locator('#publish-community-event').click();
    await page.waitForFunction(()=>document.querySelector('#community-publication-state').textContent.includes('Veröffentlicht'));
    page.once('dialog',d=>d.accept()); await page.locator('#community-summary').click();
    await page.waitForFunction(()=>document.querySelector('#community-summary-state').textContent.includes('veröffentlicht'));
    await page.locator('#community-discord > summary').click();
    await page.locator('#community-members summary').click();
    await page.locator('#community-challenges > summary').click();
    await page.getByText('Neue Kampagne mit festem Mitgliederkreis',{exact:true}).click();
    await page.locator('#campaign-game option[value="enshrouded"]').waitFor({state:'attached'});
    await page.locator('#campaign-name').fill('Eigene Browserkampagne');
    await page.locator('#campaign-game').selectOption('enshrouded');
    await page.locator('#campaign-members').selectOption({label:'Eigene Browserperson'});
    await page.locator('#campaign-form button').click();
    await page.locator('#goal-campaign option').waitFor({state:'attached'});
    await page.getByText('Neues Sammelziel',{exact:true}).click();
    await page.locator('#goal-title').fill('Gemeinsam angekommen');
    await page.locator('#goal-badge').fill('Eine Gruppe');
    await page.locator('#goal-create').click();
    const goal = page.locator('#challenge-list [data-goal-id]');
    await goal.locator('form select').nth(1).selectOption('achieved');
    await goal.getByRole('button',{name:'Nachweis speichern'}).click();
    await page.waitForFunction(()=>document.querySelector('#challenge-list').textContent.includes('Gemeinsam geschafft'));
    await page.setViewportSize({width:390,height:1000});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    await fs.mkdir('artifacts',{recursive:true}); await page.screenshot({path:'artifacts/symfony-challenges-390.png',fullPage:true});
    await page.setViewportSize({width:1440,height:1000});
    await page.locator('#community-challenges > summary').click();
    await page.locator('#server-context').click();
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
      if(view==='games') {
        const raft=page.locator('#game-catalog article').filter({has:page.getByRole('heading',{name:'Raft',exact:true})});
        await raft.locator('summary').click();
        const source=raft.locator('[data-mod-source="raft"]'); await source.locator('[name="source"]').selectOption('raftmodding');
        await source.locator('[name="game_key"]').fill('raft'); await source.getByRole('button').click();
        const mod=raft.locator('[data-mod-item="raft"]'); await mod.locator('[name="url"]').fill('https://www.raftmodding.com/mods/own-fixture');
        await mod.locator('[name="name"]').fill('Eigener Test-Mod'); await mod.getByRole('button').click();
        await raft.getByRole('link',{name:'Eigener Test-Mod'}).waitFor();
        await raft.getByRole('button',{name:'Bearbeiten',exact:true}).click();
        await mod.locator('[name="sha256"]').fill('a'.repeat(64)); await mod.locator('[name="notes"]').fill('Gemeinsame Hinweise');
        await mod.locator('[name="version"]').fill('1.0'); await mod.locator('[name="file_id"]').fill('own-file-1'); await mod.locator('[name="loader"]').fill('RML 2.8.0');
        await mod.locator('[name="order"]').fill('2'); await mod.getByRole('button').click();
        await raft.getByText('Gemeinsame Hinweise',{exact:true}).waitFor();
        const publish=raft.locator('[data-mod-publish="raft"]');
        await publish.locator('[name="name"]').fill('Eigener Gruppenstand'); await publish.locator('[name="game_version"]').fill('own-fixture-1');
        await publish.locator('[name="loader"]').fill('RML 2.8.0'); await publish.getByRole('checkbox',{name:'Eigener Test-Mod · 1.0 · own-file-1',exact:true}).check();
        await publish.getByRole('button').click();
        const profile=raft.locator('[data-mod-profile]'); await profile.getByRole('heading',{name:'Eigener Gruppenstand · Revision 1'}).waitFor();
        await profile.getByRole('button',{name:'Eigene Versionen und Hashes geprüft'}).click();
        await profile.getByText('Deine Rückmeldung: manuell abgeglichen',{exact:true}).waitFor();
        await page.setViewportSize({width:390,height:1000}); assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
        await page.setViewportSize({width:1440,height:1000});
      }
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
