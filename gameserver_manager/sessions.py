"""A private CSRF signing key survives manager restarts; grants still revoke tokens."""
import hashlib
import hmac
import os
import re
import secrets
import threading

from .configuration import ManagerError

_lock = threading.Lock()


def signing_token(root, scope):
    directory = root / 'data'
    path = directory / 'session-key'
    with _lock:
        directory.mkdir(parents=True, exist_ok=True)
        try:
            descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        except FileExistsError:
            pass
        else:
            try:
                with os.fdopen(descriptor, 'w', encoding='ascii') as output:
                    output.write(secrets.token_urlsafe(32))
                    output.flush()
                    os.fsync(output.fileno())
            except BaseException:
                path.unlink(missing_ok=True)
                raise
        try:
            key = path.read_text(encoding='ascii')
            if not re.fullmatch(r'[A-Za-z0-9_-]{43}', key):
                raise ValueError()
            path.chmod(0o600)
        except (OSError, UnicodeError, ValueError):
            raise ManagerError('Der private Sitzungsschlüssel ist nicht lesbar. Lokalen Speicher prüfen; Schlüssel nicht automatisch ersetzen.') from None
    return hmac.new(key.encode(), scope.encode(), hashlib.sha256).hexdigest()
