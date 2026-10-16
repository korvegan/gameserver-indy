"""Explicit prepared state and the controller lease shared with the Symfony supervisor."""
from contextlib import contextmanager
import json
import os
from pathlib import Path
import stat

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
