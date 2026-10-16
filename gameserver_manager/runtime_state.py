"""Explicit prepared state and the controller lease shared with the Symfony supervisor."""
from contextlib import contextmanager
import json
import os
from pathlib import Path
import stat
import re
import sqlite3

from .configuration import ManagerError
from .paths import ROOT


def state_directory(value=None):
    if value is None:
        return ROOT
    directory = Path(value)
    if not directory.is_absolute() or directory.is_symlink() or not directory.is_dir():
        raise ManagerError('Einen vorhandenen absoluten privaten Datenordner angeben.')
    marker = directory / '.indy-managed-state'
    if marker.is_symlink() or not marker.is_file() or marker.stat().st_size > 4096:
        raise ManagerError('Datenübernahme zuerst ausdrücklich vorbereiten.')
    try:
        state = json.loads(marker.read_text(encoding='utf-8'))
    except (ValueError, UnicodeError) as error:
        raise ManagerError('Unbekannte vorbereitete Datenablage.') from error
    if (not isinstance(state, dict) or state.get('format') != 'gameserver-indy-state'
            or type(state.get('version')) is not int or state['version'] != 1
            or state.get('access_layout') != 'shared-data'):
        raise ManagerError('Unbekannte vorbereitete Datenablage.')
    if (directory / 'data').is_symlink() or not (directory / 'data').is_dir():
        raise ManagerError('Die vorbereitete Datenablage ist unvollständig oder ein Link.')
    for relative in ('manager.local.json', 'data/access.sqlite3', 'data/session-key'):
        path = directory / relative
        if path.is_symlink() or not path.is_file():
            raise ManagerError('Die vorbereitete Datenablage ist unvollständig.')
    if os.name != 'posix':
        raise ManagerError('Die gemeinsame Produktionsablage unter WSL/Linux verwenden.')
    return directory.resolve()


def validate_prepared_state(directory):
    """Read-only preflight under the acquired lease. A missing database must never mean new install."""
    from .access import settings as access_settings
    from .catalog import GameCatalog
    from .registry import ServerRegistry
    from .whatsapp_client import GROUP_ID

    def document(relative, maximum=1048576):
        path=directory/relative
        if path.is_symlink() or not path.is_file() or path.stat().st_size>maximum:
            raise ManagerError('Die vorbereitete Datenablage ist unvollständig oder unbekannt.')
        value=json.loads(path.read_text(encoding='utf-8'))
        if not isinstance(value,dict):
            raise ManagerError('Die vorbereitete Datenablage ist unvollständig oder unbekannt.')
        return value

    try:
        configuration=document('manager.local.json')
        if type(configuration.get('schema_version')) is not int or configuration['schema_version'] != 2 or not isinstance(configuration.get('servers'),list) or not configuration['servers']:
            raise ManagerError('Vorbereitete Profile fehlen; keine Standardprofile ableiten.')
        GameCatalog(configuration.get('catalog_games',[]))
        ids=set(); directories=set()
        for profile in configuration['servers']:
            ServerRegistry.validate_profile(profile)
            identity=ServerRegistry.directory_identity(profile['server_directory'])
            if profile['id'] in ids or identity in directories:
                raise ManagerError('Vorbereitete Profile sind nicht eindeutig.')
            ids.add(profile['id']); directories.add(identity)
        access_settings(document('data/access-settings.json',16384))
        if not re.fullmatch(r'[A-Za-z0-9_-]{43}',(directory/'data/session-key').read_text(encoding='ascii')):
            raise ManagerError('Vorbereiteter Sitzungsschlüssel ist ungültig.')
        _database(directory/'data/access.sqlite3',1,{
            'users':['id','login','name','role','servers','enabled','revision','created_at','updated_at'],
            'audit':['id','at','actor','operation','resource','outcome']})
        _database(directory/'data/platform.sqlite3',2,{
            'jobs':['id','server_id','operation','actor_id','state','message','created_at','updated_at','result'],
            'community_events':['id','revision','game_id','instance_id','payload','recorded_at'],
            'scenes':['id','event_id','position','template'],
            'deliveries':['event_id','revision','target','state','remote_id'],
            'community_media':['id','content','width','height']})
        whatsapp=directory/'data/whatsapp'
        if whatsapp.is_symlink() or not whatsapp.is_dir():
            raise ManagerError('Vorbereitete WhatsApp-Versanddaten fehlen.')
        settings=document('data/whatsapp/settings.json',65536)
        if (not isinstance(settings.get('group_id'),str) or settings['group_id'] and not GROUP_ID.fullmatch(settings['group_id'])
                or not isinstance(settings.get('group_label'),str) or type(settings.get('enabled')) is not bool
                or type(settings.get('revision')) is not int or settings['revision']<0
                or not isinstance(settings.get('server_ids'),list) or any(not isinstance(sid,str) for sid in settings['server_ids'])
                or len(settings['server_ids'])!=len(set(settings['server_ids']))):
            raise ManagerError('Vorbereitete WhatsApp-Einstellungen sind ungültig.')
        _database(whatsapp/'deliveries.sqlite3',0,{'deliveries':['server_id','process_key','group_id','state','updated_at']},whatsapp=True)
        credentials=whatsapp/'credentials.json'
        if credentials.exists() or settings['enabled'] or settings['group_id']:
            key=document('data/whatsapp/credentials.json',4096).get('api_key')
            if not isinstance(key,str) or not re.fullmatch(r'[a-f0-9]{64}',key):
                raise ManagerError('Vorbereiteter WhatsApp-Zugang ist unvollständig.')
    except (OSError,ValueError,TypeError,KeyError,sqlite3.Error) as error:
        raise ManagerError('Vorbereitete Daten sind unvollständig oder unbekannt; kein Dienst wurde gestartet.') from error


