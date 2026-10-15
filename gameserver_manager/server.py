from __future__ import annotations

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import secrets
import sqlite3
from urllib.parse import parse_qs, urlsplit

from .api import Api, NotFound, one
from .application import Application
from .configuration import ManagerError
from .paths import ROOT
from .registry import ServerRegistry
from .security import AccessDenied, local_owner

STATIC = ROOT / "web"


class LocalServer(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, manager, port=8765, identity=None):
        self.registry = manager if isinstance(manager, ServerRegistry) else ServerRegistry(manager.root, manager)
        self.token = secrets.token_urlsafe(32)
        self.identity = identity or local_owner
        super().__init__(("127.0.0.1", port), Handler)
        try:
            self.app = Application(self.registry)
            self.api = Api(self.app)
        except BaseException:
            super().server_close()
            raise

    def server_close(self):
        if hasattr(self, "app"):
            self.app.close()
        super().server_close()


class Handler(BaseHTTPRequestHandler):
    server_version = "GameServerIndy/0.3"

    def setup(self):
        super().setup()
        self.connection.settimeout(15)

    def log_message(self, fmt, *args):
        # Never log request bodies, group passwords or Discord webhook URLs.
        pass

    def send(self, status, value, content_type="application/json; charset=utf-8", download=None):
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
        port = self.server.server_port
        return self.headers.get("Host") in (f"127.0.0.1:{port}", f"localhost:{port}")

    def do_GET(self):
        if not self.trusted_host():
            return self.send(403, {"error": "Dieser Zugriff ist nur über localhost erlaubt."})
        path = urlsplit(self.path).path
        query = parse_qs(urlsplit(self.path).query)
        try:
            if path == "/api/session":
                return self.send(200, {"token": self.server.token, "principal": self.server.identity(self).public(), "api_version": 1})
            if path == '/api/community/media':
                content = self.server.app.chronicle.media(self.server.identity(self), one(query, 'event'), one(query, 'revision'))
                return self.send(200, content, 'image/jpeg')
            if path == '/api/community/export':
                content = self.server.app.chronicle.export(self.server.identity(self), **{key: one(query, key) for key in query})
                return self.send(200, content, 'application/zip', download='sturmklingen-chronicle.zip')
            if path.startswith("/api/"):
                return self.send(200, self.server.api.get(path, parse_qs(urlsplit(self.path).query), self.server.identity(self)))
            files = {
                "/": ("index.html", "text/html; charset=utf-8"),
                "/app.js": ("app.js", "text/javascript; charset=utf-8"),
                "/style.css": ("style.css", "text/css; charset=utf-8"),
                "/favicon.svg": ("favicon.svg", "image/svg+xml"),
            }
            for name in ("api.js", "state.js", "dom.js", "forms.js", "navigation.js", "jobs.js",
                         "views/server.js", "views/logs.js", "views/catalog.js", "views/community.js", "views/updates.js", "views/connection.js", "views/chronicle-form.js", "views/story.js", "views/story-art.js"):
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
        origin = self.headers.get("Origin")
        if origin and origin != "http://" + self.headers["Host"]:
            return self.send(403, {"error": "Unzulässiger Ursprung."})
        token = self.headers.get("X-Manager-Token", "")
        if not secrets.compare_digest(token, self.server.token):
            return self.send(403, {"error": "Sitzung abgelaufen. Bitte die Seite neu laden."})
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
        path = urlsplit(self.path).path
        try:
            status, result = self.server.api.post(path, body, self.server.identity(self))
            return self.send(status, result)
        except (AccessDenied, NotFound, ManagerError) as exc:
            status = 403 if isinstance(exc, AccessDenied) else 404 if isinstance(exc, NotFound) else 409
            return self.send(status, {"error": str(exc), "code": {403: "forbidden", 404: "not_found", 409: "conflict"}[status]})
        except (OSError, ValueError, sqlite3.Error):
            return self.send(500, {"error": "Datei konnte nicht gespeichert werden. Pfad und Berechtigungen prüfen.", "code": "local_storage"})


def serve(manager, port=8765):
    with LocalServer(manager, port) as server:
        server.app.start_update_checks()
        server.app.connections.start()
        print(f"GameServer Indy: http://127.0.0.1:{server.server_port}", flush=True)
        print("Verwaltung mit Strg+C beenden. Ein laufender Spielserver bleibt dabei aktiv.", flush=True)
        try:
            server.serve_forever()
        except KeyboardInterrupt:
            pass
