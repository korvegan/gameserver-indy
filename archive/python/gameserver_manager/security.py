"""Server-side permissions. A CSRF token is never a user identity."""
from dataclasses import dataclass

from .configuration import ManagerError

READ = frozenset({"catalog.read", "status.read", "jobs.read", "community.read"})
PERMISSIONS = {
    "viewer": READ,
    "family": READ | {"server.start"},
    "owner": READ | {"config.read", "config.write", "logs.read", "server.start", "server.stop",
                      "server.restart", "server.setup", "catalog.write", "discord.write", "updates.check", "connections.refresh",
                      "community.write", "community.export", "access.manage", "backups.manage"},
}


class AccessDenied(ManagerError):
    pass


@dataclass(frozen=True)
class Principal:
    id: str
    role: str
    server_ids: frozenset | None = None
    auth_revision: int = 0

    def allows_server(self, server_id):
        return isinstance(server_id, str) and (self.server_ids is None or server_id in self.server_ids)

    def require(self, permission, server_id=None):
        if (self.role not in PERMISSIONS or not self.id or permission not in PERMISSIONS[self.role]
                or (server_id is not None and not self.allows_server(server_id))):
            raise AccessDenied("Für diese Aktion oder dieses Serverprofil fehlt die Berechtigung.")

    def public(self):
        self.require("catalog.read")
        return {"id": self.id, "role": self.role, "permissions": sorted(PERMISSIONS[self.role])}


def local_owner(_request):
    # Only LocalServer's strict loopback/Host boundary authorizes this default.
    # Incoming identity/role/proxy headers are deliberately ignored. FamilyServer
    # has its own loopback listener and verified Serve allowlist; no fallback here.
    return Principal("local-owner", "owner")
