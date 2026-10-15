"""Isolated browser QA server. Never controls a real server or sends Discord messages."""
from pathlib import Path
import sys
import tempfile
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from enshrouded_manager.core import Manager, atomic_json
from enshrouded_manager.server import serve
from test_manager import FakeBridge

with tempfile.TemporaryDirectory(prefix="enshrouded-browser-") as temporary:
    root = Path(temporary)
    installation = root / "EnshroudedServer"
    installation.mkdir()
    (installation / "enshrouded_server.exe").touch()
    atomic_json(root / "manager.local.json", {
        "server_directory": str(installation), "discord": {"webhook_url": "", "auto_notify": False},
    })
    with patch("enshrouded_manager.core.send_discord"):
        serve(Manager(root, FakeBridge()), 8766)
