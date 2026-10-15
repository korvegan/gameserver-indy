// Illustrated scenes against temporary fixture data. No live worlds or messages.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined});
  const page = await browser.newPage({viewport: {width: 1440, height: 960}}), errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  try {
    await page.goto('http://127.0.0.1:8766/#scene-qa-milestone');
    await page.locator('#scene-qa-milestone').waitFor();
    const animations = () => page.locator('#tapestry-viewport').evaluate(node => node.getAnimations({subtree: true}).map(item => item.playState));
    assert.deepEqual(await animations(), [], 'Historical scene must stay still.');
    await page.locator('#new-moment').click();
    await page.locator('#moment-title').fill('Vier Klingen am Gipfel');
    await page.locator('#moment-game').selectOption('enshrouded');
    await page.locator('#moment-instance').selectOption('enshrouded');
    await page.locator('#moment-members').fill('Indy\nSchwester\nSchwager\nGefährtin');
    await page.locator('#moment-badge').fill('Gemeinsam oben');
    await page.locator('#moment-campaign').fill('Wandteppich-Test');
    const savedResponse = page.waitForResponse(response => response.url().endsWith('/api/community') && response.request().method() === 'POST');
    await page.locator('#save-moment').click();
    const {event_id: id} = await (await savedResponse).json();
    await page.waitForFunction(id => document.activeElement.id === 'scene-' + id, id);
    assert.equal(await page.locator('.tapestry-scene.scene-revealing').count(), 1);
    assert.equal(await page.locator('#moment-detail').evaluate(node => node.open), false);
    await page.locator('#story-pause').click();
    assert.deepEqual(await animations(), ['paused']);
    await page.locator('#story-pause').click();
    await page.locator('#story-skip').click();
    assert.deepEqual(await animations(), []);
    await page.locator('#scene-' + id).focus(); await page.keyboard.press('Enter');
    await page.waitForFunction(() => document.querySelector('#moment-detail').open);
    assert.equal(await page.locator('#moment-detail .badge-list').first().locator('li').count(), 4);
    assert.ok((await page.locator('#moment-detail-body').textContent()).includes('MANUELL BESTÄTIGT'));
    await page.getByRole('button', {name: 'Moment noch einmal feiern', exact: true}).click();
    await page.locator('.scene-revealing').waitFor();
    // Leaving the visible board pauses work; coming back never resumes silently.
    await page.evaluate(() => { document.querySelector('#community').hidden = true; });
    await page.waitForFunction(() => document.querySelector('#tapestry-viewport').getAnimations({subtree: true}).every(item => item.playState === 'paused'));
    await page.evaluate(() => { document.querySelector('#community').hidden = false; });
    await page.locator('#story-skip').click();
    await page.locator('#scene-' + id).click();
    await page.getByRole('button', {name: 'Moment noch einmal feiern', exact: true}).click();
    await page.emulateMedia({reducedMotion: 'reduce'});
    await page.waitForFunction(() => !document.querySelector('#tapestry-viewport').getAnimations({subtree: true}).length);
    assert.equal(await page.locator('#story-skip').isHidden(), true);
    await page.emulateMedia({reducedMotion: 'no-preference'});
    const chapter = await page.locator('#story-chapter').inputValue();
    await page.goto('http://127.0.0.1:8766/#scene-' + id);
    await page.waitForFunction(id => document.activeElement.id === 'scene-' + id, id);
    assert.equal(await page.locator('#story-chapter').inputValue(), chapter);
    assert.deepEqual(await animations(), []);
    await page.emulateMedia({reducedMotion: 'reduce'});
    const card = page.locator(`#chronicle [data-event-id="${id}"]`);
    await card.getByRole('button', {name: 'Szene ansehen', exact: true}).click();
    assert.deepEqual(await animations(), []);
    assert.equal(await page.locator('#story-pause').isHidden(), true);
    // Zoom, drag and keyboard all operate on viewBox, never rebuild stored scenes.
    const original = await page.locator('.tapestry-canvas').getAttribute('viewBox');
    await page.locator('#story-zoom-in').click();
    const enlarged = await page.locator('.tapestry-canvas').getAttribute('viewBox');
    assert.notEqual(enlarged, original);
    await page.locator('.tapestry-canvas').focus(); await page.keyboard.press('ArrowRight');
    assert.notEqual(await page.locator('.tapestry-canvas').getAttribute('viewBox'), enlarged);
    const panned = await page.locator('.tapestry-canvas').getAttribute('viewBox');
    const box = await page.locator('.tapestry-canvas').boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down(); await page.mouse.move(box.x + box.width / 2 + 50, box.y + box.height / 2 + 35, {steps: 4}); await page.mouse.up();
    assert.notEqual(await page.locator('.tapestry-canvas').getAttribute('viewBox'), panned);
    assert.equal(await page.locator('#moment-detail').evaluate(node => node.open), false);
    // Releasing outside before capture must not leave a drag on pointer re-entry.
    const edgeY = box.y + box.height / 2;
    await page.mouse.move(box.x + 2, edgeY); await page.mouse.down();
    await page.mouse.move(box.x - 20, edgeY); await page.mouse.up();
    const released = await page.locator('.tapestry-canvas').getAttribute('viewBox');
    await page.mouse.move(box.x + 60, edgeY + 35);
    assert.equal(await page.locator('.tapestry-canvas').getAttribute('viewBox'), released);
    await page.locator('#story-fit').click();
    assert.equal(await page.locator('.tapestry-canvas').getAttribute('viewBox'), original);
    const token = (await (await page.request.get('http://127.0.0.1:8766/api/session')).json()).token;
    const latest = await (await page.request.get('http://127.0.0.1:8766/api/community/story')).json();
    const position = Math.max(...latest.events.map(event => event.scene.position));
    const padding = (6 - (position + 1) % 6) % 6;
    for (let index = 0; index < padding + 12; index++) {
      const response = await page.request.post('http://127.0.0.1:8766/api/community', {headers: {'X-Manager-Token': token}, data: {
        id: 'story-growth-' + index, revision: 1, title: 'Gemeinsamer Aufbruch ' + (index + 1), game_id: ['enshrouded', 'aska', 'raft'][index % 3],
        campaign: 'Drei Welten', members: ['Indy', 'Schwester', 'Schwager', 'Gefährtin'], badge: 'Aufbruch', occurred_at: ''
      }});
      assert.equal(response.status(), 200);
    }
    await page.goto('http://127.0.0.1:8766/#scene-story-growth-' + (padding + 11));
    await page.locator('#scene-story-growth-' + (padding + 11)).waitFor();
    assert.equal(await page.locator('.tapestry-scene').count(), 6, 'Only one bounded chapter renders.');
    const sceneNodes = await page.locator('.tapestry-canvas *').count(); assert.ok(sceneNodes < 650);
    assert.deepEqual(await animations(), []);
    fs.mkdirSync('artifacts', {recursive: true});
    await page.locator('#story-board').screenshot({path: 'artifacts/tapestry-desktop.png'});
    const downloadPromise = page.waitForEvent('download'); await page.locator('#story-export').click();
    const download = await downloadPromise; await download.saveAs('artifacts/tapestry.svg');
    const exported = fs.readFileSync('artifacts/tapestry.svg', 'utf8');
    assert.ok(exported.includes('STURMKLINGEN') || exported.includes('S T U R M K L I N G E N'));
    assert.ok(!/<script|onload=|https?:|<image|<foreignObject/.test(exported.replace('http://www.w3.org/2000/svg', '')));
    const svgBytes = Buffer.byteLength(exported); assert.ok(svgBytes < 100000);
    const latestChapter = await page.locator('#story-chapter').inputValue();
    await page.locator('#story-previous').click();
    await page.waitForFunction(chapter => document.querySelector('#story-chapter').value !== chapter, latestChapter);
    await page.locator('#story-next').click();
    await page.locator('#scene-story-growth-' + (padding + 11)).waitFor();
    for (const width of [5120, 390, 320]) {
      await page.setViewportSize({width, height: width > 400 ? 1440 : 844});
      await page.locator('#story-board').scrollIntoViewIfNeeded();
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `Overflow at ${width}px`);
      await page.locator('#story-board').screenshot({path: `artifacts/tapestry-${width}.png`});
    }
    // Idle browser metrics bound the rendering cost; no claim about native GPU/game FPS.
    const session = await page.context().newCDPSession(page); await session.send('Performance.enable');
    const metric = async () => Object.fromEntries((await session.send('Performance.getMetrics')).metrics.map(item => [item.name, item.value]));
    const before = await metric(); await page.waitForTimeout(1100); const after = await metric();
    const idleTaskMs = (after.TaskDuration - before.TaskDuration) * 1000;
    assert.ok(idleTaskMs < 200, `Idle task time unexpectedly high: ${idleTaskMs}ms`);
    // Detail responses belong to the chapter in which the reader opened them.
    let releaseDetail, detailSeen;
    const detailGate = new Promise(resolve => { releaseDetail = resolve; });
    const detailArrival = new Promise(resolve => { detailSeen = resolve; });
    const latestScene = await page.locator('.tapestry-scene').first().getAttribute('data-event-id');
    await page.route('**/api/community/event?id=' + latestScene, async route => {
      const response = await route.fetch(); detailSeen(); await detailGate; await route.fulfill({response});
    });
    await page.locator('#scene-' + latestScene).click(); await detailArrival;
    await page.locator('#story-previous').click();
    await page.waitForFunction(chapter => document.querySelector('#story-chapter').value !== chapter, latestChapter);
    const detailDone = page.waitForResponse(response => response.url().endsWith('/api/community/event?id=' + latestScene));
    releaseDetail(); await detailDone;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal(await page.locator('#moment-detail').evaluate(node => node.open), false);
    await page.unroute('**/api/community/event?id=' + latestScene);
    await page.locator('#story-next').click();
    await page.waitForFunction(chapter => document.querySelector('#story-chapter').value === chapter, latestChapter);
    // A chapter requested by an older scene click must not steal a newer draft.
    let releaseChapter, chapterSeen;
    const gate = new Promise(resolve => { releaseChapter = resolve; });
    const seen = new Promise(resolve => { chapterSeen = resolve; });
    await page.route('**/api/community/story?**', async route => {
      const response = await route.fetch(); chapterSeen(); await gate; await route.fulfill({response});
    });
    await page.locator(`#chronicle [data-event-id="${id}"]`).getByRole('button', {name: 'Szene ansehen', exact: true}).click(); await seen;
    await page.locator('#new-moment').click();
    await page.locator('#moment-title').fill('Dieser Entwurf bleibt im Vordergrund');
    const done = page.waitForResponse(response => response.url().includes('/api/community/story?'));
    releaseChapter(); await done;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal(await page.locator('#moment-editor').evaluate(node => node.open), true);
    assert.equal(await page.locator('#moment-title').inputValue(), 'Dieser Entwurf bleibt im Vordergrund');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'moment-title');
    assert.equal(await page.locator('#scene-' + id).count(), 0);
    assert.deepEqual(errors, []);
    console.log(`Tapestry QA passed: durable chapters, 4-player reveal, pause/skip, hidden view, reduced motion, keyboard, zoom, SVG, 5120/390/320px. ${sceneNodes} SVG nodes, ${svgBytes} bytes, idle tasks ${idleTaskMs.toFixed(1)}ms / 1.1s.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
