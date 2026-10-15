"""Private family access, separate from chronicle snapshots and local owner access."""
from contextlib import contextmanager
from datetime import datetime, timezone
from email.header import decode_header, make_header
import json
from pathlib import Path
import re
import sqlite3
from uuid import uuid4

from .configuration import ManagerError, atomic_json, read_json
from .security import AccessDenied, Principal

def now():
    return datetime.now(timezone.utc).isoformat()


def login_name(value, encoded=False):
    if not isinstance(value, str) or not value or len(value) > 320:
        raise ManagerError('Bitte den persönlichen Tailscale-Anmeldenamen angeben.')
    if encoded:
        try:
            value = str(make_header(decode_header(value)))
        except (LookupError, UnicodeError, ValueError):
            raise ManagerError('Ungültige Tailscale-Identität.') from None
    value = value.strip().casefold()
    if not value or len(value) > 254 or any(c.isspace() or ord(c) < 32 for c in value):
        raise ManagerError('Ungültiger Tailscale-Anmeldename.')
    return value


def settings(value):
    if not isinstance(value, dict) or set(value) - {'enabled', 'hostname', 'port'}:
        raise ManagerError('Ungültige Einstellungen für den Familienzugang.')
    enabled, hostname, port = value.get('enabled', False), value.get('hostname', ''), value.get('port', 8767)
    if type(enabled) is not bool or type(port) is not int or not 1024 <= port <= 65535:
        raise ManagerError('Ungültiger Familienzugangs-Port.')
    if not isinstance(hostname, str) or (hostname and (len(hostname) > 253 or not re.fullmatch(r'(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.){2,}ts\.net', hostname))):
        raise ManagerError('Bitte ausschließlich den vollständigen Tailscale-Gerätenamen mit .ts.net angeben.')
    if enabled and not hostname:
        raise ManagerError('Für HTTPS fehlt der Tailscale-Gerätename.')
    return {'enabled': enabled, 'hostname': hostname, 'port': port}


