"""Own private installation only: migration, persistence, leases and data-preserving recovery."""
import base64
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
from unittest.mock import patch
import urllib.error
import urllib.parse
import urllib.request

root=Path(__file__).resolve().parents[2]
php=os.environ.get('SLICE_PHP') or shutil.which('php')
assert php, 'PHP CLI required'
engine=os.environ.get('SLICE_FRANKENPHP')
spec=importlib.util.spec_from_file_location('release',root / 'app/tools/release.py')
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)


def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1',0));return s.getsockname()[1]


with tempfile.TemporaryDirectory(prefix='indy-release-check-') as temporary:
    directory=Path(temporary);directory.chmod(0o700)
    code=directory / 'code';shutil.copytree(root / 'app',code / 'app')
    revision='a'*40;(code / 'REVISION').write_text(revision+'\n')
    legacy=directory / 'access.sqlite3'
    db=sqlite3.connect(legacy)
    db.execute('CREATE TABLE users(id TEXT,login TEXT,name TEXT,role TEXT,enabled INTEGER,revision INTEGER,servers TEXT)')
    source=[('user-'+'1'*32,'OWNER@example.invalid','Owner','owner',1,3,None),
            ('user-'+'2'*32,'revoked@example.invalid','Revoked','owner',0,8,None),
            ('user-'+'3'*32,'family@example.invalid','Family','family',1,2,'["aska"]'),
            ('user-'+'4'*32,'reader@example.invalid','Reader','viewer',1,5,'["enshrouded"]')]
    db.executemany('INSERT INTO users VALUES (?,?,?,?,?,?,?)',source);db.execute('PRAGMA user_version=1');db.commit();db.close();legacy.chmod(0o600)
    original=legacy.read_bytes()
    state=directory / ('mvp-state-'+'b'*24)
    runtime={'kind':'windows','php':php,'bridge_directory':str(state),
             'bridge_command':[php,str(code / 'app/tests/game-peer.php'),str(state)],'connections':{'aska':None,'enshrouded':None}}
    local_port=port();family_port=port()
    service={'port':local_port,'family_port':family_port,'family_host':'fixture.tailnet.ts.net','php':php,'engine':engine or '/unavailable'}
    configuration=directory / 'provision.json'
    release.write(configuration,{'directory':str(state),'revision':revision,'legacy_access':str(legacy),'runtime':runtime,'service':service})
    subprocess.run([php,str(code / 'app/bin/provision'),str(configuration)],check=True)
    assert legacy.read_bytes()==original
    db=sqlite3.connect(state / 'access.sqlite')
    rows=db.execute('SELECT login,role,enabled,revision,servers FROM grants ORDER BY id').fetchall()
    assert rows==[('owner@example.invalid','administrator',1,3,'["aska","enshrouded"]'),
                  ('revoked@example.invalid','administrator',0,8,'["aska","enshrouded"]'),
                  ('family@example.invalid','family',1,2,'["aska"]'),
                  ('reader@example.invalid','family',0,5,'["enshrouded"]')]
    db.close()
    assert all((state / name).stat().st_mode & 0o777 == 0o600 for name in ('installation','state.sqlite','access.sqlite','csrf-key','runtime.json','service.json'))
    assert state.stat().st_mode & 0o777 == 0o700
    repeated=subprocess.run([php,str(code / 'app/bin/provision'),str(configuration)],capture_output=True)
    assert repeated.returncode!=0 and legacy.read_bytes()==original
    fingerprint=subprocess.check_output([php,str(code / 'app/bin/provision'),'--fingerprint',str(legacy)],text=True).strip()
    assert fingerprint==release.read(state / 'grant-import.json')['fingerprint']
    db=sqlite3.connect(legacy);db.execute("UPDATE users SET enabled=0,revision=revision+1 WHERE role='owner'");db.commit();db.close()
    changed=subprocess.check_output([php,str(code / 'app/bin/provision'),'--fingerprint',str(legacy)],text=True).strip()
    assert fingerprint!=changed
    processes=[]

    def request(path,data=None,headers=None):
        body=urllib.parse.urlencode(data).encode() if data is not None else None
        h={'Content-Type':'application/x-www-form-urlencoded','Origin':f'http://127.0.0.1:{local_port}',**(headers or {})}
        req=urllib.request.Request(f'http://127.0.0.1:{local_port}'+path,data=body,headers=h)
        try:
            with urllib.request.urlopen(req,timeout=5) as r:return r.status,r.read()
        except urllib.error.HTTPError as e:return e.code,e.read()

    def launch(settings=None):
        active_code=Path(settings['ProjectDirectory']) if settings else code
        build=settings['CodeCommit'] if settings else revision
        p=subprocess.Popen([php,str(active_code / 'app/bin/run'),str(state)],cwd=active_code,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        processes.append(p)
        for _ in range(100):
            assert p.poll() is None,'Own production-shaped HTTP start failed'
            try:
                status,body=request('/health')
                if status==200:
                    value=json.loads(body);assert value=={'mode':'production','native_enabled':True,'instance':state.name,'build':build};return p
            except urllib.error.URLError:pass
            time.sleep(0.05)
        raise AssertionError('HTTP startup timeout')

    try:
        if engine:
            p=launch()
            assert (Path('/proc') / str(p.pid) / 'cwd').resolve()==code / 'app'
            assert subprocess.run(['flock','-n',str(state / 'manager.lock'),'true']).returncode==1
            duplicate=subprocess.run([php,str(code / 'app/bin/run'),str(state)],cwd=code,capture_output=True,timeout=10)
            assert duplicate.returncode!=0 and request('/health')[0]==200
            token=json.loads(request('/api/session')[1])['csrf']
            assert request('/api/refresh',{'csrf':token})[0]==409
            assert not list(state.glob('effects-*'))
            (state / 'maintenance').unlink()
            assert request('/api/refresh',{'csrf':token})[0]==202
            for _ in range(100):
                servers=json.loads(request('/api/status')[1])['servers']
                if all(s['process']=='stopped' for s in servers):break
                time.sleep(0.05)
            else:raise AssertionError('Detached synthetic status failed')
            assert request('/api/actions',{'csrf':token,'server':'enshrouded','action':'start'})[0]==202
            for _ in range(100):
                servers=json.loads(request('/api/status')[1])['servers']
                if any(s['id']=='enshrouded' and s['process']=='running' and s['operation']['state']=='succeeded' for s in servers):break
                time.sleep(0.05)
            else:raise AssertionError('Detached synthetic start failed')
            release.write(state / 'scenario.json',{'aska':{'status_delay_ms':1200},'enshrouded':{'status_delay_ms':1200}})
            db=sqlite3.connect(state / 'state.sqlite');db.execute('UPDATE servers SET refresh_after=0');db.commit();db.close()
            assert request('/api/refresh',{'csrf':token})[0]==202
            for _ in range(100):
                with (state / 'status-aska.lock').open('a+b') as lock:
                    try:fcntl.flock(lock,fcntl.LOCK_EX | fcntl.LOCK_NB)
                    except BlockingIOError:break
                time.sleep(0.01)
            else:raise AssertionError('Own slow status executor did not start')
            p.terminate();p.wait(timeout=15)
            assert subprocess.run(['flock','-n',str(state / 'manager.lock'),'true']).returncode==0,'One-shot inherited manager lease'
            release.maintenance(state);release.drain(state,timeout=10)
            (state / 'maintenance').unlink();(state / 'scenario.json').unlink()
            p=launch()
            assert json.loads(request('/api/session')[1])['csrf']==token
            assert (state / 'effects-enshrouded.txt').read_text()=='start\n'
            assert json.loads((state / 'game-enshrouded.json').read_text())['process']=='running'
            assert b'Lokale Vorschau mit Testdaten' not in request('/')[1]
            (state / 'active-release').write_text('c'*40+'\n')
            assert request('/health')[0]==503
            stale=subprocess.run([php,str(code / 'app/bin/execute'),str(state),'aska','status'],capture_output=True)
            assert stale.returncode!=0 and not (state / 'effects-aska.txt').exists()
            (state / 'active-release').write_text(revision+'\n')
            p.terminate();p.wait(timeout=15)
        release.maintenance(state)
        db=sqlite3.connect(state / 'state.sqlite')
        db.execute("INSERT INTO operations(id,server,action,actor,state) VALUES (?,?,?,?,?)",('d'*32,'aska','start','local-owner','unknown'));db.commit();db.close()
        try:release.drain(state,timeout=0)
        except RuntimeError:pass
        else:raise AssertionError('Unresolved operation accepted for cutover')
        db=sqlite3.connect(state / 'state.sqlite');db.execute("DELETE FROM operations WHERE id=?",('d'*32,));db.commit();db.close()
        with (state / 'status-aska.lock').open('a+b') as held:
            fcntl.flock(held,fcntl.LOCK_EX)
            try:release.drain(state,timeout=0)
            except RuntimeError:pass
            else:raise AssertionError('Active status executor accepted for cutover')
        release.drain(state,timeout=0)
        if engine:
            next_code=directory / 'next-code';shutil.copytree(code,next_code);next_revision='f'*40
            (next_code / 'REVISION').write_text(next_revision+'\n')
            launcher=directory / 'http-launcher';launcher.mkdir()
            old={'RuntimeEngine':'php-mvp','CodeCommit':revision,'ProjectDirectory':str(code),
                 'FrankenPhpExecutable':engine,'RuntimeExecutable':php,'Port':local_port,'Instance':state.name}
            new={**old,'CodeCommit':next_revision,'ProjectDirectory':str(next_code)}
            release.write(launcher / 'launcher.local.json',old)
            old_configuration=(launcher / 'launcher.local.json').read_bytes()
            (launcher / 'Start-Manager.ps1').write_bytes(b'previous reviewed starter')
            release.write(directory / 'runtime-next.json',{**runtime,'bridge_command':[php,str(next_code / 'app/tests/game-peer.php'),str(state)]})
            release.write(directory / 'service-next.json',service)
            previous={name:base64.b64encode((state / name).read_bytes()).decode() for name in ('runtime.json','service.json','active-release')}
            plan={'launcher':str(launcher),'state':str(state),'release':str(directory),'first':False,'revision':next_revision,
                  'new':new,'old':old,'pwsh':'mocked','previous_runtime':previous,
                  'old_starter':base64.b64encode(b'previous reviewed starter').decode(),
                  'old_configuration':base64.b64encode(old_configuration).decode()}
            p=launch()
            def start_http(_plan,settings):launch(settings)
            def snapshot_files(_plan):return json.loads((state / 'game-enshrouded.json').read_text())
            with patch.object(release,'ps'),patch.object(release,'win',side_effect=str),patch.object(release,'verify_bindings'),\
                 patch.object(release,'start',side_effect=start_http),patch.object(release,'snapshot',side_effect=snapshot_files):
                release.switch(plan)
                assert json.loads(request('/health')[1])['build']==next_revision
                release.restart(plan)
                assert json.loads(request('/health')[1])['build']==next_revision
                db=sqlite3.connect(state / 'access.sqlite');db.execute("UPDATE grants SET enabled=0,revision=revision+1 WHERE id=?",('3'*32,));db.commit();db.close()
                revoked=(state / 'access.sqlite').read_bytes();key=(state / 'csrf-key').read_bytes()
                release.switch(plan,rollback=True)
                assert json.loads(request('/health')[1])['build']==revision
                assert (state / 'access.sqlite').read_bytes()==revoked and (state / 'csrf-key').read_bytes()==key
                def unconfirmed_start(_plan,settings):
                    launch(settings)
                    if settings['CodeCommit']==next_revision:raise RuntimeError('Synthetic missing caller confirmation')
                with patch.object(release,'start',side_effect=unconfirmed_start):
                    try:release.switch(plan)
                    except RuntimeError as error:assert 'Synthetic missing caller confirmation' in str(error)
                    else:raise AssertionError('Unconfirmed readiness reported as success')
                assert json.loads(request('/health')[1])['build']==revision
                assert (state / 'access.sqlite').read_bytes()==revoked and (state / 'csrf-key').read_bytes()==key
            for process in processes:
                if process.poll() is None:process.terminate();process.wait(timeout=15)
            release.maintenance(state)
        db=sqlite3.connect(state / 'access.sqlite');db.execute("UPDATE grants SET enabled=0,revision=revision+1 WHERE id=?",('3'*32,));db.commit();db.close()
        journal=state / 'aska-operation.json';journal.write_text('{"outcome":"unknown"}')
        preserved={name:(state / name).read_bytes() for name in ('access.sqlite','state.sqlite','csrf-key','aska-operation.json')}
        launcher=directory / 'launcher';launcher.mkdir();previous={name:base64.b64encode((state / name).read_bytes()).decode() for name in ('runtime.json','service.json','active-release')}
        plan={'launcher':str(launcher),'state':str(state),'release':str(directory),'new':{'RuntimeEngine':'php-mvp'},
              'old':{'RuntimeEngine':'php-mvp','CodeCommit':'e'*40},'previous_runtime':previous,
              'old_starter':base64.b64encode(b'previous reviewed starter').decode(),'old_configuration':base64.b64encode(b'{"previous":true}').decode()}
        with patch.object(release,'stop'),patch.object(release,'start'),patch.object(release,'snapshot',return_value={'same':'game'}):
            release.restore(plan,{'same':'game'})
        assert all((state / name).read_bytes()==data for name,data in preserved.items())
        assert release.read(launcher / 'launcher.local.json')=={'previous':True}
        private_plan=directory / 'release-plan.json';release.write(private_plan,{**plan,'version':1})
        assert release.load_plan(private_plan)['version']==1
        private_plan.chmod(0o644)
        try:release.load_plan(private_plan)
        except RuntimeError:pass
        else:raise AssertionError('Non-private deployment plan accepted')
        profiles={}
        for server in ('enshrouded','aska'):
            world=directory / ('world-'+server);world.mkdir();(world / 'save.bin').write_bytes(b'current own world data')
            profiles[server]={'world_directory':str(world)}
        release.write(state / 'profiles.local.json',{'profiles':profiles})
        release.write(directory / 'runtime-next.json',{'bridge_directory':str(state)})
        games={s:{'process':'stopped','identity':None} for s in profiles}
        with patch.object(release,'command',side_effect=lambda args: str(args[-1])),patch.object(release,'snapshot',return_value=games):
            release.checkpoint({'release':str(directory),'revision':revision},games)
            assert (directory / 'world-checkpoint/aska/save.bin').read_bytes()==b'current own world data'
            (directory / 'world-checkpoint/aska/save.bin').write_bytes(b'incomplete copy')
            try:release.checkpoint({'release':str(directory),'revision':revision},games)
            except RuntimeError:pass
            else:raise AssertionError('Corrupt world checkpoint accepted')
        assert (directory / 'world-aska/save.bin').read_bytes()==b'current own world data'
    finally:
        for p in processes:
            if p.poll() is None:p.terminate();p.wait(timeout=15)
        # Failure cleanup is restricted to the exact executor and state created by this test.
        for entry in Path('/proc').iterdir():
            if not entry.name.isdigit():continue
            try:
                args=(entry / 'cmdline').read_bytes().split(b'\0')
                if str(code / 'app/bin/execute').encode() not in args or str(state).encode() not in args:continue
                before=release.identity(int(entry.name))
                if release.identity(int(entry.name))==before:os.kill(int(entry.name),15)
            except (OSError,ProcessLookupError):pass
print('PASS: private grant migration without escalation, persistent state/key, maintenance/drain, data-preserving recovery'+(', real HTTP restart/cutover/rollback/readiness failure, single manager lease and stale-release rejection' if engine else '; HTTP checked separately with FrankenPHP'))
