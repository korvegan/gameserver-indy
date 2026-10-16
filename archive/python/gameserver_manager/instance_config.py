"""Stopped-instance configuration transactions; retain exact pre-change backups."""
from datetime import datetime
import secrets
import shutil

from .configuration import ManagerError, atomic_json


class InstanceConfiguration:
    def __init__(self, instance):
        self.instance = instance

    def read(self):
        m = self.instance
        return m.game.read_config(m.config_path) if m.config_path.exists() else None

    def setup(self):
        m = self.instance
        with m.lock:
            if not (m.directory / m.game.executable).is_file():
                raise ManagerError(f"{m.game.executable} wurde im Installationsordner nicht gefunden.")
            m.require_stopped()
            created = not m.config_path.exists()
            if created:
                config = m.game.initial_config()
                m.game.validate_config(config)
                m.game.write_config(m.config_path, config)
            else:
                m.game.validate_config(self.read())
            with m.settings_lock:
                if not m.settings_path.exists():
                    atomic_json(m.settings_path, m.settings)
            m.event("Erstes Setup abgeschlossen." if created else "Vorhandene Konfiguration geprüft.")
            return {"message": "Server vorbereitet. Die Zugangsdaten findest du unter Einstellungen.", "created": created}

    def save(self, config):
        m = self.instance
        with m.lock:
            m.require_stopped()
            if not m.config_path.exists():
                raise ManagerError("Bitte zuerst das erste Setup ausführen.")
            previous = self.read()
            config = m.game.merge_config(config, previous)
            m.game.validate_config(config)
            m.game.validate_edit(config, previous, m.profile)
            try:
                binding = m.backups.plan(config)[2]
            except ManagerError:
                binding = None
            if binding is not None:
                m.backups.exclusive(binding)
            backup = m.data / "backups" / (datetime.now().strftime("%Y%m%d-%H%M%S-") + secrets.token_hex(3) + m.config_path.suffix)
            backup.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(m.config_path, backup)
            backup.chmod(0o600)
            m.game.write_config(m.config_path, config)
            m.event("Servereinstellungen gespeichert; vorherige Konfiguration gesichert.")
            return {"message": "Einstellungen gespeichert. Sie gelten beim nächsten Serverstart.", "backup": backup.name}
