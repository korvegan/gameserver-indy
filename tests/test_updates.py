"""Steam evidence and 1.0 compatibility; only fixtures, no real Steam/game calls."""
from concurrent.futures import Future
from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import subprocess
import threading
import time
import urllib.error
import urllib.request
from unittest.mock import Mock, patch

from gameserver_manager.application import Application
from gameserver_manager.configuration import ManagerError, atomic_json
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.security import AccessDenied, Principal
from gameserver_manager.steam import SteamCmd, installed, named_object
from gameserver_manager.updates import UpdateService
from test_manager import HTTPFixture, ManagerFixture
from test_profiles import add_aska

OWNER = Principal('test-owner', 'owner')


def manifest(instance, build='100', branch='public', flags=4, extra=''):
    directory = instance.directory / 'steamapps'
    directory.mkdir(exist_ok=True)
    path = directory / f'appmanifest_{instance.game.schema["steam_server_app_id"]}.acf'
    path.write_text(f'''"AppState" {{
      "appid" "{instance.game.schema['steam_server_app_id']}" "installdir" "{instance.directory.name}"
      "StateFlags" "{flags}" "buildid" "{build}" "LastUpdated" "{int(time.time())}"
      "UserConfig" {{"BetaKey" "{branch}"}} {extra}
    }}''', encoding='utf-8')
    return path


class UpdateTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.registry = ServerRegistry(self.root, self.manager)
        self.aska = add_aska(self.registry, self.root)
        self.provider = Mock()
        self.provider.query.return_value = '100'
        self.updates = UpdateService(self.registry, self.provider)
        self.addCleanup(self.updates.close)
        self.manifest = manifest(self.manager)

    def test_current_and_different_build_use_equality_not_numerical_order(self):
        result = self.updates.check(self.manager)['update']
        self.assertEqual(result['state'], 'current')
        self.assertEqual(result['available_build'], '100')
        self.provider.query.assert_called_once_with(2278520, 'public')
        manifest(self.manager, build='999')
        self.assertEqual(self.updates.view(self.manager)['state'], 'update_available')
        self.assertIsNotNone(result['checked_at'])

    def test_local_only_missing_or_mismatched_manifest_never_claims_current(self):
        self.assertEqual(self.updates.view(self.manager)['installed_build'], '100')
        self.assertEqual(self.updates.view(self.manager)['state'], 'unknown')
        self.updates.check(self.manager)
        for text in ('"AppState" {', self.manifest.read_text().replace('2278520', '1203620'),
                     self.manifest.read_text().replace(self.manager.directory.name, 'other server')):
            with self.subTest(text=text[:30]):
                self.manifest.write_text(text)
                self.assertEqual(self.updates.view(self.manager)['state'], 'unknown')
        self.manifest.unlink()
        self.assertIsNone(self.updates.view(self.manager)['installed_build'])

    def test_client_library_must_match_exact_install_not_only_folder_name(self):
        self.manifest.unlink()
        library = self.root / 'Steam Library' / 'steamapps'
        install = library / 'common' / self.manager.directory.name
        install.mkdir(parents=True)
        path = library / 'appmanifest_2278520.acf'
        path.write_text(f'"AppState" {{ "appid" "2278520" "installdir" "{install.name}" "buildid" "100" "StateFlags" "4" }}')
        self.manager.profile['steam_manifest'] = str(path)
        with self.assertRaisesRegex(ManagerError, 'stimmen nicht'):
            installed(self.manager)
        self.manager.directory = install
        self.assertEqual(installed(self.manager)['build'], '100')

    def test_alternative_branch_compares_only_matching_remote_branch(self):
        self.updates.check(self.manager)
        manifest(self.manager, branch='preview')
        self.assertEqual(self.updates.view(self.manager)['state'], 'unknown')
        self.manager.profile['steam_branch'] = 'public'
        self.assertIsNone(self.updates.view(self.manager)['installed_build'])
        self.manager.profile['steam_branch'] = 'preview'
        self.updates.entries.clear()
        self.updates.check(self.manager)
        self.provider.query.assert_called_with(2278520, 'preview')
        self.assertEqual(self.updates.view(self.manager)['state'], 'current')

    def test_download_and_incomplete_install_are_not_current(self):
        self.updates.check(self.manager)
        manifest(self.manager, flags=1048580, extra='"BytesToDownload" "200" "BytesDownloaded" "100"')
        self.assertEqual(self.updates.view(self.manager)['state'], 'updating')
        manifest(self.manager, flags=2)
        self.assertEqual(self.updates.view(self.manager)['state'], 'unknown')
        manifest(self.manager, flags=4, extra='"TargetBuildID" "200"')
        self.assertEqual(self.updates.view(self.manager)['state'], 'unknown')
        manifest(self.manager, flags=4, extra='"BytesToDownload" "200" "BytesDownloaded" "200"')
        self.assertEqual(self.updates.view(self.manager)['state'], 'current')

    def test_offline_and_old_cache_are_stale_and_keep_last_successful_evidence(self):
        self.updates.check(self.manager)
        entry = self.updates.entries['enshrouded']
        entry['last_attempt'] = '2000-01-01T00:00:00+00:00'
        self.provider.query.side_effect = ManagerError('Steam offline.')
        with self.assertRaisesRegex(ManagerError, 'offline'):
            self.updates.check(self.manager)
        view = self.updates.view(self.manager)
        self.assertEqual(view['state'], 'stale')
        self.assertEqual(view['available_build'], '100')
        self.assertIsNotNone(view['checked_at'])
        entry.pop('error')
        entry['checked_at'] = (datetime.now(timezone.utc) - timedelta(hours=2)).isoformat()
        self.assertEqual(self.updates.view(self.manager)['state'], 'stale')

    def test_first_offline_check_is_unknown_and_does_not_invent_version(self):
        self.provider.query.side_effect = ManagerError('Steam offline.')
        with self.assertRaises(ManagerError):
            self.updates.check(self.manager)
        self.assertEqual(self.updates.view(self.manager)['state'], 'unknown')
        self.assertIsNone(self.updates.view(self.manager)['available_build'])

    def test_io_failure_marks_previous_success_stale_and_future_attempt_does_not_block(self):
        self.updates.check(self.manager)
        self.updates.entries['enshrouded']['last_attempt'] = '2999-01-01T00:00:00+00:00'
        self.provider.query.side_effect = OSError('sensitive raw path')
        with self.assertRaises(ManagerError):
            self.updates.check(self.manager)
        view = self.updates.view(self.manager)
        self.assertEqual(view['state'], 'stale')
        self.assertNotIn('sensitive raw path', json.dumps(view))

    def test_cache_throttle_restart_and_read_only_input_files(self):
        files = [self.manifest, self.manager.config_path, self.aska.config_path, self.registry.path]
        before = {path: path.read_bytes() for path in files}
        self.updates.check(self.manager)
        self.updates.check(self.manager)
        self.provider.query.assert_called_once()
        restored = UpdateService(self.registry, self.provider)
        self.assertEqual(restored.view(self.manager)['state'], 'current')
        self.assertEqual(before, {path: path.read_bytes() for path in files})
        raw = self.updates.path.read_text()
        for group in self.manager.config()['userGroups']:
            self.assertNotIn(group['password'], raw)

    def test_invalid_cache_shapes_are_discarded_without_breaking_status(self):
        for data in ([], None, 'unexpected', {'schema_version': 1, 'entries': []},
                     {'schema_version': 1, 'entries': {'enshrouded': []}}):
            with self.subTest(data=data):
                atomic_json(self.updates.path, data)
                service = UpdateService(self.registry, self.provider)
                self.assertEqual(service.view(self.manager)['state'], 'unknown')

    def test_missing_negative_or_malformed_install_state_is_unknown(self):
        for extra in ('', '"StateFlags" "-1"', '"StateFlags" "not a number"', '"StateFlags" "4" "BytesToDownload" "-10"'):
            self.manifest.write_text(f'"AppState" {{"appid" "2278520" "installdir" "{self.manager.directory.name}" "buildid" "100" {extra}}}')
            self.assertEqual(self.updates.view(self.manager)['state'], 'unknown')

    def test_running_process_build_is_not_inferred_from_updated_files(self):
        self.updates.check(self.manager)
        status = {'running': True, 'processes': [{'pid': 42, 'started': '2000-01-01T00:00:00Z'}]}
        view = self.updates.view(self.manager, status)
        self.assertEqual(view['state'], 'current')  # On disk only.
        self.assertEqual(view['process_state'], 'restart_needed')
        self.assertNotIn('running_build', view)
        status['processes'][0]['started'] = '2999-01-01T00:00:00Z'
        self.assertEqual(self.updates.view(self.manager, status)['process_state'], 'unknown')
        status['running'] = False
        self.assertEqual(self.updates.view(self.manager, status)['process_state'], 'stopped')

    def test_documented_version_is_separate_from_steam_build(self):
        (self.manager.directory / 'enshrouded_server_readme.txt').write_text('Version: 1.0.0.0\n')
        view = self.updates.view(self.manager)
        self.assertEqual(view['version'], '1.0.0.0')
        self.assertEqual(view['version_source'], 'Mitgelieferte Serveranleitung')
        self.assertEqual(view['state'], 'unknown')

    def test_update_job_does_not_block_game_action_or_status_and_enforces_rights(self):
        app = Application(self.registry)
        self.addCleanup(app.close)
        app.updates = self.updates
        entered, release = threading.Event(), threading.Event()
        def query(*_args):
            entered.set()
            if not release.wait(5):
                raise ManagerError('Test timeout.')
            return '100'
        self.provider.query.side_effect = query
        job, future = app.check_updates(OWNER, 'enshrouded')
        try:
            self.assertTrue(entered.wait(2))
            self.assertEqual(job['server_id'], 'enshrouded')
            self.assertEqual(app.status(OWNER, 'enshrouded')['update']['state'], 'unknown')
            with self.assertRaises(ManagerError):
                app.check_updates(OWNER, 'enshrouded')
            for role in ('family', 'viewer'):
                with self.assertRaises(AccessDenied):
                    app.check_updates(Principal('guest', role), 'enshrouded')
            _job, action = app.submit(OWNER, 'start', {'server_id': 'enshrouded'})
            action.result(timeout=2)
            self.assertTrue(self.bridge.running)
            _job, other = app.submit(OWNER, 'start', {'server_id': 'aska'})
            other.result(timeout=2)
            self.assertTrue(self.aska.bridge.running)
            self.assertFalse(future.done())
        finally:
            release.set()
            future.result(timeout=2)

    def test_periodic_checks_visit_profiles_and_shutdown_promptly(self):
        self.updates.startup_delay = .01
        self.updates.interval = .02
        visited = []
        complete = threading.Event()
        def callback(server_id):
            visited.append(server_id)
            if len(visited) == 2:
                complete.set()
            future = Future(); future.set_result({})
            return {}, future
        self.updates.start(callback)
        self.assertTrue(complete.wait(1))
        self.updates.close()
        self.assertEqual(set(visited), {'enshrouded', 'aska'})
        self.assertFalse(self.updates.thread.is_alive())

    def test_10_setting_validation_preserves_legacy_and_unknown_fields(self):
        config = self.manager.config()
        config['gameSettings'].pop('flameLevelFogRemoval')
        config['gameSettings']['futureOption'] = {'keep': True}
        atomic_json(self.manager.config_path, config)
        original = self.manager.config_path.read_bytes()
        self.manager.setup()
        self.assertEqual(self.manager.config_path.read_bytes(), original)
        for value in (-1, 21, True, 11.5, '11'):
            config['gameSettings']['flameLevelFogRemoval'] = value
            with self.subTest(value=value), self.assertRaises(ManagerError):
                self.manager.save_config(config)
        config['gameSettings']['flameLevelFogRemoval'] = 11
        config['gameSettings']['randomSpawnerAmount'] = 'Automatic'
        result = self.manager.save_config(config)
        self.assertEqual((self.manager.data / 'backups' / result['backup']).read_bytes(), original)
        self.assertTrue(self.manager.config()['gameSettings']['futureOption']['keep'])


class SteamCmdTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.executable = self.root / 'Steam CMD' / 'steamcmd.exe'
        self.executable.parent.mkdir()
        self.executable.touch()
        self.provider = SteamCmd({'steamcmd_path': str(self.executable)})
        self.output = 'Connecting anonymously to Steam Public...OK\n"2278520" { "depots" { "branches" { "public" { "buildid" "100" } "preview" { "buildid" "90" } } } }\n'

    def run_stub(self, arguments, **kwargs):
        kwargs['stdout'].write(self.output.encode())
        self.arguments = arguments
        return subprocess.CompletedProcess(arguments, 0)

    def test_anonymous_fixed_commands_parse_requested_app_and_branch_only(self):
        with patch('gameserver_manager.steam.subprocess.run', side_effect=self.run_stub):
            self.assertEqual(self.provider.query(2278520, 'preview'), '90')
        self.assertEqual(self.arguments[1:], ['+login', 'anonymous', '+app_info_update', '1', '+app_info_print', '2278520', '+quit'])
        with self.assertRaises(ManagerError):
            named_object(self.output, 3246670)

    def test_offline_cached_output_and_missing_branch_fail_closed(self):
        original = self.output
        for output, branch in ((original.replace('...OK', '...FAILED'), 'public'), (original, 'private')):
            self.output = output
            with patch('gameserver_manager.steam.subprocess.run', side_effect=self.run_stub), self.assertRaises(ManagerError):
                self.provider.query(2278520, branch)

    def test_timeout_releases_provider_lock_and_hides_raw_command_errors(self):
        with patch('gameserver_manager.steam.subprocess.run', side_effect=subprocess.TimeoutExpired('private command', 45)), self.assertRaisesRegex(ManagerError, '45 Sekunden'):
            self.provider.query(2278520, 'public')
        self.assertTrue(self.provider.lock.acquire(blocking=False))
        self.provider.lock.release()

    def test_keyvalues_duplicates_and_truncation_are_rejected(self):
        for text in ('"AppState" {"buildid" "1" "BuildID" "2"}', '"AppState" {"buildid" "1"',
                     '"AppState" {}\n"AppState" {}'):
            with self.subTest(text=text), self.assertRaises(ManagerError):
                named_object(text, 'AppState')


