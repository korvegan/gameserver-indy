"""Discord transport and shared settings, separate from native process control."""
import copy
import json
import os
import re
import urllib.error
import urllib.request

from .configuration import ManagerError, atomic_json

WEBHOOK = r"https://(?:canary\.|ptb\.)?discord(?:app)?\.com/api(?:/v\d+)?/webhooks/\d+/[A-Za-z0-9_-]+"


def send_discord(url, message):
    if not isinstance(url, str) or not re.fullmatch(WEBHOOK, url):
        raise ManagerError("Bitte eine gültige Discord-Webhook-URL verwenden.")
    if not isinstance(message, str) or not 1 <= len(message.strip()) <= 2000:
        raise ManagerError("Die Discord-Nachricht muss 1 bis 2000 Zeichen enthalten.")
    payload = json.dumps({"content": message.strip(), "allowed_mentions": {"parse": []}}, ensure_ascii=False).encode()
    request = urllib.request.Request(url + "?wait=true", data=payload, headers={
        "Content-Type": "application/json", "User-Agent": "GameServerIndy/0.3",
    }, method="POST")

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=12) as response:
            response.read(16384)
    except urllib.error.HTTPError as exc:
        if exc.code == 429:
            raise ManagerError("Discord begrenzt gerade die Anfragen. Bitte später erneut senden.") from None
        raise ManagerError(f"Discord hat die Nachricht abgelehnt (HTTP {exc.code}).") from None
    except (OSError, urllib.error.URLError):
        raise ManagerError("Discord ist derzeit nicht erreichbar.") from None


class DiscordService:
    def __init__(self, instance):
        self.instance = instance

    def webhook(self):
        instance = self.instance
        with instance.settings_lock:
            return os.environ.get("DISCORD_WEBHOOK_URL", "") or instance.settings.get("discord", {}).get("webhook_url", "")

    def save(self, settings):
        if not isinstance(settings, dict) or type(settings.get("auto_notify")) is not bool:
            raise ManagerError("Ungültige Discord-Einstellungen.")
        instance = self.instance
        with instance.settings_lock:
            previous = instance.settings.get("discord", {}).get("webhook_url", "")
            url = settings.get("webhook_url", "")
            if not isinstance(url, str):
                raise ManagerError("Ungültige Webhook-URL.")
            url = url.strip() or previous
            if settings.get("clear_webhook"):
                url = ""
            if url and not re.fullmatch(WEBHOOK, url):
                raise ManagerError("Bitte eine gültige Discord-Webhook-URL verwenden.")
            updated = copy.deepcopy(instance.settings)
            updated["discord"] = {"webhook_url": url, "auto_notify": settings["auto_notify"]}
            atomic_json(instance.settings_path, updated)
            instance.settings.clear()
            instance.settings.update(updated)
        instance.event("Discord-Einstellungen gespeichert.")
        return {"message": "Discord-Einstellungen gespeichert."}

    def notify(self, message):
        url = self.webhook()
        if not url:
            raise ManagerError("Bitte zuerst einen Discord-Webhook hinterlegen.")
        send_discord(url, message)
        self.instance.event("Discord-Nachricht gesendet.")
        return {"message": "Nachricht an Discord gesendet."}
