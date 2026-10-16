import json
import urllib.request
from unittest.mock import patch

from gameserver_manager.core import ManagerError
from gameserver_manager.games import AskaGame
from gameserver_manager.registry import ServerRegistry
from test_manager import FakeBridge, HTTPFixture, ManagerFixture


def add_aska(registry, root):
    install = root / "ASKA Server"
    install.mkdir()
    (install / "AskaServer.exe").touch()
    external = root / "external config" / "server properties.txt"
    external.parent.mkdir()
    config = AskaGame.initial_config()
    config.update({"save id": "existing_world", "password": "private-password", "authentication token": "private-token"})
    document = "\ufeff// Keep the manufacturer's comments\r\n" + "".join(f"{k} = {v}\r\n" for k, v in config.items())
    document += "future setting = keep me\r\n"
    external.write_bytes(document.encode("utf-8"))
    registry.register({"id": "aska", "game": "aska", "label": "ASKA Gruppe", "server_directory": str(install),
                       "config_path": str(external)})
    manager = registry.get("aska")
    manager.bridge = FakeBridge()
    return manager


class ProfileTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.registry = ServerRegistry(self.root, self.manager)
        self.aska = add_aska(self.registry, self.root)

    def test_legacy_migration_preserves_existing_server_and_shared_discord(self):
        original = self.manager.config_path.read_bytes()
        self.aska.save_discord({"webhook_url": "https://discord.com/api/webhooks/123/fake", "auto_notify": False})
        reloaded = ServerRegistry(self.root)
        self.assertEqual(set(reloaded.managers), {"enshrouded", "aska"})
        self.assertEqual(reloaded.get("enshrouded").config_path.read_bytes(), original)
        self.assertEqual(self.manager.webhook(), self.aska.webhook())
        self.assertEqual(reloaded.get("aska").config_path, self.aska.config_path)

    def test_aska_edit_preserves_secrets_comments_unknown_fields_and_exact_backup(self):
        original = self.aska.config_path.read_bytes()
        public = self.aska.public_config()
        self.assertNotIn("private-token", json.dumps(public))
        self.assertNotIn("private-password", json.dumps(public))
        public["server name"] = "Neue Runde"
        public["autosave style"] = "every 10 minutes"
        result = self.aska.save_config(public)
        self.assertEqual((self.aska.data / "backups" / result["backup"]).read_bytes(), original)
        updated = self.aska.config_path.read_bytes()
        self.assertTrue(updated.startswith(b"\xef\xbb\xbf// Keep"))
        self.assertEqual(updated.count(b"\n"), updated.count(b"\r\n"))
        self.assertEqual(self.aska.config()["authentication token"], "private-token")
        self.assertEqual(self.aska.config()["password"], "private-password")
        self.assertEqual(self.aska.config()["future setting"], "keep me")
        self.assertFalse((self.aska.directory / "server properties.txt").exists())

    def test_running_or_invalid_aska_edit_cannot_touch_configuration(self):
        original = self.aska.config_path.read_bytes()
        for key, value in (("steam game port", "99999"), ("server name", "bad\nother = injected"),
                           ("authentication token", 42), ("autosave style", "unknown")):
            config = self.aska.public_config()
            config[key] = value
            with self.subTest(key=key), self.assertRaises(ManagerError):
                self.aska.save_config(config)
        self.aska.bridge.running = True
        with self.assertRaises(ManagerError):
            self.aska.save_config(self.aska.public_config())
        self.assertEqual(self.aska.config_path.read_bytes(), original)
        self.assertFalse((self.aska.data / "backups").exists())

    def test_external_launcher_keeps_save_id_and_owns_start_notification(self):
        self.aska.profile["launcher_script"] = str(self.root / "start-aska.ps1")
        self.aska.save_discord({"webhook_url": "https://discord.com/api/webhooks/123/fake", "auto_notify": True})
        config = self.aska.public_config()
        config["save id"] = "different_world"
        with self.assertRaisesRegex(ManagerError, "Save-ID"):
            self.aska.save_config(config)
        with patch("gameserver_manager.operations.send_discord") as send:
            self.aska.action("start")
            send.assert_not_called()
        self.assertTrue(self.aska.bridge.running)
        self.assertFalse(self.bridge.running)

    def test_profile_registration_rejects_duplicate_paths_and_unsafe_ids(self):
        for profile in (
            {"id": "duplicate", "game": "aska", "label": "Duplicate", "server_directory": str(self.aska.directory)},
            {"id": "../outside", "game": "aska", "label": "Bad", "server_directory": str(self.root / "other")},
            {"id": "bad", "game": [], "label": "Bad", "server_directory": str(self.root / "other")},
        ):
            with self.assertRaises(ManagerError):
                self.registry.register(profile)
        self.assertEqual(len(self.registry.catalog()["servers"]), 2)

    def test_aska_requires_token_and_rejects_duplicate_config_keys(self):
        config = self.aska.config()
        config["authentication token"] = ""
        self.aska.game.write_config(self.aska.config_path, config)
        with self.assertRaisesRegex(ManagerError, "Token"):
            self.aska.action("start")
        self.assertNotIn("Start", self.aska.bridge.calls)
        with self.aska.config_path.open("a") as handle:
            handle.write("server name = duplicated\n")
        with self.assertRaises(ManagerError):
            self.aska.config()


class ProfileHTTPTests(HTTPFixture):
    def test_requests_stay_on_the_explicit_profile_and_secrets_stay_private(self):
        registry = self.server.registry
        aska = add_aska(registry, self.root)
        token = self.server.token
        with self.assertRaises(urllib.error.HTTPError) as rejected:
            self.post("/api/start", {}, token, self.url)
        self.assertEqual(rejected.exception.code, 409)
        rejected.exception.close()
        with self.post("/api/start", {"server_id": "aska"}, token, self.url):
            pass
        self.assertTrue(aska.bridge.running)
        self.assertFalse(self.bridge.running)
        with urllib.request.urlopen(self.url + "/api/state?server=aska") as response:
            raw = response.read().decode()
        self.assertNotIn("private-token", raw)
        self.assertNotIn("private-password", raw)
        self.assertEqual(json.loads(raw)["status"]["server_id"], "aska")
        with self.post("/api/start", {"server_id": "enshrouded"}, token, self.url):
            pass
        with self.post("/api/stop", {"server_id": "aska"}, token, self.url):
            pass
        self.assertTrue(self.bridge.running)
        self.assertFalse(aska.bridge.running)
