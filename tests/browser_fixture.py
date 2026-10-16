"""Isolated browser QA server. Never controls a real server or sends Discord messages."""
from pathlib import Path
import base64
import sys
import tempfile
import time
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from gameserver_manager.core import GameServerManager, atomic_json
from gameserver_manager.registry import ServerRegistry
from gameserver_manager.games import GAME_TYPES
from gameserver_manager.server import LocalServer, FamilyServer
from family_proxy_fixture import FamilyProxy
from gameserver_manager.security import Principal, local_owner
from test_manager import FakeBridge
from gameserver_manager.configuration import ManagerError


class BrowserBridge(FakeBridge):
    def call(self, action):
        if action == "Start":
            time.sleep(1.2)
        return super().call(action)


class BrowserSteam:
    def query(self, app_id, branch):
        time.sleep(1.2)
        if branch != 'public' or app_id not in (2278520, 3246670):
            raise ManagerError('Kein Steam-Testbranch eingerichtet.')
        return '200' if app_id == 2278520 else '300'


class BrowserWhatsApp:
    configured = True
    def __init__(self):
        self.state = 'STOPPED'
        self.checks = 0
    def session(self):
        if self.state == 'SCAN_QR_CODE':
            self.checks += 1
            if self.checks >= 3:
                self.state = 'WORKING'
        return {'status': self.state, 'exists': True}
    def connect(self):
        self.state, self.checks = 'SCAN_QR_CODE', 0
    def qr(self):
        return base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aPioAAAAASUVORK5CYII=')
    def groups(self):
        return [{'id': '123456789@g.us', 'label': 'Browser-Gruppe'}, {'id': '987654321@g.us', 'label': 'Zweite Gruppe'}]
    def send(self, group_id, text):
        pass  # Fixture delivery only, never a real WhatsApp request.
    def logout(self):
        self.state = 'STOPPED'


def steam_manifest(directory, app_id, build):
    steamapps = directory / 'steamapps'
    steamapps.mkdir()
    (steamapps / f'appmanifest_{app_id}.acf').write_text(
        f'"AppState" {{"appid" "{app_id}" "installdir" "{directory.name}" "buildid" "{build}" "StateFlags" "4"}}')


def identity(request):
    # Test-only injection through LocalServer's constructor. Product code does
    # not recognize this header or trust client-supplied roles.
    if request.headers.get("X-QA-Identity") in ("family", "viewer"):
        role = request.headers["X-QA-Identity"]
        return Principal("test-" + role, role, frozenset({"aska"}))
    return local_owner(request)

with tempfile.TemporaryDirectory(prefix="gameserver-browser-") as temporary:
    root = Path(temporary)
    installation = root / "EnshroudedServer"
    installation.mkdir()
    (installation / "enshrouded_server.exe").touch()
    (installation / 'savegame').mkdir()
    (installation / 'savegame/world0.ksd').write_bytes(b'Browser QA world')
    steam_manifest(installation, 2278520, '100')
    (installation / 'enshrouded_server_readme.txt').write_text('Version: 1.0.0.0\n')
    atomic_json(root / "manager.local.json", {
        "server_directory": str(installation), "discord": {"webhook_url": "", "auto_notify": False},
        "catalog_games": [{"id": "raft", "label": "Raft"}],
    })
    aska = root / "ASKA Server"
    aska.mkdir()
    (aska / "AskaServer.exe").touch()
    steam_manifest(aska, 3246670, '300')
    user_profile = root / "WindowsUser"
    logs = user_profile / "AppData/LocalLow/Sand Sailor Studio/Aska"
    logs.mkdir(parents=True)
    (logs / "Player.log").write_text("password = browser-secret-from-log\nThe session is now open!\n")
    with patch("gameserver_manager.discord.send_discord"), patch("gameserver_manager.operations.send_discord"), patch("gameserver_manager.instances.WindowsServerBridge", side_effect=lambda *args: BrowserBridge(str(user_profile))), patch.object(GAME_TYPES["aska"], "default_directory", str(aska)):
        with LocalServer(ServerRegistry(root, GameServerManager(root, BrowserBridge())), 8766, identity=identity) as server:
            server.app.whatsapp.client = BrowserWhatsApp()
            server.app.updates.provider = BrowserSteam()
            server.app.connections.provider = lambda: {'lan': ['192.168.1.20'], 'public': '8.8.8.8'}
            server.app.connections._collect()
            server.app.community.record({"id": "qa-milestone", "revision": 1, "game_id": "raft", "title": "Gemeinsamer Testmoment",
                "source": "test", "badge_id": "qa-badge", "member_ids": ["qa-one", "qa-two", "qa-three", "qa-four"]})
            family = FamilyServer(server.app, 'indy.example.ts.net', 18767)
            proxy = FamilyProxy(family)
            server.app.access.configure(Principal('local-owner', 'owner'), {'enabled': True, 'hostname': family.hostname, 'port': family.server_port})
            server.app.access.runtime = {'active': True, 'hostname': family.hostname, 'port': family.server_port}
            import threading
            for listener in (family, proxy):
                threading.Thread(target=listener.serve_forever, daemon=True).start()
            print("GameServer Indy: http://127.0.0.1:8766", flush=True)
            try:
                server.serve_forever()
            except KeyboardInterrupt:
                pass
            finally:
                for listener in (proxy, family):
                    listener.shutdown()
                    listener.server_close()
