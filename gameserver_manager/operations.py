"""Instance-scoped lifecycle operations; no registry-wide lock or HTTP concerns."""
from .configuration import ManagerError
from .discord import send_discord
from .logs import append_diagnostic, secret_values


class InstanceOperations:
    def __init__(self, instance):
        self.instance = instance

    def require_stopped(self):
        self.instance.backups.guard()
        state = self.instance.bridge.call("Status")
        if state.get("processes") or state.get("launcher_running"):
            raise ManagerError("Bitte zuerst den Server stoppen. Die laufende Konfiguration wird nicht überschrieben.")

    def run(self, action):
        if action not in ("start", "stop", "restart"):
            raise ManagerError("Unbekannte Serveraktion.")
        m = self.instance
        with m.lock:
            if action in ('start', 'restart'):
                m.backups.guard()
            state = m.bridge.call("Status")
            before = state.get("processes", [])
            if state.get("launcher_running") and not before:
                if action == "start":
                    return {"message": "Der Serverstarter läuft bereits. Den Fortschritt siehst du im Starterprotokoll."}
                raise ManagerError("Der Serverstarter arbeitet noch. Bitte zuerst das Starterprotokoll prüfen.")
            if action == "start" and before:
                return {"message": "Der Serverprozess läuft bereits."}
            if action == "stop" and not before:
                return {"message": "Der Server ist bereits gestoppt."}
            if action in ("stop", "restart") and before:
                m.bridge.call("Stop")
                m.event("Server gestoppt.")
                m.backups.after_stop()
            if action in ("start", "restart"):
                append_diagnostic(m.diagnostic_directory, "Serverstart angefordert.")
                config = {}
                try:
                    if not m.config_path.exists():
                        raise ManagerError("Bitte zuerst das erste Setup ausführen.")
                    config = m.config()
                    m.game.validate_config(config)
                    m.game.validate_start(config)
                    m.backups.exclusive()
                    started = m.bridge.call("Start")
                except ManagerError as exc:
                    append_diagnostic(m.diagnostic_directory, str(exc), secret_values(config, m.webhook()))
                    raise
                if started.get("launcher_running") and not started.get("processes"):
                    return {"message": "Der Serverstarter arbeitet. Den Fortschritt siehst du im Starterprotokoll."}
                m.event("Serverprozess gestartet.")
            message = "Server gestoppt." if action == "stop" else "Serverprozess gestartet. Den Verbindungsaufbau siehst du im Serverlog."
            warning = None
            external_start = m.profile.get("launcher_script") and action in ("start", "restart")
            if m.settings.get("discord", {}).get("auto_notify") and m.webhook() and not external_start:
                name = m.game.title(m.config())
                try:
                    content = f"{name}: {'Server gestoppt.' if action == 'stop' else 'Serverprozess gestartet.'}"
                    if action != 'stop':
                        content += '\n\n' + m.discord.join_message()
                    send_discord(m.webhook(), content)
                    m.event("Discord-Statusnachricht gesendet.")
                except ManagerError as exc:
                    warning = str(exc)
                    m.event("Discord-Statusnachricht fehlgeschlagen.")
            return {"message": message, "warning": warning}
