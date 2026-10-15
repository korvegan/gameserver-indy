---
name: gameserver-indy
description: GameServer Indy auf korvegans Windows-/WSL-Rechner weiterentwickeln und betreiben; Spieladapter, Mods, Discord, Familienzugriff und Sturmklingen-Gemeinschaft planen und ergänzen. Verwenden für dieses Repository sowie seine Enshrouded-/ASKA-Integration; bestätigte Betriebserkenntnisse pflegen.
---

# GameServer Indy

Das Projekt verwaltet native Windows-Dedicated-Server über einen lokalen Python-Dienst in WSL. Den bestehenden Python-/HTML-/JS-Aufbau fortführen. Quellen mit gezielten Patches ändern; Python für Backend, Datenverarbeitung und Prüfungen verwenden, nicht als spontanen Texteditor.

Die dauerhafte Freigabe für autonomes Arbeiten einschließlich Mergen steht in `AGENTS.md`. Bei Werkzeug-, Plugin- und Ablaufentscheidungen sowie vor Arbeiten an den neuen Themen [Werkzeugstand und Arbeitsmethoden](references/tooling.md) hinzuziehen. Dort zwischen tatsächlich geprüften Fähigkeiten, verfügbaren Werkzeugen und noch einzurichtenden Integrationen unterscheiden.

## Umgebung

- Repository `korvegan/gameserver-indy`; Python-Paket `gameserver_manager`, Einstieg `python3 -m gameserver_manager`. Browser: `http://127.0.0.1:8765`. `localhost` kann hier zuerst IPv6 versuchen und scheitern.
- WSL-Distribution `Indy`, Linux- und Windows-Benutzer `korvegan`. Launcher unter `%LOCALAPPDATA%\GameServerIndy`; nach Launcher- oder Checkout-Pfadänderungen den versionierten Shortcut-Installer ausführen.
- Spielprofile unter `profiles/`, lokale Instanzen in der ignorierten `manager.local.json`. Profilwahl pro Browser; schreibende Aktionen mit ausdrücklicher Profil-ID. Kein globaler aktiver Server als Prozessziel.
- Schnelle Profilwechsel konnten früher von verspäteten Antworten zurückgesetzt werden. Seit dem Frontend-Refactoring verhindern ausdrückliche Anfrageziele, Abbruch alter Statusanfragen und Auswahlgenerationen diesen Fehler. Der Selektor bleibt während eines Auftrags/Statusabrufs bedienbar. Antworten müssen auch nach Navigation zum ursprünglichen Profil und zur passenden Protokollquelle gehören; nicht zur alten Sperrstrategie zurückkehren.
- Die Profilauswahl unabhängig von der Statusabfrage laden und bedienbar halten: Eine defekte Spielkonfiguration darf den Wechsel zu gesunden Serverprofilen nicht sperren. Dafür existiert eine Browser-Regression.
- Die Profilauswahl bleibt auch in Spiele-/Gemeinschaftsansichten sichtbar, damit die Navigation ihre Position behält. Das frühere Ausblenden ließ das Menü auf Desktop und Mobilgeräten springen; eine Browser-Regression prüft nun dessen Position und Größe beim Ansichtswechsel.
- Windows-Prozesse anhand des vollständigen kanonischen EXE-Pfads erkennen. Windows kann 8.3-Pfade melden; der Prozessadapter normalisiert diese. Kein Stopp allein anhand des Prozessnamens.
- Bereits umgewandelte Windows-Pfade erkennen, einschließlich UNC-Pfaden wie `\\wsl.localhost\Indy\...`. Erneutes `wslpath -w` auf einem solchen UNC-Pfad führte im Integrationstest zu „Serverprogramm nicht gefunden“.
- WSL-Argumentnamen beim Windows-Start unquoted lassen; nur Werte mit Leerzeichen quoten. Ein vorheriger Kaltstart scheiterte an gequotetem `--distribution`.

## Spiele

Enshrouded: `D:\SteamLibrary\steamapps\common\EnshroudedServer`. Die Gruppe möchte mit 1.0 eine neue Welt beginnen. Dafür einen neuen Weltordner verwenden, bestehende Welten nicht löschen. Nach Updates Felder und Grenzen mit der installierten Version abgleichen; unbekannte Felder erhalten.

Am 15.10.2026 wurden Build 25936466 und die mitgelieferte Anleitung 1.0.0.0 geprüft: `gameSettings.flameLevelFogRemoval` hat Standard 11 und Bereich 0–20; `randomSpawnerAmount` ergänzt `Automatic`. Die EXE-Typinformationen bestätigen die Verschachtelung unter `GameSettings`; die neue Option nicht an die JSON-Wurzel schreiben. Die EXE besitzt hier keine Windows-Dateiversion. Gameplay-Vorlage und vorhandene Gegnerwahl bei einer Kompatibilitätsergänzung erhalten.

