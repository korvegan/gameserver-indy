"""Private, verified offline world snapshots and guarded owner restores.

No extraction of arbitrary archive paths, game shutdown, downloads or network IO.
The instance lock serializes this service with configuration and lifecycle actions.
"""
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import tempfile
import threading
import time
from uuid import uuid4
import zipfile

from .configuration import ManagerError, atomic_json, read_json
from .steam import installed
from .worlds import world_source

RETENTION = timedelta(hours=72)
MAX_FILES = 100000
MAX_BYTES = 16 * 1024 ** 3
CONFIG_LIMIT = 16 * 1024 ** 2
MANIFEST_LIMIT = 32 * 1024 ** 2
RESERVE = 512 * 1024 ** 2
IDENTIFIER = re.compile(r'[0-9a-f]{32}')


def now():
    return datetime.now(timezone.utc)


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def plain_path(path):
    """Reject links in every existing component, including NTFS junctions in WSL."""
    path = Path(os.path.abspath(path))
    for component in (*reversed(path.parents), path):
        try:
            attributes = component.lstat()
        except FileNotFoundError:
            continue
        if stat.S_ISLNK(attributes.st_mode) or getattr(attributes, 'st_file_attributes', 0) & 0x400:
            raise ManagerError('Verknüpfungen und Junctions sind für Weltsicherungen nicht erlaubt.')
    return path


def member_name(name):
    if not isinstance(name, str) or len(name) > 1000 or '\\' in name:
        raise ManagerError('Ungültiger Dateiname in der Weltsicherung.')
    parts = PurePosixPath(name).parts
    if not parts or '/'.join(parts) != name or any(
            part in ('.', '..') or part.endswith((' ', '.'))
            or re.search(r'[\x00-\x1f<>:"|?*]', part)
            or re.fullmatch(r'(?i)(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?', part) for part in parts):
        raise ManagerError('Unsicherer Dateiname in der Weltsicherung.')
    if PurePosixPath(name).is_absolute():
        raise ManagerError('Absolute Archivpfade sind nicht erlaubt.')
    return name


def atomic_bytes(path, content):
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix='.indy-', delete=False) as stream:
        temporary = Path(stream.name)
        try:
            temporary.chmod(0o600)
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        except BaseException:
            temporary.unlink(missing_ok=True)
            raise
    try:
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


