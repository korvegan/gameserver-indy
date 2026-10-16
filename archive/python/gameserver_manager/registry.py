"""Persistent server profiles with per-request selection, never a global active server."""
import copy
from pathlib import Path
import re
import threading

from .configuration import ManagerError, atomic_json, local_path, read_json
from .instances import GameServerManager
from .paths import ROOT
from .games import GAME_TYPES
from .steam import branch_name
from .connections import public_host


class ServerRegistry:
    def __init__(self, root=ROOT, manager=None):
        self.root = Path(root)
        self.path = self.root / "manager.local.json"
        self.lock = threading.RLock()
        self.settings = manager.settings if manager else read_json(self.path) if self.path.exists() else {}
        if not isinstance(self.settings, dict):
            raise ManagerError("manager.local.json muss ein JSON-Objekt enthalten.")
        self.settings.setdefault("discord", {"webhook_url": "", "auto_notify": False})
        self.settings.setdefault("servers", [{
            "id": "enshrouded", "game": "enshrouded", "label": "Enshrouded",
            "server_directory": self.settings.get("server_directory", GAME_TYPES["enshrouded"].default_directory),
        }])
        profiles = self.settings["servers"]
        if not isinstance(profiles, list) or not profiles:
            raise ManagerError("Mindestens ein Serverprofil ist erforderlich.")
        self.managers = {}
        directories = set()
        for profile in profiles:
            self.validate_profile(profile)
            identity = self.directory_identity(profile["server_directory"])
            if profile["id"] in self.managers or identity in directories:
                raise ManagerError("Serverprofile benötigen eindeutige IDs und getrennte Installationsordner.")
            directories.add(identity)
            supplied = manager if manager and manager.profile["id"] == profile["id"] else None
            self.managers[profile["id"]] = supplied or GameServerManager(self.root, profile=profile, settings=self.settings, settings_lock=self.lock)
            if supplied:
                manager.settings_lock = self.lock
            self.managers[profile['id']].backups.peers = self.instances

    def instances(self):
        with self.lock:
            return list(self.managers.values())

    @staticmethod
    def directory_identity(directory):
        return str(local_path(str(directory)).resolve()).replace("\\", "/").rstrip("/").casefold()

    @staticmethod
    def validate_profile(profile):
        if not isinstance(profile, dict) or not isinstance(profile.get("game"), str) or profile["game"] not in GAME_TYPES:
            raise ManagerError("Für dieses Spiel ist noch kein Serveradapter eingerichtet.")
        if not isinstance(profile.get("id"), str) or not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,47}", profile["id"]):
            raise ManagerError("Die Profil-ID benötigt 1–48 kleine Buchstaben, Zahlen, Unterstriche oder Bindestriche.")
        if not isinstance(profile.get("label"), str) or not 1 <= len(profile["label"].strip()) <= 80:
            raise ManagerError("Bitte einen Profilnamen mit höchstens 80 Zeichen angeben.")
        for key in ("server_directory", "config_path", "launcher_script", "log_directory", "steam_manifest", "diagnostic_directory"):
            value = profile.get(key, "")
            if key == "server_directory" or value:
                if (not isinstance(value, str) or not value.strip() or any(c in value for c in '\r\n\0"')
                        or not local_path(value).is_absolute()):
                    raise ManagerError(f"{key}: Bitte einen absoluten lokalen Pfad angeben.")
        if 'steam_branch' in profile:
            branch_name(profile['steam_branch'])
        if 'connection' in profile:
            connection = profile['connection']
            if not isinstance(connection, dict) or set(connection) - {'public_host'}:
                raise ManagerError('Ungültige Beitrittseinstellungen.')
            host = public_host(connection.get('public_host', ''))
            if host and GAME_TYPES[profile['game']].schema.get('connection', {}).get('mode') != 'ip_query':
                raise ManagerError('Für dieses Spiel ist kein direkter IP-/Domain-Beitritt eingerichtet.')
        if profile.get("launcher_script") and not GAME_TYPES[profile["game"]].schema.get("external_launcher"):
            raise ManagerError("Dieses Spielprofil unterstützt noch keine externen PowerShell-Starter.")
        if profile.get("launcher_script") and not profile["launcher_script"].lower().endswith(".ps1"):
            raise ManagerError("Der externe Starter muss eine PowerShell-Datei sein.")

    def get(self, server_id=None, explicit=False):
        if server_id is None:
            if explicit and len(self.managers) > 1:
                raise ManagerError("Bitte das Serverprofil ausdrücklich auswählen.")
            return next(iter(self.managers.values()))
        if not isinstance(server_id, str) or server_id not in self.managers:
            raise ManagerError("Dieses Serverprofil existiert nicht.")
        return self.managers[server_id]

    def catalog(self):
        with self.lock:
            return {
                "servers": [dict(manager.profile) for manager in self.managers.values()],
                "games": [{"id": game.id, "label": game.label, "default_directory": game.default_directory,
                           "external_launcher": bool(game.schema.get("external_launcher"))}
                          for game in GAME_TYPES.values()],
            }

    def register(self, body):
        profile = {key: body[key] for key in ("id", "game", "label", "server_directory", "config_path", "launcher_script", "log_directory", "steam_manifest", "steam_branch", "connection") if key in body}
        self.validate_profile(profile)
        with self.lock:
            if profile["id"] in self.managers:
                raise ManagerError("Diese Profil-ID ist bereits vergeben.")
            identity = self.directory_identity(profile["server_directory"])
            if any(self.directory_identity(item.directory) == identity for item in self.managers.values()):
                raise ManagerError("Diese Installation ist bereits als Serverprofil eingetragen.")
            updated = copy.deepcopy(self.settings)
            updated["schema_version"] = 2
            updated["servers"].append(profile)
            atomic_json(self.path, updated)
            self.settings.clear()
            self.settings.update(updated)
            self.managers[profile["id"]] = GameServerManager(self.root, profile=profile, settings=self.settings, settings_lock=self.lock)
            self.managers[profile['id']].backups.peers = self.instances
        return {"message": "Serverprofil hinzugefügt. Spieldateien und Welten wurden nicht verändert.", "server_id": profile["id"]}

    def save_connection(self, server_id, host):
        host = public_host(host)
        with self.lock:
            manager = self.get(server_id, explicit=True)
            profile = {**manager.profile, 'connection': {'public_host': host}}
            self.validate_profile(profile)
            updated = copy.deepcopy(self.settings)
            for item in updated['servers']:
                if item['id'] == server_id:
                    item['connection'] = profile['connection']
            atomic_json(self.path, updated)
            self.settings.clear()
            self.settings.update(updated)
            manager.profile = profile
        return {'message': 'Öffentliche Beitrittsadresse gespeichert.'}
