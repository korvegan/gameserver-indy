"""Own temporary dashboard only: fake processes, Steam, network and message transports."""
import argparse
import json
import os
from pathlib import Path
import signal
import shutil
import socket
import subprocess
import tempfile
import time


def main():
    def stop(_signum, _frame):
        raise KeyboardInterrupt

    signal.signal(signal.SIGTERM, stop)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, required=True)
    parser.add_argument('--reuse', action='store_true')
    options = parser.parse_args()
    root = options.root.resolve()
    if root.parent != Path(tempfile.gettempdir()).resolve() or not root.name.startswith('indy-symfony-dashboard-'):
        parser.error('Use an empty, own temporary dashboard directory.')
    if options.reuse:
        if not (root / '.dashboard-fixture').is_file() or (root / '.dashboard-fixture').read_text() != 'own-dashboard-fixture-v1':
            parser.error('Only an existing own fixture can be reused.')
    else:
        if any(root.iterdir()):
            parser.error('New fixture requires an empty own directory.')
        for port in (18876, 18877):
            with socket.socket() as probe:
                probe.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                probe.bind(('127.0.0.1', port))
        root.chmod(0o700)
        (root / '.dashboard-fixture').write_text('own-dashboard-fixture-v1')
    project = Path(__file__).resolve().parents[1]
    php = shutil.which(os.environ.get('SLICE_PHP', 'php'))
    frankenphp = shutil.which(os.environ.get('SLICE_FRANKENPHP', 'frankenphp'))
    if not php or not frankenphp:
        parser.error('Fixture tools not found.')
    environment = dict(os.environ, APP_ENV='test', SLICE_DRIVER='managed', SLICE_ROLE='owner',
                       SLICE_PORT='18876', SLICE_STATE_DIR=str(root / 'state'), INDY_CACHE_DIR=str(root / 'cache'),
                       INDY_DASHBOARD_FIXTURE_ROOT=str(root), XDG_CONFIG_HOME=str(root / 'caddy-config'), XDG_DATA_HOME=str(root / 'caddy-data'))
    command = [php, str(project / 'tests/Fixtures/dashboard_console.php')]
    if not options.reuse:
        for arguments in (['slice:init'], ['fixture:seed']):
            subprocess.run(command + arguments, cwd=project, env=environment, check=True)
        public = root / 'release/public'
        public.mkdir(parents=True)
        (root / 'release/bin').mkdir()
        # Explicit fixture front controller for HTTP AND workers; no fallback to native services.
        (public / 'index.php').write_text('<?php require ' + repr(str(project / 'tests/Fixtures/dashboard_http.php')) + ';', encoding='utf-8')
        (root / 'release/bin/console').write_text('<?php require ' + repr(str(project / 'tests/Fixtures/dashboard_console.php')) + ';', encoding='utf-8')
        (root / 'state/runtime.local.json').write_text(json.dumps({'php': php,
            'frankenphp': frankenphp, 'port': 18876, 'family_port': 18877, 'threads': 6}), encoding='utf-8')
    children = []
    try:
        children.append(subprocess.Popen(command + ['app:serve'], cwd=project, env=environment))
        print('Dashboard fixture http://127.0.0.1:18876', flush=True)
        while all(child.poll() is None for child in children):
            time.sleep(.2)
        for log in (root / 'state/runtime/logs').glob('*.log'):
            print(log.name + ': ' + log.read_text(errors='replace')[-3000:], flush=True)
        raise RuntimeError('An owned fixture supervisor exited.')
    except KeyboardInterrupt:
        pass
    finally:
        for child in reversed(children):
            if child.poll() is None:
                child.terminate()
        for child in children:
            try:
                child.wait(timeout=25)
            except subprocess.TimeoutExpired:
                child.kill()
                child.wait()


if __name__ == '__main__':
    main()
