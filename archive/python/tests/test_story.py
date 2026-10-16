"""Stable illustrations, bounded chapter loading and scoped evidence projections."""
from gameserver_manager.application import Application
from gameserver_manager.chronicle import ChronicleService
from gameserver_manager.community import CommunityService
from gameserver_manager.configuration import ManagerError
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.storage import Database
from test_chronicle import OWNER, FAMILY, contribution
from test_manager import ManagerFixture
from test_profiles import add_aska


class StoryTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.registry = ServerRegistry(self.root, self.manager)
        add_aska(self.registry, self.root)
        self.app = Application(self.registry)
        self.addCleanup(self.app.close)
        self.chronicle = self.app.chronicle

    def test_new_moment_has_one_durable_scene_after_retry_correction_restart_and_restore(self):
        body = contribution()
        self.chronicle.save(OWNER, body)
        first = self.chronicle.story(OWNER)['events'][0]['scene']
        self.assertEqual((first['chapter'], first['slot'], first['blueprint']), (0, 0, 'landscape-v1-mountains'))
        self.chronicle.save(OWNER, body)
        self.chronicle.save(OWNER, {**body, 'revision': 2, 'title': 'Verbesserter Titel', 'reason': 'Tippfehler'})
        self.chronicle.save(OWNER, contribution('second'))
        snapshot = self.root / 'restore.sqlite3'
        self.app.database.backup(snapshot)
        for path in (self.app.database.path, snapshot):
            service = ChronicleService(CommunityService(Database(path), self.app.catalog, self.registry))
            events = service.story(OWNER)['events']
            self.assertEqual(events[0]['scene'], first)
            self.assertEqual(events[0]['revision'], 2)
            self.assertEqual(len(events), 2)
            with service.database.connect() as db:
                self.assertEqual(db.execute('SELECT COUNT(*) FROM scenes').fetchone()[0], 2)
                self.assertEqual(db.execute('SELECT COUNT(*) FROM deliveries').fetchone()[0], 0)

    def test_chapters_do_not_compact_filtered_removed_or_unauthorized_scenes(self):
        for i in range(14):
            self.chronicle.save(OWNER, contribution(f'event-{i}', game_id='aska' if i % 2 else 'enshrouded', instance_id='aska' if i % 2 else 'enshrouded'))
        last = self.chronicle.story(OWNER)
        self.assertEqual(last['chapter'], 2)
        self.assertEqual([e['scene']['position'] for e in last['events']], [12, 13])
        first = self.chronicle.story(FAMILY, chapter='0')
        self.assertEqual([e['scene']['slot'] for e in first['events']], [1, 3, 5])
        filtered = self.chronicle.story(OWNER, game='enshrouded', chapter='1')
        self.assertEqual([e['scene']['slot'] for e in filtered['events']], [0, 2, 4])
        self.chronicle.save(OWNER, {'id': 'event-2', 'revision': 2, 'remove': True, 'reason': 'Falscher Eintrag'})
        self.assertEqual([e['scene']['slot'] for e in self.chronicle.story(OWNER, chapter='0')['events']], [0, 1, 3, 4, 5])
        self.assertEqual(self.chronicle.story(OWNER, chapter='1')['events'][0]['scene']['position'], 6)
        self.assertTrue(all(len(self.chronicle.story(OWNER, chapter=str(c['id']))['events']) <= 6 for c in last['chapters']))

    def test_legacy_scene_projection_uses_original_game_without_rewriting_records(self):
        event = {'id': 'legacy', 'revision': 1, 'game_id': 'aska', 'title': 'Früher', 'member_ids': ['one'], 'badge_id': 'first', 'source': 'test'}
        self.app.community.record(event)
        with self.app.database.connect(write=True) as db:
            db.execute("UPDATE scenes SET template='milestone' WHERE event_id='legacy'")
        self.app.community.record({**event, 'revision': 2, 'game_id': 'enshrouded'})
        before = self.chronicle.detail(OWNER, 'legacy')
        with self.app.database.connect() as db:
            stored = [tuple(row) for row in db.execute('SELECT * FROM community_events')]
        self.assertEqual(before['event']['scene']['blueprint'], 'landscape-v1-woodland')
        self.assertEqual(before['event']['scene'], before['history'][0]['scene'])
        self.chronicle.story(OWNER)
        with self.app.database.connect() as db:
            self.assertEqual(stored, [tuple(row) for row in db.execute('SELECT * FROM community_events')])
            self.assertEqual(db.execute('SELECT template FROM scenes').fetchone()[0], 'milestone')

    def test_story_rejects_invalid_chapter_and_chronicle_pagination_parameters(self):
        for args in [{'chapter': '-1'}, {'chapter': '1.5'}, {'chapter': '١'}, {'chapter': '9' * 20}, {'chapter': 1}, {'cursor': 'anything'}, {'limit': '1'}, {'other': 'x'}]:
            with self.subTest(args=args), self.assertRaises(ManagerError):
                self.chronicle.story(OWNER, **args)
        empty = self.chronicle.story(FAMILY)
        self.assertEqual((empty['chapter'], empty['events'], empty['chapters']), (0, [], []))
