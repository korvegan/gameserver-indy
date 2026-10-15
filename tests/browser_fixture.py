"""Isolated browser QA server. Never controls a real server or sends Discord messages."""
from pathlib import Path
import sys
import tempfile
import time
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from gameserver_manager.core import GameServerManager, atomic_json
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.games import GAME_TYPES
from gameserver_manager.server import LocalServer
from gameserver_manager.security import Principal, local_owner
from test_manager import FakeBridge


class BrowserBridge(FakeBridge):
    def call(self, action):
        if action == "Start":
            time.sleep(1.2)
        return super().call(action)


def identity(request):
    # Test-only injection through LocalServer's constructor. Product code does
    # not recognize this header or trust client-supplied roles.
    if request.headers.get("X-QA-Identity") == "family":
        return Principal("test-family", "family", frozenset({"aska"}))
    return local_owner(request)

with tempfile.TemporaryDirectory(prefix="gameserver-browser-") as temporary:
    root = Path(temporary)
    installation = root / "EnshroudedServer"
    installation.mkdir()
    (installation / "enshrouded_server.exe").touch()
    atomic_json(root / "manager.local.json", {
        "server_directory": str(installation), "discord": {"webhook_url": "", "auto_notify": False},
        "catalog_games": [{"id": "raft", "label": "Raft"}],
    })
    aska = root / "ASKA Server"
    aska.mkdir()
    (aska / "AskaServer.exe").touch()
    user_profile = root / "WindowsUser"
    logs = user_profile / "AppData/LocalLow/Sand Sailor Studio/Aska"
    logs.mkdir(parents=True)
    (logs / "Player.log").write_text("password = browser-secret-from-log\nThe session is now open!\n")
    with patch("gameserver_manager.discord.send_discord"), patch("gameserver_manager.operations.send_discord"), patch("gameserver_manager.instances.WindowsServerBridge", side_effect=lambda *args: BrowserBridge(str(user_profile))), patch.object(GAME_TYPES["aska"], "default_directory", str(aska)):
        with LocalServer(ServerRegistry(root, GameServerManager(root, BrowserBridge())), 8766, identity=identity) as server:
            server.app.community.record({"id": "qa-milestone", "revision": 1, "game_id": "raft", "title": "Gemeinsamer Testmoment",
                "source": "test", "badge_id": "qa-badge", "member_ids": ["qa-one", "qa-two", "qa-three", "qa-four"]})
            print("GameServer Indy: http://127.0.0.1:8766", flush=True)
            try:
                server.serve_forever()
            except KeyboardInterrupt:
                pass
