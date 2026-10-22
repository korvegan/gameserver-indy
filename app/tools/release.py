"""Prepare an immutable MVP release; activate only in the agreed maintenance window."""
import base64
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import signal
import sqlite3
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('foundation_installer', ROOT / 'app/tools/install.py')
packager = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packager)
RUNTIME_SHA = '46b62a9cd98ce3cccd403f2183bfa4f3c4a8c53a1f16bef488f426d95f654897'
RUNTIME_URL = 'https://github.com/php/frankenphp/releases/download/v1.13.2/frankenphp-linux-x86_64'


def file_sha(path):
    with Path(path).open('rb') as stream:
        return hashlib.file_digest(stream,'sha256').hexdigest()


def read(path):
    return json.loads(Path(path).read_text(encoding='utf-8-sig'))


def write(path, value):
    path = Path(path)
    data = value if isinstance(value, bytes) else (json.dumps(value, indent=2) + '\n').encode()
    temporary = path.with_name(path.name + '.release-new')
    try:
        with temporary.open('xb') as out:
            os.chmod(temporary, 0o600)
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def command(args, timeout=90):
    result = subprocess.run([str(a) for a in args], capture_output=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError('Local command failed; no further cutover step permitted')
    return result.stdout.decode('utf-8-sig').strip()


def win(path):
    return command(['wslpath', '-w', path])


def ps(pwsh, script, *args):
    return command([pwsh, '-NoLogo', '-NoProfile', '-NonInteractive', '-File', win(script), *args])


def build(revision, destination, artifact):
    if file_sha(artifact) != RUNTIME_SHA:
        raise RuntimeError('Official runtime SHA256 mismatch')
    destination.mkdir(mode=0o700)
    code = destination / 'code'
    code.mkdir()
    packager.package(ROOT, revision, code)
    runtime = destination / 'runtime'
    runtime.mkdir(mode=0o700)
    engine = runtime / 'frankenphp'
    shutil.copyfile(artifact, engine)
    engine.chmod(0o755)
    php = runtime / 'php'
    # Release paths are restricted below; the wrapper supplies the documented php-cli entrypoint.
    php.write_text('#!/bin/sh\nexec "$(dirname "$0")/frankenphp" php-cli "$@"\n')
    php.chmod(0o755)
    version = command([php, '-r', 'echo PHP_VERSION; foreach (["pdo_sqlite","mbstring","iconv","pcntl","ctype","posix"] as $e) if (!extension_loaded($e)) exit(1);'])
    if version != '8.5.12':
        raise RuntimeError('Unexpected embedded PHP version')
    write(destination / 'runtime-source.json', {'version':'1.13.2', 'php':version, 'url':RUNTIME_URL, 'sha256':RUNTIME_SHA})
    return code, php, engine


def prepare(revision, launcher, pwsh, artifact):
    if not re.fullmatch('[a-f0-9]{40}', revision) or command(['git','-C',ROOT,'rev-parse','HEAD']) != revision:
        raise RuntimeError('Expected exact committed checkout')
    command(['git','-C',ROOT,'merge-base','--is-ancestor',revision,'origin/main'])
    if command(['git','-C',ROOT,'status','--porcelain','--untracked-files=no']):
        raise RuntimeError('Tracked checkout is dirty')
    configuration = launcher / 'launcher.local.json'
    starter = launcher / 'Start-Manager.ps1'
    original = configuration.read_bytes()
    old = json.loads(original.decode('utf-8-sig'))
    if old['RuntimeEngine'] not in ('php-foundation','php-mvp'):
        raise RuntimeError('Legacy controller must remain retired')
    releases = Path.home() / '.local/share/gameserver-indy/mvp-releases'
    releases.mkdir(mode=0o700, parents=True, exist_ok=True)
    release = releases / revision
    if not re.fullmatch(r'/[^\s"\x00]+', str(release)):
        raise RuntimeError('Unsupported release path')
    code, php, engine = build(revision, release, artifact)
    windows = launcher / 'mvp-releases' / revision
    windows.mkdir(parents=True)
    for file in (code / 'app/windows').iterdir():
        if file.is_file(): shutil.copyfile(file, windows / file.name)
    native_script = windows / 'Bridge.ps1'
    first = old['RuntimeEngine'] == 'php-foundation'
    legacy = Path(old['StateDirectory'])
    if first:
        if read(legacy / 'runtime-lifecycle.json')['active']:
            raise RuntimeError('Archived controller is not retired')
        state = releases.parent / ('mvp-state-' + os.urandom(12).hex())
        bridge = launcher / state.name / 'bridge'
        ps(pwsh, windows / 'Prepare-Profiles.ps1', '-ManagerConfiguration', win(legacy / 'manager.local.json'), '-Destination', win(bridge))
        binding = read(bridge / 'binding.json')
        family = read(legacy / 'data/access-settings.json')
    else:
        state = Path(old['AppStateDirectory'])
        current = read(state / 'runtime.json')
        bridge = Path(current['bridge_directory'])
        binding = read(bridge / 'binding.json')
        service = read(state / 'service.json')
        family = {'enabled': service['family_port'] is not None, 'port': service['family_port'], 'hostname': service['family_host']}
    runtime = {'kind':'windows','php':str(php),'bridge_directory':str(bridge),
               'bridge_command':[str(pwsh),'-NoLogo','-NoProfile','-NonInteractive','-File',win(native_script),'-StateDirectory',win(bridge)],
               'connections':binding['connections']}
    service = {'port':old['Port'],'family_port':family['port'] if family['enabled'] else None,
               'family_host':family['hostname'] if family['enabled'] else None, 'php':str(php),'engine':str(engine)}
    write(release / 'runtime-next.json', runtime)
    write(release / 'service-next.json', service)
    if first:
        provision = {'directory':str(state),'revision':revision,'legacy_access':str(legacy / 'data/access.sqlite3'), 'runtime':runtime,'service':service}
        write(release / 'provision.json', provision)
        command([php, code / 'app/bin/provision', release / 'provision.json'])
    previous = {name: base64.b64encode((state / name).read_bytes()).decode() for name in ('runtime.json','service.json','active-release')}
    new = {**old,'RuntimeEngine':'php-mvp','CodeCommit':revision,'ProjectDirectory':str(code),
           'RuntimeExecutable':str(php),'FrankenPhpExecutable':str(engine),'AppStateDirectory':str(state),'Instance':state.name}
    plan = {'version':1,'revision':revision,'release':str(release),'launcher':str(launcher),'pwsh':str(pwsh),
            'first':first,'state':str(state),'old':old,'new':new,'old_configuration':base64.b64encode(original).decode(),
            'old_starter':base64.b64encode(starter.read_bytes()).decode(),'previous_runtime':previous,
            'legacy_access':str(legacy / 'data/access.sqlite3') if first else None,
            'family_settings_hash':file_sha(legacy / 'data/access-settings.json') if first else None,
            'grant_fingerprint':read(state / 'grant-import.json')['fingerprint'] if first else None}
    ps(pwsh, windows / 'Update-Startup.ps1', '-PowerShellExecutable', win(pwsh))
    games = snapshot(plan)
    write(release / 'preparation-games.json', games)
    write(release / 'release-plan.json', plan)
    print(json.dumps({'prepared':revision,'plan':str(release / 'release-plan.json'),'activated':False}))


def snapshot(plan):
    code = Path(plan['new']['ProjectDirectory'])
    return json.loads(command([plan['new']['RuntimeExecutable'],code / 'app/bin/inspect',Path(plan['release']) / 'runtime-next.json'], timeout=150))


def verify_bindings(plan):
    runtime=read(Path(plan['release']) / 'runtime-next.json')
    bridge=Path(runtime['bridge_directory'])
    profiles=read(bridge / 'profiles.local.json')['profiles']
    binding=read(bridge / 'binding.json')
    for server,profile in profiles.items():
        configuration=Path(command(['wslpath','-u',profile['configuration']]))
        if file_sha(configuration)!=binding['configuration_hashes'][server]:
            raise RuntimeError('Game configuration changed since binding; verify profiles before cutover')


def maintenance(state):
    with (state / 'admission.lock').open('a+b') as lock:
        os.chmod(lock.name, 0o600)
        fcntl.flock(lock, fcntl.LOCK_EX)
        write(state / 'maintenance', b'Release maintenance\n')


def drain(state, timeout=120):
    deadline = time.monotonic() + timeout
    while True:
        db = sqlite3.connect((state / 'state.sqlite').as_uri() + '?mode=ro', uri=True)
        try: count = db.execute("SELECT count(*) FROM operations WHERE state IN ('pending','running','unknown')").fetchone()[0]
        finally: db.close()
        locks = []
        try:
            for server in ('enshrouded','aska'):
                for kind in ('action','status'):
                    lock = (state / f'{kind}-{server}.lock').open('a+b')
                    os.chmod(lock.name,0o600)
                    locks.append(lock)
                    fcntl.flock(lock,fcntl.LOCK_EX | fcntl.LOCK_NB)
            if count == 0: return
        except BlockingIOError: pass
        finally:
            for lock in locks: lock.close()
        if time.monotonic() >= deadline: raise RuntimeError('Unresolved operations or executors; cutover cancelled')
        time.sleep(0.2)


def health(settings):
    import urllib.request
    try:
        with urllib.request.urlopen(f"http://127.0.0.1:{settings['Port']}/health",timeout=2) as r: value=json.load(r)
    except urllib.error.URLError as error:
        if isinstance(error,urllib.error.HTTPError): raise RuntimeError('Unexpected HTTP service') from error
        packager.require_free_port(settings['Port'])
        return False
    production = settings['RuntimeEngine'] == 'php-mvp'
    if value['build'] != settings['CodeCommit'] or value['mode'] != ('production' if production else 'fixture') or value['native_enabled'] != production:
        raise RuntimeError('Listener does not match configured release')
    if production and value['instance'] != settings['Instance']: raise RuntimeError('Wrong persistent state')
    return True


def identity(pid):
    p = Path('/proc') / str(pid)
    return {'pid':pid,'start':(p / 'stat').read_text().split(') ',1)[1].split()[19],
            'cwd':str((p / 'cwd').resolve()),'exe':str((p / 'exe').resolve()),'command':(p / 'cmdline').read_bytes().split(b'\0')}


def stop(settings, inspect_unhealthy=False):
    try:
        if not health(settings): return
    except (RuntimeError,OSError):
        if not inspect_unhealthy: raise
    output=command(['ss','-ltnp','sport','=',':'+str(settings['Port'])])
    pids=set(re.findall(r'pid=(\d+)',output))
    if len(pids)!=1: raise RuntimeError('Cannot identify exactly one management listener')
    before=identity(int(pids.pop()))
    if before['cwd'] != str(Path(settings['ProjectDirectory']) / 'app') or before['exe'] != str(Path(settings['FrankenPhpExecutable']).resolve()):
        raise RuntimeError('Management process identity mismatch')
    arguments=before['command']
    if b'run' not in arguments or b'--config' not in arguments or not any(a.endswith(b'/Caddyfile') for a in arguments):
        raise RuntimeError('Unexpected management command')
    if identity(before['pid']) != before: raise RuntimeError('Management identity changed')
    os.kill(before['pid'],signal.SIGTERM)
    deadline=time.monotonic()+15
    while time.monotonic()<deadline:
        try:
            if identity(before['pid']) != before: break
        except FileNotFoundError: break
        time.sleep(0.1)
    else: raise RuntimeError('Management did not stop; no forced termination')
    packager.require_free_port(settings['Port'])


def start(plan, settings):
    ps(plan['pwsh'],Path(plan['launcher']) / 'Start-Manager.ps1','-NoBrowser','-PassThru')
    if not health(settings): raise RuntimeError('Configured release did not become ready')


def checkpoint(plan, games):
    if any(g['process'] != 'stopped' for g in games.values()): raise RuntimeError('First cutover requires stopped games; no game will be interrupted')
    profiles=read(Path(read(Path(plan['release']) / 'runtime-next.json')['bridge_directory']) / 'profiles.local.json')['profiles']
    target=Path(plan['release']) / 'world-checkpoint'
    target.mkdir(mode=0o700,exist_ok=True)
    for server,profile in profiles.items():
        source=Path(command(['wslpath','-u',profile['world_directory']]))
        if any(p.is_symlink() for p in source.rglob('*')): raise RuntimeError('World checkpoint does not follow links')
        if not (target / server).exists():shutil.copytree(source,target / server,symlinks=True)
        source_files={p.relative_to(source) for p in source.rglob('*') if p.is_file()}
        copied={p.relative_to(target / server) for p in (target / server).rglob('*') if p.is_file()}
        if not source_files or source_files!=copied: raise RuntimeError('Incomplete world checkpoint')
        for relative in source_files:
            original=source / relative;copy=target / server / relative
            if original.is_symlink() or copy.is_symlink() or file_sha(original)!=file_sha(copy):
                raise RuntimeError('World checkpoint did not verify')
    if snapshot(plan)!=games: raise RuntimeError('Game process changed while creating checkpoint')
    write(target / 'verified.json',{'revision':plan['revision'],'verified':True})


def restore(plan, games):
    launcher=Path(plan['launcher']);state=Path(plan['state']);release=Path(plan['release'])
    # Stop whichever of our two exact releases is present; a foreign listener aborts recovery.
    for settings in (plan['new'],plan['old']):
        try:
            stop(settings,inspect_unhealthy=True)
            break
        except RuntimeError:
            if settings is plan['old']: raise
    for name,data in plan['previous_runtime'].items():write(state / name,base64.b64decode(data))
    write(launcher / 'Start-Manager.ps1',base64.b64decode(plan['old_starter']))
    write(launcher / 'launcher.local.json',base64.b64decode(plan['old_configuration']))
    start(plan,plan['old'])
    if snapshot(plan)!=games: raise RuntimeError('Game identities changed during recovery')
    if plan['old']['RuntimeEngine']=='php-mvp': (state / 'maintenance').unlink()
    write(release / 'rollback.json',{'revision':plan['old']['CodeCommit'],'games_unchanged':True,'access_state_restored':False})


def switch(plan, rollback=False):
    launcher=Path(plan['launcher']);state=Path(plan['state']);release=Path(plan['release'])
    configuration=launcher / 'launcher.local.json';starter=launcher / 'Start-Manager.ps1'
    current=plan['new'] if rollback else plan['old'];target=plan['old'] if rollback else plan['new']
    expected=(json.dumps(plan['new'],indent=2)+'\n').encode() if rollback else base64.b64decode(plan['old_configuration'])
    if configuration.read_bytes()!=expected: raise RuntimeError('Launcher changed since preparation; do not overwrite')
    if not rollback and starter.read_bytes()!=base64.b64decode(plan['old_starter']): raise RuntimeError('Starter changed since preparation')
    if not rollback:
        verify_bindings(plan)
        for name,data in plan['previous_runtime'].items():
            if (state / name).read_bytes()!=base64.b64decode(data):raise RuntimeError('Runtime configuration changed since preparation')
    if not rollback and plan['first']:
        if read(Path(plan['old']['StateDirectory']) / 'runtime-lifecycle.json')['active']:
            raise RuntimeError('Archived controller became active; no cutover')
        if file_sha(Path(plan['legacy_access']).parent / 'access-settings.json')!=plan['family_settings_hash']:
            raise RuntimeError('Family endpoint settings changed since preparation')
        fingerprint=command([plan['new']['RuntimeExecutable'],Path(plan['new']['ProjectDirectory']) / 'app/bin/provision','--fingerprint',plan['legacy_access']])
        if fingerprint!=plan['grant_fingerprint']: raise RuntimeError('Legacy grants changed; prepare again before cutover')
    maintenance(state)
    drain(state)
    games=snapshot(plan)
    if rollback:
        restore(plan,games)
        print(json.dumps({'running':target['CodeCommit'],'mode':target['RuntimeEngine'],'access_state_restored':False}))
        return
    if not rollback and plan['first']: checkpoint(plan,games)
    if not rollback:
        windows=launcher / 'mvp-releases' / plan['revision']
        backup=launcher / 'archives' / ('mvp-'+plan['revision'][:12])
        backup.mkdir(parents=True,exist_ok=True)
        ps(plan['pwsh'],windows / 'Update-Startup.ps1','-PowerShellExecutable',win(plan['pwsh']),'-BackupDirectory',win(backup),'-Apply')
    stop(current)
    try:
        write(state / 'runtime.json',(release / 'runtime-next.json').read_bytes())
        write(state / 'service.json',(release / 'service-next.json').read_bytes())
        write(state / 'active-release',(plan['revision']+'\n').encode())
        write(starter,(Path(plan['new']['ProjectDirectory']) / 'app/windows/Start-Manager.ps1').read_bytes())
        write(configuration,plan['new'])
        start(plan,target)
        if snapshot(plan)!=games: raise RuntimeError('Game identities changed during management cutover')
        if target['RuntimeEngine']=='php-mvp': (state / 'maintenance').unlink()
        write(release / 'activation.json',{'revision':target['CodeCommit'],'games_unchanged':True,'access_state_restored':False})
    except BaseException:
        restore(plan,games)
        raise
    print(json.dumps({'running':target['CodeCommit'],'mode':target['RuntimeEngine'],'url':f"http://127.0.0.1:{target['Port']}"}))


def restart(plan):
    state=Path(plan['state']);settings=plan['new']
    if read(Path(plan['launcher']) / 'launcher.local.json')!=settings:
        raise RuntimeError('Restart requires the exact active release')
    maintenance(state);drain(state)
    games=snapshot(plan)
    stop(settings);start(plan,settings)
    if snapshot(plan)!=games:raise RuntimeError('Game identities changed during management restart')
    (state / 'maintenance').unlink()
    write(Path(plan['release']) / 'restart.json',{'revision':plan['revision'],'games_unchanged':True})
    print('PASS: management restarted; game identities unchanged')


def load_plan(path):
    path=Path(path)
    if path.is_symlink() or path.stat().st_mode & 0o777 != 0o600 or path.stat().st_uid!=os.geteuid():
        raise RuntimeError('Private release plan required')
    plan=read(path)
    if plan['version']!=1 or Path(plan['release']).resolve()!=path.resolve().parent:
        raise RuntimeError('Release plan does not match its installation')
    return plan


if __name__=='__main__':
    try:
        if len(sys.argv)==6 and sys.argv[1]=='prepare':
            prepare(sys.argv[2],Path(sys.argv[3]),Path(sys.argv[4]),Path(sys.argv[5]))
        elif len(sys.argv)==3 and sys.argv[1] in ('activate','rollback','restart'):
            plan=load_plan(sys.argv[2])
            if sys.argv[1]=='restart':restart(plan)
            else:switch(plan,rollback=sys.argv[1]=='rollback')
        else: raise RuntimeError('Usage: release.py prepare COMMIT LAUNCHER PWSH ARTIFACT | activate PLAN | rollback PLAN | restart PLAN')
    except Exception as error:
        print(str(error),file=sys.stderr);sys.exit(1)
