"""Bounded readiness evidence tied to a single, current native process start."""
from datetime import datetime, timezone
import re

from .configuration import ManagerError, local_path


def process_identity(status):
    processes = status.get('processes', [])
    if len(processes) != 1:
        return None
    process = processes[0]
    if type(process.get('pid')) is not int or not isinstance(process.get('started'), str):
        return None
    try:
        started = datetime.fromisoformat(process['started'].replace('Z', '+00:00'))
        if started.tzinfo is None or started > datetime.now(timezone.utc):
            return None
    except ValueError:
        return None
    return f"{process['pid']}:{process['started']}", started


def readiness(instance, status):
    identity = process_identity(status)
    if not identity:
        return {'state': 'starting' if status.get('starting') else 'unknown' if status.get('running') else 'stopped',
                'label': 'Starter arbeitet' if status.get('starting') else 'Unbekannt' if status.get('running') else 'Gestoppt'}
    key, started = identity
    result = {'state': 'unknown', 'label': 'Bereitschaft noch nicht bestätigt', 'process_key': key}
    try:
        if instance.game.id == 'enshrouded':
            config = instance.config() or {}
            directory = local_path(instance.profile.get('log_directory') or config.get('logDirectory', './logs'))
            if not directory.is_absolute():
                directory = instance.directory / directory
            path = directory / 'enshrouded_server.log'
            gap = False
            with path.open('rb') as file:
                head = file.read(262144)
                head_length = len(head.decode('utf-8', errors='replace'))
                size = file.seek(0, 2)
                if size > len(head):
                    offset = max(len(head), size - 262144)
                    gap = offset > len(head)
                    file.seek(offset)
                    tail = file.read(262144)
                    head += b'\n' + tail.partition(b'\n')[2] if gap else tail
            text = head.decode('utf-8', errors='replace')
            headers = list(re.finditer(r'Current UTC date: (\d{4}\.\d{2}\.\d{2} \d{2}:\d{2}:\d{2})', text))
            if not headers:
                return result
            header = headers[-1]
            log_start = datetime.strptime(header[1], '%Y.%m.%d %H:%M:%S').replace(tzinfo=timezone.utc)
            # Enshrouded records UTC only to seconds; its current run must match
            # the native start second (or the immediately following second).
            difference = (log_start - started.replace(microsecond=0)).total_seconds()
            # With a gap, an unseen transition could supersede any marker in
            # the head. Only the contiguous tail can establish the last state.
            transition_start = max(header.end(), head_length + 1 if gap else 0)
            transitions = re.findall(r"\[Session\] 'HostOnline' \((up|down)\)!", text[transition_start:])
            ready = difference in (0, 1) and bool(transitions) and transitions[-1] == 'up'
            source = 'Enshrouded · HostOnline / Startdatum UTC'
        elif instance.game.id == 'aska':
            path = instance.diagnostic_directory / 'server.log'
            with path.open('rb') as file:
                file.seek(max(0, path.stat().st_size - 262144))
                text = file.read(262144).decode('utf-8', errors='replace')
            ready = False
            for line in text.splitlines():
                if 'The session is now open!' not in line:
                    continue
                stamp = datetime.fromisoformat(line.split(' ', 1)[0].replace('Z', '+00:00'))
                if stamp.tzinfo and started <= stamp <= datetime.now(timezone.utc):
                    ready = True
            source = 'ASKA · aktuelle Sitzungsöffnung'
        else:
            return result
        if ready:
            result.update(state='ready', label='Sitzung bereit · lokal bestätigt', source=source)
    except (ManagerError, OSError, ValueError, IndexError):
        pass
    return result
