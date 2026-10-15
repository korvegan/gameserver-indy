"""Application boundary: authorization, read models and explicit operation dispatch."""
import copy

from .catalog import GameCatalog
from .community import CommunityService
from .configuration import ManagerError
from .jobs import JobService
from .logs import redact, secret_values
from .security import AccessDenied
from .storage import Database

PUBLIC_STATUS = {"installed", "configured", "running", "server_id", "game", "game_label", "profile_label", "starting"}


class Application:
    def __init__(self, registry):
        self.registry = registry
        self.catalog = GameCatalog(registry.settings.get("catalog_games", []))
        self.database = Database(registry.root / "data" / "platform.sqlite3")
        self.jobs = JobService(self.database)
        self.community = CommunityService(self.database, self.catalog, registry)

    def instance(self, principal, server_id=None, permission="status.read", explicit=False):
        principal.require(permission, server_id)
        instance = self.registry.get(server_id, explicit=explicit)
        principal.require(permission, instance.profile["id"])
        return instance

    def servers(self, principal):
        principal.require("catalog.read")
        catalog = self.registry.catalog()
        catalog["servers"] = [profile for profile in catalog["servers"] if principal.allows_server(profile["id"])]
        if principal.role != "owner":
            catalog["servers"] = [{key: profile[key] for key in ("id", "game", "label")} for profile in catalog["servers"]]
            catalog["games"] = [{key: game[key] for key in ("id", "label")} for game in catalog["games"]]
        return catalog

    def status(self, principal, server_id):
        instance = self.instance(principal, server_id)
        status = instance.status()
        if principal.role != "owner":
            status = {key: value for key, value in status.items() if key in PUBLIC_STATUS}
        # Only specifically selected display values, never arbitrary config keys.
        summary, error = {"title": instance.profile["label"], "metrics": []}, None
        try:
            config = instance.config() or {}
            summary["title"] = instance.game.title(config)
            summary["metrics"] = [{"label": metric["label"], "value": metric.get("options", {}).get(str(config.get(metric["key"])), config.get(metric["key"]))}
                                  for metric in instance.game.schema["metrics"]]
        except (ManagerError, OSError):
            error = "Die Spielkonfiguration ist nicht lesbar. Andere Profile bleiben erreichbar."
        return {"status": status, "summary": summary, "config_error": error}

    def configuration(self, principal, server_id):
        instance = self.instance(principal, server_id, "config.read")
        return {"config": instance.public_config(), "game_profile": copy.deepcopy(instance.game.schema)}

    def legacy_state(self, principal, server_id):
        instance = self.instance(principal, server_id, "config.read")
        return {"status": instance.status(), "config": instance.public_config(), "events": instance.events(),
                "game_profile": instance.game.schema, **self.servers(principal)}

    def job_view(self, principal, job):
        principal.require("jobs.read", job["server_id"])
        if job["server_id"].startswith("@") and principal.role != "owner":
            raise AccessDenied("Dieser Auftrag gehört zur Besitzerverwaltung.")
        if principal.role != "owner":
            # Owner-entered results and diagnostics are not a family status view.
            job = {key: value for key, value in job.items() if key not in ("actor_id", "result", "message")}
            job["message"] = {"queued": "Wartet auf Ausführung.", "running": "Wird ausgeführt.",
                              "succeeded": "Auftrag abgeschlossen.", "failed": "Auftrag fehlgeschlagen.",
                              "interrupted": "Auftrag wurde unterbrochen."}[job["state"]]
        return job

    def submit(self, principal, operation, body):
        permissions = {"start": "server.start", "stop": "server.stop", "restart": "server.restart",
                       "setup": "server.setup", "config": "config.write"}
        if not isinstance(operation, str) or operation not in permissions:
            raise ManagerError("Unbekannte Serveraktion.")
        instance = self.instance(principal, body.get("server_id"), permissions[operation], explicit=True)
        # Inputs stay in memory, never in the durable job record.
        payload = copy.deepcopy(body)
        operation_callback = (lambda: instance.setup()) if operation == "setup" else (
            (lambda: instance.save_config(payload.get("config"))) if operation == "config" else lambda: instance.action(operation))

        def callback():
            try:
                values = secret_values(instance.config() or {}, instance.webhook())
            except (ManagerError, OSError):
                values = secret_values({}, instance.webhook())
            try:
                return operation_callback()
            except ManagerError as exc:
                raise ManagerError(redact(str(exc), values)) from None

        return self.jobs.submit(instance.profile["id"], operation, principal.id, callback)

    def mutate(self, principal, path, body):
        if path == "/api/servers":
            principal.require("catalog.write")
            return self.registry.register(body)
        if path in ("/api/discord/settings", "/api/discord/send"):
            principal.require("discord.write")
            instance = self.registry.get()
            callback = lambda: instance.save_discord(body) if path.endswith("settings") else instance.notify(body.get("message"))
            _job, future = self.jobs.submit("@discord", path.rsplit("/", 1)[1], principal.id, callback)
            return future.result()
        operation = path.rsplit("/", 1)[-1]
        _job, future = self.submit(principal, operation, body)
        return future.result()

    def close(self):
        self.jobs.close()
