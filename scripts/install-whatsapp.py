"""Install only the private, pinned WhatsApp companion service; never send messages."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from gameserver_manager.configuration import atomic_json, atomic_text, read_json


def install(root):
    compose = Path(__file__).resolve().parent.parent / 'ops' / 'whatsapp.compose.json'
    directory = root / 'data' / 'whatsapp'
    check = subprocess.run(['docker', 'inspect', 'gameserver-indy-whatsapp'], capture_output=True, text=True)
    if check.returncode == 0:
        container = json.loads(check.stdout)[0]
        if (container['Config'].get('Labels', {}).get('io.gameserver-indy.service') != 'whatsapp'
                or container['Config'].get('Labels', {}).get('com.docker.compose.project') != 'gameserver-indy-whatsapp'
                or container['HostConfig']['PortBindings'] != {'3000/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '18768'}]}
                or not any(mount.get('Destination') == '/app/.sessions' and mount.get('Type') == 'bind'
                           and mount.get('Source') == str(directory.resolve() / 'sessions')
                           for mount in container.get('Mounts', []))):
            raise RuntimeError('Vorhandener gleichnamiger Container gehört nicht zur erwarteten lokalen Anbindung.')
    else:
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 18768))
    if directory.is_symlink():
        raise RuntimeError('Der private WhatsApp-Datenordner darf keine Verknüpfung sein.')
    directory.mkdir(parents=True, exist_ok=True)
    directory.chmod(0o700)
    credentials = directory / 'credentials.json'
    key = read_json(credentials)['api_key'] if credentials.exists() else secrets.token_hex(32)
    if not isinstance(key, str) or not re.fullmatch('[a-f0-9]{64}', key):
        raise RuntimeError('Vorhandene WhatsApp-Zugangsdaten sind ungültig; nicht automatisch ersetzen.')
    atomic_json(credentials, {'api_key': key})
    settings = {
        'WAHA_API_KEY': 'sha512:' + hashlib.sha512(key.encode()).hexdigest(),
        'WHATSAPP_DEFAULT_ENGINE': 'GOWS', 'WAHA_PRINT_QR': 'False', 'WAHA_LOG_LEVEL': 'error',
        'WAHA_DASHBOARD_ENABLED': 'false', 'WHATSAPP_SWAGGER_ENABLED': 'false',
        'WAHA_EVENTS_DOWNLOAD_MEDIA': 'false', 'WAHA_API_DOWNLOAD_MEDIA': 'false',
        'WAHA_GOWS_DEVICE_REQUIRE_FULL_SYNC': 'false',
        'WAHA_GOWS_DEVICE_HISTORY_SYNC_FULL_SYNC_DAYS_LIMIT': '0',
        'WAHA_GOWS_DEVICE_HISTORY_SYNC_RECENT_SYNC_DAYS_LIMIT': '0',
        'WAHA_GOWS_DEVICE_HISTORY_SYNC_INITIAL_SYNC_MAX_MESSAGES_PER_CHAT': '0',
    }
    atomic_text(directory / 'waha.env', ''.join(f'{key}={value}\n' for key, value in settings.items()))
    environment = {**os.environ, 'INDY_WHATSAPP_DATA': str(directory.resolve())}
    subprocess.run(['docker', 'compose', '--project-name', 'gameserver-indy-whatsapp', '-f', str(compose),
                    'up', '-d'], env=environment, check=True)
    print('Lokaler WhatsApp-Dienst eingerichtet. Konto persönlich im Dashboard verknüpfen; keine Nachricht versendet.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parent.parent)
    args = parser.parse_args()
    try:
        install(args.root.resolve())
    except (OSError, RuntimeError, subprocess.SubprocessError, ValueError, KeyError) as error:
        parser.exit(1, 'WhatsApp-Einrichtung fehlgeschlagen. Dienst, Port und private Dateien prüfen.\n')
