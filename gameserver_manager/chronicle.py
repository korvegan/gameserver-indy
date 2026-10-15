"""Manual chronicle contributions, scoped reading, revision history and archival export."""
import base64
from datetime import datetime, timezone, timedelta
import hashlib
import html
import io
import json
import struct
import unicodedata
import zipfile

from .catalog import identifier
from .configuration import ManagerError
from .security import AccessDenied
from .storage import chronicle_time
from .story import scene_view, SCENES_PER_CHAPTER

MAX_IMAGE = 256 * 1024
MAX_EXPORT = 32 * 1024 * 1024
LATEST = 'NOT EXISTS (SELECT 1 FROM community_events n WHERE n.id=e.id AND n.revision>e.revision)'
ACTIVE = "COALESCE(json_extract(e.payload,'$.removed'),0)=0"
MOMENT_TIME = "chronicle_time(json_extract(e.payload,'$.occurred_at'),e.recorded_at)"
SELECT = '''SELECT e.payload,e.recorded_at,s.id AS scene_id,s.position,s.template,
            (SELECT first.game_id FROM community_events first WHERE first.id=e.id ORDER BY first.revision LIMIT 1) AS original_game
            FROM community_events e JOIN scenes s ON s.event_id=e.id'''


def text(value, maximum, required=False):
    if not isinstance(value, str) or len(value) > maximum or any(ord(c) < 32 and c not in '\n\t' for c in value):
        raise ManagerError('Ein Chroniktext ist ungültig oder zu lang.')
    value = value.strip()
    if required and not value:
        raise ManagerError('Bitte alle Pflichtfelder des Moments ausfüllen.')
    return value


def name_id(prefix, value):
    normalized = unicodedata.normalize('NFKC', value).casefold()
    return prefix + hashlib.sha256(normalized.encode()).hexdigest()[:24]


def event_view(row):
    event = json.loads(row['payload'])
    return {**event, 'recorded_at': row['recorded_at'],
            'scene': scene_view(row),
            'awards': [{'member_id': member, 'badge_id': event['badge_id']} for member in event['member_ids']]}


def jpeg(data):
    """Bounded JPEG header validation. Browser creates a resized JPEG preview before upload."""
    if not 4 <= len(data) <= MAX_IMAGE or data[:2] != b'\xff\xd8' or data[-2:] != b'\xff\xd9':
        raise ManagerError('Das Vorschaubild muss ein JPEG mit höchstens 256 KiB sein.')
    offset = 2
    while offset + 4 <= len(data):
        if data[offset] != 255:
            break
        marker = data[offset + 1]
        size = struct.unpack('>H', data[offset + 2:offset + 4])[0]
        if size < 2 or offset + 2 + size > len(data):
            break
        if marker in (0xc0, 0xc1, 0xc2):
            if size < 8:
                break
            height, width = struct.unpack('>HH', data[offset + 5:offset + 9])
            if 1 <= width <= 1280 and 1 <= height <= 1280:
                return data, width, height
            break
        offset += 2 + size
    raise ManagerError('Das JPEG-Vorschaubild hat ungültige Abmessungen (maximal 1280 × 1280).')


