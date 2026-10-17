// WSL maintenance command. Application data and game processes are never replaced.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const {execFileSync, spawn} = require('node:child_process');
const {setTimeout: sleep} = require('node:timers/promises');

async function activate(ops, previous, next) {
  const baseline = await ops.snapshot();
  await ops.idle();
  await ops.stop(previous);
  try {
    await ops.install(next);
    // The caller may time out while the application is already healthy.
    try { await ops.launch(); } catch { /* readiness below decides */ }
    await ops.ready(next, baseline);
    return {activated: true, rolledBack: false, code: next};
  } catch {
    await ops.stop(next);
    await ops.install(previous);
    try { await ops.launch(); } catch { /* readiness below decides */ }
    await ops.ready(previous, baseline);
    return {activated: false, rolledBack: true, code: previous};
  }
}

const json = file => JSON.parse(fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, ''));
const hash = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const run = (exe, args, options = {}) => execFileSync(exe, args, {encoding: 'utf8', timeout: 120000, ...options}).trim();
const psQuote = value => "'" + String(value).replaceAll("'", "''") + "'";
const powershell = '/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe';
function ps(script) {
  return run(powershell, ['-NoProfile', '-NonInteractive', '-EncodedCommand', Buffer.from(
    "$ErrorActionPreference='Stop';[Console]::OutputEncoding=New-Object System.Text.UTF8Encoding($false);" + script,
    'utf16le').toString('base64')]);
}
function identity(pid) {
  try {
    const stat = fs.readFileSync(`/proc/${pid}/stat`, 'utf8');
    const fields = stat.slice(stat.lastIndexOf(')') + 2).split(' ');
    return ['Z', 'X'].includes(fields[0]) ? null : fields[19];
  } catch { return null; }
}
async function until(check, description, timeout = 65000) {
  const deadline = Date.now() + timeout;
  do {
    if (await check()) return;
    await sleep(400);
  } while (Date.now() < deadline);
  throw Error(description);
}

function hostOperations(source, configFile, checkpoint) {
  const settings = json(configFile), state = settings.StateDirectory;
  if (settings.RuntimeEngine !== 'symfony' || !path.isAbsolute(state) || !path.isAbsolute(settings.RuntimeExecutable)) {
    throw Error('Vorhandene private Symfony-Startkonfiguration erforderlich.');
  }
  const base = `http://127.0.0.1:${settings.Port}`;
  const lifecycle = () => json(path.join(state, 'runtime-lifecycle.json'));
  const local = filename => /^[A-Za-z]:[\\/]|^\\\\/.test(filename) ? run('wslpath', ['-u', filename]) : filename;
  const win = filename => run('wslpath', ['-w', filename]);
  async function get(route) {
    const response = await fetch(base + route, {signal: AbortSignal.timeout(2500)});
    if (!response.ok || !response.headers.get('server')?.split(',').map(s => s.trim()).includes('GameServerIndy/Symfony')) {
      throw Error('Keine passende Symfony-Antwort.');
    }
    return response.json();
  }
  function owned(code) {
    const value = lifecycle(), owner = value.owner;
    if (!value.active || !owner || identity(owner.pid) !== owner.start) return null;
    const cmd = fs.readFileSync(`/proc/${owner.pid}/cmdline`, 'utf8').split('\0');
    if (fs.realpathSync(`/proc/${owner.pid}/cwd`) !== fs.realpathSync(code)
      || !cmd.some(arg => arg === 'symfony/bin/indy' || arg === code + '/symfony/bin/indy') || !cmd.includes(state)) {
      throw Error('Laufender Supervisor gehört nicht zum erwarteten Release/Zustand.');
    }
    return owner;
  }
  async function snapshot() {
    const session = await get('/api/session');
    if (session.driver !== 'managed' || session.role !== 'owner' || session.api_version !== 2) throw Error('Lokaler Besitzerzugriff erforderlich.');
    const files = [path.join(state, 'manager.local.json'), path.join(state, 'data/session-key')];
    const names = [];
    for (const profile of json(files[0]).servers) {
      const schema = json(path.join(source, 'profiles', profile.game + '.json'));
      files.push(local(profile.config_path || profile.server_directory.replace(/[\\/]$/, '') + '/' + schema.config_filename));
      names.push(path.win32.parse(schema.executable).name);
    }
    const native = JSON.parse(ps('$rows=@();foreach($name in @(' + names.map(psQuote).join(',') + ')){' +
      'foreach($p in @(Get-Process -Name $name -ErrorAction SilentlyContinue)){' +
      '$rows+= [pscustomobject]@{pid=$p.Id;started=$p.StartTime.ToUniversalTime().ToString("o");path=$p.Path}}};ConvertTo-Json -InputObject @($rows|Sort-Object pid) -Compress'));
    return {session: crypto.createHash('sha256').update(session.token).digest('hex'),
      files: Object.fromEntries(files.map(file => [file, hash(file)])), native};
  }
  return {
    settings,
    snapshot,
    async idle() {
      const jobs = (await get('/api/jobs')).jobs;
      if (!Array.isArray(jobs) || jobs.some(job => ['queued', 'running'].includes(job.state))) {
        throw Error('Aufträge laufen noch; später erneut ausführen.');
      }
      if (!owned(settings.ProjectDirectory)) throw Error('Kein eindeutig zugeordneter laufender Supervisor.');
    },
    async stop(code) {
      const owner = owned(code);
      if (!owner) return;
      // Recheck immediately before sending the only signal used by this command.
      if (identity(owner.pid) !== owner.start) throw Error('Supervisoridentität hat sich geändert.');
      process.kill(owner.pid, 'SIGTERM');
      await until(() => identity(owner.pid) !== owner.start
        && (!owner.guard || identity(owner.guard.pid) !== owner.guard.start), 'Verwaltung wurde nicht geordnet beendet.');
    },
    install(code) {
      run(powershell, ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
        win(path.join(code, 'scripts/install-desktop-shortcut.ps1')), '-ProjectDirectory', code]);
      const saved = json(configFile);
      if (saved.ProjectDirectory !== code || saved.StateDirectory !== state || saved.RuntimeEngine !== 'symfony'
        || saved.RuntimeExecutable !== settings.RuntimeExecutable || saved.Port !== settings.Port) {
        throw Error('Gespeicherter Launcher stimmt nicht mit der Aktivierung überein.');
      }
    },
    launch() {
      const child = spawn(powershell, ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
        win(path.join(path.dirname(configFile), 'Start-Manager.ps1')), '-NoBrowser', '-PassThru'], {detached: true, stdio: 'ignore'});
      child.on('error', () => {}); child.unref();
    },
    async ready(code, baseline) {
      await until(async () => {
        try {
          const session = await get('/api/session');
          return session.driver === 'managed' && session.api_version === 2 && owned(code)
            && json(configFile).ProjectDirectory === code;
        } catch { return false; }
      }, 'Aktiviertes Release wurde nicht bereit.');
      const after = await snapshot();
      if (JSON.stringify(after) !== JSON.stringify(baseline)) throw Error('Spielprozesse, Konfiguration oder Sitzung haben sich geändert.');
      // HTTP can be ready just before a background child's first step fails.
      await sleep(1500);
      const stable = await get('/api/session');
      if (stable.driver !== 'managed' || !owned(code) || json(configFile).ProjectDirectory !== code) {
        throw Error('Verwaltung hat die kurze Startstabilitätsprüfung nicht bestanden.');
      }
      fs.writeFileSync(path.join(checkpoint, 'verification.json'), JSON.stringify({code, state, preserved: true}) + '\n', {mode: 0o600});
    }
  };
}

