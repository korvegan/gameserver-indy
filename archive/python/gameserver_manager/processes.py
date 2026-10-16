"""Native Windows process adapter; never selects a global active instance."""
import json
import os
import re
import shutil
import subprocess

from .configuration import ManagerError
from .games import GAME_TYPES
from .paths import BRIDGE


class WindowsServerBridge:
    def __init__(self, directory, game="enshrouded", config_path=None, launcher_script=None, diagnostic_directory=None):
        self.directory = directory
        self.game = game
        self.config_path = config_path
        self.launcher_script = launcher_script
        self.diagnostic_directory = diagnostic_directory

    def call(self, action):
        powershell = shutil.which("powershell.exe") or (
            "/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe" if os.name != "nt" else "powershell.exe"
        )

        def windows_path(value):
            if os.name == "nt" or str(value).startswith("\\\\") or re.match(r"^[A-Za-z]:[\\/]", str(value)):
                return str(value)
            converted = subprocess.run(["wslpath", "-w", str(value)], capture_output=True, text=True, timeout=10)
            if converted.returncode:
                raise ManagerError("Ein Serverpfad konnte nicht in einen Windows-Pfad umgewandelt werden.")
            return converted.stdout.strip()

        arguments = [powershell, "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
                     "-File", windows_path(BRIDGE), "-Action", action, "-ServerDirectory", windows_path(self.directory),
                     "-ExecutableName", GAME_TYPES[self.game].executable,
                     "-SteamAppId", str(GAME_TYPES[self.game].schema.get("launch_steam_app_id", 0))]
        config_argument = GAME_TYPES[self.game].schema.get("config_argument")
        if config_argument:
            arguments.extend(["-ConfigArgument", config_argument])
        for flag, value in (("-ConfigPath", self.config_path), ("-LauncherScript", self.launcher_script),
                            ("-DiagnosticDirectory", self.diagnostic_directory)):
            if value:
                arguments.extend([flag, windows_path(value)])
        try:
            result = subprocess.run(arguments, capture_output=True, timeout=65, encoding="utf-8", errors="replace")
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise ManagerError("Windows-PowerShell ist nicht erreichbar oder hat nicht rechtzeitig geantwortet.") from exc
        if result.returncode:
            try:
                error = json.loads(result.stdout.strip()).get("error", "Windows-Prozesssteuerung fehlgeschlagen.")
            except ValueError:
                error = "Windows-Prozesssteuerung fehlgeschlagen. Windows-/WSL-Zugriff prüfen."
            raise ManagerError(error)
        try:
            return json.loads(result.stdout.strip())
        except ValueError as exc:
            raise ManagerError("Windows hat keine gültige Statusantwort geliefert.") from exc
