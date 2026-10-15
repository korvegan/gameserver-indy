"""Join information: no live address lookups or real game operations."""
import json
import threading
import time
import urllib.error
import urllib.request
from unittest.mock import Mock, patch

from gameserver_manager.connections import ConnectionService, MAX_AGE, windows_network
from gameserver_manager.security import Principal
from test_manager import HTTPFixture, ManagerFixture


def wait_for(service):
    limit = time.monotonic() + 2
    while service.checking and time.monotonic() < limit:
        time.sleep(.005)
    if service.checking:
        raise AssertionError('Address lookup did not finish')


class ConnectionTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.config = self.manager.config()
        self.config['queryPort'] = 23456
        self.provider = Mock(return_value={'lan': ['192.168.1.20'], 'public': '8.8.8.8'})
        self.service = ConnectionService(self.provider)
        self.addCleanup(self.service.close)

    def view(self):
        return self.service.view(self.manager, self.config)

    def test_addresses_use_configured_port_and_never_claim_external_verification(self):
        before = self.manager.config_path.read_bytes()
        calls = list(self.bridge.calls)
        self.service.refresh()
        wait_for(self.service)
        result = self.view()
        self.assertEqual(result['local'], '127.0.0.1:23456')
        self.assertEqual(result['lan'], ['192.168.1.20:23456'])
        self.assertEqual(result['external'], '8.8.8.8:23456')
        self.assertFalse(result['external_verified'])
        self.assertEqual(self.manager.config_path.read_bytes(), before)
        self.assertEqual(self.bridge.calls, calls)
        for _ in range(3):
            self.view()
        self.provider.assert_called_once()

    def test_invalid_addresses_are_filtered_and_multiple_interfaces_are_kept(self):
        self.provider.return_value = {'lan': ['192.168.1.20', '10.0.0.8', '127.0.0.1', '169.254.1.1',
            '0.0.0.0', '224.1.2.3', '::1', '192.168.1.20', 'secret', 42], 'public': '192.168.1.2', 'secret': 'hidden'}
        self.service.refresh()
        wait_for(self.service)
        self.assertEqual(self.view()['lan'], ['10.0.0.8:23456', '192.168.1.20:23456'])
        self.assertIsNone(self.view()['external'])
        self.assertNotIn('secret', json.dumps(self.view()))

    def test_failed_or_stale_lookup_does_not_keep_old_shareable_addresses(self):
        self.service.refresh()
        wait_for(self.service)
        self.service.collected -= MAX_AGE + 1
        self.assertTrue(self.view()['stale'])
        self.assertEqual(self.view()['lan'], [])
        self.assertIsNone(self.view()['external'])
        self.provider.side_effect = OSError('private diagnostic')
        self.service.refresh()
        wait_for(self.service)
        self.assertIsNone(self.view()['external'])
        self.assertNotIn('private', json.dumps(self.view()))

    def test_restricted_bind_and_invalid_port_do_not_offer_wrong_endpoints(self):
        self.service.refresh()
        wait_for(self.service)
        self.config['ip'] = '127.0.0.1'
        self.assertEqual(self.view()['lan'], [])
        self.assertIsNone(self.view()['external'])
        self.config['ip'] = '192.168.1.20'
        self.assertIsNone(self.view()['local'])
        self.assertEqual(self.view()['lan'], ['192.168.1.20:23456'])
        for port in (None, 0, 65536, True, '123', 12.5):
            self.config['queryPort'] = port
            self.assertIsNone(self.view()['local'])
            self.assertEqual(self.view()['lan'], [])
            self.assertIsNone(self.view()['external'])

    def test_slow_lookup_is_nonblocking_and_coalesces_duplicate_refreshes(self):
        gate = threading.Event()
        self.addCleanup(gate.set)
        self.provider.side_effect = lambda: (gate.wait(2), {'lan': [], 'public': None})[1]
        self.service.refresh()
        self.assertTrue(self.view()['checking'])
        self.service.refresh()
        gate.set()
        wait_for(self.service)
        self.provider.assert_called_once()

    def test_native_collector_has_bounded_fixed_commands(self):
        converted = Mock(returncode=0, stdout='C:\\test\\windows-network.ps1')
        result = Mock(returncode=0, stdout='\ufeff{"lan":[],"public":null}')
        with patch('gameserver_manager.connections.os.name', 'posix'), patch('gameserver_manager.connections.subprocess.run', side_effect=[converted, result]) as run:
            self.assertEqual(windows_network(), {'lan': [], 'public': None})
        arguments, options = run.call_args
        self.assertEqual(arguments[0][-2:], ['-File', 'C:\\test\\windows-network.ps1'])
        self.assertEqual(options['timeout'], 25)
        self.assertNotIn('shell', options)


class ConnectionHTTPTests(HTTPFixture):
    def test_refresh_requires_owner_and_explicit_authorized_profile_and_csrf(self):
        service = self.server.app.connections
        service.provider = Mock(return_value={'lan': ['192.168.1.20'], 'public': '8.8.8.8'})
        for body, token, expected in (({'server_id': 'enshrouded'}, None, 403), ({}, self.server.token, 409)):
            with self.assertRaises(urllib.error.HTTPError) as error:
                self.post('/api/connections/refresh', body, token)
            self.assertEqual(error.exception.code, expected)
            error.exception.close()
        self.server.identity = lambda _: Principal('family', 'family')
        with self.assertRaises(urllib.error.HTTPError) as error:
            self.post('/api/connections/refresh', {'server_id': 'enshrouded'}, self.server.token)
        self.assertEqual(error.exception.code, 403)
        error.exception.close()
        service.provider.assert_not_called()
        self.server.identity = lambda _: Principal('owner', 'owner')
        with self.post('/api/connections/refresh', {'server_id': 'enshrouded'}, self.server.token) as response:
            self.assertEqual(response.status, 202)
        wait_for(service)
        self.server.identity = lambda _: Principal('family', 'family', frozenset({'enshrouded'}))
        with urllib.request.urlopen(self.url + '/api/status?server=enshrouded') as response:
            status = json.load(response)
        self.assertEqual(status['connection']['external'], '8.8.8.8:15637')
        self.assertNotIn('userGroups', json.dumps(status))
        for group in self.manager.config()['userGroups']:
            self.assertNotIn(group['password'], json.dumps(status))
        self.assertEqual(self.bridge.calls.count('Start'), 0)

    def test_unreadable_config_keeps_network_view_safe(self):
        self.manager.config_path.write_text('{broken')
        with urllib.request.urlopen(self.url + '/api/status?server=enshrouded') as response:
            status = json.load(response)
        self.assertTrue(status['config_error'])
        self.assertIsNone(status['connection']['local'])
        self.assertIsNone(status['connection']['external'])