class AccessService:
    def __init__(self, root, registry):
        self.registry = registry
        self.directory = Path(root) / 'data'
        self.directory.mkdir(parents=True, exist_ok=True)
        self.path = self.directory / 'access.sqlite3'
        self.settings_path = self.directory / 'access-settings.json'
        self.runtime = {'active': False}
        with self.connect(write=True) as db:
            version = db.execute('PRAGMA user_version').fetchone()[0]
            if version not in (0, 1):
                raise ManagerError('Die Zugangsdatenbank hat eine unbekannte Version.')
            if version == 0:
                db.execute('''CREATE TABLE users (id TEXT PRIMARY KEY,login TEXT UNIQUE NOT NULL,
                    name TEXT NOT NULL,role TEXT NOT NULL,servers TEXT,enabled INTEGER NOT NULL,
                    revision INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)''')
                db.execute('''CREATE TABLE audit (id INTEGER PRIMARY KEY,at TEXT NOT NULL,actor TEXT NOT NULL,
                    operation TEXT NOT NULL,resource TEXT NOT NULL,outcome TEXT NOT NULL)''')
                db.execute('PRAGMA user_version=1')
        self.path.chmod(0o600)

    @contextmanager
    def connect(self, write=False):
        db = sqlite3.connect(self.path, timeout=5)
        db.row_factory = sqlite3.Row
        try:
            db.execute('BEGIN IMMEDIATE' if write else 'BEGIN')
            yield db
            db.commit()
        except BaseException:
            db.rollback()
            raise
        finally:
            db.close()

    def configuration(self):
        return settings(read_json(self.settings_path) if self.settings_path.exists() else {})

    def configure(self, principal, body):
        principal.require('access.manage')
        value = settings(body)
        atomic_json(self.settings_path, value)
        self.settings_path.chmod(0o600)
        self.audit(principal.id, 'access.configure', '', 'saved')
        return {'message': 'Gespeichert. Änderungen am HTTPS-Namen oder Port werden nach Neustart der Verwaltung aktiv.', 'settings': value}

    @staticmethod
    def public(row):
        return {key: row[key] for key in ('id', 'login', 'name', 'role', 'revision', 'created_at', 'updated_at')} | {
            'enabled': bool(row['enabled']), 'server_ids': json.loads(row['servers']) if row['servers'] is not None else None}

    def view(self, principal):
        principal.require('access.manage')
        with self.connect() as db:
            users = [self.public(row) for row in db.execute('SELECT * FROM users ORDER BY name,id')]
            audit = [dict(row) for row in db.execute('SELECT * FROM audit ORDER BY id DESC LIMIT 100')]
        return {'settings': self.configuration(), 'users': users, 'audit': audit,
                'runtime': dict(self.runtime),
                'servers': [{'id': p['id'], 'label': p['label']} for p in self.registry.catalog()['servers']]}

    def save_user(self, principal, body):
        principal.require('access.manage')
        if set(body) - {'id', 'revision', 'login', 'name', 'role', 'server_ids', 'enabled'}:
            raise ManagerError('Unbekanntes Freigabefeld.')
        login = login_name(body.get('login'))
        name, role, ids, enabled = body.get('name'), body.get('role'), body.get('server_ids'), body.get('enabled', True)
        if not isinstance(name, str) or not 1 <= len(name.strip()) <= 80 or any(ord(c) < 32 for c in name):
            raise ManagerError('Bitte einen Namen mit 1 bis 80 Zeichen angeben.')
        if role not in ('owner', 'family', 'viewer') or type(enabled) is not bool:
            raise ManagerError('Ungültige Rolle oder Freigabe.')
        if role == 'owner':
            if ids is not None:
                raise ManagerError('Besitzerzugänge erhalten ausdrücklich Zugriff auf alle Profile.')
        elif not isinstance(ids, list) or any(not isinstance(i, str) or i not in self.registry.managers for i in ids) or len(ids) != len(set(ids)):
            raise ManagerError('Bitte gültige Serverprofile auswählen.')
        user_id, revision = body.get('id'), body.get('revision')
        with self.connect(write=True) as db:
            previous = db.execute('SELECT * FROM users WHERE id=?', (user_id,)).fetchone() if isinstance(user_id, str) else None
            if user_id is not None and (previous is None or type(revision) is not int or previous['revision'] != revision):
                raise ManagerError('Die Freigabe wurde zwischenzeitlich geändert. Bitte neu laden.')
            if previous and login != previous['login']:
                raise ManagerError('Für eine andere Identität einen neuen Zugang anlegen und den alten sperren.')
            conflict = db.execute('SELECT id FROM users WHERE login=?', (login,)).fetchone()
            if conflict and (not previous or conflict['id'] != user_id):
                raise ManagerError('Für diese Identität existiert bereits eine Freigabe.')
            if not previous:
                user_id, revision = 'user-' + uuid4().hex, 0
            stamp = now()
            db.execute('INSERT OR REPLACE INTO users VALUES (?,?,?,?,?,?,?,?,?)',
                       (user_id, login, name.strip(), role, json.dumps(sorted(ids)) if ids is not None else None,
                        int(enabled), revision + 1, previous['created_at'] if previous else stamp, stamp))
            self._audit(db, principal.id, 'access.user', user_id, 'enabled' if enabled else 'revoked')
        return {'message': 'Persönliche Freigabe gespeichert.', 'id': user_id}

    def principal(self, login):
        try:
            login = login_name(login, encoded=True)
        except ManagerError:
            raise AccessDenied('Keine bestätigte persönliche Tailscale-Identität vorhanden.') from None
        with self.connect() as db:
            row = db.execute('SELECT * FROM users WHERE login=? AND enabled=1', (login,)).fetchone()
        if row is None:
            raise AccessDenied('Dieser Zugang ist noch nicht freigegeben oder wurde gesperrt. Bitte den Besitzer um Freigabe bitten.')
        return Principal(row['id'], row['role'], frozenset(json.loads(row['servers'])) if row['servers'] is not None else None, row['revision'])

    def validate(self, principal):
        if not principal.auth_revision:
            return
        with self.connect() as db:
            row = db.execute('SELECT enabled,revision FROM users WHERE id=?', (principal.id,)).fetchone()
        if row is None or not row['enabled'] or row['revision'] != principal.auth_revision:
            raise AccessDenied('Die Freigabe wurde geändert oder entzogen. Bitte neu laden.')

    def audit(self, actor, operation, resource, outcome):
        with self.connect(write=True) as db:
            self._audit(db, actor, operation, resource, outcome)

    @staticmethod
    def _audit(db, actor, operation, resource, outcome):
        db.execute('INSERT INTO audit(at,actor,operation,resource,outcome) VALUES (?,?,?,?,?)',
                   (now(), actor, operation, resource, outcome))
        db.execute('DELETE FROM audit WHERE id<=(SELECT MAX(id)-10000 FROM audit)')
