"""Bounded background execution with durable outcomes and per-target exclusion."""
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import json
import sqlite3
import threading
from uuid import uuid4

from .configuration import ManagerError


def utcnow():
    return datetime.now(timezone.utc).isoformat()


class JobService:
    def __init__(self, database, workers=4):
        self.database = database
        self.lock = threading.Lock()
        self.active = {}
        self.unsettled = {}
        self.closed = False
        self.executor = ThreadPoolExecutor(max_workers=workers, thread_name_prefix="gameserver-job")
        with database.connect(write=True) as db:
            db.execute("UPDATE jobs SET state='interrupted', message=?, updated_at=? WHERE state IN ('queued', 'running')",
                       ("Verwaltung unterbrochen. Ergebnis unbekannt; Prozessstatus und Protokoll prüfen. Kein automatischer Wiederholungsversuch.", utcnow()))

    def submit(self, server_id, operation, actor_id, callback):
        with self.lock:
            if self.closed:
                raise ManagerError("Die Verwaltung wird gerade beendet.")
            if server_id in self.active:
                raise ManagerError("Für dieses Ziel arbeitet bereits ein Auftrag. Bitte dessen Ergebnis abwarten.")
            if len(self.active) >= 16:
                raise ManagerError("Die Auftragswarteschlange ist voll. Bitte später erneut versuchen.")
            job_id, now = uuid4().hex, utcnow()
            with self.database.connect(write=True) as db:
                db.execute("INSERT INTO jobs VALUES (?, ?, ?, ?, 'queued', ?, ?, ?, NULL)",
                           (job_id, server_id, operation, actor_id, "Wartet auf Ausführung.", now, now))
            self.active[server_id] = job_id
            try:
                future = self.executor.submit(self._run, job_id, server_id, callback)
            except BaseException:
                self.active.pop(server_id, None)
                self._update(job_id, "interrupted", "Auftrag konnte nicht gestartet werden.")
                raise
        return self.get(job_id), future

    def _update(self, job_id, state, message, result=None):
        with self.database.connect(write=True) as db:
            db.execute("UPDATE jobs SET state=?, message=?, updated_at=?, result=? WHERE id=?",
                       (state, message, utcnow(), json.dumps(result, ensure_ascii=False) if result is not None else None, job_id))

    def _run(self, job_id, server_id, callback):
        try:
            self._update(job_id, "running", "Wird ausgeführt. Fortschritt im Serverprotokoll prüfen.")
            try:
                result = callback()
            except ManagerError as exc:
                self._update(job_id, "failed", str(exc))
                raise
            except Exception:
                self._update(job_id, "failed", "Auftrag fehlgeschlagen. Lokale Dateien und Windows-Anbindung prüfen.")
                raise ManagerError("Auftrag fehlgeschlagen. Lokale Dateien und Windows-Anbindung prüfen.") from None
            self._update(job_id, "succeeded", result.get("message", "Auftrag abgeschlossen."), result)
            return result
        except (sqlite3.Error, OSError):
            message = "Auftragsergebnis konnte nicht gespeichert werden. Ausgang unbekannt; Prozessstatus und Protokoll prüfen. Kein automatischer Wiederholungsversuch."
            with self.lock:
                self.unsettled[job_id] = {"state": "interrupted", "message": message, "result": None}
            self._reconcile()
            raise ManagerError(message) from None
        finally:
            with self.lock:
                self.active.pop(server_id, None)

    def _reconcile(self):
        with self.lock:
            unsettled = dict(self.unsettled)
        for job_id, outcome in unsettled.items():
            try:
                self._update(job_id, outcome["state"], outcome["message"])
            except (sqlite3.Error, OSError):
                continue
            with self.lock:
                self.unsettled.pop(job_id, None)

    def _public(self, row):
        result = dict(row)
        with self.lock:
            result.update(self.unsettled.get(result["id"], {}))
        result["result"] = json.loads(result["result"]) if result["result"] else None
        result.update(cancellable=False, resumable=False, progress={"phase": result["state"],
                      "completed": 1 if result["state"] == "succeeded" else 0, "total": 1, "unit": "Auftrag"})
        return result

    def get(self, job_id):
        self._reconcile()
        with self.database.connect() as db:
            row = db.execute("SELECT * FROM jobs WHERE id=?", (job_id,)).fetchone()
        if row is None:
            raise ManagerError("Dieser Auftrag existiert nicht.")
        return self._public(row)

    def recent(self, server_id):
        self._reconcile()
        with self.database.connect() as db:
            rows = db.execute("SELECT * FROM jobs WHERE server_id=? ORDER BY created_at DESC LIMIT 20", (server_id,)).fetchall()
        return [self._public(row) for row in rows]

    def close(self):
        with self.lock:
            self.closed = True
        self.executor.shutdown(wait=True)
