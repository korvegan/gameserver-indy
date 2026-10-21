# GameServer Indy

Die schrittweise **Symfony-Migration** ist in [Epic #45](https://github.com/korvegan/gameserver-indy/issues/45) aufgeteilt. Der isolierte erste Durchstich liegt unter `symfony/`; Start, Prüfungen und Grenzen stehen in [docs/symfony-migration.md](docs/symfony-migration.md). Die bestehende Verwaltung bleibt bis zum geplanten Cutover der produktive Einstieg.

Der geprüfte [Technik- und Versionsstand](docs/tech-stack.md) unterscheidet laufende Verwaltung, Symfony-Zielsystem und Entwicklungswerkzeuge. Die Node-Version für Entwicklung und Browser-CI steht in `.node-version`; Bibliotheken bleiben in den beiden Lockfiles festgehalten.

Lokale Browseroberfläche für Windows-Dedicated-Server. **Enshrouded und ASKA** haben eigene Konfigurationsprofile. Start, regulärer Stopp, Neustart, Konfigurationssicherungen und Discord teilen sich eine gemeinsame Verwaltung. Weitere Spiele werden über Adapter ergänzt.

## Start

**GameServer Indy** auf dem Desktop öffnet **http://127.0.0.1:8765** und startet bei Bedarf die Verwaltung. Es startet keinen Spielserver. Im Browser das gewünschte Serverprofil auswählen.

Alternativ im Checkout mit Python 3.11 oder neuer:

```bash
bash start.sh
python3 -m gameserver_manager status --server enshrouded
python3 -m gameserver_manager status --server aska
```

Mit Windows-Python: `py -3 -m gameserver_manager`. Für den Betrieb sind keine zusätzlichen Python- oder Node-Pakete nötig. Der Dienst bindet nur an `127.0.0.1`; Host-/Origin-Prüfungen und ein Sitzungstoken schützen Änderungen. Verwaltung und Spielserver unter demselben Windows-Konto ausführen.

## Profile

- `profiles/enshrouded.json` und `profiles/aska.json`: Formularfelder, Kennzahlen und Startmetadaten eines Spiels.
- `gameserver_manager/games.py`: Adapter für Dateiformat, Validierung, Standardwerte und Besonderheiten.
- `manager.local.json`: lokale Serverprofile mit ID, Spiel, Namen, Installationsordner und optional externer Konfiguration/Starter. Von Git ausgeschlossen.
- **Serverprofile** im Browser fügt vorhandene Installationen hinzu. Dabei werden keine Spiele installiert oder Welten verändert.

Die bisherige Einzelserver-Konfiguration wird als Enshrouded-Profil übernommen und beim Speichern eines weiteren Profils lokal erweitert. Discord bleibt gemeinsam konfiguriert. Jede Aktion trägt ihre Profil-ID; die Auswahl ist pro Browser und verändert keinen laufenden Server. Pro Installationsordner ist ein Profil möglich, da Prozesse über ihren vollständigen EXE-Pfad zugeordnet werden.

### Enshrouded

Installation: `D:\SteamLibrary\steamapps\common\EnshroudedServer`.

**Server vorbereiten** erzeugt nur eine fehlende `enshrouded_server.json`. Die vier Benutzergruppen erhalten zufällige Passwörter. Unbekannte Felder bleiben beim Bearbeiten erhalten. Eigene Gameplay-Werte benötigen `gameSettingsPreset: "Custom"`.

**Weltordner** steuert `saveDirectory`: Ein neuer, leerer Ordner erzeugt beim nächsten Spielserverstart eine neue Welt; ein bestehender setzt dessen Welt fort. Die alte Welt wird nicht gelöscht. Neue Felder und Grenzen nach Spielupdates müssen anhand der ausgelieferten Version abgeglichen werden.

**Mitspielen** in der Übersicht zeigt kopierbare Beitrittsadressen für denselben PC, das Heimnetz und externe Mitspieler. Die öffentliche IPv4 wird im Hintergrund ermittelt; ihre Anzeige bestätigt keine Erreichbarkeit von außen. Details zu Router, Firewall und weiteren Spielen stehen unter [Beitrittsinformationen](docs/connection-info.md).

Für 1.0 sind die Flammenstufe zum Bauen im Miasma (`gameSettings.flameLevelFogRemoval`, 0–20, Standard 11) und die Gegneranzahl einschließlich `Automatic` im Formular verfügbar. Vorhandene Spielregeln werden erhalten; Details und Quellen stehen unter [Steam-Updates und Enshrouded 1.0](docs/steam-updates.md).

### ASKA

Installation: `D:\SteamLibrary\steamapps\common\ASKA Dedicated Server`.

ASKA verwendet `server properties.txt` und `-propertiesPath`. Externe Konfigurationen werden von Steam-Updates nicht überschrieben. Kommentare, unbekannte Felder, BOM und Zeilenenden bleiben erhalten. Passwort und Token bleiben im Browser leer; leere Werte behalten die gespeicherten Zugangsdaten. Ein Passwort kann ersetzt, derzeit aber nicht im Formular entfernt werden.

Auf Indy wird die **aktive externe Konfiguration** unter `C:\Users\korvegan\Documents\workspace\Aska\config\server properties.txt` verwendet. Der vorhandene `start-aska-discord.ps1` lädt die bestätigte Bestandswelt und versendet seine eigene Discord-Meldung nach offener Sitzung. Dafür sendet das Dashboard keine zusätzliche Startmeldung. Die Save-ID bleibt bei externem Starter gebunden.

Ohne externen Starter setzt die Windows-Anbindung SteamAppId/SteamGameId 1898300 und übergibt den Konfigurationspfad direkt. Ein Gameserver-Token für Spiel-App-ID 1898300 ist nötig. Die Download-App-ID des Servers ist 3246670.

Grundlage: mitgelieferte **ASKA Dedicated Server Setup - EN.pdf** und installierte Konfigurationsvorlage. Die Anleitung beschreibt Ctrl+C als regulären Stopp. Der Stopp mit dem vorhandenen Starter an der echten Welt bleibt im Betrieb zu bestätigen; automatisierte Prüfungen nutzen Testprozesse.

## Serverlogs

Unter **Serverlog** zwischen **Spielserver** und **Starter / Startfehler** wechseln. Die Ansicht zeigt die letzten 200 Zeilen und den Dateistand; sie aktualisiert sich alle acht Sekunden. Bei einem fehlgeschlagenen Start öffnet sich das Starterprotokoll automatisch. Ein noch arbeitender externer Starter wird als solcher angezeigt und gegen doppelte Starts gesperrt.

Passwörter, Tokens und Discord-Webhooks werden vor der Anzeige ausgeblendet. Enshrouded verwendet seinen konfigurierten Logordner, ASKA zunächst `Player.log` bzw. `Player-prev.log` im Windows-Benutzerprofil. Deren Datum beachten: Ein alter Lauf erklärt keinen neuen Startfehler. Für abweichende Installationen kann das lokale Serverprofil einen absoluten `log_directory` enthalten.

Der bestehende ASKA-Starter verwirft normalerweise seine umgeleitete Serverausgabe. Einmalig den folgenden Integrationsschritt in Windows PowerShell ausführen, um diese Ausgabe bei Dashboard-Starts bereinigt zu protokollieren. Das Skript legt vorher eine Sicherung neben dem Starter an und lässt sich wiederholt ausführen:

```powershell
.\scripts\enable-aska-logs.ps1 -LauncherScript 'C:\Users\korvegan\Documents\workspace\Aska\start-aska-discord.ps1'
```

Auf Indy ist dieser Schritt eingerichtet. Die neuen Protokolle liegen im jeweiligen Datenordner unter `logs/`, für ASKA unter `data/servers/aska/logs/`. Der externe Starter erhält ein eigenes Protokoll; sein Server-Ausgabestrom hat Vorrang vor `Player.log`. Beide Dateien rotieren bei etwa 2 MiB mit jeweils einer Vorgängerdatei. Die ursprünglichen Unity-Dateien werden nicht verändert.

## Betrieb und Backups

**Steam-Updates** in der Übersicht zeigt installierten/angebotenen Build, Branch, Quellen und Prüfzeit. **Steam-Updates prüfen** startet einen lesenden Hintergrundauftrag. Nach Einrichtung von `steamcmd_path` wird auch etwa alle 30 Minuten geprüft. Alte oder fehlgeschlagene Befunde bleiben erkennbar; der Build eines laufenden Prozesses wird nicht aus bereits aktualisierten Dateien abgeleitet. [Einrichtung, Zustände und Grenzen](docs/steam-updates.md).

Bearbeiten nur bei gestopptem Server. Zuerst wird die Konfiguration exakt gesichert, dann atomar ersetzt. Enshrouded behält `data/backups/`; weitere Profile verwenden `data/servers/<id>/backups/`.

**Weltsicherungen** sichert den vollständigen Weltordner mit seiner Konfiguration, prüft SHA-256 und bewahrt nicht angeheftete Stände drei Tage rollend auf. Optional nach regulärem Dashboard-Stopp sichern. Wiederherstellen bleibt eine Besitzeraktion mit Zielvorschau und vorheriger Rückwegsicherung. [Bedienung, Speicherorte und Wiederherstellung](docs/world-backups.md).

**Konfigurationssicherungen enthalten keine Weltspielstände.** Für eine Weltsicherung regulär stoppen und den vollständigen Spielstandordner kopieren. ASKA speichert im Windows-Benutzerprofil.

Stoppen sendet Ctrl+C an die Konsole der ausgewählten Installation und wartet bis zu 45 Sekunden. Kein erzwungener Abbruch. Ist die Konsole nicht erreichbar, im Serverfenster stoppen. Neustart erst nach erfolgreichem Stopp.

„Prozess läuft“ bestätigt keinen Beitritt. Enshrouded meldet Bereitschaft mit `HostOnline`; der vorhandene ASKA-Starter beobachtet `The session is now open!`. Steam verwaltet weiterhin Spielupdates. Kein Update- oder Mod-Automatismus.

## Discord

Unter **Discord** den Dashboard-Webhook hinterlegen und optional Prozessmeldungen einschalten. Ein leeres URL-Feld behält den Webhook; die Checkbox entfernt ihn. Die URL wird nicht zurückgegeben. `DISCORD_WEBHOOK_URL` hat Vorrang. Discord-Fehler stoppen keinen erfolgreich gestarteten Server; Erwähnungen erzeugen keine Pings.

Der ASKA-Starter behält seine eigene Windows-DPAPI-Anbindung. Sie wird nicht importiert. Der Dashboard-Starter lädt das Verschlüsselungsmodul ausdrücklich aus seiner eigenen PowerShell-Installation: Ein geerbter PowerShell-7-Modulpfad kann sonst unter Windows PowerShell 5.1 die Entschlüsselung verhindern. Manuelle Nachrichten und Stoppmeldungen verwenden den Dashboard-Webhook.

## Desktop-Starter

Aus dem WSL-Checkout in Windows PowerShell:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "\\wsl.localhost\Indy\home\korvegan\projects\gameserver-indy\scripts\install-desktop-shortcut.ps1" -WslUser korvegan
```

Der Installer erkennt Distribution und Projektpfad, kopiert den Starter nach `%LOCALAPPDATA%\GameServerIndy\Start-Manager.ps1` und erstellt **GameServer Indy.lnk**. Nach einem Umzug des Checkouts oder Änderungen am Starter erneut ausführen. Fremde Verknüpfungen werden nicht überschrieben. Weitere Aufrufe verwenden den laufenden Dienst. Fehlerprotokolle: `%LOCALAPPDATA%\GameServerIndy\logs\`. PowerShell-Ausführungsoptionen gelten nur für den jeweiligen Prozess.

## Weitere Spiele ergänzen

1. `profiles/<spiel-id>.json` mit Formularfeldern, Kennzahlen und Startparametern hinzufügen. Textbasierte Dateiformate können für Zahlen `value_type: "string"` angeben.
2. In `gameserver_manager/games.py` einen Adapter mit den Attributen und Methoden der vorhandenen Spiele ergänzen und in `GAME_TYPES` registrieren. CLI und Browser übernehmen die Spielauswahl automatisch.
3. Speichern und regulären Stopp anhand der Herstelleranleitung prüfen. Die gemeinsame Windows-Anbindung unterstützt EXE-Start, ein optionales Konfigurationsargument und vorhandene PowerShell-Starter. Spiele mit RCON, Java oder anderen Stoppverfahren brauchen einen passenden Prozessadapter.
4. Dateiformat, Zugangsdaten und profilbezogene Aktionen isoliert prüfen, dann die Installation als lokales Serverprofil hinzufügen.

Frontend und zentrale HTTP-Routen benötigen keine weiteren spielspezifischen Formulare. Der Eintrag einer EXE allein garantiert keine vollständige Spielunterstützung.

## Entwicklung

```bash
python3 -m unittest discover -s tests -v
npm run check:js
npm run test:browser
```

Die Browserprüfung verwendet temporäre Installationen auf Port 8766, steuert keine echten Server und sendet keine Nachrichten. Screenshots: `artifacts/`. Fehlende Werkzeuge mit `npm ci --no-audit --no-fund` und `npx playwright install chromium` vorbereiten.

Windows-Prozessintegration mit temporären Testservern:

```bash
GAMESERVER_WINDOWS_TEST=1 python3 -m unittest discover -s tests -p test_windows_bridge.py -v
```

Unter Windows prüft `tests/test-desktop-launcher.ps1` die Starter isoliert. `scripts/check-desktop-start.ps1` ist die manuelle Kaltstartprüfung der echten Desktop-Verknüpfung. GitHub Actions prüft Backend auf Linux/Windows, Prozessanbindung, Starter und Chromium.

Für weitere Arbeiten den [projektspezifischen Skill](skills/gameserver-indy/SKILL.md) verwenden und neue bestätigte Fallstricke dort pflegen.

## Ausbau und Architektur

Die [Bewertung aller Ausbauissues](docs/backlog.md) beschreibt Nutzen, Aufwand, Abhängigkeiten und Reihenfolge. Die [Architektur und API-Verträge](docs/architecture.md) erklären Dienstgrenzen, Rollen und Hintergrundaufträge. Unter **Familienzugang** lassen sich persönliche Tailscale-Freigaben je Server verwalten. [Einrichtung und praktische Abnahme](docs/family-access.md) beschreiben den privaten HTTPS-Zugang und Start ohne Windows-Anmeldung. Beide Verwaltungslistener binden weiterhin nur localhost.

Die zusätzliche lokale Datenbank `data/platform.sqlite3` hält Aufträge und Gemeinschaftsreferenzen getrennt von bestehenden Spielkonfigurationen und technischen Logs. Vorhandene Profile, externe ASKA-Dateien und Welten werden dadurch nicht verschoben. Eine unbekannte Datenbankversion wird abgewiesen. Die bisherigen Python-Importnamen unter `gameserver_manager.core` und HTTP-Routen bleiben für den lokalen Besitzer erhalten.

Ein ausführbarer Gemeinschaftstest verwendet ausschließlich temporäre Daten und einen Discord-Stub:

```bash
python3 -m gameserver_manager demo
```

Der Test verbindet einen Gruppenmoment, vier gleiche Auszeichnungen, eine stabile Szenen-ID und genau eine Stub-Mitteilung. Es werden keine echten Errungenschaften oder Discord-Nachrichten erzeugt.

Die Oberfläche zeigt Serveraktionen als **Aufträge** mit Phase und Ergebnis. Während eines Auftrags bleibt ein anderes Profil bedienbar. Die Besitzerkonfiguration wird gezielt unter **Einstellungen** geladen und ist kein Teil der regelmäßigen Statusantwort. Ungespeicherte Entwürfe bleiben bei Navigation erhalten; ein Profilwechsel fragt vor dem Verwerfen nach.

**Starten, Stoppen und Neustarten** bleiben auch während Statusabfragen bedienbar und werden je Server nacheinander ausgeführt. Nach einem fehlgeschlagenen oder unklaren Vorgänger werden wartende Folgebefehle nicht ausgeführt; das Ergebnis nennt den Grund. Die Dashboard-Sitzung bleibt über Verwaltungsneustarts erhalten. Ein ausnahmsweise veralteter Sitzungsschlüssel wird erneuert, ohne den abgewiesenen Befehl automatisch zu wiederholen.

**Spiele / Mods** zeigt den unabhängigen Spielkatalog mit vorhandenen Serverprofilen und dem Stand der Verwaltungsfunktionen. Unter **Sturmklingen → Moment festhalten** können Besitzer gemeinsame Erinnerungen mit Beteiligten, Kampagne, gleicher Auszeichnung und Screenshot erfassen. Filter, weitere Seiten, dauerhafte Detail-Links, versionierte Korrekturen und ein lesbares ZIP-Archiv ergänzen die [Chronik](docs/chronicle.md). Neue Einträge werden angekündigt und erst auf Wunsch geladen. Im normalen Betrieb werden keine Beispieldaten angelegt. Das illustrierte Geschichtsbild, automatische Spielquellen und Discord-Veröffentlichung folgen ihren Fachissues.

JavaScript ist in native Browsermodule für API, Zustand, Formulare, Navigation, Aufträge und Ansichten aufgeteilt. Ein Bundler ist nicht nötig; `npm run check:js` prüft alle ausgelieferten Module. Die Browserfixture hat zusätzliche künstliche Verzögerungen und eine ausschließlich für Tests injizierte Familienidentität. Produktcode vertraut keinem vom Browser gelieferten Rollenheader.
