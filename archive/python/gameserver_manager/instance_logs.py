"""Profile-scoped log sources and redaction, independent of HTTP and rendering."""
from datetime import datetime, timezone

from .configuration import ManagerError, local_path
from .logs import read_tail, secret_values


class InstanceLogs:
    def __init__(self, instance):
        self.instance = instance

    def read(self, source=None):
        if source not in (None, "server", "launcher"):
            raise ManagerError("Unbekannte Protokollquelle.")
        m = self.instance
        config_error = False
        try:
            config = m.config() or {}
        except (ManagerError, OSError):
            config, config_error = {}, True
        values = secret_values(config, m.webhook())
        owned = m.diagnostic_directory / "server.log"
        files = [owned] if source != "launcher" and owned.is_file() else []
        directory = None
        if not files and source != "launcher":
            if m.profile.get("log_directory"):
                directory = local_path(m.profile["log_directory"])
            elif m.game.schema.get("windows_log_directory"):
                if not m._windows_user_profile:
                    m._windows_user_profile = m.bridge.call("Status").get("user_profile")
                if m._windows_user_profile:
                    directory = local_path(m._windows_user_profile) / m.game.schema["windows_log_directory"]
            else:
                directory = local_path(config.get("logDirectory", "./logs"))
                if not directory.is_absolute():
                    directory = m.directory / directory
            if directory:
                allowed = m.game.schema.get("log_files")
                files = [p for p in directory.glob("*") if p.is_file() and p.suffix.lower() in (".log", ".txt")
                         and (not allowed or p.name in allowed)]
        server = max(files, key=lambda p: p.stat().st_mtime) if files else None
        launcher = m.diagnostic_directory / "launcher.log"
        paths = {"server": server, "launcher": launcher if launcher.is_file() else None}
        selected = source or ("server" if server else "launcher" if paths["launcher"] else "server")
        path = paths[selected]
        result = {"source": selected, "sources": [{"id": "server", "label": "Spielserver"}, {"id": "launcher", "label": "Starter / Startfehler"}],
                  "file": path.name if path else None, "updated_at": None}
        if path:
            result["updated_at"] = datetime.fromtimestamp(path.stat().st_mtime, timezone.utc).isoformat()
            result["text"] = "Die Konfiguration ist nicht lesbar; das Serverprotokoll kann deshalb gerade nicht bereinigt werden." if config_error and selected == "server" else read_tail(path, values)
        else:
            result["text"] = "Noch keine Serverlogs vorhanden." if selected == "server" else "Noch kein Starterprotokoll vorhanden. Der nächste Start über das Dashboard wird hier protokolliert."
        return result
