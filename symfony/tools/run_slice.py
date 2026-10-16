"""Run only the isolated Symfony slice; never replaces the production manager."""
import argparse
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import time


def main():
    def request_stop(_signal, _frame):
        raise KeyboardInterrupt

    signal.signal(signal.SIGTERM, request_stop)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=os.environ.get('SLICE_PHP', 'php'))
    parser.add_argument('--frankenphp', default=os.environ.get('SLICE_FRANKENPHP', 'frankenphp'))
    parser.add_argument('--driver', choices=['simulated', 'windows'], default='simulated')
    parser.add_argument('--role', choices=['owner', 'viewer'], default='owner')
    parser.add_argument('--state-dir', type=Path)
    parser.add_argument('--port', type=int, default=18865)
    parser.add_argument('--family-port', type=int)
    options = parser.parse_args()
    if not 18800 <= options.port <= 18999:
        parser.error('Use an isolated test port between 18800 and 18999.')
    if options.family_port is not None and (not 18800 <= options.family_port <= 18999 or options.family_port == options.port):
        parser.error('Use a separate isolated family test port between 18800 and 18999.')
    root = Path(__file__).resolve().parents[1]
    if not (root / 'vendor/autoload.php').is_file():
        parser.error('Run composer install in symfony/ first.')
    for tool in (options.php, options.frankenphp):
        if not shutil.which(tool):
            parser.error('Tool not found: ' + tool)
    for port in (options.port, options.family_port):
        if port is None:
            continue
        with socket.socket() as probe:
            if os.name != 'nt':
                probe.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            try:
                probe.bind(('127.0.0.1', port))
            except OSError:
                parser.error('The isolated port is already in use; no existing listener is adopted.')
    state = (options.state_dir or root / 'var/slice').resolve()
    environment = dict(os.environ, APP_ENV='prod', SLICE_STATE_DIR=str(state), SLICE_DRIVER=options.driver,
                       SLICE_ROLE=options.role, SLICE_PORT=str(options.port),
                       INDY_CACHE_DIR=str(state.with_name(state.name + '.cache')),
                       XDG_CONFIG_HOME=str(state / 'caddy-config'), XDG_DATA_HOME=str(state / 'caddy-data'))
    command = [options.php, 'bin/console']
    subprocess.run(command + ['slice:init'], cwd=root, env=environment, check=True)
    family_config = state / 'family.caddy'
    family_config.write_text('' if options.family_port is None else (
        f'http://:{options.family_port} {{\n bind 127.0.0.1\n root * public\n'
        f' request_body {{\n max_size 8KB\n }}\n php_server {{\n env INDY_LISTENER_PORT {options.family_port}\n }}\n}}\n'), encoding='utf-8')
    environment['SLICE_FAMILY_CONFIG'] = family_config.as_posix()
    children = []
    try:
        for _ in range(2):
            children.append(subprocess.Popen(command + ['messenger:consume', 'async', '--sleep=0.2', '--memory-limit=128M', '--no-interaction'], cwd=root, env=environment))
        children.append(subprocess.Popen([options.frankenphp, 'run', '--config', 'Caddyfile'], cwd=root, env=environment))
        print(f'Symfony-Durchstich: http://127.0.0.1:{options.port} ({options.driver}, {options.role})', flush=True)
        while all(child.poll() is None for child in children):
            time.sleep(.2)
        raise RuntimeError('A slice process exited; see its diagnostic output.')
    except KeyboardInterrupt:
        pass
    finally:
        for child in reversed(children):
            if child.poll() is None: child.terminate()
        for child in children:
            try: child.wait(timeout=10)
            except subprocess.TimeoutExpired:
                child.kill(); child.wait()
        if options.driver == 'windows':
            subprocess.run(command + ['slice:cleanup'], cwd=root, env=environment, check=True, timeout=100)


if __name__ == '__main__':
    main()
