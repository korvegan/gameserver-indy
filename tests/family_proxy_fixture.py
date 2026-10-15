"""Serve-shaped proxy for browser QA only; it is not a production identity adapter."""
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class FamilyProxy(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, upstream, port=8768):
        self.upstream = upstream
        super().__init__(('127.0.0.1', port), ProxyHandler)


class ProxyHandler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def forward(self):
        upstream = self.server.upstream
        headers = {key.lower(): value for key, value in self.headers.items() if not key.lower().startswith(('tailscale-', 'x-forwarded-', 'x-qa-')) and key.lower() not in ('host', 'connection')}
        headers['host'] = upstream.hostname
        headers['tailscale-user-login'] = self.headers.get('X-QA-Login', 'qa-family@example.test')
        if headers.get('origin') == f'http://127.0.0.1:{self.server.server_port}':
            headers['origin'] = 'https://' + upstream.hostname
        length = int(self.headers.get('Content-Length', '0'))
        body = self.rfile.read(length) if length else None
        connection = HTTPConnection('127.0.0.1', upstream.server_port, timeout=20)
        try:
            connection.request(self.command, self.path, body, headers)
            response = connection.getresponse()
            content = response.read()
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ('server', 'date', 'connection'):
                    self.send_header(key, value)
            self.end_headers()
            self.wfile.write(content)
        finally:
            connection.close()

    do_GET = forward
    do_POST = forward
