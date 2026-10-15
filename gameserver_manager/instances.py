"""Composition of services for one installed server instance."""
from pathlib import Path
import threading

from .configuration import ManagerError, local_path, read_json
from .discord import DiscordService
from .games import GAME_TYPES
from .history import TechnicalHistory
from .instance_config import InstanceConfiguration
from .instance_logs import InstanceLogs
from .operations import InstanceOperations
from .paths import ROOT
from .processes import WindowsServerBridge

DEFAULT_DIRECTORY = GAME_TYPES["enshrouded"].default_directory


class GameServerManager:
    def __init__(self, root=ROOT, bridge=None, profile=None, settings=None, lock=None, settings_lock=None):
        self.root = Path(root)
        self.settings_path = self.root / "manager.local.json"
        self.lock = lock or threading.RLock()
        self.settings_lock = settings_lock or threading.RLock()
        self.settings = settings if settings is not None else read_json(self.settings_path) if self.settings_path.exists() else {
            "server_directory": DEFAULT_DIRECTORY, "discord": {"webhook_url": "", "auto_notify": False},
        }
        if not isinstance(self.settings, dict):
            raise ManagerError("manager.local.json benötigt server_directory.")
        self.profile = profile or {"id": "enshrouded", "game": "enshrouded", "label": "Enshrouded",
                                   "server_directory": self.settings.get("server_directory", DEFAULT_DIRECTORY)}
        self.game = GAME_TYPES[self.profile["game"]]
        self.data = self.root / "data" if self.profile["id"] == "enshrouded" else self.root / "data" / "servers" / self.profile["id"]
        self.diagnostic_directory = self.data / "logs"
        self._windows_user_profile = None
        self.directory = local_path(self.profile["server_directory"])
        self.bridge = bridge or WindowsServerBridge(self.profile["server_directory"], self.game.id,
                                                    str(self.config_path), self.profile.get("launcher_script"), str(self.diagnostic_directory))
        self.configuration = InstanceConfiguration(self)
        self.operations = InstanceOperations(self)
        self.diagnostics = InstanceLogs(self)
        self.discord = DiscordService(self)
        self.history = TechnicalHistory(self.data)

    @property
    def config_path(self):
        return local_path(self.profile["config_path"]) if self.profile.get("config_path") else self.directory / self.game.config_filename

    def config(self):
        return self.configuration.read()

    def public_config(self):
        return self.game.public_config(self.config())

    def setup(self):
        return self.configuration.setup()

    def save_config(self, config):
        return self.configuration.save(config)

    def require_stopped(self):
        return self.operations.require_stopped()

    def action(self, action):
        return self.operations.run(action)

    def webhook(self):
        return self.discord.webhook()

    def save_discord(self, settings):
        return self.discord.save(settings)

    def notify(self, message):
        return self.discord.notify(message)

    def event(self, message):
        return self.history.append(message)

    def events(self):
        return self.history.recent()

    def logs(self, source=None):
        return self.diagnostics.read(source)

    def status(self):
        state = self.bridge.call("Status")
        processes = state.get("processes", [])
        self._windows_user_profile = state.get("user_profile") or self._windows_user_profile
        with self.settings_lock:
            auto_notify = self.settings.get("discord", {}).get("auto_notify", False)
        return {
            "installed": (self.directory / self.game.executable).is_file(),
            "configured": self.config_path.is_file(), "running": bool(processes),
            "processes": processes, "directory": self.profile["server_directory"],
            "server_id": self.profile["id"], "game": self.game.id, "game_label": self.game.label,
            "profile_label": self.profile["label"], "config_path": str(self.config_path),
            "external_launcher": bool(self.profile.get("launcher_script")),
            "starting": bool(state.get("launcher_running") and not processes),
            "discord_configured": bool(self.webhook()), "auto_notify": auto_notify,
        }
