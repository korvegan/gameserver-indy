"""Chronicle persistence and authorization. Only temporary worlds/accounts/images."""
import base64
from concurrent.futures import ThreadPoolExecutor
import io
import json
import sqlite3
import urllib.error
import urllib.request
import zipfile
from unittest.mock import patch

from gameserver_manager.application import Application
from gameserver_manager.chronicle import ChronicleService, MAX_IMAGE, jpeg
from gameserver_manager.community import CommunityService
from gameserver_manager.configuration import ManagerError
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.security import AccessDenied, Principal
from gameserver_manager.storage import Database
from test_manager import ManagerFixture, HTTPFixture
from test_profiles import add_aska

OWNER = Principal('qa-owner', 'owner')
FAMILY = Principal('qa-sister', 'family', frozenset({'aska'}))
# Header fixture for server-side size/signature checks; browser QA uploads a decoded real image.
JPEG = bytes.fromhex('ffd8 ffc0 0011 08 0001 0001 03 011100 021100 031100 ffda 0008 010100003f00 00 ffd9')


def contribution(id='first-moment', **extra):
    return {'id': id, 'revision': 1, 'game_id': 'enshrouded', 'instance_id': 'enshrouded',
            'title': 'Unser erstes Lager', 'description': 'Gemeinsam begonnen.', 'campaign': 'Neue Welt',
            'members': ['Eins', 'Zwei', 'Drei', 'Vier'], 'badge': 'Lagergründer',
            'occurred_at': '2026-10-15T20:15:00+02:00', **extra}


class ChronicleTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.registry = ServerRegistry(self.root, self.manager)
        add_aska(self.registry, self.root)
        self.app = Application(self.registry)
        self.addCleanup(self.app.close)
        self.chronicle = self.app.chronicle

    def test_manual_moment_is_idempotent_and_survives_restart_with_equal_awards(self):
        original = self.manager.config_path.read_bytes()
        body = contribution()
        first = self.chronicle.save(OWNER, body)
        self.assertEqual(self.chronicle.save(OWNER, body), first)
        other = ChronicleService(CommunityService(Database(self.app.database.path), self.app.catalog, self.registry))
        result = other.page(OWNER)
        self.assertEqual(result['total'], 1)
        event = result['events'][0]
        self.assertEqual(len(event['awards']), 4)
        self.assertEqual(len({award['badge_id'] for award in event['awards']}), 1)
        self.assertEqual(event['occurred_at'], '2026-10-15T18:15:00+00:00')
        self.assertEqual(event['source'], 'manual')
        self.assertEqual(event['actor_id'], OWNER.id)
        self.assertNotEqual(event['recorded_at'], event['occurred_at'])
        self.assertEqual(self.manager.config_path.read_bytes(), original)
        self.assertNotIn('Start', self.bridge.calls)
        with self.app.database.connect() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM deliveries').fetchone()[0], 0)

    def test_filter_keyset_pagination_orders_play_time_and_preserves_scene_identity(self):
        for index in range(25):
            self.chronicle.save(OWNER, contribution(f'event-{index:02}', occurred_at=f'2026-10-{index + 1:02}T12:00:00Z'))
        first = self.chronicle.page(OWNER, limit='10')
        self.assertEqual(first['events'][0]['id'], 'event-24')
        second = self.chronicle.page(OWNER, limit='10', cursor=first['next_cursor'])
        third = self.chronicle.page(OWNER, limit='10', cursor=second['next_cursor'])
        self.assertEqual(len({e['id'] for e in first['events'] + second['events'] + third['events']}), 25)
        self.assertIsNone(third['next_cursor'])
        event = first['events'][0]
        filtered = self.chronicle.page(OWNER, game='enshrouded', campaign=event['campaign_id'], member=event['member_ids'][0], **{'from': '2026-10-25', 'to': '2026-10-25'})
        self.assertEqual([e['id'] for e in filtered['events']], ['event-24'])
        self.assertEqual(filtered['events'][0]['scene'], event['scene'])
        self.assertEqual(self.chronicle.page(OWNER, game='aska')['total'], 0)

    def test_member_names_are_stable_and_duplicate_names_are_rejected(self):
        self.chronicle.save(OWNER, contribution('one', members=['Änne']))
        self.chronicle.save(OWNER, contribution('two', members=['ÄNNE']))
        events = self.chronicle.page(OWNER)['events']
        self.assertEqual(events[0]['member_ids'], events[1]['member_ids'])
        with self.assertRaises(ManagerError):
            self.chronicle.save(OWNER, contribution(members=['Änne', 'ÄNNE']))

    def test_correction_is_revisioned_removed_moments_keep_audit_and_do_not_duplicate_awards(self):
        body = contribution()
        self.chronicle.save(OWNER, body)
        old = self.chronicle.detail(OWNER, body['id'])['event']
        corrected = {**body, 'revision': 2, 'title': 'Unser Lager am Fluss', 'reason': 'Ortsangabe ergänzt', 'members': ['Eins', 'Zwei', 'Drei']}
        self.chronicle.save(OWNER, corrected)
        self.chronicle.save(OWNER, corrected)
        detail = self.chronicle.detail(OWNER, body['id'])
        self.assertEqual(detail['event']['scene'], old['scene'])
        self.assertEqual(len(detail['event']['awards']), 3)
        self.assertEqual(len(detail['history']), 2)
        self.assertEqual(len(detail['history'][1]['awards']), 4)
        with self.assertRaises(ManagerError):
            self.chronicle.save(OWNER, {**corrected, 'title': 'Stale edit'})
        with self.assertRaises(ManagerError):
            self.chronicle.save(OWNER, {**corrected, 'revision': 3, 'reason': ''})
        removed = {'id': body['id'], 'revision': 3, 'remove': True, 'reason': 'Doppelt dokumentiert'}
        self.chronicle.save(OWNER, removed)
        self.chronicle.save(OWNER, removed)
        self.assertEqual(self.chronicle.page(OWNER)['events'], [])
        self.assertTrue(self.chronicle.detail(OWNER, body['id'])['event']['removed'])
        archive = zipfile.ZipFile(io.BytesIO(self.chronicle.export(OWNER)))
        self.assertEqual(len(json.loads(archive.read('chronicle.json'))['events']), 3)

    def test_scoped_readers_cannot_edit_export_or_fetch_foreign_details_and_media(self):
        self.chronicle.save(OWNER, contribution(image=base64.b64encode(JPEG).decode()))
        self.assertEqual(self.chronicle.page(FAMILY)['total'], 0)
        for operation in (lambda: self.chronicle.save(FAMILY, contribution()), lambda: self.chronicle.export(FAMILY),
                          lambda: self.chronicle.detail(FAMILY, 'first-moment'), lambda: self.chronicle.media(FAMILY, 'first-moment')):
            with self.assertRaises(AccessDenied):
                operation()
        self.chronicle.save(OWNER, contribution('aska-moment', game_id='aska', instance_id='aska', image=base64.b64encode(JPEG).decode()))
        self.assertEqual(self.chronicle.page(FAMILY)['total'], 1)
        self.assertEqual(self.chronicle.detail(FAMILY, 'aska-moment')['history'], [])
        self.assertEqual(self.chronicle.media(FAMILY, 'aska-moment'), JPEG)
        self.chronicle.save(OWNER, {'id': 'aska-moment', 'revision': 2, 'remove': True, 'reason': 'Korrektur'})
        with self.assertRaises(AccessDenied):
            self.chronicle.media(FAMILY, 'aska-moment')

    def test_invalid_input_creates_neither_event_nor_media(self):
        for extra in ({'title': ''}, {'members': []}, {'members': ['X'] * 17}, {'occurred_at': '2026-10-15T20:15'},
                      {'image': 'wrong base64'}, {'image': base64.b64encode(b'<svg/>').decode()},
                      {'source': 'verified-game'}, {'instance_id': 'aska'}, {'revision': True}):
            with self.subTest(extra=extra), self.assertRaises(ManagerError):
                self.chronicle.save(OWNER, contribution(**extra))
        with self.app.database.connect() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM community_events').fetchone()[0], 0)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM community_media').fetchone()[0], 0)
        for data in (b'\xff\xd8\xff\xd9', b'\xff\xd8' + b'x' * MAX_IMAGE + b'\xff\xd9', JPEG.replace(b'\x00\x01\x00\x01', b'\xff\xff\xff\xff')):
            with self.assertRaises(ManagerError):
                jpeg(data)

    def test_atomic_conflicting_image_does_not_leave_orphan_and_export_backup_include_images(self):
        body = contribution(image=base64.b64encode(JPEG).decode())
        self.chronicle.save(OWNER, body)
        with self.assertRaises(ManagerError):
            self.chronicle.save(OWNER, {**body, 'title': 'Conflicting content'})
        archive = zipfile.ZipFile(io.BytesIO(self.chronicle.export(OWNER, game='enshrouded')))
        manifest = json.loads(archive.read('chronicle.json'))
        media_id = manifest['events'][0]['media_id']
        self.assertEqual(archive.read('media/' + media_id + '.jpg'), JPEG)
        backup = self.root / 'restorable.sqlite3'
        self.app.database.backup(backup)
        restored = ChronicleService(CommunityService(Database(backup), self.app.catalog, self.registry))
        self.assertEqual(restored.media(OWNER, body['id']), JPEG)
        self.assertEqual(restored.detail(OWNER, body['id'])['event']['scene']['id'], 'scene-first-moment')
        with self.app.database.connect() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM community_media').fetchone()[0], 1)

    def test_invalid_filters_and_cursors_fail_closed(self):
        for query in ({'game': "' OR 1=1"}, {'member': '../x'}, {'from': 'not-date'}, {'to': '9999-12-31'},
                      {'limit': '0'}, {'limit': '51'}, {'cursor': '%'}, {'unknown': 'value'}):
            with self.subTest(query=query), self.assertRaises(ManagerError):
                self.chronicle.page(OWNER, **query)

    def test_change_token_changes_on_correction_and_removal_without_new_group_moment(self):
        self.chronicle.save(OWNER, contribution())
        before = self.chronicle.page(OWNER)['change_token']
        self.chronicle.save(OWNER, contribution(revision=2, reason='Text ergänzt', description='Neue Beschreibung'))
        self.assertNotEqual(self.chronicle.page(OWNER)['change_token'], before)
        self.assertEqual(self.chronicle.page(OWNER)['total'], 1)
        options = self.chronicle.options(OWNER)
        self.assertEqual(options['campaigns'][0]['label'], 'Neue Welt')
        self.assertEqual({member['name'] for member in options['members']}, {'Eins', 'Zwei', 'Drei', 'Vier'})

    def test_schema_one_upgrade_creates_exact_backup_before_media_table(self):
        old = self.root / 'v1.sqlite3'
        self.app.database.backup(old)
        with sqlite3.connect(old) as db:
            db.execute('DROP TABLE community_media')
            db.execute('PRAGMA user_version=1')
        upgraded = Database(old)
        backups = list((self.root / 'backups').glob('platform-v1-*.sqlite3'))
        self.assertEqual(len(backups), 1)
        with sqlite3.connect(backups[0]) as db:
            self.assertEqual(db.execute('PRAGMA user_version').fetchone()[0], 1)
            self.assertIsNone(db.execute("SELECT name FROM sqlite_master WHERE name='community_media'").fetchone())
        with upgraded.connect() as db:
            self.assertEqual(db.execute('PRAGMA user_version').fetchone()[0], 2)
        Database(old)
        self.assertEqual(len(list((self.root / 'backups').glob('platform-v1-*.sqlite3'))), 1)

    def test_failed_migration_backup_leaves_version_one_untouched(self):
        old = self.root / 'backup-unavailable.sqlite3'
        self.app.database.backup(old)
        with sqlite3.connect(old) as db:
            db.execute('DROP TABLE community_media')
            db.execute('PRAGMA user_version=1')
        with patch.object(Database, 'backup', side_effect=OSError('unavailable')):
            with self.assertRaises(OSError):
                Database(old)
        with sqlite3.connect(old) as db:
            self.assertEqual(db.execute('PRAGMA user_version').fetchone()[0], 1)
            self.assertIsNone(db.execute("SELECT name FROM sqlite_master WHERE name='community_media'").fetchone())

    def test_concurrent_corrections_commit_exactly_one_new_revision(self):
        self.chronicle.save(OWNER, contribution())
        def save(title):
            try:
                self.chronicle.save(OWNER, contribution(revision=2, title=title, reason='Korrektur'))
                return True
            except ManagerError:
                return False
        with ThreadPoolExecutor(max_workers=2) as executor:
            results = list(executor.map(save, ['Erste Korrektur', 'Zweite Korrektur']))
        self.assertEqual(sum(results), 1)
        detail = self.chronicle.detail(OWNER, 'first-moment')
        self.assertEqual(len(detail['history']), 2)
        self.assertEqual(self.chronicle.page(OWNER)['total'], 1)

    def test_offline_archive_escapes_user_text(self):
        self.chronicle.save(OWNER, contribution(title='<script>alert(1)</script>', description='<img onerror=bad()>'))
        archive = zipfile.ZipFile(io.BytesIO(self.chronicle.export(OWNER)))
        document = archive.read('index.html').decode()
        self.assertIn('&lt;script&gt;', document)
        self.assertNotIn('<script>', document)
        self.assertNotIn('<img onerror', document)

    def test_correction_preserves_legacy_member_badge_campaign_and_mod_identifiers(self):
        self.app.community.record({'id': 'legacy', 'revision': 1, 'game_id': 'enshrouded', 'instance_id': 'enshrouded',
            'title': 'Alt', 'source': 'manual', 'member_ids': ['old-one', 'old-two'], 'badge_id': 'old-badge',
            'campaign_id': 'old-campaign', 'mod_profile_id': 'old-mods'})
        self.chronicle.save(OWNER, contribution('legacy', revision=2, title='Korrigiert', reason='Tippfehler',
            members=['old-one', 'old-two'], badge='old-badge', campaign='old-campaign'))
        corrected = self.chronicle.detail(OWNER, 'legacy')['event']
        self.assertEqual(corrected['member_ids'], ['old-one', 'old-two'])
        self.assertEqual(corrected['badge_id'], 'old-badge')
        self.assertEqual(corrected['campaign_id'], 'old-campaign')
        self.assertEqual(corrected['mod_profile_id'], 'old-mods')


class ChronicleHTTPTests(HTTPFixture):
    def test_http_save_media_export_and_csrf_contracts(self):
        for token in (None, 'invalid'):
            with self.assertRaises(urllib.error.HTTPError) as error:
                self.post('/api/community', contribution(), token)
            self.assertEqual(error.exception.code, 403)
            error.exception.close()
        with self.post('/api/community', contribution(image=base64.b64encode(JPEG).decode()), self.server.token) as response:
            self.assertEqual(json.load(response)['event_id'], 'first-moment')
        with urllib.request.urlopen(self.url + '/api/community/media?event=first-moment') as response:
            self.assertEqual(response.headers.get_content_type(), 'image/jpeg')
            self.assertEqual(response.read(), JPEG)
        with urllib.request.urlopen(self.url + '/api/community/export') as response:
            self.assertIn('attachment', response.headers['Content-Disposition'])
            self.assertEqual(response.headers.get_content_type(), 'application/zip')
            self.assertIn('chronicle.json', zipfile.ZipFile(io.BytesIO(response.read())).namelist())
