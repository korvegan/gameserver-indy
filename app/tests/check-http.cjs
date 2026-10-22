const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { setTimeout: delay } = require('node:timers/promises');

async function main() {
  const php = process.env.SLICE_PHP || 'php';
  execFileSync(php, [path.join(__dirname, 'run.php')], { stdio: 'inherit' });
  execFileSync(php, [path.join(__dirname, 'check-bridge.php')], { stdio: 'inherit' });
  execFileSync(php, [path.join(__dirname, 'check-access.php')], { stdio: 'inherit' });
  execFileSync(php, [path.join(__dirname, 'check-servers.php')], { stdio: 'inherit' });
  const reservation = net.createServer();
  await new Promise((resolve, reject) => reservation.once('error', reject).listen(0, '127.0.0.1', resolve));
  const port = reservation.address().port;
  await new Promise((resolve) => reservation.close(resolve));
  const familyReservation = net.createServer();
  await new Promise((resolve, reject) => familyReservation.once('error', reject).listen(0, '127.0.0.1', resolve));
  const familyPort = familyReservation.address().port;
  await new Promise((resolve) => familyReservation.close(resolve));
  const root = path.resolve(__dirname, '..');
  const child = spawn(php, [path.join(root, 'bin/dev'), String(port), String(familyPort), 'fixture.tailnet.ts.net'], { env: process.env, stdio: ['ignore', 'pipe', 'pipe'] });
  let output = '';
  let errors = '';
  let exited = false;
  child.stdout.on('data', (chunk) => { output += chunk; });
  child.stderr.on('data', (chunk) => { errors = (errors + chunk).slice(-8000); });
  child.once('exit', () => { exited = true; });
  child.once('error', (error) => { errors += error.message; exited = true; });
  let fixture;
  try {
    const url = `http://127.0.0.1:${port}`;
    let healthy = false;
    for (let attempt = 0; attempt < 100; attempt += 1) {
      fixture = output.match(/^Fixture: (.+)$/m)?.[1];
      if (exited) throw new Error(errors || 'Server exited');
      if (fixture) {
        try {
          const response = await fetch(`${url}/health`, { signal: AbortSignal.timeout(500) });
          const health = await response.json();
          assert.equal(health.instance, path.basename(fixture));
          assert.equal(health.mode, 'fixture');
          assert.equal(health.native_enabled, false);
          const revisionFile = path.join(root, '../REVISION');
          assert.equal(health.build, fs.existsSync(revisionFile) ? fs.readFileSync(revisionFile, 'utf8').trim() : 'development');
          healthy = true;
          break;
        } catch { /* Wait for this instance, never accept a different listener. */ }
      }
      await delay(100);
    }
    assert.ok(healthy, `Startup failed: ${errors}`);
    const page = await fetch(url);
    assert.match(await page.text(), /Serverzentrale/);
    assert.match(page.headers.get('content-security-policy'), /default-src 'none'/);
    assert.equal(page.headers.get('cache-control'), 'no-store');
    const status = await (await fetch(`${url}/api/status`)).json();
    assert.deepEqual(status.servers.map((server) => [server.id, server.process, server.ready]),
      [['aska', 'unknown', null], ['enshrouded', 'unknown', null]]);
    assert.equal((await fetch(`${url}/api/status`, { method: 'POST', body: '{}' })).status, 405);
    const foreignHost = await new Promise((resolve, reject) => {
      const request = http.get(`${url}/api/status`, { headers: { Host: 'untrusted.example' } }, (response) => {
        response.resume();
        response.once('end', () => resolve(response.statusCode));
      });
      request.once('error', reject);
    });
    assert.equal(foreignHost, 403);
    assert.equal((await fetch(`${url}/missing`)).status, 404);
    assert.equal((await fetch(`${url}/src/Foundation.php`)).status, 404);
    const session = await (await fetch(`${url}/api/session`)).json();
    assert.equal(session.role, 'administrator');
    const post = (data, headers = {}) => fetch(`${url}/access`, { method: 'POST', redirect: 'manual',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Origin: url, ...headers },
      body: typeof data === 'string' ? data : new URLSearchParams({ csrf: session.csrf, ...data }) });
    const create = { action: 'create', login: 'Robin@example.org', role: 'family' };
    assert.equal((await post(create, { Origin: 'https://evil.example' })).status, 403);
    assert.equal((await post(create, { Origin: '' })).status, 403);
    assert.equal((await post({ ...create, csrf: 'wrong' })).status, 403);
    assert.equal((await post(new URLSearchParams({ csrf: session.csrf, ...create }).toString()+'&role=administrator')).status, 400);
    assert.equal((await post(create)).status, 303);
    assert.equal((await post(create)).status, 400);
    const grants = async () => (await (await fetch(`${url}/api/access`)).json()).grants;
    const robin = (await grants())[0];
    // Node fetch replaces Host; use HTTP directly to emulate Serve's fixed public Host.
    const family = (login = 'robin@example.org', extra = {}, route = '/api/session', payload = null) => new Promise((resolve, reject) => {
      const request = http.request(`http://127.0.0.1:${familyPort}${route}`, {
        method: payload === null ? 'GET' : 'POST',
        headers: { Host: 'fixture.tailnet.ts.net', ...(login ? { 'Tailscale-User-Login': login } : {}), ...extra } }, (response) => {
        let body = '';
        response.setEncoding('utf8');
        response.on('data', (chunk) => { body += chunk; });
        response.once('end', () => resolve({ status: response.statusCode, json: async () => JSON.parse(body) }));
      });
      request.once('error', reject);
      request.end(payload);
    });
    assert.equal((await family(null)).status, 403);
    assert.equal((await family('unknown@example.org')).status, 403);
    assert.equal((await family('robin@example.org', { Host: `127.0.0.1:${port}` })).status, 403);
    assert.equal((await family('robin@example.org', { 'Indy-Listener': 'local', 'X-Role': 'administrator' }, '/api/access')).status, 403);
    assert.equal((await family('robin@example.org', {}, '/access')).status, 403);
    const familySession = await (await family()).json();
    assert.equal(familySession.role, 'family');
    assert.notEqual(familySession.csrf, session.csrf);
    assert.equal((await post({ action: 'toggle', id: robin.id, revision: robin.revision, enabled: '0' })).status, 303);
    assert.equal((await family()).status, 403);
    assert.equal((await family('robin@example.org', {}, '/api/status')).status, 403);
    assert.equal((await post({ action: 'toggle', id: robin.id, revision: robin.revision, enabled: '1' })).status, 409);
    assert.equal((await post({ action: 'toggle', id: robin.id, revision: 2, enabled: '1' })).status, 303);
    assert.notEqual((await (await family()).json()).csrf, familySession.csrf);
    const familyHeaders = { 'Content-Type': 'application/x-www-form-urlencoded', Origin: 'https://fixture.tailnet.ts.net' };
    assert.equal((await family('robin@example.org', familyHeaders, '/access', new URLSearchParams({ csrf: familySession.csrf, ...create }).toString())).status, 403);
    assert.equal((await post({ action: 'create', login: 'alex@example.org', role: 'administrator' })).status, 303);
    const adminSession = await (await family('alex@example.org')).json();
    const adminPayload = new URLSearchParams({ csrf: adminSession.csrf, action: 'create', login: 'jules@example.org', role: 'family' }).toString();
    assert.equal((await family('alex@example.org', { ...familyHeaders, Origin: url }, '/access', adminPayload)).status, 403);
    assert.equal((await family('alex@example.org', familyHeaders, '/access', adminPayload)).status, 303);
    const alex = (await grants()).find((g) => g.login === 'alex@example.org');
    assert.equal((await post({ action: 'role', id: alex.id, revision: alex.revision, role: 'family' })).status, 303);
    assert.equal((await family('alex@example.org', familyHeaders, '/access', adminPayload)).status, 403);
    assert.match(await (await fetch(`${url}/access`)).text(), /Person freigeben/);
    const apiPost = (route, data, extra = {}) => fetch(`${url}${route}`, { method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Origin: url, ...extra },
      body: new URLSearchParams({ csrf: session.csrf, ...data }) });
    assert.equal((await apiPost('/api/refresh', { csrf: 'wrong' })).status, 403);
    assert.equal((await apiPost('/api/refresh', {})).status, 202);
    const waitServer = async (id, predicate) => {
      for (let attempt = 0; attempt < 100; attempt++) {
        const state = (await (await fetch(`${url}/api/status`)).json()).servers.find((s) => s.id === id);
        if (predicate(state)) return state;
        await delay(50);
      }
      throw new Error(`HTTP executor did not finish for ${id}`);
    };
    for (const id of ['aska','enshrouded']) await waitServer(id, (s) => s.can_start);
    assert.equal((await apiPost('/api/actions', { server: '../escape', action: 'start' })).status, 400);
    assert.equal((await apiPost('/api/actions', { server: 'aska', action: 'shell' })).status, 400);
    assert.equal((await apiPost('/api/actions', { server: 'aska', action: 'start' }, { Origin: 'https://evil.example' })).status, 403);
    assert.equal((await apiPost('/api/actions', { server: 'enshrouded', action: 'start' })).status, 202);
    const started = await waitServer('enshrouded', (s) => s.operation?.state === 'succeeded');
    assert.equal(started.process, 'running');
    assert.equal(started.ready, null);
    assert.equal((await apiPost('/api/actions', { server: 'enshrouded', action: 'stop' })).status, 403);
    assert.equal((await apiPost('/api/actions', { server: 'enshrouded', action: 'stop', confirmed: 'aska' })).status, 403);
    assert.equal((await apiPost('/api/actions', { server: 'enshrouded', action: 'stop', confirmed: 'enshrouded' })).status, 202);
    await waitServer('enshrouded', (s) => s.process === 'stopped' && s.operation?.state === 'succeeded');
    const currentFamily = await (await family()).json();
    const familyAction = new URLSearchParams({ csrf: currentFamily.csrf, server: 'aska', action: 'start' }).toString();
    assert.equal((await family('robin@example.org', familyHeaders, '/api/actions', familyAction)).status, 202);
    await waitServer('aska', (s) => s.process === 'running' && s.operation?.state === 'succeeded');
    assert.match(await (await fetch(`${url}/servers/aska`)).text(), /Servername/);
    assert.match(await (await fetch(`${url}/servers/enshrouded`)).text(), /Beitrittsadresse/);
    assert.equal((await fetch(`${url}/servers/foreign`)).status, 404);
    assert.equal((await post({ action: 'toggle', id: robin.id, revision: 3, enabled: '0' })).status, 303);
    const revokedStop = new URLSearchParams({ csrf: currentFamily.csrf, server: 'aska', action: 'stop', confirmed: 'aska' }).toString();
    assert.equal((await family('robin@example.org', familyHeaders, '/api/actions', revokedStop)).status, 403);
    fs.renameSync(path.join(fixture, 'fixture'), path.join(fixture, 'fixture.off'));
    assert.equal((await fetch(`${url}/health`)).status, 503);
    fs.renameSync(path.join(fixture, 'fixture.off'), path.join(fixture, 'fixture'));
    assert.equal((await fetch(`${url}/health`)).status, 200);
    fs.renameSync(path.join(fixture, 'state.sqlite'), path.join(fixture, 'state.saved'));
    fs.writeFileSync(path.join(fixture, 'state.sqlite'), '');
    assert.equal((await fetch(`${url}/health`)).status, 503);
    assert.equal((await fetch(`${url}/api/status`)).status, 503);
    fs.renameSync(path.join(fixture, 'state.saved'), path.join(fixture, 'state.sqlite'));
    assert.equal((await fetch(`${url}/health`)).status, 200);
    console.log('PASS: real FrankenPHP, separate listeners, grants/forms, strict Origin/CSRF, confirmed game actions, family rights/revocation, routes and unavailable fixture');
    if (process.argv.includes('--idle')) {
      const ticks = () => {
        const raw = fs.readFileSync(`/proc/${child.pid}/stat`, 'utf8');
        const fields = raw.slice(raw.lastIndexOf(')') + 2).split(/\s+/);
        return Number(fields[11]) + Number(fields[12]);
      };
      const before = ticks();
      const started = performance.now();
      await delay(5000);
      const elapsed = performance.now() - started;
      const cpuTicks = ticks() - before;
      const hz = Number(execFileSync('getconf', ['CLK_TCK'], { encoding: 'utf8' }).trim());
      const statusText = fs.readFileSync(`/proc/${child.pid}/status`, 'utf8');
      const descendants = fs.readFileSync(`/proc/${child.pid}/task/${child.pid}/children`, 'utf8').trim();
      console.log(JSON.stringify({ idle: { elapsed_ms: Math.round(elapsed), cpu_ms: cpuTicks * 1000 / hz,
        rss_kib: Number(statusText.match(/^VmRSS:\s+(\d+)/m)[1]), threads: Number(statusText.match(/^Threads:\s+(\d+)/m)[1]),
        server_processes: 1, child_pids: descendants ? descendants.split(/\s+/).map(Number) : [],
        limit: 'Single local fixture sample; no native adapters, family access or production load' } }));
    }
  } finally {
    child.kill('SIGTERM');
    for (let attempt = 0; !exited && attempt < 50; attempt += 1) await delay(100);
    if (!exited) {
      child.kill('SIGKILL');
      await new Promise((resolve) => child.once('exit', resolve));
    }
    if (fixture) {
      assert.equal(path.dirname(fixture), fs.realpathSync(os.tmpdir()));
      assert.match(path.basename(fixture), /^indy-next-[a-f0-9]{24}$/);
      fs.rmSync(fixture, { recursive: true });
    }
  }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
