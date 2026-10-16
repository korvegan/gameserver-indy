"""All WhatsApp tests use local fixtures; no personal accounts or messages."""
import io
import importlib.util
import json
import sqlite3
import threading
import urllib.error
import urllib.request
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import Mock, patch

from gameserver_manager.configuration import ManagerError, atomic_json
from gameserver_manager.readiness import process_identity, readiness
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.security import Principal
from gameserver_manager.whatsapp import WhatsAppService, local_whatsapp_owner, short_message
from gameserver_manager.whatsapp_client import LocalWhatsAppClient, WhatsAppError
from test_manager import HTTPFixture, ManagerFixture
from test_profiles import add_aska

STARTED = '2024-01-01T10:00:00.250000Z'
STATUS = {'running': True, 'processes': [{'pid': 42, 'started': STARTED}]}
GROUP = '123456789@g.us'


def client():
    result = Mock(configured=True)
    result.session.return_value = {'status': 'WORKING', 'exists': True}
    result.groups.return_value = [{'id': GROUP, 'label': 'Testgruppe'}, {'id': '987654321@g.us', 'label': 'Andere Gruppe'}]
    return result


class ReadinessTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.logs = self.install / 'logs'
        self.logs.mkdir()
        self.log = self.logs / 'enshrouded_server.log'

    def test_enshrouded_uses_current_start_header_and_latest_transition(self):
        self.log.write_text("Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n")
        self.assertEqual(readiness(self.manager, STATUS)['state'], 'ready')
        for text in (
            "Current UTC date: 2023.12.31 10:00:00\n[Session] 'HostOnline' (up)!\n",
            "[Session] 'HostOnline' (up)!\nCurrent UTC date: 2024.01.01 10:00:00\n",
            "Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n[Session] 'HostOnline' (down)!\n",
            "Current UTC date: 2024.01.01 10:00:00\njust a running process\n",
        ):
            self.log.write_text(text)
            self.assertEqual(readiness(self.manager, STATUS)['state'], 'unknown')

    def test_missing_ambiguous_future_process_and_logs_fail_closed(self):
        self.assertEqual(readiness(self.manager, {'processes': []})['state'], 'stopped')
        self.assertEqual(readiness(self.manager, {'starting': True})['state'], 'starting')
        self.assertIsNone(process_identity({'processes': [{'pid': 42}]}))
        self.assertIsNone(process_identity({'processes': [STATUS['processes'][0]] * 2}))
        self.assertIsNone(process_identity({'processes': [{'pid': 42, 'started': '2999-01-01T00:00:00Z'}]}))
        self.assertEqual(readiness(self.manager, STATUS)['state'], 'unknown')

    def test_large_log_gap_never_reuses_up_before_an_unseen_down(self):
        prefix = "Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n"
        middle = 'routine line\n' * 25000
        self.log.write_text(prefix + middle + "[Session] 'HostOnline' (down)!\n" + middle)
        self.assertEqual(readiness(self.manager, STATUS)['state'], 'unknown')
        with self.log.open('a') as file:
            file.write("[Session] 'HostOnline' (up)!\n")
        self.assertEqual(readiness(self.manager, STATUS)['state'], 'ready')

    def test_contiguous_read_boundary_does_not_split_down_transition(self):
        prefix = b"Current UTC date: 2024.01.01 10:00:00\n[Session] 'HostOnline' (up)!\n"
        offset = 262144 - 11
        self.log.write_bytes(prefix + b'x' * (offset - len(prefix) - 1) + b'\n'
                             + b"[Session] 'HostOnline' (down)!\n")
        self.assertEqual(readiness(self.manager, STATUS)['state'], 'unknown')

    def test_aska_only_reads_timestamped_sanitized_owned_log_for_current_start(self):
        registry = ServerRegistry(self.root, self.manager)
        aska = add_aska(registry, self.root)
        aska.diagnostic_directory.mkdir(parents=True)
        path = aska.diagnostic_directory / 'server.log'
        path.write_text('2024-01-01T09:59:59Z The session is now open!\n')
        self.assertEqual(readiness(aska, STATUS)['state'], 'unknown')
        path.write_text('2024-01-01T10:00:01Z The session is now open!\n')
        self.assertEqual(readiness(aska, STATUS)['state'], 'ready')
        self.assertNotIn('private-password', json.dumps(readiness(aska, STATUS)))


class WhatsAppTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.registry = ServerRegistry(self.root, self.manager)
        self.registry.save_connection('enshrouded', 'group.example.org')
        self.bridge.call = Mock(return_value=STATUS)
        self.client = client()
        self.probe = Mock(return_value={'state': 'ready', 'label': 'Sitzung bereit', 'process_key': '42:' + STARTED})
        self.service = WhatsAppService(self.registry, self.client, self.probe)
        self.addCleanup(self.service.close)
        self.body = {'group_id': GROUP, 'enabled': True, 'server_ids': ['enshrouded'], 'revision': 0}

    def test_no_send_before_explicit_group_and_profiles_then_short_safe_message(self):
        self.service.tick(); self.client.send.assert_not_called()
        self.service.configure(self.body)
        self.client.send.assert_not_called()  # configuration itself has no network side effect
        self.service.tick()
        self.client.send.assert_called_once()
        target, text = self.client.send.call_args.args
        self.assertEqual(target, GROUP)
        self.assertEqual(len(text.splitlines()), 3)
        self.assertIn('group.example.org:15637', text)
        self.assertIn('Bereit zum Beitritt', text)
        self.assertNotIn('127.0.0.1', text)
        for group in self.manager.config()['userGroups']:
            self.assertNotIn(group['password'], text)

    def test_durable_dedup_and_unknown_delivery_are_never_replayed(self):
        self.service.configure(self.body)
        self.service.tick(); self.service.tick()
        reloaded = WhatsAppService(self.registry, self.client, self.probe)
        self.addCleanup(reloaded.close)
        reloaded.tick(); self.client.send.assert_called_once()
        with self.service.connection() as db:
            db.execute("DELETE FROM deliveries")
        self.client.send.side_effect = ManagerError('Transport failed')
        self.service.tick(); self.service.tick()
        self.assertEqual(self.client.send.call_count, 2)
        self.assertEqual(self.service.view()['previews'][0]['delivery'], 'uncertain')
        with self.service.connection() as db:
            db.execute("UPDATE deliveries SET state='sending'")
        restarted = WhatsAppService(self.registry, self.client, self.probe)
        self.addCleanup(restarted.close)
        restarted.tick(); self.assertEqual(self.client.send.call_count, 2)

    def test_target_and_authorization_changes_block_stale_automatic_work(self):
        self.service.configure(self.body)
        old = self.service.settings()['revision']
        self.service.configure({**self.body, 'enabled': False, 'revision': old})
        with self.assertRaises(ManagerError): self.service.send_current(self.manager, automatic=True, revision=old)
        self.client.send.assert_not_called()
        self.service.configure({**self.body, 'revision': self.service.settings()['revision']})
        self.bridge.call.side_effect = [STATUS, {'processes': [{'pid': 43, 'started': STARTED}]}]
        with self.assertRaises(ManagerError): self.service.send_current(self.manager)
        self.client.send.assert_not_called()

    def test_not_ready_offline_invalid_target_revision_and_shutdown_do_not_send(self):
        self.service.configure(self.body)
        self.probe.return_value = {'state': 'unknown', 'label': 'Unbekannt'}
        self.service.tick(); self.client.send.assert_not_called()
        with self.assertRaises(ManagerError): self.service.configure({**self.body, 'revision': 0})
        with self.assertRaises(ManagerError): self.service.configure({**self.body, 'group_id': '12345@c.us', 'revision': 1})
        with self.assertRaises(ManagerError): self.service.configure({**self.body, 'server_ids': ['other'], 'revision': 1})
        self.service.close(); self.service.tick(); self.client.send.assert_not_called()

    def test_manual_send_cannot_retarget_after_group_change_during_probe(self):
        self.service.configure(self.body)
        revision = self.service.settings()['revision']
        entered, release = threading.Event(), threading.Event()
        ready = self.probe.return_value
        def blocked_probe(*_):
            entered.set()
            if not release.wait(5):
                raise AssertionError('Probe was not released')
            return ready
        self.probe.side_effect = blocked_probe
        errors = []
        def send():
            try:
                self.service.send_current(self.manager, revision=revision)
            except ManagerError as error:
                errors.append(error)
        worker = threading.Thread(target=send)
        worker.start()
        try:
            self.assertTrue(entered.wait(5))
            self.service.configure({**self.body, 'group_id': '987654321@g.us', 'revision': revision})
        finally:
            release.set(); worker.join(5)
        self.assertFalse(worker.is_alive())
        self.assertEqual(len(errors), 1)
        self.client.send.assert_not_called()

    def test_shutdown_during_final_native_status_check_blocks_delivery(self):
        self.service.configure(self.body)
        calls = 0
        def status(*_):
            nonlocal calls
            calls += 1
            if calls == 2:
                self.service.close()
            return STATUS
        self.bridge.call.side_effect = status
        with self.assertRaises(ManagerError):
            self.service.send_current(self.manager)
        self.client.send.assert_not_called()

    def test_final_storage_failure_keeps_durable_claim_and_uncertain_view(self):
        self.service.configure(self.body)
        original = self.service.connection
        calls = 0
        @contextmanager
        def failed_finalization():
            nonlocal calls
            calls += 1
            if calls == 2:
                raise sqlite3.OperationalError('fixture storage unavailable')
            with original() as db:
                yield db
        with patch.object(self.service, 'connection', failed_finalization):
            with self.assertRaises(sqlite3.Error):
                self.service.send_current(self.manager)
        self.client.send.assert_called_once()
        self.assertEqual(self.service.view()['previews'][0]['delivery'], 'uncertain')
        with self.assertRaises(ManagerError):
            self.service.send_current(self.manager)
        restarted = WhatsAppService(self.registry, self.client, self.probe)
        self.addCleanup(restarted.close)
        restarted.tick()
        self.client.send.assert_called_once()

    def test_message_has_no_invented_aska_endpoint_and_disconnect_disables_first(self):
        aska = add_aska(self.registry, self.root)
        text = short_message(aska, aska.config(), True)
        self.assertIn('Serverliste', text)
        self.assertNotIn('27016', text)
        self.assertNotIn('private-password', text)
        self.service.configure(self.body)
        self.client.logout.side_effect = ManagerError('offline')
        with self.assertRaises(ManagerError): self.service.disconnect()
        self.assertFalse(self.service.settings()['enabled'])
        self.assertEqual(self.service.settings()['group_id'], '')


class WhatsAppTransportTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.client = LocalWhatsAppClient(self.root)
        atomic_json(self.client.credentials, {'api_key': 'a' * 64})

    def test_fixed_loopback_key_bounded_request_and_no_raw_response_errors(self):
        response = Mock()
        response.__enter__ = Mock(return_value=response); response.__exit__ = Mock(return_value=False)
        response.read.return_value = b'{"id":"test-message"}'
        self.client.opener = Mock()
        self.client.opener.open.return_value = response
        self.client.send(GROUP, 'Enshrouded · Test\ngroup.example.org:15637\nReady')
        request = self.client.opener.open.call_args.args[0]
        self.assertEqual(request.full_url, 'http://127.0.0.1:18768/api/sendText')
        payload = json.loads(request.data)
        self.assertEqual(payload['chatId'], GROUP)
        self.assertFalse(payload['linkPreview'])
        self.assertEqual(request.get_header('X-api-key'), 'a' * 64)
        response.read.assert_called_with(2 * 1024 * 1024 + 1)
        self.client.opener.open.side_effect = urllib.error.HTTPError(request.full_url, 500, 'private-key', {}, io.BytesIO(b'private'))
        with self.assertRaises(WhatsAppError) as error: self.client.send(GROUP, 'Test')
        self.assertNotIn('private', str(error.exception))
        with self.assertRaises(ManagerError): self.client.send('12345@c.us', 'Test')

    def test_projects_both_gows_and_webjs_groups_without_participants_or_history(self):
        self.client.request = Mock(side_effect=[{'status': 'WORKING'}, [
            {'JID': GROUP, 'Name': 'Testgruppe', 'Participants': ['private']},
            {'id': {'_serialized': '987654321@g.us'}, 'subject': 'Testgruppe', 'participants': ['private']},
            {'id': '123456@c.us', 'name': 'Private chat'},
        ]])
        groups = self.client.groups()
        self.assertEqual(len(groups), 2)
        self.assertEqual({group['label'] for group in groups}, {'Testgruppe'})
        self.assertNotIn('private', json.dumps(groups))
        self.assertIn('exclude=participants', self.client.request.call_args.args[0])


class WhatsAppInstallerTests(ManagerFixture):
    def test_existing_container_from_other_checkout_is_not_adopted_or_changed(self):
        spec = importlib.util.spec_from_file_location('whatsapp_installer', Path(__file__).resolve().parents[1] / 'scripts' / 'install-whatsapp.py')
        installer = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(installer)
        container = {
            'Config': {'Labels': {'io.gameserver-indy.service': 'whatsapp',
                                  'com.docker.compose.project': 'gameserver-indy-whatsapp'}},
            'HostConfig': {'PortBindings': {'3000/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '18768'}]}},
            'Mounts': [{'Type': 'bind', 'Destination': '/app/.sessions', 'Source': '/another-app/private/sessions'}],
        }
        with patch.object(installer.subprocess, 'run', return_value=Mock(returncode=0, stdout=json.dumps([container]))) as run:
            with self.assertRaises(RuntimeError):
                installer.install(self.root)
            run.assert_called_once()
        self.assertFalse((self.root / 'data' / 'whatsapp' / 'credentials.json').exists())


class WhatsAppHTTPTests(HTTPFixture):
    def setUp(self):
        super().setUp()
        self.server.app.whatsapp.client = client()

    def test_personal_setup_is_local_owner_only_and_post_requires_csrf(self):
        for principal in (Principal('family', 'family'), Principal('relative', 'owner'),
                          Principal('local-owner', 'owner', frozenset({'enshrouded'}))):
            self.server.identity = lambda _, p=principal: p
            for path in ('/api/whatsapp', '/api/whatsapp/groups', '/api/whatsapp/qr'):
                with self.assertRaises(urllib.error.HTTPError) as error: urllib.request.urlopen(self.url + path)
                self.assertEqual(error.exception.code, 403); error.exception.close()
        self.server.identity = lambda _: Principal('local-owner', 'owner')
        with self.assertRaises(urllib.error.HTTPError) as error: self.post('/api/whatsapp/connect', {})
        self.assertEqual(error.exception.code, 403); error.exception.close()
        self.server.app.whatsapp.client.connect.assert_not_called()
        with self.post('/api/whatsapp/connect', {}, self.server.token) as response: self.assertEqual(response.status, 200)
        self.server.app.whatsapp.client.connect.assert_called_once()
        with urllib.request.urlopen(self.url + '/api/whatsapp') as response:
            self.assertEqual(response.headers['Cache-Control'], 'no-store')
            result = json.load(response)
        self.assertNotIn('api_key', json.dumps(result))
