"""Durable event/scene/outbox contracts; no automatic awards or live Discord sender."""
from dataclasses import asdict, dataclass
import json

from .catalog import identifier
from .configuration import ManagerError
from .jobs import utcnow


@dataclass(frozen=True)
class EventContext:
    game_id: str
    instance_id: str | None = None
    campaign_id: str | None = None
    mod_profile_id: str | None = None

    def validate(self):
        if not identifier(self.game_id) or any(value is not None and not identifier(value)
                for value in (self.instance_id, self.campaign_id, self.mod_profile_id)):
            raise ManagerError("Ereignisse benötigen gültige Spiel- und Kontext-IDs.")


class CommunityService:
    def __init__(self, database, catalog, registry=None):
        self.database, self.catalog, self.registry = database, catalog, registry
        with database.connect(write=True) as db:
            db.execute("UPDATE deliveries SET state='uncertain' WHERE state='sending'")

    def record(self, event, publish=False, target="discord-stub"):
        allowed = {"id", "revision", "title", "description", "member_ids", "badge_id", "source", "occurred_at",
                   "game_id", "instance_id", "campaign_id", "mod_profile_id"}
        if not isinstance(event, dict) or set(event) - allowed:
            raise ManagerError("Unbekannte Felder im Gemeinschaftsereignis.")
        context = EventContext(**{key: event[key] for key in ("game_id", "instance_id", "campaign_id", "mod_profile_id") if key in event}) if "game_id" in event else None
        if context is None:
            raise ManagerError("Das Spiel für den Gruppenmoment fehlt.")
        context.validate()
        if context.game_id not in self.catalog.games:
            raise ManagerError("Dieses Spiel ist nicht im Katalog eingetragen.")
        if context.instance_id is not None:
            if self.registry is None or self.registry.get(context.instance_id).game.id != context.game_id:
                raise ManagerError("Spiel und Serverinstanz des Ereignisses stimmen nicht überein.")
        members = event.get("member_ids")
        if (not identifier(event.get("id")) or type(event.get("revision")) is not int or event["revision"] < 1
                or not identifier(event.get("badge_id")) or not isinstance(members, list) or not 1 <= len(members) <= 16
                or any(not identifier(member) for member in members) or len(set(members)) != len(members)
                or event.get("source") not in ("test", "manual", "verified-game", "steam-import")):
            raise ManagerError("Ungültige Ereignisrevision, Quelle oder Mitgliederzuordnung.")
        for field, maximum in (("title", 160), ("description", 1000), ("occurred_at", 80)):
            if not isinstance(event.get(field, ""), str) or len(event.get(field, "")) > maximum:
                raise ManagerError("Der Gruppenmoment enthält ungültige Texte.")
        if not event.get("title", "").strip():
            raise ManagerError("Der Gruppenmoment benötigt einen Titel.")
        if not identifier(target):
            raise ManagerError("Ungültiges Veröffentlichungsziel.")
        payload = json.dumps({**event, **asdict(context)}, ensure_ascii=False, sort_keys=True)
        with self.database.connect(write=True) as db:
            existing = db.execute("SELECT revision, payload FROM community_events WHERE id=? ORDER BY revision DESC LIMIT 1",
                                  (event["id"],)).fetchone()
            if existing and event["revision"] <= existing["revision"]:
                previous = db.execute("SELECT payload FROM community_events WHERE id=? AND revision=?",
                                      (event["id"], event["revision"])).fetchone()
                if previous is None or previous["payload"] != payload:
                    raise ManagerError("Diese Ereignisrevision existiert bereits mit anderem Inhalt.")
            else:
                if event["revision"] != (existing["revision"] + 1 if existing else 1):
                    raise ManagerError("Ereignisrevisionen müssen lückenlos aufeinander folgen.")
                db.execute("INSERT INTO community_events VALUES (?, ?, ?, ?, ?, ?)",
                           (event["id"], event["revision"], context.game_id, context.instance_id, payload, utcnow()))
                position = db.execute("SELECT COALESCE(MAX(position), -1) + 1 FROM scenes").fetchone()[0]
                db.execute("INSERT OR IGNORE INTO scenes VALUES (?, ?, ?, 'milestone')",
                           ("scene-" + event["id"], event["id"], position))
            if publish:
                db.execute("INSERT OR IGNORE INTO deliveries VALUES (?, ?, ?, 'pending', NULL)",
                           (event["id"], event["revision"], target))
        return {"event_id": event["id"], "revision": event["revision"], "scene_id": "scene-" + event["id"]}

    def recent(self, principal):
        principal.require("community.read")
        parameters = []
        visibility = ""
        if principal.server_ids is not None:
            ids = sorted(principal.server_ids)
            visibility = " AND (e.instance_id IS NULL" + (" OR e.instance_id IN (" + ",".join("?" for _ in ids) + ")" if ids else "") + ")"
            parameters.extend(ids)
        with self.database.connect() as db:
            rows = db.execute("""SELECT e.payload, e.recorded_at, s.id AS scene_id, s.position, s.template
                FROM community_events e JOIN scenes s ON s.event_id=e.id
                WHERE NOT EXISTS (SELECT 1 FROM community_events n WHERE n.id=e.id AND n.revision>e.revision)"""
                + visibility + " ORDER BY s.position DESC LIMIT 20", parameters).fetchall()
        events = []
        for row in rows:
            event = json.loads(row["payload"])
            events.append({**event, "recorded_at": row["recorded_at"],
                           "scene": {"id": row["scene_id"], "position": row["position"], "template": row["template"]},
                           "awards": [{"member_id": member, "badge_id": event["badge_id"]} for member in event["member_ids"]]})
        return events

    def deliver_pending(self, sender):
        """Explicit injected transport only. Never replay an ambiguous send."""
        with self.database.connect() as db:
            rows = db.execute("SELECT * FROM deliveries WHERE state='pending' LIMIT 8").fetchall()
        for row in rows:
            key = (row["event_id"], row["revision"], row["target"])
            with self.database.connect(write=True) as db:
                ambiguous = db.execute("SELECT 1 FROM deliveries WHERE event_id=? AND target=? AND state IN ('sending', 'uncertain') LIMIT 1",
                                       (row["event_id"], row["target"])).fetchone()
                if ambiguous:
                    db.execute("UPDATE deliveries SET state='uncertain' WHERE event_id=? AND revision=? AND target=?", key)
                    continue
                claimed = db.execute("UPDATE deliveries SET state='sending' WHERE event_id=? AND revision=? AND target=? AND state='pending'", key).rowcount
                event = db.execute("SELECT payload FROM community_events WHERE id=? AND revision=?", key[:2]).fetchone()
                previous = db.execute("SELECT remote_id FROM deliveries WHERE event_id=? AND target=? AND state='sent' ORDER BY revision DESC LIMIT 1",
                                      (row["event_id"], row["target"])).fetchone()
            if not claimed:
                continue
            event = json.loads(event["payload"])
            card = {"event_id": event["id"], "revision": event["revision"], "scene_id": "scene-" + event["id"],
                    "content": event["title"], "allowed_mentions": {"parse": []}, "template_version": 1}
            card.update(operation="update" if previous else "create", previous_remote_id=previous["remote_id"] if previous else None)
            remote_id = None
            try:
                remote_id = sender(row["target"], card)
                if not isinstance(remote_id, str) or not remote_id:
                    raise TimeoutError()
                state = "sent"
            except TimeoutError:
                state = "uncertain"
                remote_id = None
            except Exception:
                # A transport exception alone cannot prove that Discord did not
                # receive the request. Keep later revisions from reposting it.
                state = "uncertain"
                remote_id = None
            with self.database.connect(write=True) as db:
                db.execute("UPDATE deliveries SET state=?, remote_id=? WHERE event_id=? AND revision=? AND target=?",
                           (state, remote_id, *key))
