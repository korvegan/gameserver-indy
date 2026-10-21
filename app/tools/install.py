"""Install the reviewed local foundation; never start or stop a process."""
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile


def git(root, *arguments):
    return subprocess.check_output(['git', '-C', str(root), *arguments])


def package(root, revision, destination):
    rows = git(root, 'ls-tree', '-rz', revision, 'app/').split(b'\0')
    for row in filter(None, rows):
        entry, name = row.split(b'\t')
        mode, kind, oid = entry.decode().split()
        if kind != 'blob' or mode not in ('100644', '100755'):
            raise RuntimeError('Unsupported release entry')
        target = destination / name.decode()
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(git(root, 'cat-file', 'blob', oid))
        target.chmod(0o755 if mode == '100755' else 0o644)
    (destination / 'REVISION').write_text(revision + '\n')
    if not (destination / 'app/bin/dev').is_file():
        raise RuntimeError('Missing foundation entrypoint')


def new_settings(old, code, revision, engine):
    return {**old, 'RuntimeEngine': 'php-foundation', 'ProjectDirectory': str(code),
            'CodeCommit': revision, 'FrankenPhpExecutable': str(engine)}


def replace(path, data):
    temporary = path.with_name(path.name + '.foundation-new')
    with temporary.open('xb') as stream:
        stream.write(data)
    try:
        temporary.chmod(0o600)
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def install(root, revision, launcher, engine):
    if not re.fullmatch('[a-f0-9]{40}', revision):
        raise RuntimeError('Expected full commit SHA')
    if git(root, 'rev-parse', 'HEAD').decode().strip() != revision:
        raise RuntimeError('Checkout does not match release')
    subprocess.run(['git', '-C', str(root), 'merge-base', '--is-ancestor', revision, 'origin/main'], check=True)
    if git(root, 'status', '--porcelain', '--untracked-files=no'):
        raise RuntimeError('Tracked checkout is dirty')
    if not engine.is_file() or not os.access(engine, os.X_OK):
        raise RuntimeError('FrankenPHP is not executable')
    configuration = launcher / 'launcher.local.json'
    starter = launcher / 'Start-Manager.ps1'
    original_config, original_starter = configuration.read_bytes(), starter.read_bytes()
    old = json.loads(original_config.decode('utf-8-sig'))
    if old['RuntimeEngine'] != 'symfony':
        raise RuntimeError('Only explicit legacy-to-foundation installation is supported')
    lifecycle = json.loads((Path(old['StateDirectory']) / 'runtime-lifecycle.json').read_text())
    if lifecycle['active']:
        raise RuntimeError('Stop the owned legacy management first')
    try:
        connection = socket.create_connection(('127.0.0.1', int(old['Port'])), timeout=1)
    except ConnectionRefusedError:
        pass
    else:
        connection.close()
        raise RuntimeError('Management port is already occupied')
    releases = Path.home() / '.local/share/gameserver-indy/foundation-releases'
    releases.mkdir(parents=True, exist_ok=True)
    release = releases / revision
    release.mkdir()  # Never overwrite an existing release.
    code = release / 'code'
    code.mkdir()
    package(root, revision, code)
    source_starter = git(root, 'show', revision + ':app/windows/Start-Manager.ps1')
    backup_root = launcher / 'archives'
    backup_root.mkdir(exist_ok=True)
    backup = Path(tempfile.mkdtemp(prefix='php-legacy-', dir=backup_root))
    (backup / 'launcher.local.json').write_bytes(original_config)
    (backup / 'Start-Manager.ps1').write_bytes(original_starter)
    for file in backup.iterdir():
        file.chmod(0o600)
    settings = new_settings(old, code, revision, engine.resolve())
    updated = (json.dumps(settings, indent=2) + '\n').encode()
    try:
        replace(starter, source_starter)
        replace(configuration, updated)
    except BaseException:
        replace(starter, original_starter)
        replace(configuration, original_config)
        raise
    record = {'revision': revision, 'code': str(code), 'backup': str(backup),
              'port': old['Port'], 'process_started': False, 'private_state_moved': False}
    (release / 'installation.json').write_text(json.dumps(record, indent=2) + '\n')
    print(json.dumps(record))


if __name__ == '__main__':
    if len(sys.argv) != 4:
        sys.exit('Usage: python3 app/tools/install.py COMMIT WINDOWS_LAUNCHER_DIRECTORY FRANKENPHP')
    install(Path(__file__).resolve().parents[2], sys.argv[1], Path(sys.argv[2]), Path(sys.argv[3]))
