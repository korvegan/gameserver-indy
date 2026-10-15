"""Regression tests for the application boundaries, not real games or accounts."""
import json
import sqlite3
import threading
import urllib.error
import urllib.request
from unittest.mock import patch

from gameserver_manager.application import Application
from gameserver_manager.catalog import GameCatalog
from gameserver_manager.community import CommunityService
from gameserver_manager.configuration import ManagerError
from gameserver_manager.jobs import utcnow
from gameserver_manager.prototype import demonstrate
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.security import AccessDenied, Principal
from gameserver_manager.storage import Database
from test_manager import HTTPFixture, ManagerFixture
from test_profiles import add_aska

OWNER = Principal("test-owner", "owner")
FAMILY = Principal("sister", "family", frozenset({"aska"}))


class PlatformTests(ManagerFixture):
    def setUp(self):
        super().setUp()
        self.manager.setup()
        self.registry = ServerRegistry(self.root, self.manager)
        self.aska = add_aska(self.registry, self.root)
        self.app = Application(self.registry)
        self.addCleanup(self.app.close)

    def test_long_job_is_exclusive_only_for_its_target_and_status_remains_readable(self):
        entered, release = threading.Event(), threading.Event()
        call = self.bridge.call

        def slow(action):
            if action == "Start":
                entered.set()
                if not release.wait(5):
                    raise ManagerError("Test wurde nicht freigegeben.")
            return call(action)

        self.bridge.call = slow
        job, future = self.app.submit(OWNER, "start", {"server_id": "enshrouded"})
        try:
            self.assertTrue(entered.wait(2))
            self.assertEqual(self.app.jobs.get(job["id"])["state"], "running")
            self.assertFalse(self.app.status(OWNER, "enshrouded")["status"]["running"])
            self.assertEqual(len(self.app.servers(OWNER)["servers"]), 2)
            other, other_future = self.app.submit(OWNER, "start", {"server_id": "aska"})
            self.assertIn("gestartet", other_future.result(timeout=2)["message"])
            self.assertEqual(self.app.jobs.get(other["id"])["state"], "succeeded")
            for operation in ("start", "stop", "config", "setup"):
                with self.subTest(operation=operation), self.assertRaisesRegex(ManagerError, "bereits ein Auftrag"):
                    self.app.submit(OWNER, operation, {"server_id": "enshrouded"})
            self.assertFalse(future.done())
        finally:
            release.set()
            future.result(timeout=2)

    def test_public_read_models_and_permissions_do_not_expose_owner_configuration(self):
        secrets = [group["password"] for group in self.manager.config()["userGroups"]]
        raw = json.dumps(self.app.status(OWNER, "enshrouded"))
        self.assertTrue(all(secret not in raw for secret in secrets))
        self.assertIn(secrets[0], json.dumps(self.app.configuration(OWNER, "enshrouded")))
        for principal in (FAMILY, Principal("observer", "viewer", frozenset({"aska"}))):
            public = json.dumps({**self.app.servers(principal), **self.app.status(principal, "aska")})
            for secret in ("private-token", "private-password", str(self.aska.directory), str(self.aska.config_path)):
                self.assertNotIn(secret, public)
            with self.assertRaises(AccessDenied):
                self.app.configuration(principal, "aska")
            with self.assertRaises(AccessDenied):
                self.app.status(principal, "enshrouded")
            for operation in ("stop", "restart", "config", "setup"):
                with self.subTest(operation=operation), self.assertRaises(AccessDenied):
                    self.app.submit(principal, operation, {"server_id": "aska"})

    def test_recovery_preserves_running_process_and_does_not_replay_unknown_outcome(self):
        self.bridge.running = True
        self.app.close()
        now = utcnow()
        with self.app.database.connect(write=True) as db:
            db.execute("INSERT INTO jobs VALUES ('interrupted-test', 'enshrouded', 'restart', 'owner', 'running', 'running', ?, ?, NULL)", (now, now))
        replacement = Application(self.registry)
        self.addCleanup(replacement.close)
        recovered = replacement.jobs.get("interrupted-test")
        self.assertEqual(recovered["state"], "interrupted")
        self.assertFalse(recovered["resumable"])
        self.assertTrue(replacement.status(OWNER, "enshrouded")["status"]["running"])
        self.assertNotIn("Start", self.bridge.calls)
        self.assertNotIn("Stop", self.bridge.calls)

    def test_failed_jobs_keep_configuration_secrets_out_of_the_database(self):
        def fail(_action):
            raise ManagerError("Failure containing private-token and private-password")

        self.aska.action = fail
        job, future = self.app.submit(OWNER, "start", {"server_id": "aska"})
        with self.assertRaises(ManagerError):
            future.result(timeout=2)
        failed = self.app.jobs.get(job["id"])
        self.assertEqual(failed["state"], "failed")
        self.assertIn("[ENTFERNT]", failed["message"])
        database = self.app.database.path.read_bytes()
        self.assertNotIn(b"private-token", database)
        self.assertNotIn(b"private-password", database)

    def test_storage_failure_before_and_after_callback_becomes_interrupted_without_replay(self):
        for phase in ("running", "succeeded"):
            with self.subTest(phase=phase):
                calls = []
                update = self.app.jobs._update
                failed = []

                def flaky(job_id, state, message, result=None):
                    if state == phase and not failed:
                        failed.append(state)
                        raise sqlite3.OperationalError("temporary write failure")
                    return update(job_id, state, message, result)

                with patch.object(self.app.jobs, "_update", side_effect=flaky):
                    job, future = self.app.jobs.submit("storage-test", "start", OWNER.id,
                        lambda: calls.append("effect") or {"message": "done"})
                    with self.assertRaisesRegex(ManagerError, "Ausgang unbekannt"):
                        future.result(timeout=2)
                    self.assertEqual(self.app.jobs.get(job["id"])["state"], "interrupted")
                self.assertEqual(calls, [] if phase == "running" else ["effect"])
                self.assertFalse(self.app.jobs.active)

    def test_unpersisted_terminal_outcome_is_visible_and_reconciled_when_storage_recovers(self):
        update = self.app.jobs._update
        with patch.object(self.app.jobs, "_update", side_effect=sqlite3.OperationalError("write unavailable")):
            job, future = self.app.jobs.submit("storage-test", "start", OWNER.id, lambda: {"message": "never called"})
            with self.assertRaises(ManagerError):
                future.result(timeout=2)
            self.assertEqual(self.app.jobs.get(job["id"])["state"], "interrupted")
            self.assertEqual(self.app.jobs.recent("storage-test")[0]["state"], "interrupted")
        self.assertEqual(self.app.jobs.get(job["id"])["state"], "interrupted")
        self.assertFalse(self.app.jobs.unsettled)
        with self.app.database.connect() as db:
            self.assertEqual(db.execute("SELECT state FROM jobs WHERE id=?", (job["id"],)).fetchone()[0], "interrupted")

    def test_startup_and_sqlite_backup_preserve_existing_external_files_and_unknown_fields(self):
        original = self.aska.config_path.read_bytes()
        settings = self.registry.path.read_bytes()
        event = {"id": "snapshot-event", "revision": 1, "game_id": "aska", "title": "Vor Sicherung",
                 "source": "test", "badge_id": "snapshot-badge", "member_ids": ["one"]}
        self.app.community.record(event, publish=True)
        second = Database(self.app.database.path)
        snapshot = self.root / "snapshot.sqlite3"
        second.backup(snapshot)
        with self.assertRaises(FileExistsError):
            second.backup(snapshot)
        restored = Database(snapshot)
        self.app.community.record({**event, "revision": 2, "title": "Nach Sicherung"})
        restored_events = CommunityService(restored, self.app.catalog).recent(OWNER)
        self.assertEqual(restored_events[0]["title"], "Vor Sicherung")
        self.assertEqual(restored_events[0]["scene"]["id"], "scene-snapshot-event")
        self.assertEqual(self.app.community.recent(OWNER)[0]["title"], "Nach Sicherung")
        with restored.connect() as db:
            self.assertEqual(db.execute("PRAGMA user_version").fetchone()[0], 2)
        self.assertEqual(self.aska.config_path.read_bytes(), original)
        self.assertEqual(self.registry.path.read_bytes(), settings)
        self.assertEqual(self.aska.config()["future setting"], "keep me")
        with restored.connect(write=True) as db:
            db.execute("PRAGMA user_version = 99")
        before = snapshot.read_bytes()
        with self.assertRaisesRegex(ManagerError, "nicht unterstützte Version"):
            Database(snapshot)
        self.assertEqual(snapshot.read_bytes(), before)

    def test_supplied_manager_is_not_reused_for_other_persisted_profiles(self):
        reloaded = ServerRegistry(self.root, self.manager)
        self.assertIs(reloaded.get("enshrouded"), self.manager)
        self.assertIsNot(reloaded.get("aska"), self.manager)
        self.assertEqual(reloaded.get("aska").config_path, self.aska.config_path)
        self.assertIsNot(reloaded.get("aska").lock, self.manager.lock)

    def test_serverless_event_is_idempotent_revisioned_and_has_one_stable_scene(self):
        catalog = GameCatalog([{"id": "raft", "label": "Raft"}])
        community = CommunityService(self.app.database, catalog)
        event = {"id": "winter", "revision": 1, "game_id": "raft", "title": "Winter geschafft",
                 "source": "test", "badge_id": "winter-badge", "member_ids": ["one", "two", "three", "four"]}
        first = community.record(event, publish=True)
        self.assertEqual(community.record(event, publish=True), first)
        cards = []

        def stub(_target, card):
            cards.append(card)
            return "remote-message-1"

        community.deliver_pending(stub)
        community.deliver_pending(stub)
        self.assertEqual(len(cards), 1)
        records = community.recent(OWNER)
        self.assertEqual(len(records), 1)
        self.assertEqual(len(records[0]["awards"]), 4)
        self.assertEqual({award["badge_id"] for award in records[0]["awards"]}, {"winter-badge"})
        self.assertEqual(records[0]["scene"]["position"], 0)
        corrected = {**event, "revision": 2, "title": "Winter gemeinsam geschafft"}
        self.assertEqual(community.record(corrected, publish=True)["scene_id"], first["scene_id"])
        community.deliver_pending(stub)
        self.assertEqual(cards[-1]["operation"], "update")
        self.assertEqual(cards[-1]["previous_remote_id"], "remote-message-1")
        self.assertEqual(community.recent(OWNER)[0]["scene"]["position"], 0)
        self.assertEqual(len(community.recent(OWNER)), 1)
        with self.assertRaises(ManagerError):
            community.record({**corrected, "title": "Other value in same revision"})

    def test_ambiguous_delivery_is_not_automatically_repeated_even_after_correction(self):
        community = self.app.community
        event = {"id": "first-boss", "revision": 1, "game_id": "aska", "instance_id": "aska",
                 "title": "Test", "source": "test", "badge_id": "boss", "member_ids": ["one"]}
        community.record(event, publish=True)
        calls = []

        def timeout(_target, card):
            calls.append(card)
            raise TimeoutError()

        community.deliver_pending(timeout)
        replacement = CommunityService(self.app.database, self.app.catalog, self.registry)
        replacement.record({**event, "revision": 2}, publish=True)
        replacement.deliver_pending(timeout)
        self.assertEqual(len(calls), 1)
        self.assertEqual(replacement.recent(Principal("observer", "viewer", frozenset())) , [])
        with self.app.database.connect() as db:
            self.assertEqual({row[0] for row in db.execute("SELECT state FROM deliveries")}, {"uncertain"})

    def test_executable_prototype_needs_no_installed_server_or_live_transport(self):
        result = demonstrate(self.root / "prototype")
        self.assertEqual(len(result["events"]), 1)
        self.assertEqual(len(result["events"][0]["awards"]), 4)
        self.assertEqual(len(result["discord_stub"]), 1)
        self.assertEqual(result["events"][0]["scene"]["id"], result["scene_id"])

    def test_old_revision_replay_cannot_overwrite_a_published_correction(self):
        community = self.app.community
        event = {"id": "correction", "revision": 1, "game_id": "aska", "title": "Original",
                 "source": "test", "badge_id": "badge", "member_ids": ["one"]}
        community.record(event)
        community.record({**event, "revision": 2, "title": "Corrected"}, publish=True)
        cards = []

        def stub(_target, card):
            cards.append(card)
            return "message-1"

        community.deliver_pending(stub)
        community.record(event, publish=True)
        community.deliver_pending(stub)
        self.assertEqual([card["content"] for card in cards], ["Corrected"])

    def test_correction_supersedes_pending_revisions_and_waits_for_inflight_delivery(self):
        community = self.app.community
        event = {"id": "pending-correction", "revision": 1, "game_id": "aska", "title": "Original",
                 "source": "test", "badge_id": "badge", "member_ids": ["one"]}
        community.record(event, publish=True)
        community.record({**event, "revision": 2, "title": "Second"}, publish=True)
        entered, release = threading.Event(), threading.Event()
        cards = []

        def slow(_target, card):
            cards.append(card)
            entered.set()
            release.wait(3)
            return "message-1"

        thread = threading.Thread(target=community.deliver_pending, args=(slow,))
        thread.start()
        try:
            self.assertTrue(entered.wait(2))
            community.record({**event, "revision": 3, "title": "Latest"}, publish=True)
            community.deliver_pending(slow)
            self.assertEqual(len(cards), 1)
        finally:
            release.set()
            thread.join(timeout=3)
        community.deliver_pending(slow)
        self.assertEqual([card["content"] for card in cards], ["Second", "Latest"])
        self.assertEqual(cards[-1]["previous_remote_id"], "message-1")


