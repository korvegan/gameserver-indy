from __future__ import annotations

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import hashlib
import hmac
import json
import re
import secrets
import sqlite3
import threading
from urllib.parse import parse_qs, urlsplit

from .api import Api, NotFound, one
from .application import Application
from .configuration import ManagerError
from .paths import ROOT
from .registry import ServerRegistry
from .security import AccessDenied, local_owner
from .sessions import signing_token
from .whatsapp import local_whatsapp_owner

STATIC = ROOT / "web"


class LocalServer(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, manager, port=8765, identity=None, application=None):
        self.registry = manager if isinstance(manager, ServerRegistry) else ServerRegistry(manager.root, manager)
        self.token = signing_token(self.registry.root, 'family:' + self.hostname if hasattr(self, 'hostname') else 'local-owner')
        self.identity = identity or local_owner
        self.scheme = 'http'
        self.owns_application = application is None
        super().__init__(("127.0.0.1", port), Handler)
        try:
            self.app = application or Application(self.registry)
            self.api = Api(self.app)
        except BaseException:
            super().server_close()
            raise

    def server_close(self):
        if hasattr(self, "app") and self.owns_application:
            self.app.close()
        super().server_close()

    def csrf_token(self, principal):
        if not principal.auth_revision:
            return self.token
        message = f'{principal.id}:{principal.auth_revision}'.encode()
        return hmac.new(self.token.encode(), message, hashlib.sha256).hexdigest()

    def trusted_host(self, headers):
        return len(headers.get_all('Host', [])) == 1 and headers['Host'] in (
            f'127.0.0.1:{self.server_port}', f'localhost:{self.server_port}')


class FamilyServer(LocalServer):
    """Dedicated loopback listener for Serve; never falls back to local-owner."""
    def __init__(self, application, hostname, port=8767):
        self.hostname = hostname
        super().__init__(application.registry, port, identity=self.identify, application=application)
        self.scheme = 'https'

    def trusted_host(self, headers):
        return len(headers.get_all('Host', [])) == 1 and headers['Host'] in (self.hostname, self.hostname + ':443')

    def identify(self, request):
        current = self.app.access.configuration()
        if not current['enabled'] or current['hostname'] != self.hostname or current['port'] != self.server_port:
            raise AccessDenied('Der Familienzugang ist gesperrt oder benötigt einen Neustart der Verwaltung.')
        if request.client_address[0] != '127.0.0.1' or len(request.headers.get_all('Tailscale-User-Login', [])) != 1:
            raise AccessDenied('Zugriff nur über den privaten Tailscale-HTTPS-Zugang.')
        return self.app.access.principal(request.headers['Tailscale-User-Login'])


