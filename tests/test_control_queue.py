import json
import os
import threading
from urllib.request import Request, urlopen
from unittest.mock import patch

from gameserver_manager.configuration import ManagerError
from gameserver_manager.sessions import signing_token
from gameserver_manager.server import LocalServer
from gameserver_manager.security import Principal
from gameserver_manager.application import Application
from gameserver_manager.registry import ServerRegistry
from test_manager import ManagerFixture
from test_platform import OWNER
from test_profiles import add_aska


class ControlQueueTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.registry = ServerRegistry(self.root, self.manager)
        add_aska(self.registry, self.root)
        self.app = Application(self.registry)
        self.addCleanup(self.app.close)

    def test_commands_execute_in_fifo_order_without_blocking_another_profile(self):
        entered, release = threading.Event(), threading.Event()
        order = []

        def first():
            order.append('first')
            entered.set()
            if not release.wait(3):
                raise ManagerError('test timeout')
            return {'message': 'first done'}

        job, first_future = self.app.jobs.submit('enshrouded', 'start', OWNER.id, first, enqueue=True)
        try:
            self.assertTrue(entered.wait(1))
            waiting = []
            for position, operation in enumerate(('stop', 'start', 'restart'), 1):
                item, future = self.app.jobs.submit('enshrouded', operation, OWNER.id,
                    lambda op=operation: (order.append(op) or {'message': op}), enqueue=True)
                self.assertEqual(item['state'], 'queued')
                self.assertEqual(item['queue_position'], position)
                waiting.append(future)
            _other, independent = self.app.jobs.submit('aska', 'start', OWNER.id, lambda: {'message': 'independent'}, enqueue=True)
            self.assertEqual(independent.result(timeout=1)['message'], 'independent')
            self.assertEqual(order, ['first'])
        finally:
            release.set()
        first_future.result(timeout=2)
        for future in waiting:
            future.result(timeout=2)
        self.assertEqual(order, ['first', 'stop', 'start', 'restart'])
        self.assertFalse(self.app.jobs.active)

    def test_predecessor_failure_does_not_blindly_execute_queued_commands(self):
        entered, release = threading.Event(), threading.Event()
        def failing():
            entered.set()
            release.wait(3)
            raise ManagerError('failed backup')
        job, first = self.app.jobs.submit('enshrouded', 'backup', OWNER.id, failing)
        try:
            self.assertTrue(entered.wait(1))
            with patch.object(self.manager, 'action') as action:
                item, queued = self.app.submit(OWNER, 'start', {'server_id': 'enshrouded'})
                release.set()
                with self.assertRaises(ManagerError):
                    first.result(timeout=2)
                with self.assertRaisesRegex(ManagerError, 'vorheriger Auftrag'):
                    queued.result(timeout=2)
                action.assert_not_called()
            self.assertEqual(self.app.jobs.get(item['id'])['state'], 'interrupted')
        finally:
            release.set()

    def test_queue_capacity_and_shutdown_never_replay_waiting_work(self):
        entered, release = threading.Event(), threading.Event()
        def first():
            entered.set(); release.wait(3)
            return {'message': 'done'}
        _first, active = self.app.jobs.submit('enshrouded', 'start', OWNER.id, first, enqueue=True)
        self.assertTrue(entered.wait(1))
        futures = []
        with patch.object(self.manager, 'action') as action:
            for _ in range(7):
                _item, future = self.app.submit(OWNER, 'stop', {'server_id': 'enshrouded'})
                futures.append(future)
            with self.assertRaisesRegex(ManagerError, 'sieben Befehle'):
                self.app.submit(OWNER, 'start', {'server_id': 'enshrouded'})
            with self.app.jobs.lock:
                self.app.jobs.closed = True
            release.set(); active.result(timeout=2)
            for future in futures:
                with self.assertRaisesRegex(ManagerError, 'beendet'):
                    future.result(timeout=2)
            action.assert_not_called()

    def test_queued_owner_command_rechecks_changed_grant_when_it_executes(self):
        body = {'login': 'remote@example.test', 'name': 'Remote', 'role': 'owner', 'server_ids': None, 'enabled': True}
        user_id = self.app.access.save_user(OWNER, body)['id']
        principal = self.app.access.principal(body['login'])
        entered, release = threading.Event(), threading.Event()
        def first():
            entered.set(); release.wait(3)
            return {'message': 'done'}
        _item, active = self.app.jobs.submit('enshrouded', 'backup', OWNER.id, first)
        try:
            self.assertTrue(entered.wait(1))
            with patch.object(self.manager, 'action') as action:
                job, future = self.app.submit(principal, 'stop', {'server_id': 'enshrouded'})
                user = next(user for user in self.app.access.view(OWNER)['users'] if user['id'] == user_id)
                self.app.access.save_user(OWNER, {**body, 'id': user_id, 'revision': user['revision'], 'enabled': False})
                release.set(); active.result(timeout=2)
                with self.assertRaises(ManagerError):
                    future.result(timeout=2)
                action.assert_not_called()
            self.assertEqual(self.app.jobs.get(job['id'])['state'], 'failed')
        finally:
            release.set()

    def test_signing_key_survives_listener_restart_and_is_scoped(self):
        local = signing_token(self.root, 'local-owner')
        family = signing_token(self.root, 'family:indy.example.ts.net')
        self.assertEqual(local, signing_token(self.root, 'local-owner'))
        self.assertNotEqual(local, family)
        server = LocalServer(self.registry, 0)
        token = server.token
        server.server_close()
        replacement = LocalServer(self.registry, 0)
        try:
            self.assertEqual(token, replacement.token)
        finally:
            replacement.server_close()
        if os.name != 'nt':
            self.assertEqual((self.root / 'data/session-key').stat().st_mode & 0o777, 0o600)
        (self.root / 'data/session-key').write_text('broken')
        with self.assertRaises(ManagerError):
            signing_token(self.root, 'local-owner')
        self.assertEqual((self.root / 'data/session-key').read_text(), 'broken')

    def test_open_browser_token_remains_valid_for_commands_after_manager_restart(self):
        first = LocalServer(self.registry, 0)
        previous_token = first.token
        first.server_close()
        replacement = LocalServer(self.registry, 0)
        thread = threading.Thread(target=replacement.serve_forever, daemon=True)
        thread.start()
        try:
            url = f'http://127.0.0.1:{replacement.server_port}'
            request = Request(url + '/api/jobs', data=json.dumps({'server_id': 'enshrouded', 'operation': 'stop'}).encode(),
                headers={'Content-Type': 'application/json', 'Origin': url, 'X-Manager-Token': previous_token})
            with urlopen(request, timeout=3) as response:
                self.assertEqual(response.status, 202)
                self.assertEqual(json.load(response)['job']['operation'], 'stop')
        finally:
            replacement.shutdown()
            replacement.server_close()
