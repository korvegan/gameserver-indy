"""Game adapters for configuration and native dedicated server behavior."""
import copy
import ipaddress
import json
from pathlib import Path
import re
import secrets

from .configuration import ManagerError, atomic_json, atomic_text, local_path, read_json


class EnshroudedGame:
    id = "enshrouded"

    @staticmethod
    def read_config(path):
        return read_json(path)

    @staticmethod
    def public_config(config):
        return config

    @staticmethod
    def merge_config(config, previous):
        return config

    @staticmethod
    def validate_edit(config, previous, profile):
        pass

    @staticmethod
    def validate_start(config):
        pass

    @staticmethod
    def write_config(path, config):
        atomic_json(path, config)

    @staticmethod
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
        try:
            json.dumps(config, allow_nan=False)
        except (ValueError, TypeError) as exc:
            raise ManagerError("Die Konfiguration enthält ungültige JSON-Werte.") from exc

    @staticmethod
    def initial_config():
        groups = []
        for name in ("Admin", "Friend", "Guest", "Visitor"):
            groups.append({
                "name": name, "password": secrets.token_urlsafe(18),
                "canKickBan": name == "Admin", "canAccessInventories": name in ("Admin", "Friend"),
                "canEditWorld": name != "Visitor", "canEditBase": name in ("Admin", "Friend"),
                "canExtendBase": name == "Admin", "reservedSlots": 0,
            })
        return {
            "name": "Enshrouded Indy", "saveDirectory": "./savegame", "logDirectory": "./logs",
            "ip": "0.0.0.0", "queryPort": 15637, "slotCount": 16, "tags": ["German"],
            "voiceChatMode": "Proximity", "enableVoiceChat": False, "enableTextChat": False,
            "gameSettingsPreset": "Default", "userGroups": groups, "bannedAccounts": [],
        }

    @staticmethod
    def title(config):
        return config.get("name", "Enshrouded")


class AskaGame:
    id = "aska"
    secrets = ("password", "authentication token")
    choices = {
        "keep server world alive": ("false", "true"),
        "mode": ("normal", "custom"),
        "autosave style": ("every morning", "disabled", "every 5 minutes", "every 10 minutes",
                           "every 15 minutes", "every 20 minutes"),
        "region": ("default", "asia", "japan", "europe", "south america", "south korea", "usa east",
                   "usa west", "australia", "canada east", "hong kong", "india", "turkey",
                   "united arab emirates", "usa south central", "south africa"),
    }

    @staticmethod
    def read_config(path):
        config = {}
        for line in path.read_text(encoding="utf-8-sig").splitlines():
            stripped = line.strip()
            if not stripped or stripped.startswith(("//", "#", ";")):
                continue
            key, separator, value = stripped.partition("=")
            key = key.strip()
            if not separator or not key or key in config:
                raise ManagerError("Die ASKA-Konfiguration enthält eine ungültige oder doppelte Einstellung.")
            config[key] = value.strip()
        return config

    @classmethod
    def public_config(cls, config):
        if config is None:
            return None
        public = copy.deepcopy(config)
        for key in cls.secrets:
            public[key] = ""
        return public

    @classmethod
    def merge_config(cls, config, previous):
        if not isinstance(config, dict):
            raise ManagerError("Die Serverkonfiguration muss ein JSON-Objekt sein.")
        merged = copy.deepcopy(config)
        for key in cls.secrets:
            if not merged.get(key):
                merged[key] = previous.get(key, "")
        return merged

    @classmethod
    def validate_config(cls, config):
        if not isinstance(config, dict):
            raise ManagerError("Die Serverkonfiguration muss ein JSON-Objekt sein.")
        for key, value in config.items():
            if (not isinstance(key, str) or not key.strip() or key != key.strip() or "=" in key
                    or key.startswith(("//", "#", ";")) or not isinstance(value, str)
                    or any(c in key + value for c in "\r\n\0")):
                raise ManagerError("ASKA-Einstellungen benötigen einzeilige Schlüssel und Textwerte.")
        for key in ("server name", "display name"):
            if not config.get(key, "").strip():
                raise ManagerError(f"{key}: Bitte einen Namen angeben.")
        for key in ("steam game port", "steam query port"):
            if not config.get(key, "").isascii() or not config[key].isdigit() or not 1 <= int(config[key]) <= 65535:
                raise ManagerError(f"{key}: Erlaubt sind Ports von 1 bis 65535.")
        if config["steam game port"] == config["steam query port"]:
            raise ManagerError("Spielport und Query-Port müssen unterschiedlich sein.")
        for key, allowed in cls.choices.items():
            if key in config and config[key] not in allowed:
                raise ManagerError(f"{key}: Unbekannter Wert für diese ASKA-Version.")
        if config.get("save id") and not re.fullmatch(r"[A-Za-z0-9_-]+", config["save id"]):
            raise ManagerError("save id: Bitte nur Buchstaben, Zahlen, Unterstriche und Bindestriche verwenden.")

    @staticmethod
    def validate_edit(config, previous, profile):
        if profile.get("launcher_script") and config.get("save id") != previous.get("save id"):
            raise ManagerError("Der vorhandene ASKA-Starter ist an diese Save-ID gebunden. Die Welt darüber verwalten.")

    @staticmethod
    def validate_start(config):
        if not config.get("authentication token", "").strip():
            raise ManagerError("Bitte zuerst einen Steam-Gameserver-Token für ASKA hinterlegen.")
        if config.get("mode") == "custom" and not config.get("custom game preset path", "").strip():
            raise ManagerError("Für den benutzerdefinierten ASKA-Modus fehlt der Pfad zur Spielvorlage.")

    @staticmethod
    def write_config(path, config):
        # Preserve the shipped comments, unknown fields, BOM and original line endings.
        original = path.read_bytes() if path.exists() else b""
        bom = "\ufeff" if original.startswith(b"\xef\xbb\xbf") else ""
        document = original.decode("utf-8-sig")
        newline = "\r\n" if "\r\n" in document else "\n"
        lines = []
        written = set()
        for line in document.splitlines(keepends=True):
            stripped = line.strip()
            key = stripped.partition("=")[0].strip()
            if not stripped.startswith(("//", "#", ";")) and "=" in stripped:
                if key not in config:
                    continue
                match = re.match(r"^(\s*[^=]+?\s*=)[ \t]*(.*?)(\r?\n)?$", line)
                lines.append(match.group(1) + " " + config[key] + (match.group(3) or ""))
                written.add(key)
            else:
                lines.append(line)
        if lines and not lines[-1].endswith(("\n", "\r")):
            lines[-1] += newline
        lines.extend(f"{key} = {value}{newline}" for key, value in config.items() if key not in written)
        atomic_text(path, bom + "".join(lines))

    @staticmethod
    def initial_config():
        return {
            "save id": "", "display name": "ASKA Indy", "server name": "ASKA Indy", "seed": "",
            "password": "", "authentication token": "", "steam game port": "27015",
            "steam query port": "27016", "region": "europe", "keep server world alive": "false",
            "autosave style": "every morning", "mode": "normal", "custom game preset path": "",
        }

    @staticmethod
    def title(config):
        return config.get("server name", "ASKA")


GAME_TYPES = {game.id: game() for game in (EnshroudedGame, AskaGame)}
for game in GAME_TYPES.values():
    game.schema = read_json(Path(__file__).resolve().parent.parent / "profiles" / f"{game.id}.json")
    for attribute in ("label", "executable", "config_filename", "default_directory"):
        setattr(game, attribute, game.schema[attribute])
