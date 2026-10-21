import tempfile
from pathlib import Path
import unittest
from unittest.mock import patch

from gameserver_manager.logs import append_diagnostic, read_tail, redact, secret_values
from gameserver_manager.core import ManagerError
from gameserver_manager.registry import ServerRegistry
from test_manager import ManagerFixture
from test_profiles import add_aska


class RedactionTests(unittest.TestCase):
    def test_configured_and_rotated_secrets_are_removed_but_errors_remain(self):
        values = secret_values({"userGroups": [{"password": "private-pass"}], "authentication token": "ABC-secret"})
        raw = ('bare PRIVATE-pass and abc-SECRET\npassword = old rotated value\n'
               'authentication token:\nolder-token\n'
               'https://discord.com/api/webhooks/123/private_webhook\nGSLTExpired: Steam login failed')
        safe = redact(raw, values)
        for secret in ("PRIVATE-pass", "abc-SECRET", "old rotated value", "older-token", "private_webhook"):
            self.assertNotIn(secret, safe)
        self.assertIn("GSLTExpired", safe)

    def test_bounded_tail_supports_utf16_and_discards_partial_sensitive_lines(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "server.log"
            path.write_text("\n".join(f"line {i}" for i in range(300)), encoding="utf-16")
            text = read_tail(path)
            self.assertEqual(text.splitlines()[0], "line 100")
            self.assertEqual(len(text.splitlines()), 200)
            path.write_text("password=" + "x" * 300000 + "partial-secret\nhealthy line\n")
            self.assertEqual(read_tail(path), "healthy line")


class LogSourceTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.registry = ServerRegistry(self.root, self.manager)
        self.aska = add_aska(self.registry, self.root)
        self.unity = self.root / "Unity logs"
        self.unity.mkdir()
        self.aska.profile["log_directory"] = str(self.unity)

    def test_aska_player_log_is_visible_and_sanitized(self):
        (self.unity / "Player.log").write_text("Connected to Steam successfully\nprivate-token\npassword = previous-secret\nThe session is now open!\n")
        result = self.aska.logs("server")
        self.assertEqual(result["file"], "Player.log")
        self.assertIn("The session is now open!", result["text"])
        self.assertNotIn("private-token", result["text"])
        self.assertNotIn("previous-secret", result["text"])
        self.assertIsNotNone(result["updated_at"])

    def test_new_server_stream_takes_precedence_and_profiles_stay_separate(self):
        (self.unity / "Player.log").write_text("old server output")
        self.aska.diagnostic_directory.mkdir(parents=True)
        (self.aska.diagnostic_directory / "server.log").write_text("new server stream")
        append_diagnostic(self.aska.diagnostic_directory, "ASKA startup failed")
        self.assertEqual(self.aska.logs("server")["text"], "new server stream")
        self.assertIn("ASKA startup failed", self.aska.logs("launcher")["text"])
        self.assertNotIn("ASKA startup failed", self.manager.logs("launcher")["text"])
        with self.assertRaises(ManagerError):
            self.aska.logs("../../manager.local.json")

    def test_failed_start_is_diagnosed_and_unreadable_config_hides_raw_logs(self):
        with patch.object(self.aska.bridge, "call", side_effect=lambda action: (_ for _ in ()).throw(ManagerError("Start fehlgeschlagen")) if action == "Start" else {"processes": []}):
            with self.assertRaises(ManagerError):
                self.aska.action("start")
        self.assertIn("Start fehlgeschlagen", self.aska.logs("launcher")["text"])
        (self.unity / "Player.log").write_text("bare private-token")
        self.aska.config_path.write_text("invalid configuration")
        self.assertNotIn("private-token", self.aska.logs("server")["text"])
        # Local starter diagnostics remain available even if Windows is unreachable.
        with patch.object(self.aska.bridge, "call", side_effect=ManagerError("Windows nicht erreichbar")):
            self.assertIn("Start fehlgeschlagen", self.aska.logs("launcher")["text"])
        with self.assertRaises(ManagerError):
            self.aska.action("start")
        self.assertIn("ungültige oder doppelte Einstellung", self.aska.logs("launcher")["text"])

    def test_pending_launcher_blocks_duplicate_starts_and_config_edits(self):
        with patch.object(self.aska.bridge, "call", return_value={"processes": [], "launcher_running": True}):
            self.assertTrue(self.aska.status()["starting"])
            self.assertIn("bereits", self.aska.action("start")["message"])
            with self.assertRaises(ManagerError):
                self.aska.save_config(self.aska.public_config())
