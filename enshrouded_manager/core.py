from __future__ import annotations

import copy
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tempfile
import threading
import urllib.error
import urllib.request
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DIRECTORY = r"D:\SteamLibrary\steamapps\common\EnshroudedServer"
BRIDGE = ROOT / "scripts" / "windows-server.ps1"


class ManagerError(Exception):
    pass


def read_json(path: Path):
    try:
        return json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, ValueError) as exc:
        raise ManagerError(f"JSON-Datei nicht lesbar: {path.name}") from exc


def atomic_json(path: Path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=".manager-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            json.dump(value, handle, ensure_ascii=False, indent=2, allow_nan=False)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def local_path(value: str) -> Path:
    if os.name != "nt" and re.match(r"^[A-Za-z]:[\\/]", value):
        return Path("/mnt") / value[0].lower() / value[3:].replace("\\", "/")
    return Path(value).expanduser()


def validate_config(config):
    if not isinstance(config, dict):
        raise ManagerError("Die Serverkonfiguration muss ein JSON-Objekt sein.")
    for key in ("name", "saveDirectory", "logDirectory", "ip", "gameSettingsPreset"):
        if not isinstance(config.get(key), str) or not config[key].strip():
            raise ManagerError(f"{key}: Bitte einen nicht leeren Text angeben.")
    for key, low, high in (("slotCount", 1, 16), ("queryPort", 1, 65535)):
        if type(config.get(key)) is not int or not low <= config[key] <= high:
            raise ManagerError(f"{key}: Erlaubt sind ganze Zahlen von {low} bis {high}.")
    try:
        ipaddress.IPv4Address(config["ip"])
    except ipaddress.AddressValueError as exc:
        raise ManagerError("ip: Bitte eine gültige IPv4-Adresse angeben.") from exc
    if config["gameSettingsPreset"] not in ("Default", "Relaxed", "Hard", "Survival", "Custom"):
        raise ManagerError("Unbekannte Schwierigkeitsvorlage.")
    for key in ("enableVoiceChat", "enableTextChat"):
        if key in config and type(config[key]) is not bool:
            raise ManagerError(f"{key}: Erlaubt sind true oder false.")
    if "voiceChatMode" in config and config["voiceChatMode"] not in ("Proximity", "Global"):
        raise ManagerError("voiceChatMode: Erlaubt sind Proximity oder Global.")
    if "gameSettings" in config and not isinstance(config["gameSettings"], dict):
        raise ManagerError("gameSettings muss ein Objekt sein.")
    if "tags" in config and (not isinstance(config["tags"], list) or
                             any(not isinstance(tag, str) for tag in config["tags"])):
        raise ManagerError("tags muss eine Liste von Texten sein.")
    groups = config.get("userGroups")
    if not isinstance(groups, list) or not groups:
        raise ManagerError("Mindestens eine Benutzergruppe ist erforderlich.")
    names = set()
    for group in groups:
        if not isinstance(group, dict) or not isinstance(group.get("name"), str) or not group["name"].strip():
            raise ManagerError("Jede Benutzergruppe benötigt einen Namen.")
        if group["name"].casefold() in names:
            raise ManagerError("Benutzergruppen benötigen eindeutige Namen.")
        names.add(group["name"].casefold())
        if not isinstance(group.get("password"), str):
            raise ManagerError("Jede Benutzergruppe benötigt ein Passwortfeld.")
        for key in ("canKickBan", "canAccessInventories", "canEditWorld", "canEditBase", "canExtendBase"):
            if key in group and type(group[key]) is not bool:
                raise ManagerError(f"{group['name']}.{key}: Erlaubt sind true oder false.")
        slots = group.get("reservedSlots", 0)
        if type(slots) is not int or not 0 <= slots <= config["slotCount"]:
            raise ManagerError("Reservierte Plätze müssen zwischen 0 und slotCount liegen.")
    # Reject nonstandard JSON values (NaN, Infinity) before touching the original.
    try:
        json.dumps(config, allow_nan=False)
    except (ValueError, TypeError) as exc:
        raise ManagerError("Die Konfiguration enthält ungültige JSON-Werte.") from exc


def initial_config():
    groups = []
    for name in ("Admin", "Friend", "Guest", "Visitor"):
        groups.append({
            "name": name, "password": secrets.token_urlsafe(18),
            "canKickBan": name == "Admin",
            "canAccessInventories": name in ("Admin", "Friend"),
            "canEditWorld": name != "Visitor",
            "canEditBase": name in ("Admin", "Friend"),
            "canExtendBase": name == "Admin", "reservedSlots": 0,
        })
    return {
        "name": "Enshrouded Indy", "saveDirectory": "./savegame", "logDirectory": "./logs",
        "ip": "0.0.0.0", "queryPort": 15637, "slotCount": 16, "tags": ["German"],
        "voiceChatMode": "Proximity", "enableVoiceChat": False, "enableTextChat": False,
        "gameSettingsPreset": "Default", "userGroups": groups, "bans": [],
    }


class WindowsBridge:
    def __init__(self, directory: str):
        self.directory = directory

    def call(self, action: str):
        powershell = shutil.which("powershell.exe") or (
            "/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe" if os.name != "nt"
            else "powershell.exe"
        )
        script = str(BRIDGE)
        if os.name != "nt":
            result = subprocess.run(["wslpath", "-w", script], capture_output=True, text=True, check=True)
            script = result.stdout.strip()
        try:
            result = subprocess.run(
                [powershell, "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
                 "-File", script, "-Action", action, "-ServerDirectory", self.directory],
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
        "Content-Type": "application/json", "User-Agent": "EnshroudedIndy/0.1",
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


class Manager:
    def __init__(self, root: Path = ROOT, bridge=None):
        self.root = root
        self.settings_path = root / "manager.local.json"
        self.data = root / "data"
        self.lock = threading.RLock()
        self.settings = read_json(self.settings_path) if self.settings_path.exists() else {
            "server_directory": DEFAULT_DIRECTORY, "discord": {"webhook_url": "", "auto_notify": False},
        }
        if not isinstance(self.settings, dict) or not isinstance(self.settings.get("server_directory"), str):
            raise ManagerError("manager.local.json benötigt server_directory.")
        self.directory = local_path(self.settings["server_directory"])
        self.bridge = bridge or WindowsBridge(self.settings["server_directory"])

    @property
    def config_path(self):
        return self.directory / "enshrouded_server.json"

    def config(self):
        return read_json(self.config_path) if self.config_path.exists() else None

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
            "installed": (self.directory / "enshrouded_server.exe").is_file(),
            "configured": self.config_path.is_file(), "running": bool(processes),
            "processes": processes, "directory": self.settings["server_directory"],
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
            if not (self.directory / "enshrouded_server.exe").is_file():
                raise ManagerError("enshrouded_server.exe wurde im Installationsordner nicht gefunden.")
            self.require_stopped()
            created = not self.config_path.exists()
            if created:
                config = initial_config()
                validate_config(config)
                atomic_json(self.config_path, config)
            else:
                validate_config(self.config())
            if not self.settings_path.exists():
                atomic_json(self.settings_path, self.settings)
            self.event("Erstes Setup abgeschlossen." if created else "Vorhandene Konfiguration geprüft.")
            return {"message": "Server vorbereitet. Die Gruppenpasswörter findest du unter Einstellungen.", "created": created}

    def save_config(self, config):
        validate_config(config)
        with self.lock:
            self.require_stopped()
            if not self.config_path.exists():
                raise ManagerError("Bitte zuerst das erste Setup ausführen.")
            backup = self.data / "backups" / (datetime.now().strftime("%Y%m%d-%H%M%S-") + secrets.token_hex(3) + ".json")
            backup.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(self.config_path, backup)
            backup.chmod(0o600)
            atomic_json(self.config_path, config)
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
                validate_config(self.config())
                self.bridge.call("Start")
                self.event("Serverprozess gestartet.")
            message = "Server gestoppt." if action == "stop" else "Serverprozess gestartet. Den Verbindungsaufbau siehst du im Serverlog."
            warning = None
            if self.settings.get("discord", {}).get("auto_notify") and self.webhook():
                name = self.config().get("name", "Enshrouded")
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
            self.settings = updated
            self.event("Discord-Einstellungen gespeichert.")
            return {"message": "Discord-Einstellungen gespeichert."}

    def notify(self, message):
        if not self.webhook():
            raise ManagerError("Bitte zuerst einen Discord-Webhook hinterlegen.")
        send_discord(self.webhook(), message)
        self.event("Discord-Nachricht gesendet.")
        return {"message": "Nachricht an Discord gesendet."}

    def logs(self):
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
