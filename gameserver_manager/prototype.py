"""Executable contract demonstration. Caller must supply a disposable directory."""
from .catalog import GameCatalog
from .community import CommunityService
from .security import Principal
from .storage import Database


def demonstrate(directory):
    database = Database(directory / "platform.sqlite3")
    catalog = GameCatalog([{"id": "raft", "label": "Raft"}])
    community = CommunityService(database, catalog)
    event = {"id": "prototype-winter", "revision": 1, "game_id": "raft", "campaign_id": "prototype-campaign",
             "title": "Gemeinsamer Testmeilenstein", "source": "test", "badge_id": "prototype-badge",
             "member_ids": ["member-1", "member-2", "member-3", "member-4"]}
    first = community.record(event, publish=True)
    community.record(event, publish=True)
    cards = []

    def stub(target, card):
        cards.append({"target": target, **card})
        return "stub-message-1"

    community.deliver_pending(stub)
    community.deliver_pending(stub)
    return {**first, "events": community.recent(Principal("test-owner", "owner")), "discord_stub": cards}
