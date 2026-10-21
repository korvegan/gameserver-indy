"""Read-only native host addresses. A public IP never proves inbound reachability."""
from datetime import datetime, timezone
import ipaddress
import json
import os
import re
import shutil
import subprocess
import threading
import time

from .paths import ROOT
from .configuration import ManagerError

MAX_AGE = 15 * 60


def public_host(value):
    if not isinstance(value, str):
        raise ManagerError('Bitte eine öffentliche Domain oder IPv4 ohne Protokoll und Port angeben.')
    host = value.strip().lower()
    if not host:
        return ''
    if host.endswith('.'):
        host = host[:-1]
    labels = host.split('.')
    if (not host or len(host) > 253 or len(labels) < 2
            or not all(re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label) for label in labels)
            or (all(label.isdigit() for label in labels) and ipv4(host, public=True) is None)):
        raise ManagerError('Bitte eine öffentliche Domain oder IPv4 ohne Protokoll und Port angeben.')
    return host


def join_details(instance, config):
    """Shared public join contract; no network access, passwords or local addresses."""
    metadata = instance.game.schema.get('connection', {})
    mode = metadata.get('mode', 'server_browser')
    port = config.get(metadata.get('port_key'))
    port = port if type(port) is int and 1 <= port <= 65535 else None
    bind = config.get(metadata.get('bind_key'), '0.0.0.0')
    with instance.settings_lock:
        host = public_host(instance.profile.get('connection', {}).get('public_host', ''))
    direct = mode == 'ip_query' and port is not None
    address = f'{host}:{port}' if direct and host and (bind == '0.0.0.0' or ipv4(bind)) else None
    name = ' '.join(str(instance.game.title(config)).split())[:160]
    instructions = metadata.get('instructions') or 'Im Spiel die Serverliste öffnen und nach dem Servernamen suchen.'
    lines = [f'{instance.game.label} · Mitspielen', f'Servername: {name}']
    if address:
        lines.append(f'Adresse: `{address}`')
    lines.extend([instructions, 'Ein benötigtes Passwort separat teilen; es wird hier nicht mitgesendet.'])
    return {'mode': mode, 'port': port, 'public_host': host, 'public_address': address,
            'server_name': name, 'instructions': instructions, 'share_message': '\n'.join(lines),
            'password_hint': metadata.get('password_hint')}


def ipv4(value, public=False):
    if not isinstance(value, str):
        return None
    try:
        address = ipaddress.IPv4Address(value)
    except ValueError:
        return None
    if address.is_unspecified or address.is_loopback or address.is_link_local or address.is_multicast or address.is_reserved:
        return None
    return str(address) if not public or address.is_global else None


def windows_network():
    script = str(ROOT / 'scripts' / 'windows-network.ps1')
    if os.name != 'nt':
        converted = subprocess.run(['wslpath', '-w', script], capture_output=True, text=True, timeout=5, check=True)
        script = converted.stdout.strip()
    powershell = shutil.which('powershell.exe') or (
        '/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe' if os.name != 'nt' else 'powershell.exe')
    result = subprocess.run([powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', script],
                            capture_output=True, encoding='utf-8', errors='replace', timeout=25, check=True)
    return json.loads(result.stdout.lstrip('\ufeff'))


class ConnectionService:
    def __init__(self, provider=None):
        self.provider = provider or windows_network
        self.lock = threading.Lock()
        self.stop = threading.Event()
        self.checking = False
        self.snapshot = {}
        self.collected = None
        self.scheduler = None

    def refresh(self):
        with self.lock:
            if self.checking or self.stop.is_set():
                return
            self.checking = True
        threading.Thread(target=self._collect, name='host-addresses', daemon=True).start()

    def _collect(self):
        try:
            raw = self.provider()
            # Explicit allowlist: no arbitrary command output reaches status or logs.
            addresses = raw.get('lan', [])
            if not isinstance(addresses, list):
                addresses = []
            snapshot = {'lan': sorted({ip for value in addresses[:64] if (ip := ipv4(value))}),
                        'public': ipv4(raw.get('public'), public=True)}
        except Exception:
            snapshot = {'lan': [], 'public': None}
        snapshot['checked_at'] = datetime.now(timezone.utc).isoformat()
        with self.lock:
            self.snapshot = snapshot
            self.collected = time.monotonic()
            self.checking = False

    def start(self):
        self.refresh()

        def schedule():
            while not self.stop.wait(MAX_AGE):
                self.refresh()
        self.scheduler = threading.Thread(target=schedule, name='host-address-scheduler', daemon=True)
        self.scheduler.start()

    def close(self):
        self.stop.set()
        if self.scheduler:
            self.scheduler.join(timeout=1)

    def view(self, instance, config):
        with self.lock:
            snapshot = dict(self.snapshot)
            fresh = self.collected is not None and time.monotonic() - self.collected < MAX_AGE
            checking = self.checking
        details = join_details(instance, config)
        mode, port = details['mode'], details['port']
        metadata = instance.game.schema.get('connection', {})
        bind = config.get(metadata.get('bind_key'), '0.0.0.0')
        all_interfaces = bind == '0.0.0.0'
        direct = mode == 'ip_query' and port is not None
        local = f'127.0.0.1:{port}' if direct and (all_interfaces or bind == '127.0.0.1') else None
        lan = [f'{address}:{port}' for address in snapshot.get('lan', [])
               if direct and fresh and (all_interfaces or address == bind)]
        # External NAT mapping and firewall have deliberately not been probed.
        external_ip = f'{snapshot["public"]}:{port}' if direct and fresh and snapshot.get('public') and lan else None
        return {**details, 'local': local, 'lan': lan, 'external': details['public_address'] or external_ip,
                'external_ip': external_ip,
                'checking': checking, 'stale': bool(snapshot) and not fresh,
                'checked_at': snapshot.get('checked_at'), 'external_verified': False}
