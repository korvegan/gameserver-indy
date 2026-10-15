"""Game identity is independent of installations and of future mod providers."""
from dataclasses import asdict, dataclass
import re

from .configuration import ManagerError
from .games import GAME_TYPES


def identifier(value):
    return isinstance(value, str) and re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,79}", value) is not None


@dataclass(frozen=True)
class Capability:
    state: str
    reason: str

    def __post_init__(self):
        if self.state not in ("supported", "unsupported", "unknown") or not self.reason:
            raise ValueError("A capability needs an explicit state and reason.")


class GameCatalog:
    def __init__(self, additional=()):
        self.games = {game.id: {"id": game.id, "label": game.label} for game in GAME_TYPES.values()}
        if not isinstance(additional, (list, tuple)):
            raise ManagerError("catalog_games muss eine Liste von Spieleinträgen sein.")
        for entry in additional:
            if (not isinstance(entry, dict) or not identifier(entry.get("id"))
                    or not isinstance(entry.get("label"), str) or not 1 <= len(entry["label"].strip()) <= 80
                    or entry["id"] in self.games):
                raise ManagerError("Spiele im Katalog benötigen eindeutige IDs und Namen.")
            self.games[entry["id"]] = {"id": entry["id"], "label": entry["label"].strip()}

    def entries(self):
        result = []
        for entry in self.games.values():
            hosted = entry["id"] in GAME_TYPES
            capabilities = {name: Capability("unknown", "Noch keine geprüfte Umsetzung eingerichtet.")
                            for name in ("readiness", "world_backup", "update_detection", "players", "mods")}
            capabilities["configuration"] = Capability("supported" if hosted else "unsupported",
                "Vorhandener Konfigurationsadapter." if hosted else "Kein Dedicated-Server-Adapter eingerichtet.")
            capabilities["process_control"] = Capability("supported" if hosted else "unsupported",
                "Windows-Prozessadapter; regulärer Stopp an Testprozessen geprüft."
                + (" Echter ASKA-Weltstopp steht noch aus." if entry["id"] == "aska" else "")
                if hosted else "Kein Prozessadapter eingerichtet.")
            if hosted:
                capabilities['update_detection'] = Capability('supported', 'Lokales Steam-Appmanifest und anonymer SteamCMD-Branchvergleich; Online-Prüfung benötigt eingerichtetes SteamCMD.')
            result.append({**entry, "server_adapter": hosted,
                           "capabilities": {name: asdict(value) for name, value in capabilities.items()}})
        return result
