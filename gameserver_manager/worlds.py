"""Explicit world-storage capabilities; add a resolver for a new game here."""
from dataclasses import dataclass
from pathlib import Path

from .configuration import ManagerError, local_path


@dataclass(frozen=True)
class WorldSource:
    directory: Path
    extra_files: dict
    save_id: str = ''


def enshrouded(instance, config):
    directory = local_path(config['saveDirectory'].replace('\\', '/'))
    if not directory.is_absolute():
        directory = instance.directory / directory
    return WorldSource(directory, {})


def aska(instance, config):
    save_id = config.get('save id', '')
    if not save_id:
        raise ManagerError('ASKA benötigt für eine eindeutige Weltsicherung eine feste Save-ID.')
    if not instance._windows_user_profile:
        instance._windows_user_profile = instance.bridge.call('Status').get('user_profile')
    if not instance._windows_user_profile:
        raise ManagerError('Das Windows-Benutzerprofil für die ASKA-Welt ist nicht bekannt.')
    directory = local_path(instance._windows_user_profile) / 'AppData/LocalLow/Sand Sailor Studio/Aska/data/server' / ('savegame_' + save_id)
    extras = {}
    if preset := config.get('custom game preset path', ''):
        target = local_path(preset)
        if not target.is_absolute():
            raise ManagerError('ASKA-Spielvorlagen benötigen für eine eindeutige Sicherung einen absoluten Pfad.')
        extras['config/preset'] = target
    return WorldSource(directory, extras, save_id)


WORLD_ADAPTERS = {'enshrouded': enshrouded, 'aska': aska}


def world_source(instance, config):
    resolver = WORLD_ADAPTERS.get(instance.game.id)
    if resolver is None:
        raise ManagerError('Für dieses Spiel ist noch kein Weltadapter eingerichtet.')
    return resolver(instance, config)
