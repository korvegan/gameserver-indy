"""Isolated browser QA server. Never controls a real server or sends Discord messages."""
from pathlib import Path
import sys
import tempfile
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from gameserver_manager.core import GameServerManager, atomic_json
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.games import GAME_TYPES
from gameserver_manager.server import serve
from test_manager import FakeBridge

with tempfile.TemporaryDirectory(prefix="gameserver-browser-") as temporary:
    root = Path(temporary)
    installation = root / "EnshroudedServer"
    installation.mkdir()
    (installation / "enshrouded_server.exe").touch()
    atomic_json(root / "manager.local.json", {
        "server_directory": str(installation), "discord": {"webhook_url": "", "auto_notify": False},
    })
    aska = root / "ASKA Server"
    aska.mkdir()
    (aska / "AskaServer.exe").touch()
    user_profile = root / "WindowsUser"
    logs = user_profile / "AppData/LocalLow/Sand Sailor Studio/Aska"
    logs.mkdir(parents=True)
    (logs / "Player.log").write_text("password = browser-secret-from-log\nThe session is now open!\n")
    with patch("gameserver_manager.discord.send_discord"), patch("gameserver_manager.operations.send_discord"), patch("gameserver_manager.instances.WindowsServerBridge", side_effect=lambda *args: FakeBridge(str(user_profile))), patch.object(GAME_TYPES["aska"], "default_directory", str(aska)):
        serve(ServerRegistry(root, GameServerManager(root, FakeBridge())), 8766)
