# Enshrouded Indy

Lokale Browseroberfläche für den **Windows Dedicated Server von Enshrouded**. Server starten, regulär stoppen und neu starten, Konfiguration bearbeiten, Logs lesen und Nachrichten an Discord senden.

Voreingestellte Installation: `D:\SteamLibrary\steamapps\common\EnshroudedServer`

## Start auf diesem Rechner

In WSL, mit Python **3.11 oder neuer**:

```bash
cd /home/korvegan/projects/enshrouded-indy
bash start.sh
```

Danach **http://localhost:8765** im Windows-Browser öffnen. Alternativ unter Windows `start.cmd` ausführen; dieser Starter verwendet den obigen WSL-Projektpfad. Es werden keine zusätzlichen Python- oder Node-Pakete benötigt. Python und der lokale Dienst müssen laufen, solange die Oberfläche benutzt wird.

Direkt aus Windows PowerShell:

```powershell
wsl.exe --cd /home/korvegan/projects/enshrouded-indy python3 -m enshrouded_manager
```

Alternativ läuft der Dienst mit einer Windows-Python-Installation (`py -3 -m enshrouded_manager`) aus einer lokalen Kopie des Projekts. Die Prozesssteuerung nutzt Windows PowerShell 5.1. Unter WSL muss Windows-Interop verfügbar sein. Verwaltung und Spielserver unter demselben Windows-Benutzer starten.

## Erstes Setup

1. Die Übersicht erkennt das Serverprogramm im hinterlegten Installationsordner.
2. **Server vorbereiten** erzeugt eine fehlende `enshrouded_server.json`. Eine vorhandene Datei wird geprüft und beibehalten. Der Server muss gestoppt sein.
3. Unter **Einstellungen** Servername, Spielerplätze, Schwierigkeit, Chat und Gruppenpasswörter setzen. Die vier anfänglichen Gruppen Admin, Friend, Guest und Visitor erhalten unterschiedliche zufällige Passwörter. „Anzeigen“ blendet sie ein.
4. **Server starten** startet die EXE mit dem Installationsordner als Arbeitsverzeichnis. „Prozess läuft“ beschreibt den Windows-Prozess; die Bereitschaft zum Verbinden lässt sich im **Serverlog** prüfen, z. B. anhand von `HostOnline`.

Das Setup startet den Spielserver nicht automatisch. Er kann beim ersten Spielstart zusätzliche Standardeinstellungen ergänzen. Eigene Gameplay-Werte unter `gameSettings` benötigen `gameSettingsPreset: "Custom"`.

Bei einem anderen Installationsort `manager.example.json` nach `manager.local.json` kopieren und `server_directory` ändern. Danach die Verwaltung neu starten. Für einen anderen lokalen Port:

```bash
bash start.sh --port 8766
```

## Konfiguration und Stoppen

Der Server muss zum Bearbeiten gestoppt sein. Vor jeder Änderung kopiert die Verwaltung die bisherige Konfiguration nach `data/backups/`, prüft die neue Konfiguration und ersetzt die Originaldatei atomar. Unbekannte Felder bleiben beim Bearbeiten über das Formular erhalten. Der ausklappbare JSON-Editor erlaubt auch Änderungen an Spielregeln, Rollenrechten, Speicherpfaden und weiteren Serverfeldern; sein eigener Speicherbutton übernimmt den gesamten Inhalt.

**Stoppen** sendet Strg+C an die Konsole genau dieser Installation und wartet bis zu 45 Sekunden auf das Ende. Bei einem Fehler bleibt der Prozess aktiv: Im Serverfenster manuell Strg+C drücken. Es gibt keinen automatischen erzwungenen Abbruch. Ein Neustart startet erst, wenn das Stoppen erfolgreich war. Wurde der Server außerhalb der Verwaltung gestartet, muss seine Konsole für denselben Windows-Benutzer zugänglich sein.

`data/backups/` enthält Konfigurationssicherungen, keine Weltspielstände. Weltdateien liegen im in `saveDirectory` angegebenen Verzeichnis. Für eine manuelle Weltsicherung zuerst den Server stoppen und dieses gesamte Verzeichnis kopieren.

Die Verwaltung selbst mit Strg+C beenden. Der Spielserver läuft dabei weiter. Die Anwendung installiert keinen Autostart, Windows-Dienst oder Update-Automatismus. Steam bleibt für die Serverupdates zuständig.

## Discord

1. Im gewünschten Discord-Kanal **Kanal bearbeiten → Integrationen → Webhooks** öffnen und einen Webhook anlegen.
2. Die kopierte URL unter **Discord** hinterlegen und die Verbindung speichern.
3. Eine eigene Nachricht schreiben und **An Discord senden** wählen.

