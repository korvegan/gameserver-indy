const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const { spawn, execFileSync } = require('node:child_process');
const { setTimeout: delay } = require('node:timers/promises');

async function main() {
  const manifest = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  const mapped = execFileSync('wslpath', ['-u', manifest.root], { encoding: 'utf8' }).trim();
  assert.ok(mapped.includes('/indy-bridge-test-') && fs.statSync(path.join(mapped, 'profiles.local.json')).isFile());
  const reservation = net.createServer();
  await new Promise((resolve, reject) => reservation.once('error', reject).listen(0, '127.0.0.1', resolve));
  const port = reservation.address().port;
  await new Promise((resolve) => reservation.close(resolve));
  const root = path.resolve(__dirname, '..');
  const child = spawn(process.env.SLICE_PHP || 'php', [path.join(root, 'bin/dev'), String(port)], { env: process.env, stdio: ['ignore','pipe','pipe'] });
  let output = '', errors = '', fixture, exited = false;
  child.stdout.on('data', (c) => { output += c; });
  child.stderr.on('data', (c) => { errors = (errors + c).slice(-8000); });
  child.once('exit', () => { exited = true; });
  child.once('error', () => { exited = true; });
  const url = `http://127.0.0.1:${port}`;
  try {
    let healthy = false;
    for (let i = 0; i < 100; i++) {
      fixture = output.match(/^Fixture: (.+)$/m)?.[1];
      if (exited) throw new Error('Own HTTP instance failed: ' + errors);
      if (fixture) {
        try { const r = await fetch(url + '/health'); healthy = r.ok && (await r.json()).instance === path.basename(fixture); } catch {}
      }
      if (healthy) break;
      await delay(100);
    }
    assert.ok(healthy);
    const file = path.join(fixture, 'runtime.json');
    const settings = JSON.parse(fs.readFileSync(file,'utf8'));
    Object.assign(settings, { kind:'windows', bridge_directory:mapped,
      bridge_command:[manifest.pwsh_wsl,'-NoLogo','-NoProfile','-NonInteractive','-File',manifest.bridge,'-StateDirectory',manifest.root] });
    fs.writeFileSync(file, JSON.stringify(settings));
    assert.equal((await (await fetch(url + '/health')).json()).native_enabled, true);
    const session = await (await fetch(url + '/api/session')).json();
    const post = async (route, data) => {
      const response = await fetch(url + route, { method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded', Origin:url },
        body:new URLSearchParams({ csrf:session.csrf, ...data }), signal:AbortSignal.timeout(3000) });
      assert.equal(response.status, 202);
      return response.json();
    };
    const wait = async (id, predicate) => {
      for (let i = 0; i < 300; i++) {
        const server = (await (await fetch(url + '/api/status')).json()).servers.find((s) => s.id === id);
        if (predicate(server)) return server;
        await delay(100);
      }
      throw new Error('Native HTTP result not confirmed for ' + id);
    };
    await post('/api/refresh', {});
    for (const id of ['enshrouded','aska']) await wait(id, (s) => s.can_start);
    const requests = await Promise.all(['enshrouded','aska'].map((server) => post('/api/actions', { server, action:'start' })));
    assert.notEqual(requests[0].operation.id, requests[1].operation.id);
    for (const id of ['enshrouded','aska']) {
      const server = await wait(id, (s) => s.operation?.state === 'succeeded');
      assert.equal(server.process, 'running');
      assert.equal(server.ready, null);
      assert.equal(server.can_stop, true);
    }
    await Promise.all(['enshrouded','aska'].map((server) => post('/api/actions', { server, action:'stop', confirmed:server })));
    for (const id of ['enshrouded','aska']) await wait(id, (s) => s.operation?.state === 'succeeded' && s.process === 'stopped');
    console.log('PASS: real HTTP -> detached PHP -> PowerShell 7 -> both native Windows fixtures, independent start/stop, observation and no invented readiness');
  } finally {
    child.kill('SIGTERM');
    for (let i = 0; !exited && i < 50; i++) await delay(100);
    if (!exited) child.kill('SIGKILL');
    if (fixture) {
      assert.equal(path.dirname(fixture), fs.realpathSync(os.tmpdir()));
      assert.match(path.basename(fixture), /^indy-next-[a-f0-9]{24}$/);
      fs.rmSync(fixture, { recursive:true });
    }
  }
}
main().catch((error) => { console.error(error); process.exitCode=1; });
