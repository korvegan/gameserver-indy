"""Compatibility imports for the original Python API; implementations are services."""
from .configuration import ManagerError, atomic_json
from .discord import send_discord
from .games import GAME_TYPES
from .instances import DEFAULT_DIRECTORY, GameServerManager
from .paths import BRIDGE, ROOT
from .processes import WindowsServerBridge

validate_config = GAME_TYPES["enshrouded"].validate_config
initial_config = GAME_TYPES["enshrouded"].initial_config
