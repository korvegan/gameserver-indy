"""Shared file operations for GameServer Indy."""
import json
import os
from pathlib import Path
import re
import tempfile


class ManagerError(Exception):
    pass


def read_json(path: Path):
    try:
        return json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, ValueError) as exc:
        raise ManagerError(f"JSON-Datei nicht lesbar: {path.name}") from exc


def atomic_text(path: Path, text: str):
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=".manager-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="") as handle:
            handle.write(text)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def atomic_json(path: Path, value):
    atomic_text(path, json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False) + "\n")


def local_path(value: str) -> Path:
    if os.name != "nt" and re.match(r"^[A-Za-z]:[\\/]", value):
        return Path("/mnt") / value[0].lower() / value[3:].replace("\\", "/")
    return Path(value).expanduser()