class WorldBackups:
    def __init__(self, instance, *, settle_seconds=1):
        self.instance = instance
        self.folder = instance.root / 'data' / 'world-backups' / instance.profile['id']
        self.pending = self.folder / 'restore-pending.json'
        self.settings = self.folder / 'settings.json'
        self.settle_seconds = settle_seconds
        self.progress_lock = threading.Lock()
        self.progress = None
        self.peers = lambda: []

    def phase(self, label, completed=0, total=0):
        with self.progress_lock:
            self.progress = {'phase': label, 'completed': completed, 'total': total}

    def guard(self):
        if self.pending.exists():
            raise ManagerError('Eine Weltwiederherstellung wurde unterbrochen. Zuerst unter Weltsicherungen den vorherigen Stand zurückholen.')

    def offline(self):
        state = self.instance.bridge.call('Status')
        if (not isinstance(state, dict) or not isinstance(state.get('processes'), list)
                or state.get('launcher_running') is not False):
            raise ManagerError('Der gestoppte Zustand ist nicht eindeutig bestätigt. Windows-Anbindung prüfen.')
        if state['processes']:
            raise ManagerError('Weltsicherungen und Wiederherstellungen benötigen einen gestoppten Server.')
        self.instance._windows_user_profile = state.get('user_profile') or self.instance._windows_user_profile

    def plan(self, config=None):
        m = self.instance
        config = m.config() if config is None else config
        if not config:
            raise ManagerError('Zuerst eine vorhandene Spielkonfiguration einrichten.')
        m.game.validate_config(config)
        config_path = plain_path(m.config_path)
        extras = {'config/server': config_path}
        source = world_source(m, config)
        extras.update({name: plain_path(path) for name, path in source.extra_files.items()})
        world = plain_path(source.directory)
        storage = plain_path(self.folder)
        protected = [m.directory.resolve(), m.root.resolve(), storage]
        if storage == m.directory.resolve() or m.directory.resolve() in storage.parents or any(world == p or world in p.parents for p in protected) or any(
                p == world or world in p.parents for p in extras.values()) or storage == world or storage in world.parents:
            raise ManagerError('Der Weltordner überschneidet sich mit geschützten Verwaltungs- oder Konfigurationsdateien.')
        binding = {'server_id': m.profile['id'], 'game': m.game.id, 'save_id': source.save_id,
                   'world': str(world), 'files': {name: str(path) for name, path in extras.items()}}
        return world, extras, binding

    def exclusive(self, binding=None):
        if binding is None:
            try:
                binding = self.plan()[2]
            except ManagerError:
                # New worlds may not have a Save-ID yet. Backup itself still
                # requires a complete plan; this hook also guards normal starts.
                return
        def overlaps(a, b):
            a, b = (str(Path(value)).replace('\\', '/').rstrip('/').casefold() for value in (a, b))
            return a == b or a.startswith(b + '/') or b.startswith(a + '/')
        own_files = binding['files'].values()
        for instance in self.peers():
            if instance is self.instance or not instance.config_path.exists():
                continue
            try:
                other = instance.backups.plan()[2]
            except ManagerError:
                continue
            if (overlaps(binding['world'], other['world'])
                    or any(overlaps(binding['world'], path) for path in other['files'].values())
                    or any(overlaps(other['world'], path) for path in own_files)
                    or any(overlaps(a, b) for a in own_files for b in other['files'].values())):
                raise ManagerError('Mehrere Serverprofile verwenden dieselben Welt- oder Konfigurationsdateien. Zuerst getrennte Speicherorte einrichten.')

    def token(self, binding):
        files = {key: digest(plain_path(Path(value))) for key, value in binding['files'].items()}
        return hashlib.sha256(json.dumps([binding, files], sort_keys=True).encode()).hexdigest()

    def inventory(self, world, extras):
        entries, directories = dict(extras), ['world']
        if not world.is_dir():
            raise ManagerError('Der konfigurierte Weltordner existiert noch nicht. Erst eine Welt anlegen und regulär speichern.')
        def scan_failed(error):
            raise ManagerError('Der Weltordner ist nicht vollständig lesbar. Keine Sicherung erstellt.') from error

        for parent, dirs, files in os.walk(world, followlinks=False, onerror=scan_failed):
            for child in sorted(dirs + files):
                path = plain_path(Path(parent) / child)
                relative = 'world/' + path.relative_to(world).as_posix()
                member_name(relative)
                mode = path.lstat().st_mode
                if stat.S_ISDIR(mode):
                    directories.append(relative)
                elif stat.S_ISREG(mode) and path.stat().st_nlink == 1:
                    entries[relative] = path
                else:
                    raise ManagerError('Die Welt enthält eine Verknüpfung oder eine nicht unterstützte Datei.')
                if len(entries) + len(directories) > MAX_FILES:
                    raise ManagerError('Die Welt enthält zu viele Dateien für eine Sicherung.')
        if not any(key.startswith('world/') for key in entries):
            raise ManagerError('Der Weltordner enthält keine Spielstanddateien.')
        names, signature, total = set(), {}, 0
        for name, path in sorted(entries.items()):
            member_name(name)
            plain_path(path)
            info = path.stat()
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                raise ManagerError('Konfigurationen und Weltdateien müssen gewöhnliche Dateien sein.')
            if name.startswith('config/') and info.st_size > CONFIG_LIMIT:
                raise ManagerError('Eine Konfigurationsdatei überschreitet das Limit von 16 MiB.')
            total += info.st_size
            signature[name] = [info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns]
        for name in sorted([*entries, *directories]):
            if name.casefold() in names:
                raise ManagerError('Die Welt enthält unter Windows mehrdeutige Dateinamen.')
            names.add(name.casefold())
        if total > MAX_BYTES:
            raise ManagerError('Die Welt überschreitet das Sicherungslimit von 16 GiB.')
        return entries, sorted(directories), signature, total

    def ensure_space(self, directory, required):
        if shutil.disk_usage(directory).free < required + RESERVE:
            raise ManagerError('Nicht genug freier Speicher. Bestehende Sicherungen bleiben erhalten.')

    def metadata_path(self, identifier):
        if not isinstance(identifier, str) or not IDENTIFIER.fullmatch(identifier):
            raise ManagerError('Ungültige Sicherungs-ID.')
        return self.folder / (identifier + '.json')

    def metadata(self, identifier):
        path = plain_path(self.metadata_path(identifier))
        if not path.is_file():
            raise ManagerError('Diese Weltsicherung existiert nicht.')
        value = read_json(path)
        required = {'id', 'created_at', 'verified_at', 'pinned', 'reason', 'bytes', 'count', 'build', 'manifest', 'sha256'}
        if (not isinstance(value, dict) or not required.issubset(value) or value.get('id') != identifier
                or not isinstance(value['manifest'], dict) or not isinstance(value['pinned'], bool)
                or not isinstance(value['sha256'], str) or not re.fullmatch(r'[0-9a-f]{64}', value['sha256'])):
            raise ManagerError('Die Sicherungsmetadaten sind beschädigt.')
        return value

    def view(self):
        m = self.instance
        records = []
        for path in sorted(self.folder.glob('*.json')):
            if not IDENTIFIER.fullmatch(path.stem):
                continue
            try:
                item = self.metadata(path.stem)
                records.append({key: item[key] for key in ('id', 'created_at', 'verified_at', 'pinned', 'reason', 'bytes', 'count', 'build')})
            except (ManagerError, OSError, KeyError, ValueError):
                records.append({'id': path.stem, 'error': 'Sicherungsmetadaten nicht lesbar.'})
        records.sort(key=lambda row: row.get('created_at', ''), reverse=True)
        error, binding = None, None
        try:
            _world, _extras, binding = self.plan()
        except (ManagerError, OSError) as exc:
            error = str(exc) if isinstance(exc, ManagerError) else 'Weltdateien sind nicht lesbar.'
        with self.progress_lock:
            progress = self.progress.copy() if self.progress else None
        settings = read_json(self.settings) if self.settings.exists() else {}
        return {'server_id': m.profile['id'], 'backups': records, 'target': binding, 'error': error,
                'retention_hours': 72, 'automatic_after_stop': settings.get('automatic_after_stop') is True,
                'recovery_required': self.pending.exists(), 'progress': progress}

    def create(self, *, reason='manual', pinned=False, prune=True):
        with self.instance.lock:
            self.guard()
            try:
                return self._create(reason=reason, pinned=pinned, prune=prune)
            finally:
                self.phase('idle')

    def _create(self, *, reason, pinned, prune):
        self.offline()
        world, extras, binding = self.plan()
        self.exclusive(binding)
        self.phase('Warte auf ruhende Weltdateien')
        _entries, _dirs, first, _size = self.inventory(world, extras)
        time.sleep(self.settle_seconds)
        self.offline()
        entries, directories, signature, size = self.inventory(world, extras)
        if signature != first or directories != _dirs:
            raise ManagerError('Weltdateien ändern sich noch. Später erneut sichern.')
        plain_path(self.folder).mkdir(parents=True, exist_ok=True, mode=0o700)
        self.folder.chmod(0o700)
        self.ensure_space(self.folder, size * 2 + MANIFEST_LIMIT)
        identifier = uuid4().hex
        temporary = self.folder / (identifier + '.partial')
        archive = self.folder / (identifier + '.zip')
        try:
            installation = installed(self.instance)
            build = installation['build'] if installation['complete'] and not installation['busy'] else None
        except (ManagerError, OSError):
            build = None
        manifest = {'schema': 1, 'id': identifier, 'created_at': now().isoformat(), 'binding': binding,
                    'directories': directories, 'files': [], 'build': build}
        try:
            with temporary.open('xb') as stream:
                temporary.chmod(0o600)
                with zipfile.ZipFile(stream, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=1) as output:
                    for position, (name, path) in enumerate(sorted(entries.items())):
                        self.phase('Sichere Dateien', position, len(entries))
                        checksum = hashlib.sha256()
                        count = 0
                        with path.open('rb') as source, output.open(name, 'w', force_zip64=True) as target:
                            while chunk := source.read(1024 * 1024):
                                count += len(chunk)
                                if count > signature[name][2]:
                                    raise ManagerError('Eine Quelldatei hat sich während der Sicherung geändert.')
                                checksum.update(chunk)
                                target.write(chunk)
                        manifest['files'].append({'name': name, 'size': count, 'sha256': checksum.hexdigest()})
                    output.writestr('manifest.json', json.dumps(manifest, ensure_ascii=False))
                stream.flush()
                os.fsync(stream.fileno())
            self.offline()
            _, after_dirs, after, _ = self.inventory(world, extras)
            if signature != after or directories != after_dirs or self.plan()[2] != binding:
                raise ManagerError('Die Welt oder ihre Konfiguration hat sich während der Sicherung geändert.')
            self.exclusive(binding)
            self.phase('Prüfe Archiv und Prüfsummen')
            self._inspect(temporary, manifest)
            record = {'id': identifier, 'created_at': manifest['created_at'], 'verified_at': now().isoformat(),
                      'pinned': pinned, 'reason': reason, 'bytes': temporary.stat().st_size,
                      'count': len(entries), 'build': build, 'manifest': manifest, 'sha256': digest(temporary)}
            os.replace(temporary, archive)
            atomic_json(self.metadata_path(identifier), record)
        except BaseException:
            temporary.unlink(missing_ok=True)
            # An archive without metadata is not offered as a successful backup.
            raise
        if prune:
            self.prune(identifier)
        self.instance.event('Weltsicherung erstellt und vollständig geprüft.')
        return {'message': 'Welt und Konfiguration gesichert; alle Prüfsummen bestätigt.', 'backup_id': identifier}

    def _inspect(self, path, expected):
        try:
            with zipfile.ZipFile(path) as archive:
                infos = archive.infolist()
                if len(infos) > MAX_FILES + 1 or sum(info.file_size for info in infos) > MAX_BYTES + MANIFEST_LIMIT:
                    raise ManagerError('Das Sicherungsarchiv überschreitet die Größenlimits.')
                names = [member_name(info.filename) for info in infos]
                if len(set(name.casefold() for name in names)) != len(names):
                    raise ManagerError('Mehrdeutige Archivdateien sind nicht erlaubt.')
                if any(stat.S_ISLNK(info.external_attr >> 16) or info.is_dir() for info in infos):
                    raise ManagerError('Das Archiv enthält unzulässige Dateitypen.')
                if archive.getinfo('manifest.json').file_size > MANIFEST_LIMIT:
                    raise ManagerError('Das Sicherungsmanifest ist zu groß.')
                manifest = json.loads(archive.read('manifest.json'))
                if manifest != expected or manifest.get('schema') != 1:
                    raise ManagerError('Archiv und Sicherungsmanifest passen nicht zusammen.')
                expected_files = {item['name']: item for item in manifest['files']}
                if len(expected_files) != len(manifest['files']) or set(names) != {*expected_files, 'manifest.json'}:
                    raise ManagerError('Das Archiv ist unvollständig.')
                for directory in manifest['directories']:
                    member_name(directory)
                    if directory != 'world' and not directory.startswith('world/'):
                        raise ManagerError('Ungültiger Weltordner im Manifest.')
                for name, item in expected_files.items():
                    if not (name.startswith('world/') or name in ('config/server', 'config/preset')):
                        raise ManagerError('Unbekannte Sicherungsdatei.')
                    if name.startswith('config/') and archive.getinfo(name).file_size > CONFIG_LIMIT:
                        raise ManagerError('Eine gesicherte Konfigurationsdatei ist zu groß.')
                    checksum, count = hashlib.sha256(), 0
                    with archive.open(name) as source:
                        while chunk := source.read(1024 * 1024):
                            checksum.update(chunk)
                            count += len(chunk)
                            if count > MAX_BYTES:
                                raise ManagerError('Archivdatei überschreitet das Größenlimit.')
                    if count != item['size'] or checksum.hexdigest() != item['sha256']:
                        raise ManagerError('Eine Prüfsumme der Weltsicherung stimmt nicht.')
                return manifest
        except (zipfile.BadZipFile, KeyError, ValueError, TypeError, RuntimeError) as exc:
            raise ManagerError('Das Sicherungsarchiv ist beschädigt oder unvollständig.') from exc

    def verify(self, identifier):
        with self.instance.lock:
            try:
                self.phase('Prüfe Archiv und Prüfsummen')
                record = self.metadata(identifier)
                archive = plain_path(self.folder / (identifier + '.zip'))
                if digest(archive) != record['sha256']:
                    raise ManagerError('Die Prüfsumme des Sicherungsarchivs stimmt nicht.')
                self._inspect(archive, record['manifest'])
                record['verified_at'] = now().isoformat()
                atomic_json(self.metadata_path(identifier), record)
                return {'message': 'Alle Dateien und Prüfsummen der Weltsicherung bestätigt.'}
            except (ManagerError, OSError):
                if 'record' in locals():
                    record['verified_at'] = None
                    atomic_json(self.metadata_path(identifier), record)
                raise ManagerError('Sicherung nicht vollständig lesbar oder Prüfsummen fehlerhaft. Keine Weltdateien geändert.') from None
            finally:
                self.phase('idle')

    def prune(self, newest):
        # Never called before a new archive has passed every verification step.
        cutoff = now() - RETENTION
        for path in self.folder.glob('*.json'):
            if not IDENTIFIER.fullmatch(path.stem) or path.stem == newest:
                continue
            try:
                record = self.metadata(path.stem)
                created = datetime.fromisoformat(record['created_at'])
                if created.tzinfo is None or record['pinned'] or not record['verified_at'] or created >= cutoff:
                    continue
                archive = plain_path(path.with_suffix('.zip'))
                archive.unlink(missing_ok=True)
                path.unlink()
            except (ManagerError, OSError, KeyError, ValueError, TypeError):
                continue

    def configure(self, value):
        with self.instance.lock:
            if not isinstance(value, bool):
                raise ManagerError('Bitte die automatische Sicherung ausdrücklich aktivieren oder deaktivieren.')
            plain_path(self.folder).mkdir(parents=True, exist_ok=True, mode=0o700)
            atomic_json(self.settings, {'automatic_after_stop': value})
            return {'message': 'Sicherung nach regulärem Dashboard-Stopp ' + ('aktiviert.' if value else 'deaktiviert.')}

    def after_stop(self):
        if self.settings.exists() and read_json(self.settings).get('automatic_after_stop') is True:
            try:
                self.create(reason='after_stop')
            except (ManagerError, OSError):
                raise ManagerError('Server gestoppt, aber Weltsicherung fehlgeschlagen. Unter Weltsicherungen prüfen; ein angeforderter Neustart wurde nicht ausgeführt.') from None

    def pin(self, identifier, value):
        with self.instance.lock:
            if not isinstance(value, bool):
                raise ManagerError('Ungültiger Wert für angeheftete Sicherung.')
            if self.pending.exists() and read_json(self.pending).get('before') == identifier and not value:
                raise ManagerError('Die Rückwegsicherung bleibt bis zur abgeschlossenen Wiederherstellung angeheftet.')
            record = self.metadata(identifier)
            record['pinned'] = value
            atomic_json(self.metadata_path(identifier), record)
            return {'message': 'Sicherung angeheftet.' if value else 'Sicherung unterliegt wieder der Aufbewahrung von 72 Stunden.'}

    def preview(self, identifier, *, recovery=False):
        record = self.metadata(identifier)
        _world, _extras, binding = self.plan()
        if record['manifest']['binding'] != binding:
            raise ManagerError('Diese Sicherung gehört zu einem anderen Weltziel. Kein Spiel- oder Weltwechsel durch Wiederherstellen.')
        self.exclusive(binding)
        if recovery:
            if not self.pending.exists() or read_json(self.pending).get('before') != identifier:
                raise ManagerError('Diese Sicherung gehört nicht zum ausstehenden Rückweg.')
        return {'backup_id': identifier, 'created_at': record['created_at'], 'target': binding,
                'token': self.token(binding), 'confirmation': 'WIEDERHERSTELLEN'}

    def recovery_preview(self):
        if not self.pending.exists():
            raise ManagerError('Es gibt keine ausstehende Wiederherstellung.')
        return self.preview(read_json(self.pending)['before'], recovery=True)

    def restore(self, identifier, token, confirmation, *, recovery=False):
        try:
            return self._restore(identifier, token, confirmation, recovery=recovery)
        finally:
            self.phase('idle')

    def _restore(self, identifier, token, confirmation, *, recovery=False):
        with self.instance.lock:
            if confirmation != 'WIEDERHERSTELLEN':
                raise ManagerError('Die ausdrückliche Wiederherstellungsbestätigung fehlt.')
            if not recovery:
                self.guard()
            preview = self.preview(identifier, recovery=recovery)
            if token != preview['token']:
                raise ManagerError('Das Wiederherstellungsziel hat sich geändert. Vorschau erneut laden.')
            self.offline()
            self.verify(identifier)
            if self.token(preview['target']) != token:
                raise ManagerError('Das Wiederherstellungsziel hat sich geändert. Vorschau erneut laden.')
            if recovery:
                before = identifier
            else:
                before = self._create(reason='before_restore', pinned=True, prune=False)['backup_id']
                atomic_json(self.pending, {'before': before, 'binding': preview['target']})
            try:
                self._install(identifier)
            except Exception:
                if not recovery:
                    try:
                        self._install(before)
                    except Exception:
                        raise ManagerError('Wiederherstellung und Rückweg unterbrochen. Server bleibt gesperrt; Rückweg unter Weltsicherungen ausführen.') from None
                    self.pending.unlink()
                    raise ManagerError('Wiederherstellung fehlgeschlagen. Der zuvor gesicherte Stand wurde zurückgeholt.') from None
                raise ManagerError('Rückweg nicht abgeschlossen. Server bleibt gesperrt; lokale Dateien und freien Speicher prüfen.') from None
            finally:
                self.phase('idle')
            self.pending.unlink()
            self.instance.event('Welt und zugehörige Konfiguration wiederhergestellt; vorheriger Stand bleibt angeheftet.')
            return {'message': 'Welt und Konfiguration wiederhergestellt. Server bleibt gestoppt.', 'before_backup_id': before}

    def _install(self, identifier):
        record = self.metadata(identifier)
        world, extras, binding = self.plan()
        if binding != record['manifest']['binding']:
            raise ManagerError('Wiederherstellungsziel stimmt nicht mit dem Archiv überein.')
        self.verify(identifier)
        archive_path = plain_path(self.folder / (identifier + '.zip'))
        required = sum(item['size'] for item in record['manifest']['files'])
        self.ensure_space(world.parent, required)
        # Stage on the world's filesystem; directory replacement must be a rename.
        stage = Path(tempfile.mkdtemp(prefix='.indy-restore-', dir=world.parent))
        old = world.parent / ('.indy-previous-' + uuid4().hex)
        moved = False
        try:
            self.phase('Bereite Wiederherstellung vor')
            with zipfile.ZipFile(archive_path) as archive:
                for directory in record['manifest']['directories']:
                    (stage / member_name(directory)).mkdir(parents=True, exist_ok=True)
                for item in record['manifest']['files']:
                    target = stage / member_name(item['name'])
                    target.parent.mkdir(parents=True, exist_ok=True)
                    with archive.open(item['name']) as source, target.open('xb') as output:
                        target.chmod(0o600)
                        shutil.copyfileobj(source, output, 1024 * 1024)
                        output.flush()
                        os.fsync(output.fileno())
                    if digest(target) != item['sha256']:
                        raise ManagerError('Die vorbereiteten Dateien sind nicht vollständig.')
                restored_config = self.instance.game.read_config(stage / 'config/server')
                if self.plan(restored_config)[2] != binding:
                    raise ManagerError('Die gesicherte Konfiguration verweist auf eine andere Welt.')
                self.offline()
                plain_path(world)
                self.exclusive(binding)
                for name, path in extras.items():
                    self.ensure_space(path.parent, (stage / name).stat().st_size)
                # No external desktop starter may run during this owner operation.
                self.phase('Ersetze Welt und Konfiguration')
                if world.exists():
                    world.rename(old)
                    moved = True
                (stage / 'world').rename(world)
                for name, path in extras.items():
                    self.ensure_space(path.parent, (stage / name).stat().st_size)
                    atomic_bytes(plain_path(path), (stage / name).read_bytes())
                self.offline()
                entries, _dirs, _signature, _size = self.inventory(world, extras)
                if _dirs != record['manifest']['directories'] or set(entries) != {item['name'] for item in record['manifest']['files']} or any(
                        digest(entries[item['name']]) != item['sha256'] for item in record['manifest']['files']):
                    raise ManagerError('Die wiederhergestellten Dateien stimmen nicht mit der Sicherung überein.')
        except BaseException:
            # Preserve the displaced directory if completion is uncertain. The
            # durable pre-restore archive is the authoritative explicit recovery.
            raise
        else:
            if moved:
                shutil.rmtree(old)
        finally:
            shutil.rmtree(stage)
