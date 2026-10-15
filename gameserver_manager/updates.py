"""Cached update evidence; no game downloads, config migration or process actions."""
import copy
from datetime import datetime
import re
import threading
import time

from .configuration import ManagerError, atomic_json, read_json
from .jobs import utcnow
from .steam import SteamCmd, branch_name, installed, small_text


class UpdateService:
    interval = 1800
    startup_delay = 60

    def __init__(self, registry, provider=None):
        self.registry = registry
        self.provider = provider or SteamCmd(registry.settings)
        self.path = registry.root / 'data' / 'steam-updates.json'
        self.lock = threading.RLock()
        self.entries = {}
        try:
            data = read_json(self.path) if self.path.exists() else {}
            if data.get('schema_version') == 1 and isinstance(data.get('entries'), dict):
                self.entries = data['entries']
        except ManagerError:
            pass  # Disposable metadata cache; never claim an unparseable result.
        self.stop = threading.Event()
        self.thread = None

    def _entry(self, instance):
        with self.lock:
            entry = copy.deepcopy(self.entries.get(instance.profile['id'], {}))
        if not isinstance(entry, dict):
            return {}
        if entry.get('error') is not None and not isinstance(entry['error'], str):
            entry['error'] = 'Der lokale Steam-Cache enthält einen ungültigen Fehlerbefund.'
        return entry

    def check(self, instance):
        entry = self._entry(instance)
        try:
            attempted = datetime.fromisoformat(entry.get('last_attempt', '')).timestamp()
        except (ValueError, TypeError):
            attempted = 0
        if 0 <= time.time() - attempted < 60:
            return {'message': 'Die letzte Steam-Prüfung liegt weniger als eine Minute zurück.', 'update': self.view(instance)}
        entry['last_attempt'] = utcnow()
        app_id = instance.game.schema.get('steam_server_app_id')
        try:
            local = installed(instance)
            branch = local['branch']
        except (ManagerError, OSError):
            branch = branch_name(instance.profile.get('steam_branch', 'public'))
        try:
            build = self.provider.query(app_id, branch)
            if not isinstance(build, str) or not re.fullmatch(r'[0-9]{1,20}', build) or int(build) == 0:
                raise ManagerError('Steam hat keine gültige Build-ID geliefert.')
            entry = {'app_id': app_id, 'branch': branch, 'build': build, 'checked_at': utcnow(),
                     'last_attempt': entry['last_attempt'], 'error': None}
        except ManagerError as error:
            entry.update(error=str(error))
        except OSError:
            entry.update(error='Steam-Metadaten konnten nicht abgefragt werden. Lokale Dateien und Verbindung prüfen.')
        with self.lock:
            self.entries[instance.profile['id']] = entry
            atomic_json(self.path, {'schema_version': 1, 'entries': self.entries})
        view = self.view(instance)
        if entry.get('error'):
            raise ManagerError(entry['error'])
        return {'message': 'Steam-Version geprüft. ' + view['label'] + '.', 'update': view}

    def view(self, instance, status=None):
        app_id = instance.game.schema.get('steam_server_app_id')
        entry = self._entry(instance)
        branch = branch_name(instance.profile.get('steam_branch', 'public'))
        result = {'app_id': app_id, 'branch': branch, 'state': 'unknown', 'label': 'Unbekannt',
                  'installed_build': None, 'available_build': None, 'checked_at': None,
                  'local_source': 'Steam-Appmanifest', 'remote_source': 'SteamCMD · anonym',
                  'message': 'Noch keine erfolgreiche Steam-Prüfung.', 'version': None,
                  'automatic': self.registry.settings.get('automatic_update_checks', True) is True,
                  'version_source': None, 'process_state': 'unknown', 'process_message': 'Laufender Build nicht ermittelt.'}
        try:
            local = installed(instance)
            result.update(installed_build=local['build'], branch=local['branch'])
            branch = local['branch']
        except (ManagerError, OSError) as error:
            result['message'] = str(error) if isinstance(error, ManagerError) else 'Das lokale Steam-Appmanifest ist nicht lesbar.'
            local = None
        if instance.game.id == 'enshrouded':
            try:
                match = re.search(r'(?m)^Version:\s*([0-9]+(?:\.[0-9]+){3})\s*$', small_text(instance.directory / 'enshrouded_server_readme.txt'))
                if match:
                    result.update(version=match[1], version_source='Mitgelieferte Serveranleitung')
            except (ManagerError, OSError):
                pass
        if entry.get('app_id') == app_id and entry.get('branch') == branch:
            build, checked = entry.get('build'), entry.get('checked_at')
            try:
                age = time.time() - datetime.fromisoformat(checked).timestamp()
                valid = isinstance(build, str) and re.fullmatch(r'[0-9]{1,20}', build) and int(build) > 0
            except (TypeError, ValueError):
                valid, age = False, float('inf')
            if valid:
                result.update(available_build=build, checked_at=checked)
                if local and local['complete']:
                    state = 'current' if local['build'] == build else 'update_available'
                    result.update(state=state, label='Aktuell' if state == 'current' else 'Update verfügbar',
                                  message='Installierter und angebotener Build für denselben Branch verglichen.')
                if age > self.interval or age < -60 or entry.get('error'):
                    result.update(state='stale', label='Veraltet', message='Steam-Befund nicht mehr aktuell. ' + (entry.get('error') if isinstance(entry.get('error'), str) else 'Bitte erneut prüfen.'))
            elif entry.get('error'):
                result['message'] = entry['error']
        elif entry.get('error'):
            result['message'] = entry['error']
        if local and (local['busy'] or not local['complete']):
            result.update(state='updating' if local['busy'] else 'unknown',
                          label='Installation/Update läuft' if local['busy'] else 'Unbekannt',
                          message='Steam hat die Installation noch nicht als vollständig abgeschlossen bestätigt.')
        if status is not None:
            if not status.get('running'):
                result.update(process_state='stopped', process_message='Kein Serverprozess aktiv.')
            elif local:
                result['process_message'] = 'Prozess läuft; sein Build ist nicht unabhängig bestätigt.'
                for process in status.get('processes', []):
                    try:
                        started = datetime.fromisoformat(process['started'].replace('Z', '+00:00')).timestamp()
                        changed = max(local['updated'], (instance.directory / instance.game.executable).stat().st_mtime)
                        if changed > started:
                            result.update(process_state='restart_needed', process_message='Dateien seit Prozessstart aktualisiert. Laufender Build unbekannt; kontrollierten Neustart einplanen.')
                    except (OSError, ValueError, KeyError, TypeError):
                        pass
        return result

    def start(self, callback):
        def periodic():
            if self.stop.wait(self.startup_delay):
                return
            while not self.stop.is_set():
                with self.registry.lock:
                    instances = list(self.registry.managers.values())
                for instance in instances:
                    if self.stop.is_set():
                        return
                    try:
                        _job, future = callback(instance.profile['id'])
                        future.result(timeout=55)
                    except Exception:
                        pass  # Fixed, sanitized error remains in job/cache evidence.
                if self.stop.wait(self.interval):
                    return
        self.thread = threading.Thread(target=periodic, name='steam-update-checks', daemon=True)
        self.thread.start()

    def close(self):
        self.stop.set()
        if self.thread:
            self.thread.join(timeout=1)
