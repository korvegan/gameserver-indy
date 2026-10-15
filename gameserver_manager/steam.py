"""Read-only Steam manifests and anonymous SteamCMD application metadata."""
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import threading

from .configuration import ManagerError, local_path

LIMIT = 4 * 1024 * 1024
TOKEN = re.compile(r'\s+|//[^\n]*|"((?:\\.|[^"\\])*)"|([{}])')


def object_at(text, offset=0):
    """Parse one quoted Valve KeyValues object, rejecting ambiguous keys."""
    if len(text) > LIMIT:
        raise ManagerError("Steam-Metadaten sind zu groß.")
    position = offset

    def token():
        nonlocal position
        while position < len(text):
            match = TOKEN.match(text, position)
            if not match:
                raise ManagerError("Steam-Metadaten sind nicht lesbar.")
            position = match.end()
            if match.group(1) is not None:
                return re.sub(r'\\(["\\])', r'\1', match.group(1)), False
            if match.group(2):
                return match.group(2), True
        raise ManagerError("Steam-Metadaten sind unvollständig.")

    def block(depth=0):
        if depth > 32:
            raise ManagerError("Steam-Metadaten sind zu tief verschachtelt.")
        result = {}
        while True:
            key, structural = token()
            if structural and key == "}":
                return result
            if structural or key.casefold() in result:
                raise ManagerError("Steam-Metadaten enthalten mehrdeutige Schlüssel.")
            value, structural = token()
            if structural and value != "{":
                raise ManagerError("Steam-Metadaten sind unvollständig.")
            result[key.casefold()] = block(depth + 1) if structural else value

    opening, structural = token()
    if not structural or opening != "{":
        raise ManagerError("Steam-Metadaten enthalten kein Objekt.")
    return block()


def named_object(text, name):
    match = re.search(r'(?m)^\s*"' + re.escape(str(name)) + r'"\s*(?=\{)', text)
    if not match:
        raise ManagerError("Steam hat keine passenden App-Informationen geliefert.")
    return object_at(text, match.end())


def small_text(path):
    with path.open('rb') as source:
        data = source.read(LIMIT + 1)
    if len(data) > LIMIT:
        raise ManagerError("Steam-Metadaten sind zu groß.")
    try:
        return data.decode('utf-8-sig')
    except UnicodeError:
        raise ManagerError("Steam-Metadaten sind nicht UTF-8-lesbar.") from None


def branch_name(value):
    if not isinstance(value, str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,80}', value):
        raise ManagerError("Der Steam-Branch ist nicht gültig.")
    return 'public' if value.casefold() in ('public', 'default') else value


