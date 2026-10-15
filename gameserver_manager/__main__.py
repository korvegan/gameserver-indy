import argparse
import json

from .core import ManagerError
from .server import serve
from .registry import ServerRegistry
from .games import GAME_TYPES


def main():
    parser = argparse.ArgumentParser(description="GameServer Indy: lokale Dedicated-Server-Verwaltung")
    parser.add_argument("command", nargs="?", default="serve", choices=("serve", "setup", "status", "register"))
    parser.add_argument("--port", type=int, default=8765)
    parser.add_argument("--server", help="Profil-ID; bei setup/status mit mehreren Profilen erforderlich")
    parser.add_argument("--game", choices=tuple(GAME_TYPES))
    parser.add_argument("--label")
    parser.add_argument("--directory")
    parser.add_argument("--config-path")
    parser.add_argument("--launcher-script")
    args = parser.parse_args()
    if not 1 <= args.port <= 65535:
        parser.error("Port muss zwischen 1 und 65535 liegen.")
    try:
        registry = ServerRegistry()
        if args.command == "serve":
            serve(registry, args.port)
        elif args.command == "register":
            result = registry.register({
                "id": args.server, "game": args.game, "label": args.label,
                "server_directory": args.directory, "config_path": args.config_path or "",
                "launcher_script": args.launcher_script or "",
            })
            print(json.dumps(result, ensure_ascii=False, indent=2))
        else:
            manager = registry.get(args.server, explicit=True)
            print(json.dumps(manager.setup() if args.command == "setup" else manager.status(), ensure_ascii=False, indent=2))
    except (ManagerError, OSError) as exc:
        parser.exit(1, f"Fehler: {exc}\n")


if __name__ == "__main__":
    main()
