"""Run isolated PHP fixtures from a short, disposable NTFS checkout under WSL.

Copies only versioned/nonignored Symfony sources, profiles/scripts and vendor.
No live manager data, environment credentials, game installs or global PHP config.
"""
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def ps_value(expression):
    return subprocess.check_output(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
        '[Console]::Write(' + expression + ')'], text=True).strip()


def local(path):
    return Path(path) if os.name == 'nt' else Path(subprocess.check_output(['wslpath', '-u', path], text=True).strip())


native_temp = local(ps_value('[IO.Path]::GetTempPath()'))
native_php = os.environ.get('INDY_NATIVE_PHP') or ps_value('(Join-Path $env:LOCALAPPDATA "GameServerIndy\\tools\\php85\\php.exe")')
executable = str(local(native_php))
modules = subprocess.check_output([executable, '-m'], text=True).splitlines()
php_options = [] if 'zip' in modules else ['-d', 'extension=zip']
with tempfile.TemporaryDirectory(prefix='ib-', dir=native_temp) as directory:
    target = Path(directory)
    sources = subprocess.check_output(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard',
        'symfony', 'profiles', 'scripts'], cwd=ROOT).decode().split('\0')
    print('Preparing own short NTFS checkout (no live state).', flush=True)
    for name in sources:
        if not name:
            continue
        source = ROOT / name
        if source.is_symlink() or not source.is_file():
            raise RuntimeError('Only regular source files can enter the fixture')
        destination = target / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, destination)
    if os.name == 'nt':
        shutil.copytree(ROOT / 'symfony/vendor', target / 'symfony/vendor')
    else:
        # One bulk crossing into NTFS avoids thousands of slow WSL metadata roundtrips.
        with tempfile.NamedTemporaryFile(prefix='indy-php-vendor-', suffix='.tar') as temporary:
            with tarfile.open(fileobj=temporary, mode='w') as bundle:
                bundle.add(ROOT / 'symfony/vendor', arcname='vendor')
            temporary.flush()
            windows_archive = subprocess.check_output(['wslpath', '-w', temporary.name], text=True).strip()
            windows_target = subprocess.check_output(['wslpath', '-w', str(target / 'symfony')], text=True).strip()
            subprocess.run(['tar.exe', '-xf', windows_archive, '-C', windows_target], check=True)
    print('Running native PHP fixtures.', flush=True)
    environment = {**os.environ, 'SLICE_WINDOWS_TEST': '1'}
    if os.name != 'nt':
        environment['WSLENV'] = ':'.join(filter(None, [environment.get('WSLENV', ''), 'SLICE_WINDOWS_TEST']))
    subprocess.run([executable, *php_options, 'vendor/bin/phpunit', '--filter',
        sys.argv[1] if len(sys.argv) > 1 else 'WorldBackupsTest|NativeBackupPathsTest'], cwd=target / 'symfony',
        env=environment, check=True)
print('Native PHP fixture checkout removed.', flush=True)
