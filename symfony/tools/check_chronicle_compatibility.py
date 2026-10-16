"""Original SQLite payloads, revisions and real byte exports across Python/PHP, on own data."""
import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT), str(ROOT / 'tests')]
from gameserver_manager.catalog import GameCatalog
from gameserver_manager.chronicle import ChronicleService
from gameserver_manager.community import CommunityService
from gameserver_manager.instances import GameServerManager
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.security import Principal
from gameserver_manager.storage import Database
from test_manager import FakeBridge
from test_profiles import add_aska
from test_chronicle import contribution, JPEG


with tempfile.TemporaryDirectory(prefix='indy-chronicle-parity-') as directory:
    root = Path(directory)
    legacy = root / 'legacy'
    legacy.mkdir()
    install = root / 'Own Enshrouded install'
    install.mkdir()
    manager = GameServerManager(legacy, FakeBridge(), profile={'id': 'enshrouded', 'game': 'enshrouded', 'label': 'Own mountains', 'server_directory': str(install)})
    registry = ServerRegistry(legacy, manager)
    add_aska(registry, root)
    database = Database(legacy / 'data/platform.sqlite3')
    community = CommunityService(database, GameCatalog(), registry)
    service = ChronicleService(community)
    owner, family = Principal('local-owner', 'owner'), Principal('relative', 'family', frozenset({'aska'}))
    retries = [contribution('original', image=base64.b64encode(JPEG).decode()),
        contribution('original', revision=2, title='Am Fluss', reason='Titel korrigiert'),
        contribution('original', revision=3, title='Am Fluss', reason='Bild entfernt', clear_image=True),
        {'id': 'original', 'revision': 4, 'remove': True, 'reason': 'Archiviert'}]
    for body in retries:
        service.save(owner, body)
    for index in range(14):
        game = 'aska' if index % 2 else 'enshrouded'
        service.save(owner, contribution('event-' + str(index), game_id=game, instance_id=game, members=['Änne', 'Ｓtraße'],
            title='<script>own text</script>' if index == 0 else 'Moment ' + str(index), occurred_at=f'2026-10-{index+1:02}T20:00:00+02:00'))
    for event_id, occurred in [('early-offset', '2026-10-15T00:30:00+02:00'), ('later-z', '2026-10-14T23:30:00Z')]:
        community.record({'id': event_id, 'revision': 1, 'game_id': 'enshrouded', 'title': event_id, 'source': 'verified-game', 'member_ids': ['legacy-one'], 'badge_id': 'badge', 'occurred_at': occurred})
    community.record({'id': 'old-identities', 'revision': 1, 'game_id': 'aska', 'title': 'Alt', 'source': 'manual', 'member_ids': ['old-one'], 'badge_id': 'old-badge', 'campaign_id': 'old-campaign', 'mod_profile_id': 'old-mods'}, publish=True)
    with database.connect(write=True) as db:
        db.execute("UPDATE scenes SET template='milestone' WHERE event_id='old-identities'")
        db.execute("UPDATE deliveries SET state='sending',remote_id='own-remote' WHERE event_id='old-identities'")
    queries = [{'scope': 'owner', 'query': {'limit': '10'}}, {'scope': 'family', 'query': {}},
        {'scope': 'owner', 'query': {'from': '2026-10-14', 'to': '2026-10-14'}}]
    first = service.page(owner, limit='10')
    queries.append({'scope': 'owner', 'query': {'limit': '10', 'cursor': first['next_cursor']}})
    expected = [service.page(family if q['scope'] == 'family' else owner, **q['query']) for q in queries]
    new = contribution('php-new', members=['ÄNNE', 'strasse'], title='PHP Moment', image=base64.b64encode(JPEG).decode())
    fixture = {'marker': root.name, 'database': str(database.path), 'settings': str(legacy / 'manager.local.json'), 'queries': queries,
        'retries': retries, 'new': new, 'race': {**new, 'revision': 2, 'reason': 'Konkurrenz'}}
    (root / 'fixture.json').write_text(json.dumps(fixture), encoding='utf-8')
    original = database.path.read_bytes()
    command = [os.environ.get('SLICE_PHP', 'php'), str(Path(__file__).with_name('chronicle_compatibility.php')), str(root)]
    result = subprocess.run(command, check=True, capture_output=True, encoding='utf-8')
    actual = json.loads(result.stdout)
    assert actual['views'] == expected, 'Scoped chronological read contract differs'
    assert actual['story'] == service.story(owner, chapter='0')
    assert actual['family_story'] == service.story(family, chapter='1')
    assert actual['media_sha256'] == hashlib.sha256(JPEG).hexdigest()
    assert database.path.read_bytes() == original, 'Import or retries modified source database'
    copy = Database(root / 'php-state/data/platform.sqlite3')
    rollback = ChronicleService(CommunityService(copy, GameCatalog(), registry))
    assert rollback.save(owner, new)['event_id'] == 'php-new', 'PHP payload cannot be replayed by Python'
    assert rollback.media(owner, 'php-new') == JPEG
    with zipfile.ZipFile(root / 'php-export.zip') as archive:
        events = json.loads(archive.read('chronicle.json'))['events']
        assert len(events) == 22
        assert archive.read('media/' + hashlib.sha256(JPEG).hexdigest() + '.jpg') == JPEG
        assert b'<script>' not in archive.read('index.html')
    def race(title):
        return subprocess.run([*command, 'race', title], check=True, capture_output=True, encoding='utf-8').stdout
    with ThreadPoolExecutor(max_workers=2) as pool:
        outcomes = list(pool.map(race, ['First correction', 'Second correction']))
    assert sorted(outcomes) == ['committed', 'conflict'], outcomes
    assert len(rollback.detail(owner, 'php-new')['history']) == 2
    print('Python -> Symfony -> Python: original SQLite evidence, scoped reads, fixed chapters, JPEG bytes, historical retries, archive and concurrent corrections verified.')
