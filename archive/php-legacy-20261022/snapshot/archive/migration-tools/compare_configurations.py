"""Compare migrated adapters with the Python baseline on temporary synthetic files."""
import base64
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))
from gameserver_manager.configuration import ManagerError
from gameserver_manager.games import GAME_TYPES


def main():
    cases = []
    enshrouded = GAME_TYPES['enshrouded'].initial_config()
    for group in enshrouded['userGroups']:
        group['password'] = 'synthetic-password'
    enshrouded['unknown'] = {'object': {}, 'array': [], 'ratio': 1.0, 'id': 76561198000000000}
    enshrouded['gameSettings']['unknown'] = {'future': [True, None, 'ö']}
    aska = GAME_TYPES['aska'].initial_config()
    aska['save id'] = 'fixture-save'
    aska['authentication token'] = 'synthetic-token'
    aska['password'] = 'synthetic-password'
    aska['unknown'] = 'a=b'
    originals = {
        'enshrouded': b'\xef\xbb\xbf' + json.dumps(enshrouded).encode(),
        'aska': b'\xef\xbb\xbf# fixture\r\n' + ''.join(f' {key}  = {value}\r\n' for key, value in aska.items()).encode() + b'; final comment\r\n',
    }

    def add(game, config, profile=None, start=False):
        cases.append({'game': game, 'config': config, 'original': base64.b64encode(originals[game]).decode(),
                      'profile': profile or {}, 'start': start})

    add('enshrouded', enshrouded)
    for field, values in [('name', ['\u00a0', '\x1c', '\u180e', ' German ']),
                          ('slotCount', [True, 0, 17, 4]), ('queryPort', [False, -1, 65536, 15637]),
                          ('gameSettingsPreset', ['Custom', 'unknown']), ('tags', [{'bad': 1}, ['German'], [7]])]:
        for value in values:
            changed = copy.deepcopy(enshrouded)
            changed[field] = value
            add('enshrouded', changed)
    for value in [None, True, -1, 0, 11, 20, 21, 1.0]:
        changed = copy.deepcopy(enshrouded)
        changed['gameSettings']['flameLevelFogRemoval'] = value
        add('enshrouded', changed)
    changed = copy.deepcopy(enshrouded)
    changed['userGroups'][1]['name'] = 'ADMIN'
    add('enshrouded', changed)
    add('aska', aska, {'launcher_script': 'synthetic.ps1'}, True)
    for field, value in [('server name', 'Neu'), ('server name', '\u00a0'), ('server name', '\x1c'),
                         ('steam game port', '０'), ('steam game port', '27016'),
                         ('steam game port', '65536'), ('region', 'europe'), ('region', 'unknown'),
                         ('unknown', 'line\nbreak'), ('mode', 'custom'), ('save id', 'another-world')]:
        changed = copy.deepcopy(aska)
        changed[field] = value
        add('aska', changed, {'launcher_script': 'synthetic.ps1'}, True)
    changed = copy.deepcopy(aska)
    changed['password'] = changed['authentication token'] = ''
    add('aska', changed, start=True)

    expected = []
    with tempfile.TemporaryDirectory(prefix='indy-configuration-contract-') as temporary:
        for index, case in enumerate(cases):
            path = Path(temporary) / f'{index}.config'
            path.write_bytes(base64.b64decode(case['original']))
            adapter = GAME_TYPES[case['game']]
            try:
                previous = adapter.read_config(path)
                configuration = adapter.merge_config(copy.deepcopy(case['config']), previous)
                adapter.validate_config(configuration)
                adapter.validate_edit(configuration, previous, case['profile'])
                if case['start']:
                    adapter.validate_start(configuration)
                public = adapter.public_config(configuration)
                adapter.write_config(path, configuration)
                expected.append({'valid': True, 'public': public, 'bytes': path.read_bytes()})
            except ManagerError:
                expected.append({'valid': False})
    completed = subprocess.run([os.environ.get('SLICE_PHP', 'php'), str(ROOT / 'symfony/tools/configuration-contract.php')],
                               input=json.dumps(cases), text=True, capture_output=True, check=True)
    actual = json.loads(completed.stdout)
    if len(actual) != len(expected):
        raise AssertionError('Different number of contract results')
    for index, (old, new) in enumerate(zip(expected, actual)):
        if old['valid'] != new['valid']:
            raise AssertionError(f'Validation differs for synthetic case {index}')
        if old['valid']:
            if old['public'] != new['public']:
                raise AssertionError(f'Public values differ for synthetic case {index}')
            encoded = base64.b64decode(new['encoded'])
            if cases[index]['game'] == 'aska':
                if old['bytes'] != encoded:
                    raise AssertionError(f'ASKA bytes differ for synthetic case {index}')
            elif json.loads(old['bytes']) != json.loads(encoded):
                raise AssertionError(f'Enshrouded JSON values differ for synthetic case {index}')
    print(f'Python/Symfony configuration parity: {len(cases)} synthetic cases passed; ASKA bytes and Enshrouded JSON preserved.')


if __name__ == '__main__':
    main()