class PlatformHTTPTests(HTTPFixture):
    def test_jobs_api_uses_202_and_existing_requests_remain_compatible(self):
        with self.post("/api/jobs", {"server_id": "enshrouded", "operation": "start"}, self.server.token, self.url) as response:
            self.assertEqual(response.status, 202)
            job = json.load(response)["job"]
        # Waiting for close joins the worker; read contracts remain available.
        self.server.app.jobs.close()
        with urllib.request.urlopen(self.url + "/api/jobs?id=" + job["id"]) as response:
            self.assertEqual(json.load(response)["job"]["state"], "succeeded")
        with urllib.request.urlopen(self.url + "/api/status?server=enshrouded") as response:
            self.assertTrue(json.load(response)["status"]["running"])
        with self.assertRaises(urllib.error.HTTPError) as error:
            self.post("/api/jobs", {"operation": []}, self.server.token, self.url)
        self.assertEqual(error.exception.code, 409)
        error.exception.close()

    def test_family_permissions_cover_read_write_legacy_routes_and_forged_headers(self):
        add_aska(self.server.registry, self.root)
        self.server.identity = lambda _request: FAMILY
        for path in ("/api/config?server=aska", "/api/state?server=aska", "/api/logs?server=aska", "/api/status?server=enshrouded"):
            request = urllib.request.Request(self.url + path, headers={"X-Role": "owner", "Tailscale-User-Login": "owner"})
            with self.subTest(path=path), self.assertRaises(urllib.error.HTTPError) as error:
                urllib.request.urlopen(request)
            self.assertEqual(error.exception.code, 403)
            error.exception.close()
        for path, body in (("/api/config", {}), ("/api/setup", {}), ("/api/stop", {}), ("/api/restart", {}),
                           ("/api/servers", {}), ("/api/discord/settings", {"auto_notify": True}), ("/api/discord/send", {"message": "hello"})):
            with self.subTest(path=path), self.assertRaises(urllib.error.HTTPError) as error:
                self.post(path, {"server_id": "aska", **body}, self.server.token, self.url)
            self.assertEqual(error.exception.code, 403)
            error.exception.close()
        with self.assertRaises(urllib.error.HTTPError) as error:
            self.post("/api/jobs", {"server_id": {}, "operation": "start"}, self.server.token, self.url)
        self.assertEqual(error.exception.code, 403)
        error.exception.close()
        with urllib.request.urlopen(self.url + "/api/status?server=aska") as response:
            data = json.load(response)
        self.assertNotIn("config", data)
        self.assertNotIn("directory", data["status"])
        with self.post("/api/jobs", {"server_id": "aska", "operation": "start"}, self.server.token, self.url) as response:
            self.assertEqual(response.status, 202)
            self.assertNotIn("actor_id", json.load(response)["job"])
