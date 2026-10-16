"""Compare migrated integration contracts on own fixtures, without network or sends."""
import json
import os
from pathlib import Path
from types import SimpleNamespace
import subprocess
import sys
import tempfile
import threading

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from gameserver_manager.connections import ipv4, public_host, join_details
from gameserver_manager.games import GAME_TYPES
from gameserver_manager.steam import named_object, installed, branch_name


def main():
    cases, profiles = [], []

    def check(kind, value, callback, **extra):
        try:
            expected = {'valid': True, 'value': callback()}
        except Exception:
            expected = {'valid': False}
        cases.append({'kind': kind, 'value': value, **extra, 'expected': expected})

    for text in ['"AppState" { "A" "x\\\\y" }', '"AppState" { "a" "1" "A" "2" }',
                 '"AppState" { "a" {', '"AppState" {}\n"AppState" {}',
                 '"AppState" { // comment\n "section" { "a" "1" } }']:
        check('keyvalues', text, lambda: named_object(text, 'AppState'))
    for value in ['default', 'PUBLIC', 'preview', 'with space', '', None]:
        check('branch', value, lambda: branch_name(value))
    for value in ['192.168.178.22', '100.98.144.36', '127.0.0.1', '169.254.0.1', '0.0.0.0', '0.1.2.3',
                  '224.0.0.1', '240.0.0.1', '192.0.0.8', '192.0.0.9', '192.0.0.10', '192.0.2.1',
                  '8.8.8.8', '2001:db8::1', '01.2.3.4', None]:
        for public in [False, True]:
            check('ipv4', value, lambda: ipv4(value, public=public), public=public)
    for value in ['sturmklingen.dedyn.io', ' EXAMPLE.ORG. ', '\u00a0fixture.example.org\x1c', '192.0.0.9', '192.0.0.10', '127.0.0.1', '192.168.178.22',
                  'https://example.org', 'example.org:15637', '-x.example.org', '', None]:
        check('host', value, lambda: public_host(value))
    with tempfile.TemporaryDirectory(prefix='indy-integration-parity-') as temporary:
        root = Path(temporary)
        for game in ['enshrouded', 'aska']:
            for bind in ['0.0.0.0', '127.0.0.1', '192.168.178.22', 'invalid']:
                config = GAME_TYPES[game].initial_config()
                config.update({'ip': bind, 'name': '  Own\u00a0\x1cServer  ', 'server name': 'Own ASKA'})
                directory = root / f'join-{game}-{bind}'
                directory.mkdir()
                profile = {'id': f'join-{len(profiles)}', 'game': game, 'label': 'Own fixture',
                           'server_directory': str(directory), 'connection': {'public_host': 'fixture.example.org'} if game == 'enshrouded' else {}}
                path = directory / GAME_TYPES[game].config_filename
                GAME_TYPES[game].write_config(path, config)
                profiles.append(profile)
                instance = SimpleNamespace(game=GAME_TYPES[game], profile=profile, settings_lock=threading.RLock())
                check('join', profile['id'], lambda: join_details(instance, config))
        for index, extra in enumerate(['', '"TargetBuildID" "200"', '"BytesToDownload" "20" "BytesDownloaded" "1"',
            '"UserConfig" { "BetaKey" "preview" }',
            '"UserConfig" { "BetaKey" "preview" } "MountedConfig" { "BetaKey" "public" }']):
            directory = root / f'install-{index}' / 'steamapps' / 'common' / 'Fixture'
            directory.mkdir(parents=True)
            manifest = directory.parent.parent / 'appmanifest_2278520.acf'
            manifest.write_text('"AppState" { "appid" "2278520" "installdir" "Fixture" "buildid" "100" "StateFlags" "4" "LastUpdated" "1" ' + extra + ' }')
            profile = {'id': f'install-{index}', 'game': 'enshrouded', 'label': 'Own fixture', 'server_directory': str(directory)}
            profiles.append(profile)
            instance = SimpleNamespace(game=GAME_TYPES['enshrouded'], profile=profile, directory=directory)
            check('installed', profile['id'], lambda: installed(instance))
        settings = root / 'settings.json'
        settings.write_text(json.dumps({'schema_version': 2, 'servers': profiles}))
        fixture = {'marker': root.name, 'settings': str(settings), 'cases': cases}
        (root / 'fixture.json').write_text(json.dumps(fixture, ensure_ascii=False), encoding='utf-8')
        result = subprocess.run([os.environ.get('SLICE_PHP', 'php'), str(ROOT / 'symfony/tools/integration_compatibility.php'), str(root)],
                                text=True, encoding='utf-8', capture_output=True, check=True)
        actual = json.loads(result.stdout)
        if len(actual) != len(cases):
            raise AssertionError('Integration case count differs')
        for index, (case, migrated) in enumerate(zip(cases, actual)):
            if case['expected'] != migrated:
                raise AssertionError(f'Integration contract differs at {index} ({case["kind"]} {case["value"]!r}): {case["expected"]!r} != {migrated!r}')
    print(f'Python/Symfony integration parity: {len(cases)} synthetic cases passed; no network, game actions or messages.')


if __name__ == '__main__':
    main()
