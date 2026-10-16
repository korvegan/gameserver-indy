// Layout and race checks use owned fixtures or browser response doubles only.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');

(async () => {
  const origin = process.env.INDY_BROWSER_ORIGIN || 'http://127.0.0.1:8766';
  const browser = await chromium.launch({headless: true});
  const page = await browser.newPage({viewport: {width: 1440, height: 1000}}), errors = [], external = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => { if (!request.url().startsWith(origin) && !request.url().startsWith('blob:') && !request.url().startsWith('data:')) external.push(request.url()); });
  const nav = require('./browser-navigation.cjs').navigation(page);
  const position = element => { const rect = element.getBoundingClientRect(); return {x: rect.x + scrollX, y: rect.y + scrollY, width: rect.width, height: rect.height}; };
  try {
    await page.goto(origin);
    await page.waitForFunction(() => document.querySelector('#world-list [data-select-server="aska"]'));
    await page.locator('[data-select-server="enshrouded"]').click();
    await page.waitForFunction(() => document.querySelector('#server-detail').dataset.game === 'enshrouded' && document.querySelector('#quick-join-value').textContent.includes(':'));
    assert.equal(await page.locator('#network-details').evaluate(node => node.open), false);
    await fs.mkdir('artifacts', {recursive:true});
    for (const width of [1440, 820, 390, 320, 2560]) {
      await page.setViewportSize({width, height: 1000});
      for (const game of ['aska', 'enshrouded']) {
        await page.locator(`[data-select-server="${game}"]`).click();
        await page.waitForFunction(game => document.querySelector('#game-banner-art').getAttribute('src') === `/artwork/${game}-hero.jpg`, game);
        await page.waitForFunction(async game => { const {state} = await import('/state.js'); return state.selectedServer === game && state.available && !state.refreshing; }, game);
        await page.locator('#game-banner-art').evaluate(image => image.decode());
        const card = page.locator(`[data-select-server="${game}"]`);
        assert.equal(await card.getAttribute('aria-describedby'), `world-status-${game}`);
        assert.ok((await page.locator(`#world-status-${game}`).innerText()).length > 0);
        const before = await page.locator('#workspace-controlbar').evaluate(position);
        for (const label of ['Übersicht', 'Einstellungen', 'Protokoll & Aufträge', 'Sicherungen', 'Updates', 'Mitteilungen']) {
          await page.getByRole('tab', {name: label, exact: true}).click();
          assert.equal(await page.locator('.server-tabs [aria-selected="true"]').count(), 1);
          assert.equal(await page.getByRole('tab', {name: label, exact: true}).getAttribute('aria-selected'), 'true');
          assert.equal(await page.locator('#server-select').inputValue(), game);
          assert.deepEqual(await page.locator('#workspace-controlbar').evaluate(position), before);
          assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${game}/${label}/${width} overflow`);
          assert.equal(await page.locator('#quick-join-value').isVisible(), true);
          for (const id of ['start', 'stop', 'restart']) {
            assert.equal(await page.locator('#'+id).isVisible(), true);
            if (width < 761) assert.ok((await page.locator('#'+id).boundingBox()).height >= 44);
          }
        }
        await nav('Übersicht').click();
        await page.evaluate(() => scrollTo(0, 0));
        if ([1440,390].includes(width)) await page.screenshot({path:`artifacts/serverzentrale-${game}-${width}.png`,fullPage:true});
      }
    }
    await page.setViewportSize({width:1440,height:1000});
    await nav('Einstellungen').click();
    await page.getByLabel('Servername',{exact:true}).fill('Unberührter Tab-Entwurf');
    await page.evaluate(() => { window.workspaceDraftNode = document.querySelector('[name="name"]'); });
    await page.getByRole('tab',{name:'Updates',exact:true}).click();
    await page.locator('#refresh').click();
    await nav('Einstellungen').click();
    assert.equal(await page.getByLabel('Servername',{exact:true}).inputValue(),'Unberührter Tab-Entwurf');
    assert.equal(await page.evaluate(() => window.workspaceDraftNode === document.querySelector('[name="name"]')),true);
    page.once('dialog',dialog=>dialog.dismiss());
    await page.locator('[data-select-server="aska"]').click();
    assert.equal(await page.locator('#server-select').inputValue(),'enshrouded');
    page.once('dialog',dialog=>dialog.accept());
    await page.locator('[data-select-server="aska"]').click();
    await nav('Übersicht').click();
    // A failed status keeps the selected theme and actions; it never exposes the old address.
    await page.route('**/api/status?server=aska', route => route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({error:'Isolierter Statusausfall'})}));
    await page.locator('#refresh').click();
    await page.waitForFunction(() => document.querySelector('#server-state').textContent === 'Status nicht erreichbar');
    assert.equal(await page.locator('#server-detail').getAttribute('data-game'),'aska');
    assert.match(await page.locator('[data-select-server="aska"] .world-status').innerText(),/unbekannt/);
    for (const id of ['start','stop','restart']) assert.equal(await page.locator('#'+id).isEnabled(),true);
    await page.unrouteAll({behavior:'wait'});
    await page.locator('[data-select-server="enshrouded"]').click();
    await page.waitForFunction(() => document.querySelector('#game-label').textContent.startsWith('ENSHROUDED'));
    await page.getByRole('tab',{name:'Übersicht',exact:true}).focus();
    await page.keyboard.press('End');
    assert.equal(await page.evaluate(() => document.activeElement.id),'tab-discord');
    await page.keyboard.press('Home');
    assert.equal(await page.evaluate(() => document.activeElement.id),'tab-overview');
    const selector = await page.locator('#server-context').evaluate(position);
    await nav('Sturmklingen').click();
    assert.deepEqual(await page.locator('#server-context').evaluate(position),selector);
    await nav('Übersicht').click();
    assert.equal(await page.locator('#server-select').inputValue(),'enshrouded');
    assert.deepEqual(errors,[]); assert.deepEqual(external,[]);
    console.log('Serverzentrale QA passed: six tabs, per-game artwork, stable controls, card draft guard, surviving form nodes, unknown status, keyboard, global selection and 1440/820/390/320/2560px.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
