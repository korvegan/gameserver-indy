"""Small, local WAHA transport. No chat history, public URLs or arbitrary API proxy."""
import json
import re
import urllib.error
import urllib.request

from .configuration import ManagerError, read_json

GROUP_ID = re.compile(r'[0-9]{5,32}(?:-[0-9]{1,20})?@g\.us')


class WhatsAppError(ManagerError):
    def __init__(self, status=None):
        self.status = status
        super().__init__('Der lokale WhatsApp-Dienst antwortet nicht wie erwartet. Verbindung im Dashboard prüfen.')


class LocalWhatsAppClient:
    def __init__(self, root):
        self.credentials = root / 'data' / 'whatsapp' / 'credentials.json'
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    @property
    def configured(self):
        return self.credentials.is_file()

    def request(self, path, body=None, method=None, image=False):
        if not self.configured:
            raise ManagerError('Der lokale WhatsApp-Dienst ist noch nicht eingerichtet.')
        key = read_json(self.credentials).get('api_key', '')
        if not isinstance(key, str) or not re.fullmatch(r'[a-f0-9]{64}', key):
            raise ManagerError('Der lokale WhatsApp-Zugang ist nicht lesbar.')
        headers = {'X-Api-Key': key, 'Accept': 'image/png' if image else 'application/json'}
        data = None if body is None else json.dumps(body).encode()
        if data is not None:
            headers['Content-Type'] = 'application/json'
        request = urllib.request.Request('http://127.0.0.1:18768' + path, data=data, headers=headers, method=method)
        try:
            with self.opener.open(request, timeout=12) as response:
                content = response.read(2 * 1024 * 1024 + 1)
                if len(content) > 2 * 1024 * 1024:
                    raise WhatsAppError()
                if image:
                    if not content.startswith(b'\x89PNG\r\n\x1a\n'):
                        raise WhatsAppError()
                    return content
                return json.loads(content)
        except urllib.error.HTTPError as error:
            status = error.code
            error.close()
            raise WhatsAppError(status) from None
        except (OSError, ValueError, urllib.error.URLError):
            raise WhatsAppError() from None

    def session(self):
        try:
            result = self.request('/api/sessions/default')
        except WhatsAppError as error:
            if error.status == 404:
                return {'status': 'STOPPED', 'exists': False}
            raise
        state = result.get('status') if isinstance(result, dict) else None
        if state not in ('STOPPED', 'STARTING', 'SCAN_QR_CODE', 'WORKING', 'FAILED',
                         'PASSKEY_REQUIRED', 'PASSKEY_CONFIRMATION_REQUIRED'):
            raise WhatsAppError()
        return {'status': state, 'exists': True}

    def connect(self):
        session = self.session()
        if not session['exists']:
            self.request('/api/sessions', {'name': 'default', 'start': True, 'config': {
                'debug': False, 'webhooks': [],
                'client': {'deviceName': 'GameServer Indy', 'browserName': 'Chrome'},
                'ignore': {'status': True, 'channels': True, 'broadcast': True},
                'gows': {'storage': {'messages': False, 'chats': False, 'contacts': False,
                                     'labels': False, 'messageSecrets': False, 'groups': True}},
            }})
        elif session['status'] in ('STOPPED', 'FAILED'):
            action = 'restart' if session['status'] == 'FAILED' else 'start'
            self.request('/api/sessions/default/' + action, {}, 'POST')

    def qr(self):
        if self.session()['status'] != 'SCAN_QR_CODE':
            raise ManagerError('WhatsApp wartet derzeit nicht auf einen QR-Code. Status aktualisieren.')
        return self.request('/api/default/auth/qr', image=True)

    def groups(self):
        if self.session()['status'] != 'WORKING':
            raise ManagerError('Bitte WhatsApp zuerst per QR-Code verknüpfen.')
        result = []
        for offset in range(0, 1000, 100):
            page = self.request(f'/api/default/groups?exclude=participants&limit=100&offset={offset}')
            if not isinstance(page, list):
                raise WhatsAppError()
            for group in page:
                identifier = group.get('id') or group.get('JID')
                if isinstance(identifier, dict):
                    identifier = identifier.get('_serialized')
                name = group.get('subject') or group.get('name') or group.get('Name')
                if isinstance(identifier, str) and GROUP_ID.fullmatch(identifier) and isinstance(name, str):
                    result.append({'id': identifier, 'label': ' '.join(name.split())[:120]})
            if len(page) < 100:
                return sorted({g['id']: g for g in result}.values(), key=lambda g: (g['label'].casefold(), g['id']))
        raise ManagerError('Zu viele WhatsApp-Gruppen für die begrenzte Auswahl.')

    def send(self, group_id, text):
        if not isinstance(group_id, str) or not GROUP_ID.fullmatch(group_id):
            raise ManagerError('Bitte eine WhatsApp-Gruppe auswählen.')
        result = self.request('/api/sendText', {'session': 'default', 'chatId': group_id,
                                              'text': text, 'linkPreview': False})
        if not isinstance(result, dict) or not result.get('id'):
            raise WhatsAppError()

    def logout(self):
        self.request('/api/sessions/default/logout', {}, 'POST')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None