def _database(path,version,tables,*,whatsapp=False):
    if path.is_symlink() or not path.is_file():
        raise ManagerError('Eine vorbereitete Verwaltungsdatenbank fehlt.')
    db=sqlite3.connect(path.resolve().as_uri()+'?mode=ro',uri=True,timeout=5)
    try:
        db.execute('PRAGMA query_only=ON')
        actual={row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")}
        if (db.execute('PRAGMA user_version').fetchone()[0]!=version or actual!=set(tables)
                or db.execute('PRAGMA quick_check').fetchall()!=[('ok',)]
                or db.execute('PRAGMA foreign_key_check').fetchone() is not None
                or db.execute("SELECT 1 FROM sqlite_master WHERE type IN ('view','trigger') LIMIT 1").fetchone() is not None):
            raise ManagerError('Version oder Struktur der vorbereiteten Datenbank ist unbekannt.')
        for table,columns in tables.items():
            if [row[1] for row in db.execute('PRAGMA table_info('+table+')')]!=columns:
                raise ManagerError('Vorbereitete Datenbankstruktur ist unvollständig.')
        if whatsapp:
            columns=db.execute('PRAGMA table_info(deliveries)').fetchall()
            if ([row[5] for row in columns]!=[1,2,3,0,0] or any(row[2]!='TEXT' or row[3]!=1 for row in columns)
                    or db.execute("SELECT 1 FROM deliveries WHERE state NOT IN ('sent','sending','uncertain') LIMIT 1").fetchone() is not None):
                raise ManagerError('Vorbereitete WhatsApp-Versandclaims sind unbekannt.')
    finally:
        db.close()


@contextmanager
def controller_lease(directory):
    """Before composition/claims, for the complete controller lifetime; never adopt another run."""
    if os.name != 'posix':
        # Legacy native Windows use remains separate; shared-state mode is WSL-only.
        yield
        return
    import fcntl
    path = Path(directory) / '.indy-controller.lock'
    try:
        descriptor = os.open(path, os.O_CREAT | os.O_RDWR | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
    except OSError as error:
        raise ManagerError('Controllersperre ist nicht zugänglich oder ein Link.') from error
    with os.fdopen(descriptor, 'r+b') as handle:
        if not stat.S_ISREG(os.fstat(descriptor).st_mode):
            raise ManagerError('Controllersperre muss eine reguläre Datei sein.')
        os.fchmod(descriptor, 0o600)
        try:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise ManagerError('Eine Verwaltung besitzt diese Datenablage bereits.') from error
        try:
            yield
        finally:
            fcntl.flock(handle, fcntl.LOCK_UN)
