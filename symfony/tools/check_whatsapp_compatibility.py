"""Readiness/log/claim migration comparisons on synthetic data; no personal accounts."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import threading
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from gameserver_manager.games import GAME_TYPES
from gameserver_manager.logs import redact, read_tail
from gameserver_manager.readiness import readiness
from gameserver_manager.whatsapp import short_message


def main():
    with tempfile.TemporaryDirectory(prefix='indy-whatsapp-parity-') as temporary:
        root = Path(temporary)
        profiles, cases, expected = [], [], []
        status = {'known': True, 'running': True, 'starting': False, 'launcher_running': False,
                  'processes': [{'pid': 42, 'started': '2024-01-01T10:00:00.250000Z'}]}
        prefix = "Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n"
        middle = 'routine line\n' * 25000
        texts = [prefix, prefix + "[Session] 'HostOnline' (down)!\n", "[Session] 'HostOnline' (up)!\n",
                 prefix.replace('2024.01.01', '2023.12.31'),
                 prefix + middle + "[Session] 'HostOnline' (down)!\n" + middle,
                 prefix + middle + "[Session] 'HostOnline' (down)!\n" + middle + "[Session] 'HostOnline' (up)!\n",
                 prefix + 'x' * (262144 - 11 - len(prefix) - 1) + "\n[Session] 'HostOnline' (down)!\n"]
        for game, text in [('enshrouded', text) for text in texts] + [('aska', '2024-01-01T09:59:59Z The session is now open!\n'), ('aska', '2024-01-01T10:00:01Z The session is now open!\n')]:
            sid = f'fixture-{len(profiles)}'
            directory = root / sid
            (directory / 'logs').mkdir(parents=True)
            diagnostic = root / f'{sid}-retained-diagnostic'
            diagnostic.mkdir()
            config = GAME_TYPES[game].initial_config()
            GAME_TYPES[game].write_config(directory / GAME_TYPES[game].config_filename, config)
            profile = {'id': sid, 'game': game, 'label': 'Own fixture', 'server_directory': str(directory),
                       'diagnostic_directory': str(diagnostic), 'connection': {'public_host': 'fixture.example.org'} if game == 'enshrouded' else {}}
            profiles.append(profile)
            path = directory / 'logs/enshrouded_server.log' if game == 'enshrouded' else diagnostic / 'server.log'
            path.write_text(text)
            instance = SimpleNamespace(profile=profile, directory=directory, diagnostic_directory=diagnostic,
                config=lambda: config, game=GAME_TYPES[game], settings_lock=threading.RLock())
            evidence = readiness(instance, status)
            cases.append({'kind': 'readiness', 'id': sid, 'status': status})
            expected.append({'evidence': evidence, 'message': short_message(instance, config, evidence['state'] == 'ready')})
        lines = ['password: old-secret\nError: retained', 'Authentication token:\nrotated-token\nReady',
                 'https://discord.com/api/webhooks/123/rotated\nSYNTHETIC-PASSWORD\n',
                 'password =\n\nrotated-token\n', 'Ärger\r\nNothing private\n',
                 'authorization: Bearer old-value\n', 'custom: synthetic-password\n']
        for index, text in enumerate(lines):
            for encoding in ['utf-8-sig', 'utf-16']:
                path = root / f'log-{index}-{encoding}.log'
                path.write_bytes(text.encode(encoding))
                cases.append({'kind': 'tail', 'path': str(path), 'values': ['synthetic-password']})
                expected.append(read_tail(path, ['synthetic-password']))
        source = root / 'old-whatsapp'
        source.mkdir()
        settings = {'group_id': '123456789@g.us', 'group_label': 'Own group', 'enabled': True, 'server_ids': [profiles[0]['id']], 'revision': 7}
        settings_bytes = json.dumps(settings, ensure_ascii=False).encode()
        (source / 'settings.json').write_bytes(settings_bytes)
        (source / 'credentials.json').write_text('{"api_key":"not-to-be-exported"}')
        with sqlite3.connect(source / 'deliveries.sqlite3') as database:
            database.execute('CREATE TABLE deliveries (server_id TEXT NOT NULL,process_key TEXT NOT NULL,group_id TEXT NOT NULL,state TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(server_id,process_key,group_id))')
            rows = [(profiles[0]['id'], f'{42 + i}:2024-01-01T10:00:00Z', '123456789@g.us', state, '2024-01-01T10:00:01Z') for i, state in enumerate(['sent', 'sending', 'uncertain'])]
            database.executemany('INSERT INTO deliveries VALUES (?,?,?,?,?)', rows)
        database.close()
        settings_path = root / 'profiles.json'
        settings_path.write_text(json.dumps({'schema_version': 2, 'servers': profiles}))
        (root / 'fixture.json').write_text(json.dumps({'marker': root.name, 'settings': str(settings_path), 'cases': cases, 'source': str(source)}))
        result = subprocess.run([os.environ.get('SLICE_PHP', 'php'), str(ROOT / 'symfony/tools/whatsapp_compatibility.php'), str(root)], capture_output=True, text=True, encoding='utf-8', check=True)
        actual = json.loads(result.stdout)
        if len(actual) != len(expected):
            raise AssertionError('Different readiness/log result count')
        for index, (old, new) in enumerate(zip(expected, actual)):
            if old != new:
                raise AssertionError(f'Readiness/log contract differs at {index}: {old!r} != {new!r}')
        copied = root / 'copy'
        if (copied / 'settings.json').read_bytes() != settings_bytes or (copied / 'credentials.json').exists():
            raise AssertionError('Settings changed or account credentials exported')
        with sqlite3.connect(copied / 'deliveries.sqlite3') as database:
            if database.execute('SELECT * FROM deliveries ORDER BY process_key').fetchall() != rows:
                raise AssertionError('Delivery claims changed')
        database.close()
    print(f'Python/Symfony WhatsApp parity: {len(cases)} readiness/log/message cases and three unchanged delivery states; no network or sends.')


if __name__ == '__main__':
    main()