def archive_html(events):
    latest = {}
    for event in events:
        latest[event['id']] = event
    cards = []
    for event in sorted(latest.values(), key=lambda item: (chronicle_time(item.get('occurred_at'), item['recorded_at']), item['scene']['position']), reverse=True):
        esc = lambda value: html.escape(str(value))
        members = ', '.join(event.get('member_names', {}).get(member, member) for member in event['member_ids'])
        picture = '<img alt="Screenshot" src="media/' + event['media_id'] + '.jpg">' if event.get('media_id') else ''
        cards.append(f'<article id="{esc(event["id"])}"><h2>{esc(event["title"])}{" · entfernt" if event.get("removed") else ""}</h2>'
                     f'<p>{esc(event["game_id"])} · {esc(event.get("campaign_label") or "")} · {esc(event.get("occurred_at") or event["recorded_at"])}</p>'
                     f'<p>{esc(event.get("description") or "")}</p><p>✦ {esc(event.get("badge_label") or event["badge_id"])} · {esc(members)}</p>{picture}</article>')
    return ('<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>Chronik der Sturmklingen</title><style>body{max-width:900px;margin:40px auto;padding:20px;background:#101813;color:#e1e8dc;font:16px/1.6 system-ui}'
            'article{padding:24px;margin:20px 0;border:1px solid #49543e;border-radius:8px;overflow-wrap:anywhere}img{max-width:100%;max-height:600px}p{white-space:pre-wrap}</style>'
            '<h1>Chronik der Sturmklingen</h1><p>Privates Archiv. Sämtliche Versionen und Quellen stehen in chronicle.json. Bilder liegen unter media/.</p>'
            + ''.join(cards) + '</html>').encode()