def installed(instance):
    app_id = instance.game.schema.get('steam_server_app_id')
    directory = instance.directory.resolve()
    explicit = instance.profile.get('steam_manifest')
    candidates = [local_path(explicit)] if explicit else [
        directory.parent.parent / f'appmanifest_{app_id}.acf',
        directory / 'steamapps' / f'appmanifest_{app_id}.acf',
    ]
    found = [path for path in candidates if path.is_file()]
    if len(found) != 1:
        raise ManagerError("Kein eindeutig zugeordnetes Steam-Appmanifest gefunden.")
    path = found[0]
    data = named_object(small_text(path), 'AppState')
    if data.get('appid') != str(app_id):
        raise ManagerError("Das Steam-Appmanifest gehört zu einer anderen App.")
    folder = data.get('installdir', '')
    if not isinstance(folder, str) or not folder:
        raise ManagerError("Im Steam-Appmanifest fehlt der Installationsordner.")
    client_match = path.parent.name.casefold() == 'steamapps' and (path.parent / 'common' / folder).resolve() == directory
    cmd_match = (path.parent.resolve() == directory / 'steamapps'
                 and (folder == directory.name or local_path(folder).resolve() == directory))
    if not (client_match or cmd_match):
        raise ManagerError("Steam-Appmanifest und Serverordner stimmen nicht überein.")
    user_config = data.get('userconfig', {})
    mounted = data.get('mountedconfig', {})
    if not isinstance(user_config, dict) or not isinstance(mounted, dict):
        raise ManagerError("Der installierte Steam-Branch ist nicht lesbar.")
    branch = branch_name(user_config.get('betakey', mounted.get('betakey', 'public')))
    if mounted.get('betakey') and branch_name(mounted['betakey']) != branch:
        raise ManagerError("Steam hat den Branchwechsel noch nicht abgeschlossen.")
    if instance.profile.get('steam_branch') and branch_name(instance.profile['steam_branch']) != branch:
        raise ManagerError("Profil-Branch und installierter Steam-Branch stimmen nicht überein.")
    build = data.get('buildid', '')
    if not isinstance(build, str) or not re.fullmatch(r'[0-9]{1,20}', build) or int(build) == 0:
        raise ManagerError("Das Steam-Appmanifest enthält keine installierte Build-ID.")
    try:
        flags = int(data.get('stateflags', '-1'))
        incomplete = any(int(data.get(total, '0')) > int(data.get(done, '0'))
                         for total, done in (('bytestodownload', 'bytesdownloaded'), ('bytestostage', 'bytesstaged')))
        updated = int(data.get('lastupdated', '0'))
    except (TypeError, ValueError):
        raise ManagerError("Der Steam-Installationszustand ist nicht lesbar.") from None
    # FullyInstalled (4), optionally AppRunning (64). Any in-flight mutation
    # is kept separate from the completed on-disk build.
    busy = bool(flags & (256 | 512 | 1024 | 131072 | 262144 | 524288 | 1048576 | 2097152 | 4194304 | 8388608))
    target_pending = data.get('targetbuildid', '') not in ('', '0', build)
    return {'app_id': app_id, 'branch': branch, 'build': build, 'updated': updated,
            'busy': busy or incomplete, 'complete': flags in (4, 68) and not target_pending}


class SteamCmd:
    def __init__(self, settings):
        self.settings = settings
        self.lock = threading.Lock()

    def executable(self):
        configured = self.settings.get('steamcmd_path')
        if configured:
            if not isinstance(configured, str) or not local_path(configured).is_absolute():
                raise ManagerError("steamcmd_path benötigt einen absoluten Pfad.")
            path = local_path(configured)
            if not path.is_file():
                raise ManagerError("Die eingerichtete SteamCMD-Datei fehlt.")
            return path.resolve()
        executable = shutil.which('steamcmd') or shutil.which('steamcmd.exe')
        if not executable:
            raise ManagerError("SteamCMD ist nicht eingerichtet. steamcmd_path in den lokalen Managereinstellungen hinterlegen.")
        return Path(executable).resolve()

    def query(self, app_id, branch):
        executable = self.executable()
        if not self.lock.acquire(timeout=1):
            raise ManagerError("Eine andere Steam-Abfrage läuft. Bitte später erneut prüfen.")
        try:
            # The fixed allowlist never contains app_update, a login account,
            # credentials or an installation directory.
            arguments = [str(executable), '+login', 'anonymous', '+app_info_update', '1',
                         '+app_info_print', str(app_id), '+quit']
            with tempfile.TemporaryFile() as output:
                try:
                    result = subprocess.run(arguments, cwd=executable.parent, stdin=subprocess.DEVNULL,
                                            stdout=output, stderr=output, timeout=45,
                                            creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
                except subprocess.TimeoutExpired:
                    raise ManagerError("Steam-Abfrage nach 45 Sekunden abgebrochen. Kein bestätigter neuer Stand.") from None
                except OSError:
                    raise ManagerError("SteamCMD konnte nicht ausgeführt werden.") from None
                output.seek(0)
                text = output.read(LIMIT + 1).decode('utf-8', errors='replace')
            if result.returncode != 0 or not re.search(r'Connecting anonymously[^\r\n]*\.\.\.OK', text):
                raise ManagerError("SteamCMD hat keine erfolgreiche Online-Abfrage bestätigt.")
            try:
                info = named_object(text, app_id)
                selected = info['depots']['branches'][branch.casefold()]
                build = selected['buildid']
                if selected.get('pwdrequired', '0') != '0' or not re.fullmatch(r'[0-9]{1,20}', build) or int(build) == 0:
                    raise KeyError()
            except (KeyError, TypeError):
                raise ManagerError("Für diesen Steam-Branch fehlen zugängliche Build-Metadaten.") from None
            return build
        finally:
            self.lock.release()
