import json
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch
import urllib.error
import urllib.request

from gameserver_manager.core import GameServerManager, ManagerError, atomic_json, initial_config, send_discord, validate_config
from gameserver_manager.server import LocalServer


class FakeBridge:
    def __init__(self, user_profile=None):
        self.running = False
        self.calls = []
        self.user_profile = user_profile

    def call(self, action):
        self.calls.append(action)
        if action == "Start":
            self.running = True
        elif action == "Stop":
            self.running = False
        return {"processes": [{"pid": 42}] if self.running else [], "user_profile": self.user_profile, "launcher_running": False}


class ManagerFixture(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.install = self.root / "Server mit Leerzeichen"
        self.install.mkdir()
        (self.install / "enshrouded_server.exe").touch()
        atomic_json(self.root / "manager.local.json", {"server_directory": str(self.install), "discord": {"webhook_url": "", "auto_notify": False}})
        self.bridge = FakeBridge()
        self.manager = GameServerManager(self.root, self.bridge)


class ManagerTests(ManagerFixture):
    def test_setup_is_idempotent_and_keeps_passwords_and_unknown_fields(self):
        self.assertTrue(self.manager.setup()["created"])
        config = self.manager.config()
        self.assertEqual(len({g["password"] for g in config["userGroups"]}), 4)
        self.assertTrue(all(len(g["password"]) >= 20 for g in config["userGroups"]))
        config["futureSetting"] = {"value": 99}
        atomic_json(self.manager.config_path, config)
        original = self.manager.config_path.read_bytes()
        self.assertFalse(self.manager.setup()["created"])
        self.assertEqual(self.manager.config_path.read_bytes(), original)

    def test_running_server_blocks_setup_and_edit_and_creates_no_backup(self):
        self.manager.setup()
        self.bridge.running = True
        original = self.manager.config_path.read_bytes()
        with self.assertRaises(ManagerError):
            self.manager.save_config(self.manager.config())
        with self.assertRaises(ManagerError):
            self.manager.setup()
        self.assertEqual(self.manager.config_path.read_bytes(), original)
        self.assertFalse((self.root / "data/backups").exists())

    def test_config_backup_is_exact_and_unknown_fields_survive(self):
        self.manager.setup()
        original = self.manager.config_path.read_bytes()
        config = self.manager.config()
        config.update(name="Meine Welt", futureSetting={"enabled": True})
        result = self.manager.save_config(config)
        self.assertEqual((self.root / "data/backups" / result["backup"]).read_bytes(), original)
        self.assertTrue(self.manager.config()["futureSetting"]["enabled"])

    def test_invalid_edit_does_not_touch_original(self):
        self.manager.setup()
        original = self.manager.config_path.read_bytes()
        config = self.manager.config()
        config["slotCount"] = 17
        with self.assertRaises(ManagerError):
            self.manager.save_config(config)
        self.assertEqual(self.manager.config_path.read_bytes(), original)

    def test_start_stop_restart_and_duplicate_start(self):
        self.manager.setup()
        self.manager.action("start")
        self.manager.action("start")
        self.assertEqual(self.bridge.calls.count("Start"), 1)
        self.manager.action("restart")
        self.assertTrue(self.manager.status()["running"])
        self.assertEqual(self.bridge.calls.count("Stop"), 1)
        self.manager.action("stop")
        self.assertFalse(self.manager.status()["running"])

    def test_restart_does_not_start_after_failed_stop(self):
        self.manager.setup()
        self.bridge.running = True
        original_call = self.bridge.call

        def fail_stop(action):
            if action == "Stop":
                raise ManagerError("Stop fehlgeschlagen")
            return original_call(action)

        self.bridge.call = fail_stop
        with self.assertRaises(ManagerError):
            self.manager.action("restart")
        self.assertNotIn("Start", self.bridge.calls)

    def test_discord_failure_does_not_misreport_a_successful_start(self):
        self.manager.setup()
        self.manager.save_discord({"webhook_url": "https://discord.com/api/webhooks/123/secret", "auto_notify": True})
        with patch("gameserver_manager.operations.send_discord", side_effect=ManagerError("Discord nicht erreichbar")):
            result = self.manager.action("start")
        self.assertTrue(self.bridge.running)
        self.assertIn("Discord", result["warning"])
        self.assertNotIn("secret", json.dumps(self.manager.events()))

    def test_start_and_restart_notifications_include_public_join_instructions_without_secrets(self):
        self.manager.setup()
        self.manager.profile['connection'] = {'public_host': 'group.example.org'}
        self.manager.save_discord({'webhook_url': 'https://discord.com/api/webhooks/123/secret', 'auto_notify': True})
        with patch('gameserver_manager.operations.send_discord') as sender:
            self.manager.action('start')
            content = sender.call_args.args[1]
            self.assertIn('group.example.org:15637', content)
            self.assertIn('Suche nach IPv4 / Domain', content)
            self.assertNotIn('127.0.0.1', content)
            self.assertNotIn('secret', content)
            for group in self.manager.config()['userGroups']:
                self.assertNotIn(group['password'], content)
            self.manager.action('restart')
            self.assertIn('group.example.org:15637', sender.call_args.args[1])
            self.manager.action('stop')
            self.assertNotIn('group.example.org', sender.call_args.args[1])

    def test_webhook_can_be_kept_and_deleted_without_being_returned(self):
        url = "https://discord.com/api/webhooks/123/token"
        self.manager.save_discord({"webhook_url": url, "auto_notify": False})
        self.manager.save_discord({"webhook_url": "", "auto_notify": True})
        self.assertEqual(self.manager.webhook(), url)
        self.assertNotIn("token", json.dumps(self.manager.status()))
        self.manager.save_discord({"webhook_url": "", "clear_webhook": True, "auto_notify": False})
        self.assertFalse(self.manager.webhook())

    def test_logs_honor_custom_path_and_return_only_last_200_lines(self):
        self.manager.setup()
        config = self.manager.config()
        config["logDirectory"] = "./custom logs"
        self.manager.save_config(config)
        directory = self.install / "custom logs"
        directory.mkdir()
        (directory / "server.log").write_text("\n".join(f"line {i}" for i in range(300)))
        logs = self.manager.logs()
        self.assertEqual(logs["file"], "server.log")
        self.assertEqual(logs["text"].splitlines()[0], "line 100")
        self.assertEqual(len(logs["text"].splitlines()), 200)

    def test_validation_rejects_wrong_types_and_nonfinite_numbers(self):
        for key, value in (("slotCount", True), ("queryPort", 1.5), ("enableTextChat", "false"), ("ip", "::1"), ("gameSettings", []), ("tags", "German"), ("other", float("nan"))):
            with self.subTest(key=key):
                config = initial_config()
                config[key] = value
                with self.assertRaises(ManagerError):
                    validate_config(config)


class HTTPFixture(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.server = LocalServer(self.manager, 0)
        thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        self.url = f"http://127.0.0.1:{self.server.server_port}"

    def post(self, path, body, token=None, origin=None):
        headers = {"Content-Type": "application/json"}
        if token:
            headers["X-Manager-Token"] = token
        if origin:
            headers["Origin"] = origin
        request = urllib.request.Request(self.url + path, data=json.dumps(body).encode(), headers=headers)
        return urllib.request.urlopen(request)

class HTTPTests(HTTPFixture):
    def test_no_csrf_token_and_foreign_origin_are_blocked(self):
        for token, origin in ((None, None), (self.server.token, "https://example.com")):
            with self.assertRaises(urllib.error.HTTPError) as result:
                self.post("/api/start", {}, token, origin)
            self.assertEqual(result.exception.code, 403)
            result.exception.close()
        self.assertFalse(self.bridge.running)

    def test_unknown_host_and_traversal_are_rejected(self):
        request = urllib.request.Request(self.url + "/api/session", headers={"Host": "evil.example"})
        with self.assertRaises(urllib.error.HTTPError) as result:
            urllib.request.urlopen(request)
        self.assertEqual(result.exception.code, 403)
        result.exception.close()
        with self.assertRaises(urllib.error.HTTPError) as result:
            urllib.request.urlopen(self.url + "/../manager.local.json")
        self.assertEqual(result.exception.code, 404)
        result.exception.close()

    def test_browser_api_flow_and_validation(self):
        with urllib.request.urlopen(self.url + "/api/session") as response:
            token = json.load(response)["token"]
            self.assertIn("frame-ancestors 'none'", response.headers["Content-Security-Policy"])
        with self.post("/api/start", {}, token, self.url) as response:
            self.assertEqual(response.status, 200)
        with urllib.request.urlopen(self.url + "/api/state") as response:
            self.assertTrue(json.load(response)["status"]["running"])
        with self.assertRaises(urllib.error.HTTPError) as result:
            self.post("/api/config", {"config": self.manager.config()}, token, self.url)
        self.assertEqual(result.exception.code, 409)
        result.exception.close()
        with self.post("/api/stop", {}, token, self.url):
            pass
        config = self.manager.config()
        config["name"] = "API Welt"
        with self.post("/api/config", {"config": config}, token, self.url):
            pass
        self.assertEqual(self.manager.config()["name"], "API Welt")


class DiscordTests(unittest.TestCase):
    def test_payload_disables_mentions_and_requests_confirmation(self):
        opener = unittest.mock.MagicMock()
        with patch("urllib.request.build_opener", return_value=opener):
            send_discord("https://discord.com/api/webhooks/123/fake_token", "Grüße @everyone")
        request = opener.open.call_args.args[0]
        self.assertTrue(request.full_url.endswith("?wait=true"))
        payload = json.loads(request.data)
        self.assertEqual(payload["allowed_mentions"], {"parse": []})
        self.assertEqual(payload["content"], "Grüße @everyone")

    def test_bad_url_and_message_do_not_send(self):
        with patch("urllib.request.build_opener") as opener:
            for url, message in (("https://example.com/api/webhooks/123/a", "hello"), ("https://discord.com/api/webhooks/123/a", ""), ("https://discord.com/api/webhooks/123/a", "a" * 2001), ("https://discord.com/api/webhooks/123/a", None)):
                with self.subTest(message_length=len(message or "")), self.assertRaises(ManagerError):
                    send_discord(url, message)
            opener.assert_not_called()


if __name__ == "__main__":
    unittest.main()
