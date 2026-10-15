from __future__ import annotations

import copy
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import threading
import urllib.error
import urllib.request
from datetime import datetime, timezone

from .configuration import ManagerError, atomic_json, local_path, read_json
from .games import GAME_TYPES

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DIRECTORY = GAME_TYPES["enshrouded"].default_directory
BRIDGE = ROOT / "scripts" / "windows-server.ps1"
# Preserve the existing Python API and CLI while adapters own their rules.
validate_config = GAME_TYPES["enshrouded"].validate_config
initial_config = GAME_TYPES["enshrouded"].initial_config


class WindowsServerBridge:
    def __init__(self, directory: str, game="enshrouded", config_path=None, launcher_script=None):
        self.directory = directory
        self.game = game
        self.config_path = config_path
        self.launcher_script = launcher_script

    def call(self, action: str):
        powershell = shutil.which("powershell.exe") or (
            "/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe" if os.name != "nt"
            else "powershell.exe"
        )
        def windows_path(value):
            if os.name == "nt" or str(value).startswith("\\\\") or re.match(r"^[A-Za-z]:[\\/]", str(value)):
                return str(value)
            converted = subprocess.run(["wslpath", "-w", str(value)], capture_output=True, text=True, timeout=10)
            if converted.returncode:
                raise ManagerError("Ein Serverpfad konnte nicht in einen Windows-Pfad umgewandelt werden.")
            return converted.stdout.strip()
        script = windows_path(BRIDGE)
        arguments = [powershell, "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
                     "-File", script, "-Action", action, "-ServerDirectory", windows_path(self.directory),
                     "-ExecutableName", GAME_TYPES[self.game].executable,
                     "-SteamAppId", str(GAME_TYPES[self.game].schema.get("launch_steam_app_id", 0))]
        config_argument = GAME_TYPES[self.game].schema.get("config_argument")
        if config_argument:
            arguments.extend(["-ConfigArgument", config_argument])
        for flag, value in (("-ConfigPath", self.config_path), ("-LauncherScript", self.launcher_script)):
            if value:
                arguments.extend([flag, windows_path(value)])
        try:
            result = subprocess.run(
                arguments,
                capture_output=True, timeout=65, encoding="utf-8", errors="replace",
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise ManagerError("Windows-PowerShell ist nicht erreichbar oder hat nicht rechtzeitig geantwortet.") from exc
        if result.returncode:
            # Do not echo subprocess arguments or arbitrary Windows error output into the UI.
            error = result.stdout.strip()
            try:
                error = json.loads(error).get("error", "Windows-Prozesssteuerung fehlgeschlagen.")
            except ValueError:
                error = "Windows-Prozesssteuerung fehlgeschlagen. Windows-/WSL-Zugriff prüfen."
            raise ManagerError(error)
        try:
            return json.loads(result.stdout.strip())
        except ValueError as exc:
            raise ManagerError("Windows hat keine gültige Statusantwort geliefert.") from exc


def send_discord(url: str, message: str):
    if not re.fullmatch(r"https://(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v\d+)?/webhooks/\d+/[A-Za-z0-9_-]+", url):
        raise ManagerError("Bitte eine gültige Discord-Webhook-URL verwenden.")
    if not isinstance(message, str) or not 1 <= len(message.strip()) <= 2000:
        raise ManagerError("Die Discord-Nachricht muss 1 bis 2000 Zeichen enthalten.")
    payload = json.dumps({"content": message.strip(), "allowed_mentions": {"parse": []}}, ensure_ascii=False).encode()
    request = urllib.request.Request(url + "?wait=true", data=payload, headers={
        "Content-Type": "application/json", "User-Agent": "GameServerIndy/0.2",
    }, method="POST")
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None
    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=12) as response:
            response.read(16384)
    except urllib.error.HTTPError as exc:
        if exc.code == 429:
            raise ManagerError("Discord begrenzt gerade die Anfragen. Bitte später erneut senden.") from None
        raise ManagerError(f"Discord hat die Nachricht abgelehnt (HTTP {exc.code}).") from None
    except (OSError, urllib.error.URLError) as exc:
        raise ManagerError("Discord ist derzeit nicht erreichbar.") from None