class ChronicleService:
    def __init__(self, community):
        self.community = community
        self.database = community.database

    def scope(self, principal):
        principal.require('community.read')
        if principal.server_ids is None:
            return '1', []
        ids = sorted(principal.server_ids)
        return ('(e.instance_id IS NULL' + (' OR e.instance_id IN (' + ','.join('?' for _ in ids) + ')' if ids else '') + ')', ids)

    def story(self, principal, **query):
        chapter = query.pop('chapter', None)
        if set(query) & {'cursor', 'limit'}:
            raise ManagerError('Das Geschichtsbild verwendet Kapitel statt Chronikseiten.')
        where, parameters = self.filters(principal, query)
        if chapter is not None and (not isinstance(chapter, str) or not chapter.isascii() or not chapter.isdecimal() or len(chapter) > 9):
            raise ManagerError('Ungültiges Kapitel.')
        with self.database.connect() as db:
            db.execute('BEGIN')
            chapters = [dict(row) for row in db.execute(
                'SELECT CAST(s.position / ? AS INTEGER) AS id,COUNT(*) AS count FROM community_events e '
                'JOIN scenes s ON s.event_id=e.id WHERE ' + where + ' GROUP BY 1 ORDER BY 1',
                [SCENES_PER_CHAPTER, *parameters])]
            selected = int(chapter) if chapter is not None else (chapters[-1]['id'] if chapters else 0)
            rows = db.execute(SELECT + ' WHERE ' + where + ' AND s.position>=? AND s.position<? ORDER BY s.position',
                              [*parameters, selected * SCENES_PER_CHAPTER, (selected + 1) * SCENES_PER_CHAPTER]).fetchall()
        return {'chapter': selected, 'chapters': chapters, 'events': [event_view(row) for row in rows],
                'layout': 'landscape-v1', 'slots': SCENES_PER_CHAPTER}

    def filters(self, principal, query, active=True):
        scope, parameters = self.scope(principal)
        clauses = [LATEST, scope] + ([ACTIVE] if active else [])
        if set(query) - {'game', 'campaign', 'member', 'from', 'to', 'cursor', 'limit'}:
            raise ManagerError('Unbekannter Chronikfilter.')
        for key, sql in [('game', 'e.game_id'), ('campaign', "json_extract(e.payload,'$.campaign_id')")]:
            if value := query.get(key):
                if not identifier(value):
                    raise ManagerError('Ungültiger Chronikfilter.')
                clauses.append(sql + '=?')
                parameters.append(value)
        if member := query.get('member'):
            if not identifier(member):
                raise ManagerError('Ungültiger Mitgliederfilter.')
            clauses.append("EXISTS (SELECT 1 FROM json_each(e.payload,'$.member_ids') WHERE value=?)")
            parameters.append(member)
        for key, operator in [('from', '>='), ('to', '<')]:
            if value := query.get(key):
                try:
                    date = datetime.strptime(value, '%Y-%m-%d').replace(tzinfo=timezone.utc)
                    if key == 'to':
                        date += timedelta(days=1)
                except (ValueError, TypeError, OverflowError):
                    raise ManagerError('Zeitraum bitte als gültiges Datum angeben.') from None
                clauses.append(MOMENT_TIME + operator + '?')
                parameters.append(date.isoformat(timespec='microseconds'))
        return ' AND '.join(clauses), parameters

    def page(self, principal, **query):
        where, parameters = self.filters(principal, query)
        try:
            limit = int(query.get('limit') or 20)
            if not 1 <= limit <= 50:
                raise ValueError()
        except (TypeError, ValueError):
            raise ManagerError('Chronikseiten enthalten 1 bis 50 Momente.') from None
        with self.database.connect() as db:
            stats = db.execute('SELECT COUNT(*),COALESCE(MAX(e.rowid),0),COALESCE(SUM(e.rowid),0) FROM community_events e WHERE ' + where, parameters).fetchone()
            if cursor := query.get('cursor'):
                try:
                    if not isinstance(cursor, str) or len(cursor) > 300:
                        raise ValueError()
                    stamp, position = json.loads(base64.b64decode(cursor.encode(), altchars=b'-_', validate=True))
                    if not isinstance(stamp, str) or len(stamp) > 80 or type(position) is not int or position < 0:
                        raise ValueError()
                except (ValueError, TypeError, UnicodeError):
                    raise ManagerError('Ungültige Chronikseite.') from None
                where += f' AND ({MOMENT_TIME},s.position)<(?,?)'
                parameters += [stamp, position]
            rows = db.execute(SELECT + ' WHERE ' + where + f' ORDER BY {MOMENT_TIME} DESC,s.position DESC LIMIT ?', parameters + [limit + 1]).fetchall()
        events = [event_view(row) for row in rows[:limit]]
        next_cursor = None
        if len(rows) > limit:
            last = events[-1]
            next_cursor = base64.urlsafe_b64encode(json.dumps([chronicle_time(last.get('occurred_at'), last['recorded_at']), last['scene']['position']]).encode()).decode()
        return {'events': events, 'next_cursor': next_cursor, 'total': stats[0], 'change_token': ':'.join(map(str, stats))}

    def detail(self, principal, event_id, include_history=True):
        scope, parameters = self.scope(principal)
        if not identifier(event_id):
            raise ManagerError('Ungültige Moment-ID.')
        with self.database.connect() as db:
            row = db.execute(SELECT + ' WHERE e.id=? AND ' + LATEST + ' AND ' + scope, [event_id] + parameters).fetchone()
            if row is None:
                raise AccessDenied('Dieser Moment ist nicht vorhanden oder nicht freigegeben.')
            event = event_view(row)
            if event.get('removed') and principal.role != 'owner':
                raise AccessDenied('Dieser Moment wurde aus der Chronik entfernt.')
            history = []
            if include_history and principal.role == 'owner':
                history = [event_view(item) for item in db.execute(SELECT + ' WHERE e.id=? ORDER BY e.revision DESC LIMIT 50', [event_id])]
        return {'event': event, 'history': history}

    def options(self, principal):
        scope, parameters = self.scope(principal)
        # Extract bounded metadata, never descriptions, images or arbitrary configuration.
        with self.database.connect() as db:
            rows = db.execute("SELECT DISTINCT e.game_id,json_extract(e.payload,'$.campaign_id') AS campaign,json_extract(e.payload,'$.campaign_label') AS label FROM community_events e WHERE " + LATEST + ' AND ' + ACTIVE + ' AND ' + scope, parameters).fetchall()
            members = db.execute("SELECT DISTINCT m.value AS id,COALESCE(json_extract(e.payload,'$.member_names.' || m.value),m.value) AS name FROM community_events e,json_each(e.payload,'$.member_ids') m WHERE " + LATEST + ' AND ' + ACTIVE + ' AND ' + scope + ' LIMIT 500', parameters).fetchall()
        return {'games': [{'id': game, 'label': entry['label']} for game, entry in self.community.catalog.games.items()],
                'campaigns': [{'id': row['campaign'], 'label': row['label'] or row['campaign']} for row in rows if row['campaign']],
                'members': [dict(row) for row in members]}

    def save(self, principal, body):
        principal.require('community.write')
        allowed = {'id', 'revision', 'title', 'description', 'game_id', 'instance_id', 'campaign', 'members', 'badge', 'occurred_at', 'reason', 'remove', 'image', 'clear_image'}
        if set(body) - allowed or not identifier(body.get('id')) or type(body.get('revision')) is not int:
            raise ManagerError('Ungültiger Chronikbeitrag.')
        if any(key in body and type(body[key]) is not bool for key in ('remove', 'clear_image')):
            raise ManagerError('Ungültige Auswahl zum Entfernen.')
        event_id, revision = body['id'], body['revision']
        previous = None
        if revision > 1:
            self.detail(principal, event_id, include_history=False)
            # Reconstruct retries from their actual predecessor, even after later corrections/removal.
            with self.database.connect() as db:
                row = db.execute(SELECT + ' WHERE e.id=? AND e.revision=?', [event_id, revision - 1]).fetchone()
            if row is None:
                raise ManagerError('Ereignisrevisionen müssen lückenlos aufeinander folgen.')
            previous = event_view(row)
        if previous and previous['source'] != 'manual':
            raise ManagerError('Importierte Nachweise können hier nicht manuell überschrieben werden.')
        reason = text(body.get('reason', ''), 300, required=bool(previous))
        if body.get('remove'):
            if not previous or type(body['remove']) is not bool:
                raise ManagerError('Nur vorhandene Momente können entfernt werden.')
            event = {key: value for key, value in previous.items() if key not in ('scene', 'awards', 'recorded_at')}
            event.update(revision=revision, removed=True, actor_id=principal.id, correction_reason=reason)
            return self.community.record(event)
        if previous and previous.get('removed'):
            raise ManagerError('Ein entfernter Moment wird nicht stillschweigend wiederhergestellt.')
        game = body.get('game_id')
        instance = body.get('instance_id') or None
        principal.require('community.write', instance)
        if previous and (game != previous['game_id'] or instance != previous.get('instance_id')):
            raise ManagerError('Spiel und Serverzuordnung bleiben bei Korrekturen erhalten.')
        members = body.get('members')
        if not isinstance(members, list) or not 1 <= len(members) <= 16:
            raise ManagerError('Bitte 1 bis 16 Beteiligte angeben.')
        previous_names = {unicodedata.normalize('NFKC', previous.get('member_names', {}).get(member, member)).casefold(): member
                          for member in previous['member_ids']} if previous else {}
        names = {}
        for value in members:
            name = text(value, 80, True)
            member_id = previous_names.get(unicodedata.normalize('NFKC', name).casefold(), name_id('member-', name))
            if member_id in names:
                raise ManagerError('Beteiligte bitte jeweils nur einmal eintragen.')
            names[member_id] = name
        if len(names) != len(members):
            raise ManagerError('Beteiligte bitte jeweils nur einmal eintragen.')
        occurred = text(body.get('occurred_at', ''), 80)
        if occurred:
            try:
                moment = datetime.fromisoformat(occurred.replace('Z', '+00:00'))
                if moment.tzinfo is None:
                    raise ValueError()
                occurred = moment.astimezone(timezone.utc).isoformat()
            except ValueError:
                raise ManagerError('Ereigniszeit benötigt ein gültiges Datum mit Zeitzone.') from None
        campaign = text(body.get('campaign', ''), 100)
        badge = text(body.get('badge', ''), 80, True)
        campaign_id = name_id('campaign-', campaign) if campaign else None
        badge_id = name_id('badge-', badge)
        if previous:
            if campaign == (previous.get('campaign_label') or previous.get('campaign_id') or ''):
                campaign_id = previous.get('campaign_id')
            if badge == (previous.get('badge_label') or previous['badge_id']):
                badge_id = previous['badge_id']
        event = {'id': event_id, 'revision': revision, 'game_id': game, 'instance_id': instance,
                 'campaign_id': campaign_id, 'campaign_label': campaign, 'mod_profile_id': previous.get('mod_profile_id') if previous else None,
                 'title': text(body.get('title', ''), 160, True), 'description': text(body.get('description', ''), 1000),
                 'source': 'manual', 'member_ids': list(names), 'member_names': names, 'badge_id': badge_id,
                 'badge_label': badge, 'occurred_at': occurred, 'actor_id': principal.id, 'correction_reason': reason}
        media = None
        if body.get('image'):
            try:
                data = base64.b64decode(body['image'], validate=True)
            except (ValueError, TypeError):
                raise ManagerError('Das Vorschaubild ist ungültig kodiert.') from None
            media = jpeg(data)
            event['media_id'] = hashlib.sha256(data).hexdigest()
        elif previous and not body.get('clear_image') and previous.get('media_id'):
            event['media_id'] = previous['media_id']
        result = self.community.record(event, media=media)
        return {**result, 'message': 'Moment gespeichert. Alle Beteiligten erhalten dieselbe Sturmklingen-Auszeichnung.'}

    def media(self, principal, event_id, revision=None):
        detail = self.detail(principal, event_id)
        event = detail['event']
        if revision is not None and str(event['revision']) != str(revision):
            principal.require('community.write')
            event = next((item for item in detail['history'] if str(item['revision']) == str(revision)), {})
        with self.database.connect() as db:
            row = db.execute('SELECT content FROM community_media WHERE id=?', [event.get('media_id', '')]).fetchone()
        if row is None:
            raise ManagerError('Für diesen Moment ist kein Screenshot vorhanden.')
        return row['content']

    def export(self, principal, **query):
        principal.require('community.export')
        query.pop('cursor', None)
        query.pop('limit', None)
        where, parameters = self.filters(principal, query, active=False)
        archive = io.BytesIO()
        # One read transaction keeps revisions and image references consistent.
        with self.database.connect() as db, zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as bundle:
            db.execute('BEGIN')
            ids = db.execute('SELECT e.id FROM community_events e WHERE ' + where, parameters).fetchall()
            if len(ids) > 1000:
                raise ManagerError('Bitte den Export auf höchstens 1000 Momente filtern.')
            events, media_ids, total_bytes = [], set(), 0
            for item in ids:
                rows = db.execute(SELECT + ' WHERE e.id=? ORDER BY e.revision', [item['id']])
                for row in rows:
                    event = event_view(row)
                    total_bytes += len(json.dumps(event, ensure_ascii=False).encode())
                    if total_bytes > MAX_EXPORT or len(events) >= 10000:
                        raise ManagerError('Export zu groß. Bitte die Auswahl eingrenzen.')
                    events.append(event)
                    if media_id := event.get('media_id'):
                        media_ids.add(media_id)
            manifest = json.dumps({'format': 'sturmklingen-chronicle', 'version': 1, 'events': events}, ensure_ascii=False, indent=2).encode()
            offline_page = archive_html(events)
            total_bytes = len(manifest) + len(offline_page)
            bundle.writestr('chronicle.json', manifest)
            bundle.writestr('index.html', offline_page)
            for media_id in sorted(media_ids):
                row = db.execute('SELECT content FROM community_media WHERE id=?', [media_id]).fetchone()
                if row is None:
                    raise ManagerError('Ein Chronikbild fehlt. Export wurde abgebrochen.')
                total_bytes += len(row['content'])
                if total_bytes > MAX_EXPORT:
                    raise ManagerError('Export zu groß (maximal 32 MiB). Bitte die Kampagne oder den Zeitraum eingrenzen.')
                bundle.writestr('media/' + media_id + '.jpg', row['content'])
        if total_bytes > MAX_EXPORT:
            raise ManagerError('Export zu groß. Bitte die Auswahl eingrenzen.')
        return archive.getvalue()
