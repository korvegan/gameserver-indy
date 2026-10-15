"""HTTP data contracts; transport and CSRF handling live in server.py."""
from .configuration import ManagerError


class NotFound(ManagerError):
    pass


def one(query, key):
    values = query.get(key, [None])
    if len(values) != 1:
        raise ManagerError("Ein Anfrageparameter darf nicht mehrfach angegeben werden.")
    return values[0]


class Api:
    def __init__(self, application):
        self.app = application

    def get(self, path, query, principal):
        selected = one(query, "server")
        if path == "/api/servers":
            return self.app.servers(principal)
        if path == "/api/games":
            principal.require("catalog.read")
            return {"games": self.app.catalog.entries()}
        if path == "/api/status":
            return self.app.status(principal, selected)
        if path == "/api/config":
            return self.app.configuration(principal, selected)
        if path == "/api/state":
            return self.app.legacy_state(principal, selected)
        if path == "/api/logs":
            instance = self.app.instance(principal, selected, "logs.read")
            return instance.logs(one(query, "source"))
        if path == "/api/events":
            instance = self.app.instance(principal, selected, "config.read")
            return {"events": instance.events()}
        if path == "/api/jobs":
            job_id = one(query, "id")
            if job_id:
                return {"job": self.app.job_view(principal, self.app.jobs.get(job_id))}
            instance = self.app.instance(principal, selected, "jobs.read")
            return {"jobs": [self.app.job_view(principal, job) for job in self.app.jobs.recent(instance.profile["id"])]}
        if path == "/api/community":
            return self.app.chronicle.page(principal, **{key: one(query, key) for key in query})
        if path == '/api/community/options':
            return self.app.chronicle.options(principal)
        if path == '/api/community/event':
            return self.app.chronicle.detail(principal, one(query, 'id'))
        raise NotFound("Nicht gefunden.")

    def post(self, path, body, principal):
        if path == '/api/community':
            return 200, self.app.chronicle.save(principal, body)
        if path == '/api/connections/refresh':
            return 202, self.app.refresh_connections(principal, body.get('server_id'))
        if path == '/api/updates/check':
            job, _future = self.app.check_updates(principal, body.get('server_id'))
            return 202, {'job': self.app.job_view(principal, job)}
        if path == "/api/jobs":
            job, _future = self.app.submit(principal, body.get("operation"), body)
            return 202, {"job": self.app.job_view(principal, job)}
        if path not in ("/api/servers", "/api/setup", "/api/start", "/api/stop", "/api/restart", "/api/config",
                        "/api/discord/settings", "/api/discord/send"):
            raise NotFound("Nicht gefunden.")
        return 200, self.app.mutate(principal, path, body)
