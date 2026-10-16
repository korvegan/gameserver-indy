# Spiele, Welten und Sicherungen

Profile: `profiles/`; private Instanzen: `manager.local.json`. `ConfigurationAdapter` und Service-Tags sammeln Adapter. Bei neuen Spielen Herstelleranleitung, EXE, Konfiguration, Speichern und Stopp prüfen; Metadatenimport aktiviert keinen nativen Adapter.

## Enshrouded

Installation `D:\SteamLibrary\steamapps\common\EnshroudedServer`. Nach Updates installierte Konfigurationsgrenzen prüfen, unbekannte Felder erhalten. Am 15.10.2026: Build 25936466, Anleitung 1.0.0.0, `gameSettings.flameLevelFogRemoval` Standard 11/Bereich 0–20, `randomSpawnerAmount` ergänzt `Automatic`. Keine unveränderlichen Herstellergrenzen daraus ableiten.

Neue Kampagne in neuem Weltordner, Bestandswelt erhalten. Aktuell `world0.ksd`; vollständigen konfigurierten Weltordner sichern.

## ASKA

Steam `D:\SteamLibrary\steamapps\common\ASKA Dedicated Server`. Aktive Konfiguration außerhalb Steam: `C:\Users\korvegan\Documents\workspace\Aska\config\server properties.txt`. Desktop `ASKA starten.cmd` ruft vorhandenen `start-aska-discord.ps1` auf.

Starter prüft feste Save-ID/Weltordner, übergibt `-propertiesPath`, setzt SteamAppId/SteamGameId 1898300. Bestätigte Bestandswelt: **`Aska/data/server/savegame_<id>`**. Daneben existiert wirklich ältere `data/savegame_<id>`; nicht verwechseln.

Richtige Welt und Beitritt am 15.10.2026 bestätigt. Viele gleiche Unity-Shaderwarnungen traten auch beim erfolgreichen Start auf; sie belegen keinen Startfehler. Logs können Zugangsdaten enthalten, nur bereinigt anzeigen. Stellvertreterstopp beweist nicht den echten ASKA-Speichervorgang.

## Sicherungen und Updates

Anleitungen: `docs/world-backups.md`, `docs/steam-updates.md`. Archive: `data/world-backups/<id>/`. Drei Tage bedeuten **72 Stunden rollend**; Bereinigung nach neuem verifiziertem Erfolg, angeheftete Stände bleiben.

Konfigurationsbackup ist kein Weltbackup. Schreibvorgänge regulär beenden, Quelle/Rückweg prüfen. Review fand falschen ASKA-Ordner und übergangene Scanfehler: partielle Archive nicht als Erfolg melden. Symfony benötigt `ext-zip`.

SteamCMD separat `%LOCALAPPDATA%\GameServerIndy\tools\steamcmd`; Pfad bleibt privat. Manifeststand und Steam-Metadaten unterscheiden. Netz-/Steamarbeit im Metadatenworker, nicht im Statuspoll. Reale Updates/Neustarts nur innerhalb des Auftrags.