class Handler(BaseHTTPRequestHandler):
    server_version = "GameServerIndy/0.3"

    def setup(self):
        super().setup()
        self.connection.settimeout(15)

    def log_message(self, fmt, *args):
        # Never log request bodies, group passwords or Discord webhook URLs.
        pass

    def send(self, status, value, content_type="application/json; charset=utf-8", download=None):
        if getattr(self, 'audit_request', False):
            self.audit_request = False
            if self.command == 'POST' or status >= 400 or urlsplit(self.path).path == '/api/session':
                self.server.app.access.audit(getattr(self, 'actor_id', 'unrecognized'),
                    self.audit_operation, getattr(self, 'audit_resource', ''), str(status))
        data = json.dumps(value, ensure_ascii=False).encode() if content_type.startswith("application/json") else value
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        if download:
            self.send_header('Content-Disposition', 'attachment; filename="' + download + '"')
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Security-Policy", "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'")
        self.end_headers()
        self.wfile.write(data)

    def trusted_host(self):
        return self.server.trusted_host(self.headers)

    def audit_context(self, path):
        self.audit_request = path.startswith('/api/')
        self.audit_operation = path if path in ('/api/session', '/api/access', '/api/access/settings', '/api/access/users',
            '/api/servers', '/api/jobs', '/api/status', '/api/state', '/api/config', '/api/start', '/api/stop',
            '/api/restart', '/api/setup', '/api/logs', '/api/events', '/api/discord/settings', '/api/discord/send',
            '/api/community', '/api/community/options', '/api/community/event', '/api/community/story',
            '/api/community/media', '/api/community/export', '/api/connections/refresh', '/api/connections/settings', '/api/updates/check', '/api/backups') else 'request.unknown'
        if path in ('/api/whatsapp', '/api/whatsapp/qr', '/api/whatsapp/groups', '/api/whatsapp/connect',
                    '/api/whatsapp/disconnect', '/api/whatsapp/settings', '/api/whatsapp/send'):
            self.audit_operation = path

    def do_GET(self):
        if not self.trusted_host():
            return self.send(403, {"error": "Dieser Zugriff ist nur über localhost erlaubt."})
        path = urlsplit(self.path).path
        query = parse_qs(urlsplit(self.path).query)
        self.audit_context(path)
        try:
            principal = self.server.identity(self)
            self.actor_id = principal.id
            if path == "/api/session":
                return self.send(200, {"token": self.server.csrf_token(principal), "principal": principal.public(),
                    "access_mode": 'private' if self.server.scheme == 'https' else 'local', "api_version": 1})
            if path == '/api/community/media':
                content = self.server.app.chronicle.media(principal, one(query, 'event'), one(query, 'revision'))
                return self.send(200, content, 'image/jpeg')
            if path == '/api/whatsapp/qr':
                local_whatsapp_owner(principal)
                return self.send(200, self.server.app.whatsapp.client.qr(), 'image/png')
            if path == '/api/community/export':
                content = self.server.app.chronicle.export(principal, **{key: one(query, key) for key in query})
                return self.send(200, content, 'application/zip', download='sturmklingen-chronicle.zip')
            if path.startswith("/api/"):
                return self.send(200, self.server.api.get(path, query, principal))
            files = {
                "/": ("index.html", "text/html; charset=utf-8"),
                "/app.js": ("app.js", "text/javascript; charset=utf-8"),
                "/style.css": ("style.css", "text/css; charset=utf-8"),
                "/favicon.svg": ("favicon.svg", "image/svg+xml"),
            }
            for name in ("api.js", "state.js", "dom.js", "forms.js", "navigation.js", "jobs.js", "job-stream.js",
                         "views/server.js", "views/logs.js", "views/catalog.js", "views/community.js", "views/updates.js", "views/connection.js", "views/chronicle-form.js", "views/story.js", "views/story-art.js", "views/access.js", "views/backups.js", "views/whatsapp.js"):
                files["/" + name] = (name, "text/javascript; charset=utf-8")
            if path in files:
                filename, content_type = files[path]
                return self.send(200, (STATIC / filename).read_bytes(), content_type)
            return self.send(404, {"error": "Nicht gefunden."})
        except (AccessDenied, NotFound, ManagerError) as exc:
            status = 403 if isinstance(exc, AccessDenied) else 404 if isinstance(exc, NotFound) else 409
            return self.send(status, {"error": str(exc), "code": {403: "forbidden", 404: "not_found", 409: "conflict"}[status]})
        except (OSError, sqlite3.Error):
            return self.send(500, {"error": "Eine lokale Datei ist nicht zugänglich. Pfad und Berechtigungen prüfen.", "code": "local_storage"})

    def do_POST(self):
        if not self.trusted_host():
            return self.send(403, {"error": "Unzulässiger Host."})
        path = urlsplit(self.path).path
        self.audit_context(path)
        try:
            principal = self.server.identity(self)
            self.actor_id = principal.id
        except AccessDenied as exc:
            return self.send(403, {'error': str(exc), 'code': 'forbidden'})
        except (OSError, sqlite3.Error, ManagerError):
            return self.send(503, {'error': 'Die Zugriffsprüfung ist derzeit nicht verfügbar.'})
        origin = self.headers.get("Origin")
        if len(self.headers.get_all('Origin', [])) > 1 or (origin and origin != self.server.scheme + '://' + self.headers['Host']):
            return self.send(403, {"error": "Unzulässiger Ursprung."})
        token = self.headers.get("X-Manager-Token", "")
        if len(self.headers.get_all('X-Manager-Token', [])) != 1 or not secrets.compare_digest(token.encode(), self.server.csrf_token(principal).encode()):
            return self.send(403, {"error": "Sitzung muss aktualisiert werden. Die Aktion wurde nicht ausgeführt.", "code": "session_expired"})
        if self.headers.get_content_type() != "application/json":
            return self.send(415, {"error": "JSON erforderlich."})
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if not 0 < length <= 524288:
                return self.send(413, {"error": "Ungültige Anfragegröße."})
            body = json.loads(self.rfile.read(length))
            if not isinstance(body, dict):
                raise ValueError()
        except (ValueError, UnicodeError):
            return self.send(400, {"error": "Ungültiges JSON."})
        try:
            resource = body.get('server_id')
            if isinstance(resource, str) and re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,47}', resource):
                self.audit_resource = resource
            if path == '/api/jobs' and body.get('operation') in ('start', 'stop', 'restart', 'setup', 'config'):
                self.audit_operation = 'server.' + body['operation']
            if path == '/api/jobs' and body.get('operation') in ('backup', 'backup_verify', 'backup_pin', 'backup_settings', 'backup_restore', 'backup_recover'):
                self.audit_operation = body['operation']
            self.server.app.access.audit(principal.id, self.audit_operation, getattr(self, 'audit_resource', ''), 'requested')
            self.server.app.access.validate(principal)
            status, result = self.server.api.post(path, body, principal)
            return self.send(status, result)
        except (AccessDenied, NotFound, ManagerError) as exc:
            status = 403 if isinstance(exc, AccessDenied) else 404 if isinstance(exc, NotFound) else 409
            return self.send(status, {"error": str(exc), "code": {403: "forbidden", 404: "not_found", 409: "conflict"}[status]})
        except (OSError, ValueError, sqlite3.Error):
            return self.send(500, {"error": "Datei konnte nicht gespeichert werden. Pfad und Berechtigungen prüfen.", "code": "local_storage"})


def serve(manager, port=8765):
    with LocalServer(manager, port) as server:
        family = None
        try:
            config = server.app.access.configuration()
            if config['enabled']:
                if config['port'] == port:
                    raise ManagerError('Lokaler Port und Familienport müssen verschieden sein.')
                family = FamilyServer(server.app, config['hostname'], config['port'])
                threading.Thread(target=family.serve_forever, name='family-https-backend', daemon=True).start()
                server.app.access.runtime = {'active': True, 'hostname': config['hostname'], 'port': config['port']}
        except (ManagerError, OSError):
            server.app.access.runtime = {'active': False, 'error': 'Familienzugang konnte nicht gestartet werden. Einstellungen und Port prüfen.'}
        server.app.start_update_checks()
        server.app.connections.start()
        server.app.whatsapp.start()
        print(f"GameServer Indy: http://127.0.0.1:{server.server_port}", flush=True)
        print("Verwaltung mit Strg+C beenden. Ein laufender Spielserver bleibt dabei aktiv.", flush=True)
        try:
            server.serve_forever()
        except KeyboardInterrupt:
            pass
        finally:
            if family:
                family.shutdown()
                family.server_close()
