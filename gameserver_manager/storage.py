"""Versioned local SQLite storage. Game configuration remains in its original files."""
from contextlib import contextmanager
from pathlib import Path
import sqlite3

from .configuration import ManagerError

SCHEMA_VERSION = 1
SCHEMA = (
    """CREATE TABLE jobs (
        id TEXT PRIMARY KEY, server_id TEXT NOT NULL, operation TEXT NOT NULL,
        actor_id TEXT NOT NULL, state TEXT NOT NULL, message TEXT NOT NULL,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL, result TEXT)""",
    "CREATE INDEX jobs_by_server ON jobs(server_id, created_at)",
    """CREATE TABLE community_events (
        id TEXT NOT NULL, revision INTEGER NOT NULL, game_id TEXT NOT NULL, instance_id TEXT, payload TEXT NOT NULL,
        recorded_at TEXT NOT NULL, PRIMARY KEY(id, revision))""",
    """CREATE TABLE scenes (
        id TEXT PRIMARY KEY, event_id TEXT NOT NULL UNIQUE,
        position INTEGER NOT NULL UNIQUE, template TEXT NOT NULL)""",
    """CREATE TABLE deliveries (
        event_id TEXT NOT NULL, revision INTEGER NOT NULL, target TEXT NOT NULL,
        state TEXT NOT NULL, remote_id TEXT,
        PRIMARY KEY(event_id, revision, target),
        FOREIGN KEY(event_id, revision) REFERENCES community_events(id, revision))""",
)


class Database:
    def __init__(self, path):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self.connect(write=True) as connection:
            version = connection.execute("PRAGMA user_version").fetchone()[0]
            if version not in (0, SCHEMA_VERSION):
                raise ManagerError("Die Plattformdatenbank hat eine nicht unterstützte Version. Passende Programmversion verwenden.")
            if version == 0:
                for statement in SCHEMA:
                    connection.execute(statement)
                connection.execute(f"PRAGMA user_version = {SCHEMA_VERSION}")
        self.path.chmod(0o600)

    @contextmanager
    def connect(self, write=False):
        connection = sqlite3.connect(self.path, timeout=5)
        connection.row_factory = sqlite3.Row
        try:
            connection.execute("PRAGMA foreign_keys = ON")
            if write:
                connection.execute("BEGIN IMMEDIATE")
            yield connection
            connection.commit()
        except BaseException:
            connection.rollback()
            raise
        finally:
            connection.close()

    def backup(self, destination):
        """Consistent SQLite snapshot; never includes game worlds or credentials."""
        destination = Path(destination)
        destination.parent.mkdir(parents=True, exist_ok=True)
        # Exclusive creation prevents silently overwriting a previous backup.
        with destination.open("xb"):
            pass
        try:
            with self.connect() as source:
                target = sqlite3.connect(destination)
                try:
                    source.backup(target)
                finally:
                    target.close()
            destination.chmod(0o600)
        except BaseException:
            destination.unlink(missing_ok=True)
            raise