Optional Nachrichten bei Start, Stopp und Neustart **über diese Verwaltung** einschalten. Sie melden Prozessaktionen; Abstürze und manuelle Starts außerhalb der Oberfläche werden in dieser ersten Version nicht automatisch an Discord gemeldet. Discord-Ausfälle ändern den erfolgreichen Serverstart nicht und erscheinen als Hinweis. Erwähnungen erzeugen keine Pings.

Alternativ den Webhook vor dem Start als Umgebungsvariable `DISCORD_WEBHOOK_URL` setzen; sie hat Vorrang vor dem lokal gespeicherten Wert. In diesem Fall die Variable entfernen, um den Webhook vollständig zu deaktivieren. Ein leer gelassenes URL-Feld behält den bisherigen Webhook. „Gespeicherten Webhook entfernen“ löscht ihn aus der lokalen Datei.

`manager.local.json`, `data/` und `.env` sind von Git ausgeschlossen. Webhook-URLs und Serverpasswörter gehören nicht ins Repository. Die Webhook-URL wird nicht an den Browser zurückgegeben. Gruppenpasswörter sind zum lokalen Bearbeiten in der Konfiguration zugänglich.

## Netzwerk

Die Verwaltungsoberfläche bindet ausschließlich an `127.0.0.1`. Host- und Origin-Prüfungen sowie ein Sitzungstoken schützen die schreibenden API-Aufrufe. Die Verwaltung ist für einen vertrauenswürdigen lokalen Rechner gedacht; es gibt keine Benutzerkonten oder Freigabe ins LAN.

Der Spielserver verwendet standardmäßig `queryPort: 15637`. Windows-Firewall und gegebenenfalls Router müssen separat für die benötigten Spielverbindungen eingerichtet werden. Das Setup verändert keine Firewall- oder Routerregeln. Die Erreichbarkeit über das Internet ist damit noch nicht bestätigt.

## Entwicklung und Prüfung

```bash
python3 -m unittest discover -s tests -v
node --check web/app.js
python3 -m enshrouded_manager status
```

Die Tests arbeiten in temporären Installationen mit einem simulierten Prozessadapter und gemocktem Discord. Sie prüfen insbesondere das Sperren laufender Konfigurationen, die Originaldatei bei fehlerhaften Änderungen, Backups, Start-/Stopp-Reihenfolgen, unbekannte Felder, lokale API-Zugriffskontrollen und Discord-Fehler. Es werden dabei weder der echte Server gestartet noch Discord-Nachrichten verschickt. Node wird nur für die optionale JavaScript-Syntaxprüfung benötigt.

Die Windows-Anbindung lässt sich zusätzlich mit einem temporären Testprozess prüfen:

```bash
ENSHROUDED_WINDOWS_TEST=1 python3 -m unittest discover -s tests -p test_windows_bridge.py -v
```

Für die optionale Browserprüfung in zwei Terminals:

```bash
# Terminal 1: isolierte Testinstallation auf Port 8766, ohne echten Server/Discord
python3 tests/browser_fixture.py

# Terminal 2: Playwright ausschließlich als Prüfwerkzeug installieren
npm install --prefix /tmp/enshrouded-browser-qa --no-audit --no-fund playwright
/tmp/enshrouded-browser-qa/node_modules/.bin/playwright install chromium
NODE_PATH=/tmp/enshrouded-browser-qa/node_modules node tests/browser-smoke.cjs
```

Die Browserprüfung bedient die Oberfläche einschließlich Erstkonfiguration, regulären und fehlerhaften Änderungen, Passwortanzeige, Discord, Serveraktionen und mobilen Ansichten. Screenshots liegen anschließend im ignorierten Ordner `artifacts/`. Falls Chromium bereits installiert ist, kann `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` auf dessen ausführbare Datei verweisen.

## Quellen

Die mitgelieferte `enshrouded_server_readme.txt` der Installation (Version 0.9.0.0) ist die Grundlage für die aktuellen Felder und Gruppenrechte.

- [Offizielle Serverkonfiguration](https://enshrouded.zendesk.com/hc/en-us/articles/16055441447709-Dedicated-Server-Configuration)
- [Offizielle Rollen und Passwörter](https://enshrouded.zendesk.com/hc/en-us/articles/19191581489309-Server-Roles-Configuration)
- [Server-FAQ und Startbereitschaft](https://enshrouded.zendesk.com/hc/en-us/articles/16056312924957-Dedicated-Server-FAQ)
- [Discord: Execute Webhook](https://docs.discord.com/developers/resources/webhook#execute-webhook)