class GameServerManager:
    def __init__(self, root: Path = ROOT, bridge=None, profile=None, settings=None, lock=None):
        self.root = root
        self.settings_path = root / "manager.local.json"
        self.lock = lock or threading.RLock()
        self.settings = settings if settings is not None else read_json(self.settings_path) if self.settings_path.exists() else {
            "server_directory": DEFAULT_DIRECTORY, "discord": {"webhook_url": "", "auto_notify": False},
        }
        if not isinstance(self.settings, dict):
            raise ManagerError("manager.local.json benötigt server_directory.")
        self.profile = profile or {
            "id": "enshrouded", "game": "enshrouded", "label": "Enshrouded",
            "server_directory": self.settings.get("server_directory", DEFAULT_DIRECTORY),
        }
        self.game = GAME_TYPES[self.profile["game"]]
        self.data = root / "data" if self.profile["id"] == "enshrouded" else root / "data" / "servers" / self.profile["id"]
        self.directory = local_path(self.profile["server_directory"])
        self.bridge = bridge or WindowsServerBridge(self.profile["server_directory"], self.game.id,
                                                    str(self.config_path), self.profile.get("launcher_script"))

    @property
    def config_path(self):
        return local_path(self.profile["config_path"]) if self.profile.get("config_path") else self.directory / self.game.config_filename

    def config(self):
        return self.game.read_config(self.config_path) if self.config_path.exists() else None

    def public_config(self):
        return self.game.public_config(self.config())

    def event(self, message: str):
        self.data.mkdir(parents=True, exist_ok=True)
        with (self.data / "events.jsonl").open("a", encoding="utf-8") as handle:
            handle.write(json.dumps({"time": datetime.now(timezone.utc).isoformat(), "message": message}, ensure_ascii=False) + "\n")

    def events(self):
        path = self.data / "events.jsonl"
        if not path.exists():
            return []
        with path.open("rb") as handle:
            handle.seek(max(0, path.stat().st_size - 32768))
            lines = handle.read().decode("utf-8", errors="replace").splitlines()
        entries = []
        for line in lines[-30:]:
            try:
                entries.append(json.loads(line))
            except ValueError:
                pass
        return list(reversed(entries))

    def status(self):
        processes = self.bridge.call("Status").get("processes", [])
        return {
            "installed": (self.directory / self.game.executable).is_file(),
            "configured": self.config_path.is_file(), "running": bool(processes),
            "processes": processes, "directory": self.profile["server_directory"],
            "server_id": self.profile["id"], "game": self.game.id, "game_label": self.game.label,
            "profile_label": self.profile["label"], "config_path": str(self.config_path),
            "external_launcher": bool(self.profile.get("launcher_script")),
            "discord_configured": bool(self.webhook()),
            "auto_notify": self.settings.get("discord", {}).get("auto_notify", False),
        }

    def webhook(self):
        return os.environ.get("DISCORD_WEBHOOK_URL", "") or self.settings.get("discord", {}).get("webhook_url", "")

    def require_stopped(self):
        if self.bridge.call("Status").get("processes"):
            raise ManagerError("Bitte zuerst den Server stoppen. Die laufende Konfiguration wird nicht überschrieben.")

    def setup(self):
        with self.lock:
            if not (self.directory / self.game.executable).is_file():
                raise ManagerError(f"{self.game.executable} wurde im Installationsordner nicht gefunden.")
            self.require_stopped()
            created = not self.config_path.exists()
            if created:
                config = self.game.initial_config()
                self.game.validate_config(config)
                self.game.write_config(self.config_path, config)
            else:
                self.game.validate_config(self.config())
            if not self.settings_path.exists():
                atomic_json(self.settings_path, self.settings)
            self.event("Erstes Setup abgeschlossen." if created else "Vorhandene Konfiguration geprüft.")
            return {"message": "Server vorbereitet. Die Zugangsdaten findest du unter Einstellungen.", "created": created}

    def save_config(self, config):
        with self.lock:
            self.require_stopped()
            if not self.config_path.exists():
                raise ManagerError("Bitte zuerst das erste Setup ausführen.")
            previous = self.config()
            config = self.game.merge_config(config, previous)
            self.game.validate_config(config)
            self.game.validate_edit(config, previous, self.profile)
            suffix = self.config_path.suffix
            backup = self.data / "backups" / (datetime.now().strftime("%Y%m%d-%H%M%S-") + secrets.token_hex(3) + suffix)
            backup.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(self.config_path, backup)
            backup.chmod(0o600)
            self.game.write_config(self.config_path, config)
            self.event("Servereinstellungen gespeichert; vorherige Konfiguration gesichert.")
            return {"message": "Einstellungen gespeichert. Sie gelten beim nächsten Serverstart.", "backup": backup.name}

    def action(self, action):
        if action not in ("start", "stop", "restart"):
            raise ManagerError("Unbekannte Serveraktion.")
        with self.lock:
            before = self.bridge.call("Status").get("processes", [])
            if action == "start" and before:
                return {"message": "Der Serverprozess läuft bereits."}
            if action == "stop" and not before:
                return {"message": "Der Server ist bereits gestoppt."}
            if action in ("stop", "restart") and before:
                self.bridge.call("Stop")
                self.event("Server gestoppt.")
            if action in ("start", "restart"):
                if not self.config_path.exists():
                    raise ManagerError("Bitte zuerst das erste Setup ausführen.")
                config = self.config()
                self.game.validate_config(config)
                self.game.validate_start(config)
                self.bridge.call("Start")
                self.event("Serverprozess gestartet.")
            message = "Server gestoppt." if action == "stop" else "Serverprozess gestartet. Den Verbindungsaufbau siehst du im Serverlog."
            warning = None
            external_start = self.profile.get("launcher_script") and action in ("start", "restart")
            if self.settings.get("discord", {}).get("auto_notify") and self.webhook() and not external_start:
                name = self.game.title(self.config())
                try:
                    send_discord(self.webhook(), f"{name}: {'Server gestoppt.' if action == 'stop' else 'Serverprozess gestartet.'}")
                    self.event("Discord-Statusnachricht gesendet.")
                except ManagerError as exc:
                    warning = str(exc)
                    self.event("Discord-Statusnachricht fehlgeschlagen.")
            return {"message": message, "warning": warning}

    def save_discord(self, settings):
        if not isinstance(settings, dict) or type(settings.get("auto_notify")) is not bool:
            raise ManagerError("Ungültige Discord-Einstellungen.")
        with self.lock:
            previous = self.settings.get("discord", {}).get("webhook_url", "")
            url = settings.get("webhook_url", "")
            if not isinstance(url, str):
                raise ManagerError("Ungültige Webhook-URL.")
            url = url.strip() or previous
            if settings.get("clear_webhook"):
                url = ""
            if url and not re.fullmatch(r"https://(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v\d+)?/webhooks/\d+/[A-Za-z0-9_-]+", url):
                raise ManagerError("Bitte eine gültige Discord-Webhook-URL verwenden.")
            updated = copy.deepcopy(self.settings)
            updated["discord"] = {"webhook_url": url, "auto_notify": settings["auto_notify"]}
            atomic_json(self.settings_path, updated)
            self.settings.clear()
            self.settings.update(updated)
            self.event("Discord-Einstellungen gespeichert.")
            return {"message": "Discord-Einstellungen gespeichert."}

    def notify(self, message):
        if not self.webhook():
            raise ManagerError("Bitte zuerst einen Discord-Webhook hinterlegen.")
        send_discord(self.webhook(), message)
        self.event("Discord-Nachricht gesendet.")
        return {"message": "Nachricht an Discord gesendet."}

    def logs(self):
        if self.game.schema.get("logs") == "external":
            return {"file": None, "text": f"{self.game.label}: Status und Sitzung im Starterfenster prüfen. Rohe Unity-Logs können Zugangsdaten enthalten und werden hier nicht angezeigt."}
        config = self.config() or {}
        directory = local_path(config.get("logDirectory", "./logs"))
        if not directory.is_absolute():
            directory = self.directory / directory
        files = [p for p in directory.glob("*") if p.is_file() and p.suffix.lower() in (".log", ".txt")]
        if not files:
            return {"file": None, "text": "Noch keine Serverlogs vorhanden."}
        latest = max(files, key=lambda p: p.stat().st_mtime)
        with latest.open("rb") as handle:
            handle.seek(max(0, latest.stat().st_size - 65536))
            content = handle.read().decode("utf-8", errors="replace")
        return {"file": latest.name, "text": "\n".join(content.splitlines()[-200:])}
