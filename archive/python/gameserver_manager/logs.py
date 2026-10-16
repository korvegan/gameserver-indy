"""Bounded log reads and redaction before data crosses into the browser."""
from datetime import datetime, timezone
from pathlib import Path
import re

REDACTED = "[ENTFERNT]"
SECRET_KEY = re.compile(r"(?i)(?:authentication[ _-]*token|auth[ _-]*token|access[ _-]*token|api[ _-]*key|password|passwd|webhook(?:[ _-]*url)?|authorization)")
WEBHOOK = re.compile(r"(?i)https?://(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v\d+)?/webhooks/[^\s\"'<>]+")


def secret_values(config, webhook=""):
    values = []
    def visit(item):
        if isinstance(item, dict):
            for key, value in item.items():
                if SECRET_KEY.search(key) and isinstance(value, str) and value:
                    values.append(value)
                else:
                    visit(value)
        elif isinstance(item, list):
            for value in item:
                visit(value)
    visit(config)
    if webhook:
        values.append(webhook)
    return sorted(set(values), key=len, reverse=True)


def redact(text, values=()):
    text = WEBHOOK.sub(REDACTED, text)
    # Remove whole credential-bearing lines, including unknown/rotated old values.
    lines = []
    hide_next = False
    for line in text.splitlines():
        if hide_next and line.strip():
            lines.append(REDACTED)
            hide_next = False
            continue
        match = SECRET_KEY.search(line)
        if match and re.search(r"[:=]", line[match.end():]):
            suffix = line[match.end():]
            hide_next = not re.split(r"[:=]", suffix, maxsplit=1)[1].strip().strip('\"\',')
            line = line[:match.end()] + ": " + REDACTED
        for value in values:
            line = re.sub(re.escape(value), lambda _: REDACTED, line, flags=re.IGNORECASE)
        lines.append(line)
    return "\n".join(lines)


def read_tail(path: Path, values=(), limit=200):
    with path.open("rb") as handle:
        prefix = handle.read(2)
        encoding = "utf-16" if prefix in (b"\xff\xfe", b"\xfe\xff") else "utf-8-sig"
        size = path.stat().st_size
        offset = max(0, size - 262144)
        if encoding == "utf-16":
            offset -= offset % 2
            if offset:
                encoding = "utf-16-le" if prefix == b"\xff\xfe" else "utf-16-be"
        handle.seek(offset)
        text = handle.read(262144).decode(encoding, errors="replace")
    if offset:
        # A truncated credential or keyed line must never reach the response.
        text = text.partition("\n")[2]
    return "\n".join(redact(text, values).splitlines()[-limit:])


def append_diagnostic(directory, message, values=()):
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / "launcher.log"
    if path.exists() and path.stat().st_size >= 2 * 1024 * 1024:
        path.replace(directory / "launcher.previous.log")
    with path.open("a", encoding="utf-8") as handle:
        handle.write(datetime.now(timezone.utc).isoformat() + " " + redact(message, values) + "\n")
