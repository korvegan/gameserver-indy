"""Bounded technical history; community memories live in their own store."""
from datetime import datetime, timezone
import json
import threading


class TechnicalHistory:
    def __init__(self, directory):
        self.path = directory / "events.jsonl"
        self.lock = threading.Lock()

    def append(self, message):
        with self.lock:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            with self.path.open("a", encoding="utf-8") as handle:
                handle.write(json.dumps({"time": datetime.now(timezone.utc).isoformat(), "message": message}, ensure_ascii=False) + "\n")

    def recent(self):
        with self.lock:
            if not self.path.exists():
                return []
            with self.path.open("rb") as handle:
                handle.seek(max(0, self.path.stat().st_size - 32768))
                lines = handle.read().decode("utf-8", errors="replace").splitlines()
        entries = []
        for line in lines[-30:]:
            try:
                entries.append(json.loads(line))
            except ValueError:
                pass
        return list(reversed(entries))
