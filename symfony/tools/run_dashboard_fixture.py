"""Own temporary dashboard only: fake processes, Steam, network and message transports."""
import argparse
import os
from pathlib import Path
import signal
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
    options = parser.parse_args()
    root = options.root.resolve()
    if root.parent != Path(tempfile.gettempdir()).resolve() or not root.name.startswith('indy-symfony-dashboard-') or any(root.iterdir()):
        parser.error('Use an empty, own temporary dashboard directory.')
    for port in (18876, 18877):
        with socket.socket() as probe:
            probe.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            probe.bind(('127.0.0.1', port))
    root.chmod(0o700)
    (root / '.dashboard-fixture').write_text('own-dashboard-fixture-v1')
    project = Path(__file__).resolve().parents[1]
    environment = dict(os.environ, APP_ENV='test', SLICE_DRIVER='managed', SLICE_ROLE='owner',
                       SLICE_PORT='18876', SLICE_STATE_DIR=str(root / 'state'), INDY_CACHE_DIR=str(root / 'cache'),
                       INDY_DASHBOARD_FIXTURE_ROOT=str(root), XDG_CONFIG_HOME=str(root / 'caddy-config'), XDG_DATA_HOME=str(root / 'caddy-data'))
    command = [os.environ.get('SLICE_PHP', 'php'), str(project / 'tests/Fixtures/dashboard_console.php')]
    for arguments in (['slice:init'], ['fixture:seed'], ['indy:runtime', 'open']):
        subprocess.run(command + arguments, cwd=project, env=environment, check=True)
    public = root / 'public'
    public.mkdir()
    # Explicit fixture front controller for HTTP AND workers; no fallback to native services.
    (public / 'index.php').write_text('<?php require ' + repr(str(project / 'tests/Fixtures/dashboard_http.php')) + ';', encoding='utf-8')
    sites = '\n'.join(
        f'http://:{port} {{\n bind 127.0.0.1\n root * "{public}"\n request_body {{\n max_size 1048576\n }}\n'
        f' php_server {{\n env INDY_LISTENER_PORT {port}\n }}\n}}' for port in (18876, 18877))
    config = root / 'Caddyfile'
    config.write_text('{\n admin off\n auto_https off\n frankenphp\n}\n' + sites, encoding='utf-8')
    children = []
    try:
        for transport in ('async', 'async', 'metadata'):
            children.append(subprocess.Popen(command + ['messenger:consume', transport, '--sleep=0.1', '--memory-limit=128M', '--no-interaction'], cwd=project, env=environment))
        children.append(subprocess.Popen([os.environ.get('SLICE_FRANKENPHP', 'frankenphp'), 'run', '--config', str(config)], cwd=project, env=environment))
        print('Dashboard fixture http://127.0.0.1:18876', flush=True)
        while all(child.poll() is None for child in children):
            time.sleep(.2)
        raise RuntimeError('An owned fixture child exited.')
    except KeyboardInterrupt:
        pass
    finally:
        try:
            subprocess.run(command + ['indy:runtime', 'close'], cwd=project, env=environment, check=True, timeout=15)
        finally:
            for child in reversed(children):
                if child.poll() is None:
                    child.terminate()
            for child in children:
                try:
                    child.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    child.kill()
                    child.wait()


if __name__ == '__main__':
    main()
