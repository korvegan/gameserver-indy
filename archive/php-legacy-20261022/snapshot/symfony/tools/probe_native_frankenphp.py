"""Probe an already verified Windows FrankenPHP in an own NTFS directory and loopback ports."""
import argparse
import http.client
import json
import os
from pathlib import Path
import statistics
import subprocess
import tempfile
import time


def windows(path):
    return subprocess.check_output(['wslpath', '-w', str(path)], text=True).strip().replace('\\', '/')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('binary', type=Path)
    options = parser.parse_args()
    binary = options.binary.resolve()
    if not binary.is_file() or binary.name != 'frankenphp.exe':
        parser.error('Select the verified private Windows FrankenPHP executable.')
    temporary = subprocess.check_output(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', '[Console]::Write([IO.Path]::GetTempPath())'], text=True).strip()
    temporary = Path(subprocess.check_output(['wslpath', '-u', temporary], text=True).strip())
    ports = subprocess.check_output(['powershell.exe','-NoProfile','-NonInteractive','-Command',
        '$a=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0); $b=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0); try {$a.Start(); $b.Start(); [Console]::Write(([string]$a.LocalEndpoint.Port)+","+([string]$b.LocalEndpoint.Port))} finally {$a.Stop(); $b.Stop()}'], text=True)
    web_port, admin_port = [int(value) for value in ports.split(',')]
    with tempfile.TemporaryDirectory(prefix='indy-frankenphp-probe-', dir=temporary) as folder:
        root = Path(folder)
        (root / 'index.php').write_text("<?php header('Content-Type: application/json'); echo json_encode(['php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,'sqlite'=>class_exists('SQLite3'),'pdo_sqlite'=>extension_loaded('pdo_sqlite'),'intl'=>extension_loaded('intl'),'unicode'=>class_exists('IntlChar') ? IntlChar::UNICODE_VERSION : null]);", encoding='utf-8')
        (root / 'php.ini').write_text('extension_dir="' + windows(binary.parent / 'ext') + '"\nextension=pdo_sqlite\nextension=sqlite3\nextension=intl\nextension=mbstring\nextension=zip\n', encoding='utf-8')
        config = root / 'Caddyfile'
        config.write_text('{\n admin 127.0.0.1:' + str(admin_port) + '\n auto_https off\n persist_config off\n frankenphp\n}\nhttp://:' + str(web_port) + ' {\n bind 127.0.0.1\n root * "' + windows(root) + '"\n php_server\n}\n', encoding='utf-8')
        environment = {**os.environ, 'PHPRC': str(root / 'php.ini'), 'XDG_CONFIG_HOME': str(root / 'caddy-config'), 'XDG_DATA_HOME': str(root / 'caddy-data')}
        environment['WSLENV'] = ':'.join(filter(None, [environment.get('WSLENV', ''), 'PHPRC/p', 'XDG_CONFIG_HOME/p', 'XDG_DATA_HOME/p']))
        with (root / 'output.log').open('wb') as output:
            started = time.perf_counter()
            process = subprocess.Popen([str(binary), 'run', '--config', windows(config)], cwd=root, env=environment, stdout=output, stderr=output)
            connection = None
            try:
                for attempt in range(200):
                    if process.poll() is not None:
                        raise RuntimeError((root / 'output.log').read_text(errors='replace')[-4000:])
                    try:
                        connection = http.client.HTTPConnection('127.0.0.1', web_port, timeout=1)
                        connection.request('GET', '/'); response = connection.getresponse()
                        if response.status == 200:
                            evidence = json.loads(response.read()); break
                    except (OSError, http.client.HTTPException):
                        pass
                    finally:
                        if connection:
                            connection.close()
                    time.sleep(.1)
                else:
                    raise RuntimeError('Own native runtime did not start.')
                startup_ms = round((time.perf_counter()-started)*1000, 1)
                timings = []
                for _ in range(20):
                    before = time.perf_counter()
                    connection = http.client.HTTPConnection('127.0.0.1', web_port, timeout=2)
                    connection.request('GET', '/'); response = connection.getresponse()
                    if response.status != 200 or json.loads(response.read()) != evidence:
                        raise RuntimeError('Runtime evidence changed.')
                    connection.close(); timings.append((time.perf_counter()-before)*1000)
                print(json.dumps({**evidence, 'startup_ms': startup_ms, 'median_ms': round(statistics.median(timings), 2), 'maximum_ms': round(max(timings), 2), 'requests': len(timings), 'scope': 'isolated basic HTTP; no game or boot acceptance'}))
            finally:
                # Graceful shutdown is sent only to the separately probed, own admin port.
                if process.poll() is None:
                    try:
                        connection = http.client.HTTPConnection('127.0.0.1', admin_port, timeout=2)
                        connection.request('POST', '/stop'); connection.getresponse().read(); connection.close()
                    finally:
                        process.wait(timeout=10)


if __name__ == '__main__':
    main()
