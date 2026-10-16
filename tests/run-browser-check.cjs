// Own the isolated fixture process so local and CI runs use the same lifecycle.
const {spawn} = require('node:child_process');
const {once} = require('node:events');
const {setTimeout: delay} = require('node:timers/promises');
const path = require('node:path');

async function main() {
  const root = path.resolve(__dirname, '..');
  const python = process.env.PYTHON || (process.platform === 'win32' ? 'python' : 'python3');
  const fixture = spawn(python, ['tests/browser_fixture.py'], {cwd: root, stdio: ['ignore', 'pipe', 'pipe']});
  let output = '';
  let startupError;
  let started = false;
  fixture.on('error', error => { startupError = error; });
  fixture.stdout.on('data', data => { output += data; if (output.includes('http://127.0.0.1:8766')) started = true; });
  fixture.stderr.on('data', data => { output += data; });
  try {
    for (let attempt = 0; attempt < 100; attempt++) {
      if (startupError) throw startupError;
      if (fixture.exitCode !== null) throw new Error(`Test fixture failed: ${output}`);
      if (started) break;
      await delay(100);
    }
    if (!started) throw new Error(`Test fixture did not start: ${output}`);
    for (const script of ['tests/browser-smoke.cjs', 'tests/browser-chronicle.cjs', 'tests/browser-story.cjs', 'tests/browser-access.cjs', 'tests/browser-backups.cjs', 'tests/browser-controls.cjs', 'tests/browser-join.cjs']) {
      const browser = spawn(process.execPath, [script], {cwd: root, stdio: 'inherit'});
      const [code] = await once(browser, 'exit');
      if (code !== 0) throw new Error(`Browser check failed in ${script} (exit ${code}).`);
    }
  } finally {
    if (fixture.pid && fixture.exitCode === null && fixture.signalCode === null) {
      const exited = once(fixture, 'exit');
      fixture.kill('SIGTERM');
      await exited;
    }
  }
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
