"""Bounded background execution with durable outcomes and per-target exclusion."""
from collections import deque
from concurrent.futures import Future, ThreadPoolExecutor
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
        self.waiting = {}
        self.unsettled = {}
        self.closed = False
        self.executor = ThreadPoolExecutor(max_workers=workers, thread_name_prefix="gameserver-job")
        with database.connect(write=True) as db:
            db.execute("UPDATE jobs SET state='interrupted', message=?, updated_at=? WHERE state IN ('queued', 'running')",
                       ("Verwaltung unterbrochen. Ergebnis unbekannt; Prozessstatus und Protokoll prüfen. Kein automatischer Wiederholungsversuch.", utcnow()))

    def submit(self, server_id, operation, actor_id, callback, *, reservation=None, enqueue=False):
        reservation = server_id if reservation is None else reservation
        with self.lock:
            if self.closed:
                raise ManagerError("Die Verwaltung wird gerade beendet.")
            if reservation in self.active and not enqueue:
                raise ManagerError("Für dieses Ziel arbeitet bereits ein Auftrag. Bitte dessen Ergebnis abwarten.")
            if len(self.active) + sum(len(queue) for queue in self.waiting.values()) >= 16:
                raise ManagerError("Die Auftragswarteschlange ist voll. Bitte später erneut versuchen.")
            if reservation in self.active and len(self.waiting.get(reservation, ())) >= 7:
                raise ManagerError('Für dieses Serverprofil warten bereits sieben Befehle. Bitte deren Ergebnisse abwarten.')
            job_id, now = uuid4().hex, utcnow()
            with self.database.connect(write=True) as db:
                db.execute("INSERT INTO jobs VALUES (?, ?, ?, ?, 'queued', ?, ?, ?, NULL)",
                           (job_id, server_id, operation, actor_id, "Wartet auf Ausführung.", now, now))
            future = Future()
            future.set_running_or_notify_cancel()  # Product jobs cannot be cancelled.
            entry = (job_id, callback, future)
            if reservation in self.active:
                self.waiting.setdefault(reservation, deque()).append(entry)
                start = False
            else:
                self.active[reservation] = job_id
                start = True
            try:
                if start:
                    self.executor.submit(self._drain, reservation, entry)
            except BaseException:
                self.active.pop(reservation, None)
                self._update(job_id, "interrupted", "Auftrag konnte nicht gestartet werden.")
                raise
        return self.get(job_id), future

    def _interrupt(self, job_id, message):
        try:
            self._update(job_id, 'interrupted', message)
        except (sqlite3.Error, OSError):
            with self.lock:
                self.unsettled[job_id] = {'state': 'interrupted', 'message': message, 'result': None}

    def _drain(self, reservation, entry):
        # One worker per active target; queued commands never occupy waiting workers.
        while entry is not None:
            job_id, callback, future = entry
            error, result = None, None
            try:
                result = self._run(job_id, reservation, callback)
            except BaseException as exc:
                error = exc
                if not isinstance(exc, ManagerError):
                    message = 'Auftrag wurde unterbrochen. Ausgang unbekannt; bitte Prozessstatus prüfen.'
                    self._interrupt(job_id, message)
                    error = ManagerError(message)
            with self.lock:
                queue = self.waiting.get(reservation, deque())
                cancelled = list(queue) if error is not None or self.closed else []
                if cancelled:
                    queue.clear()
                entry = queue.popleft() if queue else None
                if not queue:
                    self.waiting.pop(reservation, None)
                if entry is None:
                    self.active.pop(reservation, None)
                else:
                    self.active[reservation] = entry[0]
            message = ('Ein vorheriger Auftrag ist fehlgeschlagen oder unklar. Dieser wartende Befehl wurde nicht ausgeführt. Bitte neu beauftragen.'
                       if error is not None else 'Verwaltung wird beendet. Dieser wartende Befehl wurde nicht ausgeführt.')
            for cancelled_id, _callback, cancelled_future in cancelled:
                self._interrupt(cancelled_id, message)
                cancelled_future.set_exception(ManagerError(message))
            if error is None:
                future.set_result(result)
            else:
                future.set_exception(error)

    def _update(self, job_id, state, message, result=None):
        with self.database.connect(write=True) as db:
            db.execute("UPDATE jobs SET state=?, message=?, updated_at=?, result=? WHERE id=?",
                       (state, message, utcnow(), json.dumps(result, ensure_ascii=False) if result is not None else None, job_id))

    def _run(self, job_id, reservation, callback):
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
            position = next((index + 1 for queue in self.waiting.values() for index, entry in enumerate(queue) if entry[0] == result['id']), 0)
        result["result"] = json.loads(result["result"]) if result["result"] else None
        result.update(cancellable=False, resumable=False, queue_position=position, progress={"phase": result["state"],
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
