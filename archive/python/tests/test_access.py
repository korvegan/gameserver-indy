"""Private proxy boundary. Native games and real accounts are never used."""
import json
import threading
import urllib.error
import urllib.request
from unittest.mock import patch
import time
from concurrent.futures import ThreadPoolExecutor

from gameserver_manager.access import settings
from gameserver_manager.configuration import ManagerError
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.security import AccessDenied, Principal
from gameserver_manager.server import FamilyServer, LocalServer
from test_manager import ManagerFixture
from test_profiles import add_aska

OWNER = Principal('local-owner', 'owner')
HOST = 'indy.example.ts.net'


class AccessTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.registry = ServerRegistry(self.root, self.manager)
        add_aska(self.registry, self.root)
        self.local = LocalServer(self.registry, 0)
        self.family = FamilyServer(self.local.app, HOST, 0)
        self.access = self.local.app.access
        self.access.configure(OWNER, {'enabled': True, 'hostname': HOST, 'port': self.family.server_port})
        for server in (self.local, self.family):
            threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(self.close_servers)
        self.body = {'login': 'sister@example.com', 'name': 'Schwester', 'role': 'family', 'server_ids': ['aska'], 'enabled': True}
        self.user_id = self.access.save_user(OWNER, self.body)['id']

    def close_servers(self):
        for server in (self.family, self.local):
            server.shutdown()
            server.server_close()

    def request(self, path, body=None, login='sister@example.com', headers=None, local=False):
        server = self.local if local else self.family
        values = {'Host': f'127.0.0.1:{server.server_port}' if local else HOST}
        if login is not None:
            values['Tailscale-User-Login'] = login
        if body is not None:
            values['Content-Type'] = 'application/json'
        values.update(headers or {})
        request = urllib.request.Request(f'http://127.0.0.1:{server.server_port}' + path,
            data=json.dumps(body).encode() if body is not None else None, headers=values)
        try:
            with urllib.request.urlopen(request, timeout=5) as response:
                return response.status, json.load(response)
        except urllib.error.HTTPError as error:
            with error:
                return error.code, json.load(error)

    def session(self, login='sister@example.com'):
        code, session = self.request('/api/session', login=login)
        self.assertEqual(code, 200)
        return session

    def test_verified_allowlist_has_no_local_owner_or_header_role_fallback(self):
        for login in (None, 'stranger@example.com'):
            code, _ = self.request('/api/session', login=login, headers={'X-Role': 'owner', 'X-Forwarded-User': 'sister@example.com'})
            self.assertEqual(code, 403)
        code, _ = self.request('/api/session', headers={'Host': f'127.0.0.1:{self.family.server_port}'})
        self.assertEqual(code, 403)
        principal = self.session()['principal']
        self.assertEqual((principal['id'], principal['role']), (self.user_id, 'family'))
        code, local = self.request('/api/session', local=True, login='stranger@example.com', headers={'X-Role': 'family'})
        self.assertEqual(code, 200)
        self.assertEqual(local['principal']['id'], 'local-owner')

    def test_family_routes_are_scoped_and_config_logs_export_and_admin_denied(self):
        code, catalog = self.request('/api/servers')
        self.assertEqual(code, 200)
        self.assertEqual([p['id'] for p in catalog['servers']], ['aska'])
        self.assertNotIn('server_directory', catalog['servers'][0])
        for route in ('/api/state?server=aska', '/api/config?server=aska', '/api/logs?server=aska',
                      '/api/events?server=aska', '/api/access', '/api/status?server=enshrouded', '/api/community/export',
                      '/api/backups?server=aska', '/api/backups?server=aska&id=' + 'a' * 32,
                      '/api/backups?server=aska&recovery=true'):
            with self.subTest(route=route):
                self.assertEqual(self.request(route)[0], 403)
        code, status = self.request('/api/status?server=aska')
        self.assertEqual(code, 200)
        for secret in ('userGroups', 'webhook_url', 'server_directory', 'processes', 'user_profile'):
            self.assertNotIn(secret, json.dumps(status))
        token = self.session()['token']
        for route, body in [('/api/jobs', {'server_id': 'aska', 'operation': 'stop'}),
                            ('/api/restart', {'server_id': 'aska'}), ('/api/start', {'server_id': 'enshrouded'}),
                            ('/api/setup', {'server_id': 'aska'}), ('/api/config', {'server_id': 'aska'}),
                            ('/api/access/users', self.body), ('/api/access/settings', {}),
                            ('/api/servers', {}), ('/api/discord/send', {'message': 'not sent'})]:
            with self.subTest(route=route, body=body):
                self.assertEqual(self.request(route, body, headers={'X-Manager-Token': token})[0], 403)
        self.assertNotIn('Start', self.bridge.calls)

    def test_csrf_token_is_bound_to_identity_revision_and_https_origin(self):
        self.access.save_user(OWNER, {**self.body, 'login': 'brother@example.com', 'name': 'Schwager'})
        token = self.session()['token']
        other = self.session('brother@example.com')['token']
        self.assertNotEqual(token, other)
        self.assertNotEqual(token, self.local.token)
        body = {'operation': 'start', 'server_id': 'enshrouded'}
        for headers in ({'X-Manager-Token': other}, {'X-Manager-Token': self.local.token},
                        {'X-Manager-Token': token, 'Origin': 'http://' + HOST},
                        {'X-Manager-Token': token, 'Origin': 'https://attacker.invalid'},
                        {'X-Manager-Token': 'ä'}):
            self.assertEqual(self.request('/api/jobs', body, headers=headers)[0], 403)
        self.assertEqual(self.request('/api/jobs', body, headers={'X-Manager-Token': token, 'Origin': 'https://' + HOST})[0], 403, 'Scope still applies after valid CSRF')
        self.assertEqual(self.request('/api/jobs', body, headers={'X-Manager-Token': token, 'Origin': 'https://' + HOST + '.attacker.invalid'})[0], 403)

    def test_revocation_and_role_changes_are_immediate_and_invalidate_old_tokens(self):
        token = self.session()['token']
        user = self.access.view(OWNER)['users'][0]
        self.access.save_user(OWNER, {**self.body, 'id': user['id'], 'revision': user['revision'], 'enabled': False})
        self.assertEqual(self.request('/api/session')[0], 403)
        self.assertEqual(self.request('/api/jobs', {'operation': 'start', 'server_id': 'aska'}, headers={'X-Manager-Token': token})[0], 403)
        user = self.access.view(OWNER)['users'][0]
        self.access.save_user(OWNER, {**self.body, 'id': user['id'], 'revision': user['revision'], 'role': 'viewer'})
        self.assertNotEqual(self.session()['token'], token)
        self.assertEqual(self.request('/api/jobs', {'operation': 'start', 'server_id': 'aska'}, headers={'X-Manager-Token': token})[0], 403)

    def test_profile_revision_cas_and_concurrent_updates_do_not_restore_stale_grants(self):
        user = self.access.view(OWNER)['users'][0]
        body = {**self.body, 'id': user['id'], 'revision': user['revision'], 'enabled': False}
        def update():
            try:
                self.access.save_user(OWNER, body)
                return 'saved'
            except ManagerError:
                return 'conflict'
        with ThreadPoolExecutor(2) as pool:
            self.assertCountEqual(list(pool.map(lambda _: update(), range(2))), ['saved', 'conflict'])
        with self.assertRaises(ManagerError):
            self.access.save_user(OWNER, self.body)
        with self.assertRaises(ManagerError):
            self.access.save_user(OWNER, {**body, 'login': 'different@example.com'})

    def test_disabled_endpoint_and_hostname_change_fail_closed(self):
        self.access.configure(OWNER, {'enabled': False, 'hostname': HOST, 'port': self.family.server_port})
        self.assertEqual(self.request('/api/session')[0], 403)
        self.assertEqual(self.request('/api/session', local=True)[0], 200)
        self.access.configure(OWNER, {'enabled': True, 'hostname': 'other.example.ts.net', 'port': self.family.server_port})
        self.assertEqual(self.request('/api/session')[0], 403)

    def test_access_audit_has_actor_and_outcome_without_bodies_tokens_or_identity_headers(self):
        token = self.session()['token']
        self.request('/api/discord/send', {'message': 'private-secret-body'}, headers={'X-Manager-Token': token})
        data = self.access.view(OWNER)
        serialized = json.dumps(data['audit'])
        self.assertNotIn(token, serialized)
        self.assertNotIn('private-secret-body', serialized)
        self.assertNotIn('sister@example.com', serialized)
        self.assertTrue(any(row['actor'] == self.user_id and row['operation'] == '/api/discord/send' and row['outcome'] == '403' for row in data['audit']))
        self.assertEqual(self.request('/api/access')[0], 403)

    def test_queued_job_rechecks_grant_before_native_action(self):
        principal = self.access.principal('sister@example.com')
        user = self.access.view(OWNER)['users'][0]
        self.access.save_user(OWNER, {**self.body, 'id': user['id'], 'revision': user['revision'], 'enabled': False})
        job, future = self.local.app.submit(principal, 'start', {'server_id': 'aska'})
        with self.assertRaises(AccessDenied):
            future.result(timeout=3)
        self.assertEqual(self.local.app.jobs.get(job['id'])['state'], 'failed')

    def test_authorized_family_start_creates_audited_job_with_verified_actor(self):
        user = self.access.view(OWNER)['users'][0]
        self.access.save_user(OWNER, {**self.body, 'id': user['id'], 'revision': user['revision'], 'server_ids': ['enshrouded']})
        token = self.session()['token']
        code, result = self.request('/api/jobs', {'operation': 'start', 'server_id': 'enshrouded'},
            headers={'X-Manager-Token': token, 'Origin': 'https://' + HOST})
        self.assertEqual(code, 202)
        job_id = result['job']['id']
        self.local.app.jobs.executor.shutdown(wait=True)
        job = self.local.app.jobs.get(job_id)
        self.assertEqual((job['actor_id'], job['state']), (self.user_id, 'succeeded'))
        self.assertEqual(self.bridge.calls.count('Start'), 1)
        self.assertTrue(any(row['actor'] == self.user_id and row['resource'] == 'enshrouded' and row['outcome'] == '202'
                            for row in self.access.view(OWNER)['audit']))

    def test_revoked_remote_owner_cannot_run_queued_update_or_discord_work(self):
        remote_body = {**self.body, 'login': 'owner@example.test', 'name': 'Remote owner', 'role': 'owner', 'server_ids': None}
        remote_id = self.access.save_user(OWNER, remote_body)['id']
        principal = self.access.principal(remote_body['login'])
        gate = threading.Event()
        blockers = [self.local.app.jobs.executor.submit(gate.wait) for _ in range(4)]
        with patch.object(self.local.app.updates, 'check') as update, patch.object(self.manager, 'save_discord') as discord:
            with ThreadPoolExecutor(1) as caller:
                try:
                    _job, update_future = self.local.app.check_updates(principal, 'enshrouded')
                    discord_future = caller.submit(self.local.app.mutate, principal, '/api/discord/settings', {'server_id': 'enshrouded'})
                    deadline = time.monotonic() + 3
                    while not self.local.app.jobs.recent('@discord'):
                        self.assertLess(time.monotonic(), deadline)
                        time.sleep(.01)
                    user = next(user for user in self.access.view(OWNER)['users'] if user['id'] == remote_id)
                    self.access.save_user(OWNER, {**remote_body, 'id': user['id'], 'revision': user['revision'], 'enabled': False})
                finally:
                    gate.set()
                for future in (update_future, discord_future):
                    with self.assertRaises(AccessDenied):
                        future.result(timeout=3)
                update.assert_not_called()
                discord.assert_not_called()
        for blocker in blockers:
            blocker.result(timeout=3)

    def test_strict_configuration_and_invalid_grants(self):
        for hostname in ('evil.invalid', 'https://' + HOST, HOST + ':443', HOST + '/path', 'a..ts.net', 'a.ts.net', 'a.-b.ts.net'):
            with self.subTest(hostname=hostname), self.assertRaises(ManagerError):
                settings({'enabled': True, 'hostname': hostname})
        for extra in ({'role': 'superuser'}, {'server_ids': ['unknown']}, {'server_ids': ['aska', 'aska']},
                      {'server_ids': 'aska'}, {'login': 'person\r\n@example.com'}, {'enabled': 'yes'}, {'role': 'owner', 'server_ids': ['aska']}):
            with self.subTest(extra=extra), self.assertRaises(ManagerError):
                self.access.save_user(OWNER, {**self.body, 'login': 'new@example.com', **extra})
        with self.assertRaises(AccessDenied):
            self.access.save_user(Principal('viewer', 'viewer'), self.body)