async function main() {
  const [commit, configFile] = process.argv.slice(2);
  if (!/^[a-f0-9]{40}$/.test(commit || '') || !configFile || !path.isAbsolute(configFile) || process.platform !== 'linux') {
    throw Error('Aufruf unter WSL: node scripts/release.cjs <geprüfte vollständige Commit-SHA> <absoluter Pfad zu launcher.local.json>');
  }
  const source = path.resolve(__dirname, '..'), settings = json(configFile);
  const home = path.join(os.homedir(), '.local/share/gameserver-indy');
  fs.mkdirSync(path.join(home, 'releases'), {recursive: true, mode: 0o700});
  fs.mkdirSync(path.join(home, 'deployments'), {recursive: true, mode: 0o700});
  const checkpoint = fs.mkdtempSync(path.join(home, 'deployments/release-')); fs.chmodSync(checkpoint, 0o700);
  const destination = path.join(home, 'releases', commit), next = destination + '/code';
  fs.copyFileSync(configFile, path.join(checkpoint, 'launcher.previous.json')); fs.chmodSync(path.join(checkpoint, 'launcher.previous.json'), 0o600);
  const env = {...process.env, APP_ENV: 'prod', INDY_CACHE_DIR: path.join(checkpoint, 'build-cache')};
  if (!fs.existsSync(destination)) {
    run(settings.RuntimeExecutable, [source + '/symfony/bin/console', 'app:release:build', source, destination, commit], {env});
  }
  // Verify both immutable releases before stopping anything, including an existing build.
  for (const [code, sha] of [[next, commit], [settings.ProjectDirectory, json(settings.ProjectDirectory + '/.indy-release.json').commit]]) {
    run(settings.RuntimeExecutable, ['-r', 'require $argv[1]."/symfony/vendor/autoload.php";(new App\\Runtime\\ReleasePackage())->verify($argv[1],$argv[2]);', code, sha]);
  }
  const result = await activate(hostOperations(source, configFile, checkpoint), settings.ProjectDirectory, next);
  fs.writeFileSync(path.join(checkpoint, 'result.json'), JSON.stringify(result) + '\n', {mode: 0o600});
  console.log(JSON.stringify({...result, url: `http://127.0.0.1:${settings.Port}`, evidence: checkpoint}));
  if (!result.activated) process.exitCode = 1;
}
if (require.main === module) main().catch(() => { console.error('Releasewechsel nicht abgeschlossen. Private Release-Nachweise und Laufzeitprotokolle prüfen; keinen Datenstand zurückkopieren.'); process.exitCode = 1; });
module.exports = {activate};