ASKA: `D:\SteamLibrary\steamapps\common\ASKA Dedicated Server`. Die aktive Konfiguration liegt **außerhalb** von Steam unter `C:\Users\korvegan\Documents\workspace\Aska\config\server properties.txt`. `ASKA starten.cmd` auf dem Desktop verweist auf `start-aska-discord.ps1` in diesem Projekt. Die Vorlage im Steam-Ordner gehört nicht zur bestätigten Bestandswelt.

Der vorhandene ASKA-Starter prüft eine feste Save-ID und den Weltordner im Windows-Benutzerprofil; diese Welt erhalten. Er übergibt `-propertiesPath`, setzt SteamAppId/SteamGameId 1898300 und sendet selbst eine Discord-Meldung nach `The session is now open!`. Keine zweite Dashboard-Startmeldung senden. Die Windows-DPAPI-Anbindung bleibt getrennt und wird nicht importiert oder ausgegeben. Rohe Unity-Logs können Zugangsdaten enthalten.

Am 15.10.2026 hat der Nutzer den erfolgreichen Beitritt und die richtige Bestandswelt im Spiel bestätigt. Die wiederholten Shader-Meldungen traten auch bei diesem erfolgreichen Start auf; sie allein belegen keinen Startfehler. Ihre Zusammenfassung ist als Issue #12 geplant.

- Der Windows-PowerShell-5.1-Prozess erbt hier PowerShell-7-Pfade in `PSModulePath`. `ConvertTo-SecureString` scheiterte mit `CouldNotAutoloadMatchingModule`, ein Import nur nach Modulnamen mit doppelten Typdaten. Der explizite Import von `$PSHOME\Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1` ermöglicht die Entschlüsselung des bestehenden Webhooks im selben Konto. Diesen Import im Dashboard-Runner erhalten; keine neue Discord-Einrichtung daraus ableiten.
- Der bestehende ASKA-Starter leitet Unity-Ausgaben um und verwirft sie nach der Markerprüfung. `scripts/enable-aska-logs.ps1` ergänzt mit Sicherung einen optionalen Hook; dieser ist auf Indy eingerichtet. Der Dashboard-Runner schreibt nur bereinigte Ausgaben in profilbezogene `logs/server.log` und `logs/launcher.log`, mit Rotation. Ungefilterte Ausgaben nicht mit Transcript oder einfachen Redirects speichern. Das alte `Player.log` kann von einem früheren Lauf stammen; Datum und Starterprotokoll prüfen.
- Ein externer Starter kann länger als zwei Sekunden vorbereiten. Auf tatsächlichen Serverprozess, Starterende oder den Status „Starter arbeitet“ prüfen. Ein laufender Starter sperrt doppelte Starts und Konfigurationsänderungen; nur seine aufgezeichnete PID mit passender Startzeit übernehmen. Prozessbeginn bestätigt weiterhin keine offene Spielsitzung.

Konfigurationssicherungen sind keine Weltsicherungen. Spielstände erst sichern, nachdem deren Schreibvorgänge regulär beendet wurden; bei ASKA liegt die Welt im Windows-Benutzerprofil.

Leistungsstand vom 15.10.2026: i9-12900KF, rund 96 GB RAM, RTX 3080 mit 10 GB VRAM, Desktop 5120×1440. Vier Spieler einschließlich Host; Spielclient und Server teilen den Rechner. Native Windows-Server werden nicht durch die WSL-Grenzen von 32 GB/12 vCPUs begrenzt. Ein Speedtest ergab 10,6 Mbit/s Upload und 9 ms Leerlauflatenz; das ist keine dauerhafte Zusicherung. Vor weiteren Performance-Eingriffen unter echter Mehrspielerlast messen.

## Erweiterung und Prüfung

Bei neuen Spielen zuerst Herstelleranleitung, Konfiguration, EXE, Speichern und Stoppverfahren prüfen. Das Spielprofil beschreibt Formularfelder und Startmetadaten; der Adapter implementiert Dateiformat, Validierung und Sonderfälle. Einstellungen eines Spiels nicht auf andere übertragen.

Die Dateien `docs/architecture.md` und `docs/backlog.md` im Repository beschreiben den Ausbau ab #27. Instanzdienste liegen getrennt unter `instances.py`, `instance_config.py`, `operations.py`, `processes.py`, `instance_logs.py` und `discord.py`; `core.py` erhält die bisherigen Importnamen. In Tests das tatsächlich verwendete Modul patchen, nicht die Kompatibilitätsimporte. Die globale Registry-Sperre schützt nur gemeinsame Metadaten; lange Operationen verwenden eigene Instanzsperren und die Auftragsverwaltung.

