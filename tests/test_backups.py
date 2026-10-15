from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import threading
import unittest
from unittest.mock import patch
import zipfile

from gameserver_manager.application import Application
from gameserver_manager.backups import WorldBackups, digest, member_name
from gameserver_manager.configuration import ManagerError, atomic_json
from gameserver_manager.instances import GameServerManager
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.security import AccessDenied, Principal
from test_manager import ManagerFixture, FakeBridge


class BackupTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.world = self.install / 'savegame'
        self.world.mkdir()
        (self.world / 'world0.ksd').write_bytes(b'world one\0\xff')
        (self.world / 'backup').mkdir()
        (self.world / 'backup/prior.ksd').write_bytes(b'previous')
        (self.world / 'empty').mkdir()
        self.store = self.manager.backups
        self.store.settle_seconds = 0

    def create(self, **kwargs):
        return self.store.create(**kwargs)['backup_id']

    def restore(self, identifier):
        preview = self.store.preview(identifier)
        return self.store.restore(identifier, preview['token'], preview['confirmation'])

    def test_full_world_exact_config_manifest_and_private_storage(self):
        identifier = self.create()
        record = self.store.metadata(identifier)
        with zipfile.ZipFile(self.store.folder / (identifier + '.zip')) as archive:
            self.assertEqual(archive.read('config/server'), self.manager.config_path.read_bytes())
            self.assertEqual(archive.read('world/world0.ksd'), b'world one\0\xff')
            self.assertEqual(archive.read('world/backup/prior.ksd'), b'previous')
            self.assertIn('world/empty', record['manifest']['directories'])
            manifest = json.loads(archive.read('manifest.json'))
            self.assertEqual(manifest, record['manifest'])
        view = self.store.view()
        self.assertEqual(view['retention_hours'], 72)
        self.assertEqual(view['backups'][0]['id'], identifier)
        for group in self.manager.config()['userGroups']:
            self.assertNotIn(group['password'], json.dumps(view))
        if os.name != 'nt':
            self.assertEqual((self.store.folder / (identifier + '.zip')).stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.store.verify(identifier)['message'], 'Alle Dateien und Prüfsummen der Weltsicherung bestätigt.')

    def test_running_launcher_and_unknown_state_are_rejected(self):
        self.bridge.running = True
        with self.assertRaises(ManagerError):
            self.create()
        for state in ({}, {'processes': []}, {'processes': [], 'launcher_running': True}):
            with patch.object(self.bridge, 'call', return_value=state), self.assertRaises(ManagerError):
                self.create()
        self.assertFalse(self.store.folder.exists())

    def test_unstable_source_does_not_publish(self):
        original = self.store.inventory
        calls = 0

        def changing(*args):
            nonlocal calls
            calls += 1
            if calls == 2:
                (self.world / 'world0.ksd').write_bytes(b'changed')
            return original(*args)

        with patch.object(self.store, 'inventory', side_effect=changing), self.assertRaises(ManagerError):
            self.create()
        self.assertFalse(self.store.view()['backups'])

    def test_no_pruning_on_disk_or_verification_failure(self):
        earlier = datetime.now(timezone.utc) - timedelta(days=4)
        with patch('gameserver_manager.backups.now', return_value=earlier):
            identifier = self.create()
        with patch.object(self.store, 'ensure_space', side_effect=ManagerError('voll')), self.assertRaises(ManagerError):
            self.create()
        with patch.object(self.store, '_inspect', side_effect=ManagerError('Prüfsumme')), self.assertRaises(ManagerError):
            self.create()
        self.assertTrue(self.store.metadata_path(identifier).exists())
        self.assertFalse(list(self.store.folder.glob('*.partial')))
        self.assertEqual(len(self.store.view()['backups']), 1)

    def test_retention_exactly_72_hours_and_pinned_preserved(self):
        current = datetime.now(timezone.utc)
        with patch('gameserver_manager.backups.now', return_value=current - timedelta(hours=73)):
            old = self.create()
            pinned = self.create(pinned=True)
        with patch('gameserver_manager.backups.now', return_value=current - timedelta(hours=72)):
            boundary = self.create()
        with patch('gameserver_manager.backups.now', return_value=current):
            newest = self.create()
        self.assertFalse(self.store.metadata_path(old).exists())
        self.assertFalse((self.store.folder / (old + '.zip')).exists())
        self.assertEqual({item['id'] for item in self.store.view()['backups']}, {pinned, boundary, newest})

    def change_world(self):
        (self.world / 'world0.ksd').write_bytes(b'world two')
        (self.world / 'newer-file').write_bytes(b'new')
        config = self.manager.config()
        config['name'] = 'Späterer Abend'
        self.manager.save_config(config)

    def test_restore_exact_tree_and_config_with_pinned_rollback(self):
        config = self.manager.config_path.read_bytes()
        identifier = self.create()
        self.change_world()
        current_config = self.manager.config_path.read_bytes()
        result = self.restore(identifier)
        self.assertFalse((self.world / 'newer-file').exists())
        self.assertTrue((self.world / 'empty').is_dir())
        self.assertEqual((self.world / 'world0.ksd').read_bytes(), b'world one\0\xff')
        self.assertEqual(self.manager.config_path.read_bytes(), config)
        before = self.store.metadata(result['before_backup_id'])
        self.assertTrue(before['pinned'])
        with zipfile.ZipFile(self.store.folder / (before['id'] + '.zip')) as archive:
            self.assertEqual(archive.read('world/world0.ksd'), b'world two')
            self.assertEqual(archive.read('config/server'), current_config)
        self.assertFalse(self.store.pending.exists())
        self.assertFalse(self.bridge.running)
        self.assertNotIn('Start', self.bridge.calls)

    def test_bad_confirmation_stale_target_and_other_profile_rejected(self):
        identifier = self.create()
        preview = self.store.preview(identifier)
        with self.assertRaises(ManagerError):
            self.store.restore(identifier, preview['token'], '')
        self.change_world()
        with self.assertRaises(ManagerError):
            self.store.restore(identifier, preview['token'], preview['confirmation'])
        self.manager.profile['id'] = 'another'
        with self.assertRaises(ManagerError):
            self.store.preview(identifier)
        self.assertEqual((self.world / 'world0.ksd').read_bytes(), b'world two')

    def test_corrupt_archive_never_touches_target_and_invalidates_verified_mark(self):
        identifier = self.create()
        with (self.store.folder / (identifier + '.zip')).open('ab') as stream:
            stream.write(b'corrupt')
        with self.assertRaises(ManagerError):
            self.restore(identifier)
        self.assertIsNone(self.store.metadata(identifier)['verified_at'])
        self.assertFalse(self.store.pending.exists())
        self.assertEqual((self.world / 'world0.ksd').read_bytes(), b'world one\0\xff')

    def test_unexpected_archive_entry_is_rejected_even_with_updated_archive_hash(self):
        identifier = self.create()
        path = self.store.folder / (identifier + '.zip')
        with zipfile.ZipFile(path, 'a') as archive:
            archive.writestr('world/../../outside', 'bad')
        record = self.store.metadata(identifier)
        record['sha256'] = digest(path)
        atomic_json(self.store.metadata_path(identifier), record)
        with self.assertRaises(ManagerError):
            self.restore(identifier)
        self.assertFalse((self.root / 'outside').exists())

    def test_failed_install_rolls_back_both_world_and_config(self):
        from gameserver_manager.backups import atomic_bytes
        identifier = self.create()
        self.change_world()
        current = self.manager.config_path.read_bytes()
        calls = 0

        def fail_once(*args):
            nonlocal calls
            calls += 1
            if calls == 1:
                raise OSError('Test failure after world replacement')
            return atomic_bytes(*args)

        with patch('gameserver_manager.backups.atomic_bytes', side_effect=fail_once), self.assertRaisesRegex(ManagerError, 'zurückgeholt'):
            self.restore(identifier)
        self.assertEqual((self.world / 'world0.ksd').read_bytes(), b'world two')
        self.assertEqual(self.manager.config_path.read_bytes(), current)
        self.assertFalse(self.store.pending.exists())

    def test_crash_journal_blocks_start_and_config_and_explicit_recovery_survives_restart(self):
        identifier = self.create()
        self.change_world()
        with patch('gameserver_manager.backups.atomic_bytes', side_effect=KeyboardInterrupt), self.assertRaises(KeyboardInterrupt):
            self.restore(identifier)
        self.manager.backups = self.store = WorldBackups(self.manager, settle_seconds=0)
        for action in (lambda: self.manager.action('start'), lambda: self.manager.action('restart'),
                       lambda: self.manager.save_config(self.manager.config()), self.manager.setup):
            with self.assertRaisesRegex(ManagerError, 'unterbrochen'):
                action()
        self.assertTrue(self.manager.status()['recovery_required'])
        preview = self.store.recovery_preview()
        with self.assertRaises(ManagerError):
            self.store.pin(preview['backup_id'], False)
        self.store.restore(preview['backup_id'], preview['token'], preview['confirmation'], recovery=True)
        self.assertEqual((self.world / 'world0.ksd').read_bytes(), b'world two')
        self.assertFalse(self.store.pending.exists())

    def test_links_and_overlapping_roots_rejected(self):
        target = self.root / 'outside'
        target.write_bytes(b'private')
        try:
            (self.world / 'link').symlink_to(target)
        except OSError:
            self.skipTest('Symlink creation unavailable in this Windows test context')
        with self.assertRaises(ManagerError):
            self.create()
        (self.world / 'link').unlink()
        for folder in (self.install, self.root, self.store.folder):
            config = self.manager.config()
            config['saveDirectory'] = str(folder)
            atomic_json(self.manager.config_path, config)
            with self.assertRaises(ManagerError):
                self.create()

    def test_after_stop_is_opt_in_and_restart_never_runs_after_backup_failure(self):
        self.assertFalse(self.store.view()['automatic_after_stop'])
        self.store.configure(True)
        self.bridge.running = True
        self.manager.action('stop')
        self.assertEqual(len(self.store.view()['backups']), 1)
        self.assertEqual(self.store.view()['backups'][0]['reason'], 'after_stop')
        self.bridge.running = True
        with patch.object(self.store, '_inspect', side_effect=ManagerError('checksum')), self.assertRaisesRegex(ManagerError, 'Server gestoppt'):
            self.manager.action('restart')
        self.assertFalse(self.bridge.running)
        self.assertNotIn('Start', self.bridge.calls)

    def test_aska_uses_native_profile_save_id_and_exact_external_config_and_preset(self):
        install = self.root / 'ASKA'
        install.mkdir()
        profile = self.root / 'Windows User'
        configuration = self.root / 'external/server properties.txt'
        configuration.parent.mkdir()
        preset = self.root / 'custom-preset.json'
        preset.write_bytes(b'custom preset')
        m = GameServerManager(self.root, FakeBridge(str(profile)), profile={
            'id': 'aska', 'game': 'aska', 'label': 'ASKA Bestandswelt', 'server_directory': str(install),
            'config_path': str(configuration), 'launcher_script': 'external-starter.ps1'})
        config = m.game.initial_config()
        config.update({'save id': 'ae44d_300926202259', 'custom game preset path': str(preset), 'authentication token': 'private-token'})
        m.game.write_config(configuration, config)
        world = profile / 'AppData/LocalLow/Sand Sailor Studio/Aska/data/savegame_ae44d_300926202259'
        world.mkdir(parents=True)
        (world / 'worldgen').write_bytes(b'aska world')
        m.backups.settle_seconds = 0
        identifier = m.backups.create()['backup_id']
        self.assertEqual(m.backups.metadata(identifier)['manifest']['binding']['world'], str(world))
        with zipfile.ZipFile(m.backups.folder / (identifier + '.zip')) as archive:
            self.assertEqual(archive.read('config/server'), configuration.read_bytes())
            self.assertEqual(archive.read('config/preset'), b'custom preset')
            self.assertEqual(archive.read('world/worldgen'), b'aska world')
        (world / 'worldgen').write_bytes(b'later')
        preview = m.backups.preview(identifier)
        m.backups.restore(identifier, preview['token'], preview['confirmation'])
        self.assertEqual((world / 'worldgen').read_bytes(), b'aska world')
        self.assertNotIn('private-token', json.dumps(m.backups.view()))

    def test_windows_archive_names(self):
        for name in ('../a', '/a', 'world/a:b', 'world\\x', 'world/con', 'world/NUL.txt', 'world/a.', 'world/a ', 'world/./a', 'world//a'):
            with self.subTest(name=name), self.assertRaises(ManagerError):
                member_name(name)

    def test_shared_world_targets_block_backups_and_start_but_can_be_repaired(self):
        registry = ServerRegistry(self.root, self.manager)
        directory = self.root / 'Second install'
        directory.mkdir()
        (directory / 'enshrouded_server.exe').touch()
        registry.register({'id': 'second', 'game': 'enshrouded', 'label': 'Second', 'server_directory': str(directory)})
        second = registry.get('second')
        second.bridge = FakeBridge()
        second.setup()
        config = second.config()
        config['saveDirectory'] = str(self.world)
        atomic_json(second.config_path, config)  # Simulate an existing external edit.
        with self.assertRaisesRegex(ManagerError, 'Mehrere Serverprofile'):
            self.create()
        with self.assertRaisesRegex(ManagerError, 'Mehrere Serverprofile'):
            second.action('start')
        config['saveDirectory'] = './savegame'
        second.save_config(config)
        self.create()

    def test_revoked_owner_cannot_execute_backup_worker(self):
        app = Application(ServerRegistry(self.root, self.manager))
        self.addCleanup(app.close)
        owner = Principal('local-owner', 'owner')
        body = {'login': 'owner@example.test', 'name': 'Remote owner', 'role': 'owner', 'server_ids': None, 'enabled': True}
        identifier = app.access.save_user(owner, body)['id']
        principal = app.access.principal(body['login'])
        user = next(user for user in app.access.view(owner)['users'] if user['id'] == identifier)
        app.access.save_user(owner, {**body, 'id': identifier, 'revision': user['revision'], 'enabled': False})
        job, future = app.submit(principal, 'backup', {'server_id': 'enshrouded'})
        with self.assertRaises(AccessDenied):
            future.result(timeout=3)
        self.assertEqual(app.jobs.get(job['id'])['state'], 'failed')
        self.assertFalse(self.store.folder.exists())

    def test_api_owner_only_and_backup_reserves_lifecycle_target(self):
        app = Application(ServerRegistry(self.root, self.manager))
        self.addCleanup(app.close)
        owner = Principal('local-owner', 'owner')
        family = Principal('family', 'family', frozenset({'enshrouded'}))
        for principal in (family, Principal('reader', 'viewer')):
            for operation in ('backup', 'backup_verify', 'backup_pin', 'backup_settings', 'backup_restore', 'backup_recover'):
                with self.assertRaises(AccessDenied):
                    app.submit(principal, operation, {'server_id': 'enshrouded'})
            with self.assertRaises(AccessDenied):
                app.backups(principal, 'enshrouded')
        entered, release = threading.Event(), threading.Event()
        original = self.store._create

        def gated(**kwargs):
            entered.set()
            if not release.wait(5):
                raise ManagerError('Test timeout')
            return original(**kwargs)

        try:
            with patch.object(self.store, '_create', side_effect=gated):
                _job, future = app.submit(owner, 'backup', {'server_id': 'enshrouded'})
                self.assertTrue(entered.wait(2))
                with self.assertRaisesRegex(ManagerError, 'bereits ein Auftrag'):
                    app.submit(owner, 'start', {'server_id': 'enshrouded'})
                release.set()
                self.assertIn('gesichert', future.result(timeout=5)['message'])
        finally:
            release.set()


if __name__ == '__main__':
    unittest.main()
