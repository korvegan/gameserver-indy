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
  const reservation = net.createServer();
  await new Promise((resolve, reject) => reservation.once('error', reject).listen(0, '127.0.0.1', resolve));
  const port = reservation.address().port;
  await new Promise((resolve) => reservation.close(resolve));
  const root = path.resolve(__dirname, '..');
  const child = spawn(php, [path.join(root, 'bin/dev'), String(port)], { env: process.env, stdio: ['ignore', 'pipe', 'pipe'] });
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
          healthy = true;
          break;
        } catch { /* Wait for this instance, never accept a different listener. */ }
      }
      await delay(100);
    }
    assert.ok(healthy, `Startup failed: ${errors}`);
    const page = await fetch(url);
    assert.match(await page.text(), /PHP-Grundgerüst/);
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
    fs.renameSync(path.join(fixture, 'fixture'), path.join(fixture, 'fixture.off'));
    assert.equal((await fetch(`${url}/health`)).status, 503);
    fs.renameSync(path.join(fixture, 'fixture.off'), path.join(fixture, 'fixture'));
    assert.equal((await fetch(`${url}/health`)).status, 200);
    console.log('PASS: real FrankenPHP startup, PHP page, fixture API, read-only HTTP, Host boundary, unavailable fixture');
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