`/api/status` ist eine begrenzte Datensicht, `/api/config` und das kompatible `/api/state` sind Besitzerzugriffe. Der lokale Besitzeradapter ist keine fertige Familienanmeldung; Proxy-/Rollenheader werden nicht vertraut. SQLite unter `data/platform.sqlite3` hält Aufträge und Ereignis-/Szenen-/Versandzuordnungen getrennt von Bestandsdateien. Unterbrochene Aufträge nicht automatisch erneut ausführen, unklaren Discord-Versand nicht blind wiederholen. `python3 -m gameserver_manager demo` prüft den Gemeinschaftsvertrag ausschließlich mit temporären Daten und einem Stub.

Die lesende Steam-Erkennung ist in `steam.py`/`updates.py` getrennt; Einrichtung und belegte Grenzen stehen in `docs/steam-updates.md`. Auf Indy liegt separates SteamCMD unter `%LOCALAPPDATA%\GameServerIndy\tools\steamcmd`; der absolute Pfad gehört ausschließlich in lokale Einstellungen. Bootstrap/Selbstupdate vor Einbindung abschließen und bei einer aus WSL geschriebenen EXE deren Ausführbarkeit prüfen. `UpToDateCheck` lieferte für die beiden Server-Apps keinen Befund; nicht als erfolgreiche Aktualitätsprüfung werten. Steam-Build, dokumentierte Spielversion und laufender Prozess sind getrennte Belege. Zeitstempel allein belegen kein aktuelles Steam-Angebot.

Frontendzustand liegt zentral in `web/state.js`; API, Formulare, Navigation, Aufträge und fachliche Ansichten sind eigene native Module. Statuspolls dürfen keine Besitzerkonfiguration laden und keine Gemeinschaftsszene neu aufbauen. Im separaten Sub-Agent-Review wurden zwei konkrete Backendfehler gefunden und korrigiert: Ein vorübergehender Speicherfehler muss einen Auftrag als unterbrochen/unklar abgleichen, statt ihn ewig laufend anzuzeigen; alte Ereignisrevisionen dürfen neuere Discord-Korrekturen nicht zurücksetzen. Beide Fälle haben eigene Regressionen.

Bei weiter bedienbaren Discord-Feldern darf eine verspätete Speicherbestätigung nur die abgeschickte Entwurfsrevision bestätigen. Automatische Navigation nach Startfehlern braucht den unveränderten Auswahl-/Navigationskontext; nach Neuladen wiederentdeckte Aufträge dürfen keine Ansicht übernehmen. Beide Frontendfehler sind mit verzögerten Browserantworten abgesichert.

`python3 -m unittest discover -s tests -v`, `npm run check:js` und bei UI-Änderungen `npm run test:browser` verwenden. Prozessänderungen mit temporären Windows-Testservern prüfen. Prozess läuft, Sitzung bereit und erfolgreicher Spielerbeitritt sind unterschiedliche Prüfergebnisse. Der reguläre Stopp mit dem bestehenden ASKA-Starter muss an der echten Welt noch bestätigt werden.

## Lernen bei weiterer Arbeit

Beitrittsadressen werden über `connections.py`/`scripts/windows-network.ps1` am nativen Windows-Host ermittelt; die WSL-Adresse gehört nicht zum Spielserver. Die bestätigte Enshrouded-IP-Suche verwendet IP plus konfigurierten Query-Port. Eine öffentliche IP belegt keine Routerfreigabe oder Erreichbarkeit; Details und Grenzen stehen in `docs/connection-info.md`. ASKA bleibt bei der Serversuche nach Namen, bis ein direkter Beitrittsweg belegt ist. Statuspolls lesen nur zwischengespeicherte Adressen, keine externe Netzabfrage auslösen.

Beim Abschluss relevanter Arbeiten neue **beobachtete** Fehlerursachen, wirksame Abhilfe und verbleibende Prüflücken kurz ergänzen oder bestehende Aussagen korrigieren. Passende Regressionstests im Projekt vorziehen; keine Sammlung bloßer Vermutungen anlegen. Veraltete Hinweise entfernen, sobald aktuelle Belege sie widerlegen. Das Repository ist die Quelle; `SKILL.md` und verwendete Referenzen nach Änderungen nach `~/.codex/skills/gameserver-indy/` synchronisieren. Das geschieht während beauftragter Arbeiten, nicht durch einen Hintergrunddienst, und erweitert deren Berechtigungen nicht.