class UpdateHTTPTests(HTTPFixture):
    def test_update_check_is_async_csrf_protected_and_owner_only(self):
        self.server.app.updates.provider = Mock()
        self.server.app.updates.provider.query.return_value = '100'
        manifest(self.manager)
        with self.assertRaises(urllib.error.HTTPError) as rejected:
            self.post('/api/updates/check', {})
        self.assertEqual(rejected.exception.code, 403)
        rejected.exception.close()
        self.server.identity = lambda _request: Principal('test-family', 'family')
        with self.assertRaises(urllib.error.HTTPError) as rejected:
            self.post('/api/updates/check', {'server_id': 'enshrouded'}, self.server.token)
        self.assertEqual(rejected.exception.code, 403)
        rejected.exception.close()
        self.server.app.updates.provider.query.assert_not_called()
        self.server.identity = lambda _request: OWNER
        with self.post('/api/updates/check', {'server_id': 'enshrouded'}, self.server.token) as response:
            self.assertEqual(response.status, 202)
            job = json.load(response)['job']
        for _ in range(100):
            result = self.server.app.jobs.get(job['id'])
            if result['state'] not in ('queued', 'running'):
                break
            time.sleep(.01)
        self.assertEqual(result['state'], 'succeeded')
        with urllib.request.urlopen(self.url + '/api/status') as response:
            self.assertEqual(json.load(response)['update']['state'], 'current')
