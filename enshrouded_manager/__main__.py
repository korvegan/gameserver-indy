import argparse
import json

from .core import Manager, ManagerError
from .server import serve


def main():
    parser = argparse.ArgumentParser(description="Lokale Verwaltung des Enshrouded Dedicated Servers")
    parser.add_argument("command", nargs="?", default="serve", choices=("serve", "setup", "status"))
    parser.add_argument("--port", type=int, default=8765)
    args = parser.parse_args()
    if not 1 <= args.port <= 65535:
        parser.error("Port muss zwischen 1 und 65535 liegen.")
    try:
        manager = Manager()
        if args.command == "serve":
            serve(manager, args.port)
        elif args.command == "setup":
            print(json.dumps(manager.setup(), ensure_ascii=False, indent=2))
        else:
            print(json.dumps(manager.status(), ensure_ascii=False, indent=2))
    except (ManagerError, OSError) as exc:
        parser.exit(1, f"Fehler: {exc}\n")


if __name__ == "__main__":
    main()
