"""Opt-in ready notifications to one explicitly selected WhatsApp group."""
from contextlib import contextmanager
from datetime import datetime, timezone
import sqlite3
import threading

from .configuration import ManagerError, atomic_json, read_json
from .connections import join_details
from .readiness import process_identity, readiness
from .security import AccessDenied
from .whatsapp_client import LocalWhatsAppClient


def local_whatsapp_owner(principal):
    principal.require('discord.write')
    if principal.id != 'local-owner' or principal.auth_revision or principal.server_ids is not None:
        raise AccessDenied('Das persönliche WhatsApp-Konto wird ausschließlich am lokalen Besitzerzugang verwaltet.')


def short_message(instance, config, ready):
    details = join_details(instance, config)
    if details['mode'] == 'ip_query' and not details['public_address']:
        raise ManagerError('Bitte zuerst die öffentliche Beitrittsadresse dieses Serverprofils speichern.')
    address = details['public_address'] or 'Im Spiel in der Serverliste suchen'
    status = '✅ Bereit zum Beitritt' if ready else '⏳ Bereitschaft noch nicht bestätigt'
    return f"{instance.game.label} · {details['server_name']}\n{address}\n{status}"


class WhatsAppService:
    def __init__(self, registry, client=None, probe=readiness):
        self.registry = registry
        self.directory = registry.root / 'data' / 'whatsapp'
        self.directory.mkdir(parents=True, exist_ok=True)
        self.directory.chmod(0o700)
        self.path = self.directory / 'settings.json'
        self.database = self.directory / 'deliveries.sqlite3'
        self.client = client or LocalWhatsAppClient(registry.root)
        self.probe = probe
        self.lock = threading.RLock()
        self.poll_lock = threading.Lock()
        self.stop = threading.Event()
        self.wake = threading.Event()
        self.thread = None
        self.uncertain = set()
        with self.connection() as db:
            db.execute('''CREATE TABLE IF NOT EXISTS deliveries (
                server_id TEXT NOT NULL, process_key TEXT NOT NULL, group_id TEXT NOT NULL,
                state TEXT NOT NULL, updated_at TEXT NOT NULL,
                PRIMARY KEY(server_id, process_key, group_id))''')
            db.execute("UPDATE deliveries SET state='uncertain' WHERE state='sending'")
        self.database.chmod(0o600)

    @contextmanager
    def connection(self):
        db = sqlite3.connect(self.database, timeout=5)
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    def settings(self):
        with self.lock:
            return read_json(self.path) if self.path.exists() else {
                'group_id': '', 'group_label': '', 'enabled': False, 'server_ids': [], 'revision': 0}

    def snapshot(self, instance):
        status = instance.status()
        evidence = self.probe(instance, status)
        try:
            text = short_message(instance, instance.config() or {}, evidence['state'] == 'ready')
            error = None
        except (ManagerError, OSError):
            text, error = '', 'Beitrittsadresse oder Spielkonfiguration zuerst prüfen.'
        return {'server_id': instance.profile['id'], 'label': instance.profile['label'],
                'readiness': evidence, 'message': text, 'error': error}

    def view(self):
        settings = self.settings()
        state, error = 'NOT_INSTALLED', None
        if self.client.configured:
            try:
                state = self.client.session()['status']
            except ManagerError:
                state, error = 'UNAVAILABLE', 'Der lokale WhatsApp-Dienst ist nicht erreichbar.'
        previews = []
        for instance in self.registry.instances():
            try:
                preview = self.snapshot(instance)
                identity = preview['readiness'].get('process_key')
                with self.connection() as db:
                    row = db.execute('SELECT state FROM deliveries WHERE server_id=? AND process_key=? AND group_id=?',
                                     (instance.profile['id'], identity, settings['group_id'])).fetchone()
                key = (instance.profile['id'], identity, settings['group_id'])
                preview['delivery'] = 'uncertain' if key in self.uncertain else row['state'] if row else None
            except (ManagerError, OSError, sqlite3.Error):
                preview = {'server_id': instance.profile['id'], 'label': instance.profile['label'],
                           'readiness': {'state': 'unknown', 'label': 'Nicht ermittelbar'}, 'message': '',
                           'error': 'Serverstatus derzeit nicht ermittelbar.', 'delivery': None}
            previews.append(preview)
        return {'state': state, 'error': error, 'settings': settings, 'previews': previews}

    def configure(self, body):
        if (set(body) != {'group_id', 'enabled', 'server_ids', 'revision'} or type(body['enabled']) is not bool
                or not isinstance(body['group_id'], str) or not isinstance(body['server_ids'], list)
                or not all(isinstance(sid, str) for sid in body['server_ids'])
                or len(set(body['server_ids'])) != len(body['server_ids']) or type(body['revision']) is not int):
            raise ManagerError('Bitte Zielgruppe und Serverprofile ausdrücklich auswählen.')
        known = {instance.profile['id'] for instance in self.registry.instances()}
        if set(body['server_ids']) - known or (body['enabled'] and not body['server_ids']):
            raise ManagerError('Bitte vorhandene Serverprofile für automatische Meldungen auswählen.')
        with self.lock:
            previous = self.settings()
            if body['revision'] != previous['revision']:
                raise ManagerError('Die WhatsApp-Einstellungen wurden inzwischen geändert. Bitte neu laden.')
            label = previous['group_label']
            if body['enabled'] or body['group_id'] != previous['group_id']:
                groups = {group['id']: group['label'] for group in self.client.groups()}
                if body['group_id'] not in groups:
                    raise ManagerError('Bitte eine aktuell verfügbare WhatsApp-Gruppe auswählen.')
                label = groups[body['group_id']]
            updated = {**body, 'group_label': label, 'revision': previous['revision'] + 1}
            atomic_json(self.path, updated)
        self.wake.set()
        return {'message': 'WhatsApp-Einstellungen gespeichert.', 'revision': updated['revision']}

    def disconnect(self):
        with self.lock:
            previous = self.settings()
            atomic_json(self.path, {'group_id': '', 'group_label': '', 'enabled': False,
                                   'server_ids': [], 'revision': previous['revision'] + 1})
            # Disable before logout, even when logout itself fails.
            self.client.logout()
        return {'message': 'WhatsApp-Verknüpfung getrennt; automatische Meldungen deaktiviert.'}

    def connect(self):
        with self.lock:
            previous = self.settings()
            atomic_json(self.path, {'group_id': '', 'group_label': '', 'enabled': False,
                                   'server_ids': [], 'revision': previous['revision'] + 1})
            self.client.connect()
        return {'message': 'Geräteverknüpfung vorbereitet. QR-Code mit WhatsApp auf dem Handy scannen.'}

    def send_current(self, instance, automatic=False, revision=None):
        revision = self.settings()['revision'] if revision is None else revision
        snapshot = self.snapshot(instance)
        evidence = snapshot['readiness']
        if evidence['state'] != 'ready' or not snapshot['message']:
            raise ManagerError('Ein Beitritt ist noch nicht lokal bestätigt. Es wurde nichts gesendet.')
        identity = evidence['process_key']
        with self.lock:
            settings = self.settings()
            if (not settings['group_id'] or instance.profile['id'] not in settings['server_ids']
                    or settings['revision'] != revision or (automatic and not settings['enabled'])
                    or self.stop.is_set()):
                raise ManagerError('WhatsApp-Ziel oder Freigabe wurde geändert. Es wurde nichts gesendet.')
            if self.client.session()['status'] != 'WORKING':
                raise ManagerError('WhatsApp ist derzeit nicht verbunden. Es wurde nichts gesendet.')
            # Recheck the native process after the log/network reads. Never send
            # a previous world's readiness after a concurrent stop/restart.
            current = process_identity(instance.status())
            if self.stop.is_set() or not current or current[0] != identity:
                raise ManagerError('Der Serverprozess hat sich geändert. Es wurde nichts gesendet.')
            with self.connection() as db:
                existing = db.execute('SELECT state FROM deliveries WHERE server_id=? AND process_key=? AND group_id=?',
                                      (instance.profile['id'], identity, settings['group_id'])).fetchone()
                if existing:
                    if existing['state'] == 'sent':
                        return {'message': 'Für diesen Serverstart wurde die WhatsApp-Meldung bereits gesendet.'}
                    raise ManagerError('Der vorherige Versand ist unklar. Bitte in WhatsApp prüfen; keine automatische Wiederholung.')
                db.execute('INSERT INTO deliveries VALUES (?,?,?,?,?)',
                           (instance.profile['id'], identity, settings['group_id'], 'sending', datetime.now(timezone.utc).isoformat()))
            state = 'uncertain'
            delivery_key = (instance.profile['id'], identity, settings['group_id'])
            try:
                self.client.send(settings['group_id'], snapshot['message'])
                state = 'sent'
            finally:
                # A crash or storage error leaves a durable sending/uncertain
                # claim. Subsequent checks never repeat its external side effect.
                try:
                    with self.connection() as db:
                        db.execute('UPDATE deliveries SET state=?,updated_at=? WHERE server_id=? AND process_key=? AND group_id=?',
                                   (state, datetime.now(timezone.utc).isoformat(), *delivery_key))
                except sqlite3.Error:
                    self.uncertain.add(delivery_key)
                    raise
        return {'message': 'Kurze Bereitschaftsmeldung an die ausgewählte WhatsApp-Gruppe gesendet.'}

    def tick(self):
        if not self.poll_lock.acquire(blocking=False):
            return
        try:
            settings = self.settings()
            if not settings['enabled'] or not self.client.configured or self.stop.is_set():
                return
            for instance in self.registry.instances():
                if instance.profile['id'] not in settings['server_ids'] or self.stop.is_set():
                    continue
                try:
                    self.send_current(instance, automatic=True, revision=settings['revision'])
                except (ManagerError, OSError, sqlite3.Error):
                    # Readiness and delivery are visible in view(); failures do
                    # not fail a game operation, flood logs or trigger retries.
                    pass
        finally:
            self.poll_lock.release()

    def start(self):
        if self.thread:
            return
        def run():
            while not self.stop.is_set():
                try:
                    self.tick()
                except (ManagerError, OSError, sqlite3.Error):
                    pass
                self.wake.wait(15)
                self.wake.clear()
        self.thread = threading.Thread(target=run, name='whatsapp-ready-notifications', daemon=True)
        self.thread.start()

    def close(self):
        self.stop.set()
        self.wake.set()
        if self.thread:
            self.thread.join(timeout=15)
