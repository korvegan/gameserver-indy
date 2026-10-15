from __future__ import annotations

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import secrets
from urllib.parse import urlsplit

from .core import Manager, ManagerError, ROOT

STATIC = ROOT / "web"


class LocalServer(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, manager: Manager, port=8765):
        self.manager = manager
        self.token = secrets.token_urlsafe(32)
        super().__init__(("127.0.0.1", port), Handler)


class Handler(BaseHTTPRequestHandler):
    server_version = "EnshroudedIndy/0.1"

    def setup(self):
        super().setup()
        self.connection.settimeout(15)

    def log_message(self, fmt, *args):
        # Never log request bodies, group passwords or Discord webhook URLs.
        pass

    def send(self, status, value, content_type="application/json; charset=utf-8"):
        data = json.dumps(value, ensure_ascii=False).encode() if content_type.startswith("application/json") else value
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Security-Policy", "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'")
        self.end_headers()
        self.wfile.write(data)

    def trusted_host(self):
        port = self.server.server_port
        return self.headers.get("Host") in (f"127.0.0.1:{port}", f"localhost:{port}")

    def do_GET(self):
        if not self.trusted_host():
            return self.send(403, {"error": "Dieser Zugriff ist nur über localhost erlaubt."})
        path = urlsplit(self.path).path
        try:
            if path == "/api/session":
                return self.send(200, {"token": self.server.token})
            manager = self.server.manager
            if path == "/api/state":
                return self.send(200, {"status": manager.status(), "config": manager.config(), "events": manager.events()})
            if path == "/api/logs":
                return self.send(200, manager.logs())
            files = {
                "/": ("index.html", "text/html; charset=utf-8"),
                "/app.js": ("app.js", "text/javascript; charset=utf-8"),
                "/style.css": ("style.css", "text/css; charset=utf-8"),
                "/favicon.svg": ("favicon.svg", "image/svg+xml"),
            }
            if path in files:
                filename, content_type = files[path]
                return self.send(200, (STATIC / filename).read_bytes(), content_type)
            return self.send(404, {"error": "Nicht gefunden."})
        except ManagerError as exc:
            return self.send(409, {"error": str(exc)})
        except OSError:
            return self.send(500, {"error": "Eine lokale Datei ist nicht zugänglich. Pfad und Berechtigungen prüfen."})

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
        manager = self.server.manager
        path = urlsplit(self.path).path
        try:
            if path == "/api/setup":
                result = manager.setup()
            elif path in ("/api/start", "/api/stop", "/api/restart"):
                result = manager.action(path.rsplit("/", 1)[1])
            elif path == "/api/config":
                result = manager.save_config(body.get("config"))
            elif path == "/api/discord/settings":
                result = manager.save_discord(body)
            elif path == "/api/discord/send":
                result = manager.notify(body.get("message"))
            else:
                return self.send(404, {"error": "Nicht gefunden."})
            return self.send(200, result)
        except ManagerError as exc:
            return self.send(409, {"error": str(exc)})
        except (OSError, ValueError):
            return self.send(500, {"error": "Datei konnte nicht gespeichert werden. Pfad und Berechtigungen prüfen."})


def serve(manager: Manager, port=8765):
    with LocalServer(manager, port) as server:
        print(f"Enshrouded Indy: http://127.0.0.1:{server.server_port}", flush=True)
        print("Verwaltung mit Strg+C beenden. Ein laufender Spielserver bleibt dabei aktiv.", flush=True)
        try:
            server.serve_forever()
        except KeyboardInterrupt:
            pass
