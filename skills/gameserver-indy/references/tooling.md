# Werkzeugstand und Arbeitsmethoden

Stand der Werkzeugprüfung: 15.10.2026 vor dem Refactoring #27; die damaligen Testzahlen sind eine Bestandsaufnahme. Aktuelle Architektur und Prüfabläufe stehen im Repository unter `docs/architecture.md`. Dies beschreibt beobachtete Fähigkeiten auf Indy, keine Garantie für spätere Sitzungen. Vor Verwendung die tatsächlich angebotenen Werkzeuge und relevanten Versionen prüfen. Hauptcheckout: `/home/korvegan/projects/gameserver-indy`; der frühere Projektpfad `enshrouded-indy` existiert hier nicht mehr.

## Geprüfte Fähigkeiten

| Aufgabe | Vorhandener Weg | Ergebnis und Grenzen |
| --- | --- | --- |
| Repository, Issues, PRs, CI und Merge | Git 2.53.0, `gh` 2.102.0, GitHub-App | Repository-Zugriff mit ADMIN-Rechten über `gh` bestätigt; Issue #27 auch über die App gelesen. Merge-Verfahren verfügbar. `main` hatte beim Audit keinen Branchschutz; trotzdem CI und den geprüften PR-Commit kontrollieren. |
| Backend und HTTP | Python 3.14.4 lokal, Standardbibliothek | 28 Tests bestanden; vier Windows-Tests im normalen Lauf erwartungsgemäß übersprungen. CI prüft Python 3.11 auf Linux und Windows. |
| Frontend | Node 24.18.0, npm 11.16.0, Playwright 1.64.1 | JS-Syntaxprüfung und Browserprüfung für beide Spiele, Desktop und Mobilansicht bestanden. |
| Interaktive Browserprüfung | `ritter-playwright` MCP | Eigener temporärer Tab angelegt, DOM-Abfrage erfolgreich, eigenen Tab geschlossen. Keine Spielaktionen ausgeführt. |
| Windows-Prozesse und DPAPI | Native Windows PowerShell 5.1 aus WSL | Alle vier Integrationstests bestanden: Start, Argumente, Prozesszuordnung, regulärer Stopp der Testprogramme, externer Starter, bereinigte Logs und DPAPI-Roundtrip. Das bestätigt keinen echten ASKA-Weltstopp. |
| Desktop-Starter | `tests/test-desktop-launcher.ps1` | Isolierte Installation, Kaltstart, Wiederverwendung, Pfade mit Leerzeichen und Startfehlerdiagnose bestanden. |
| Bilder und Animationen | HTML/CSS/SVG/Canvas, Bildgenerierungswerkzeug, FFmpeg 8.0.1 | FFmpeg startet; Bildgenerierung ist angeboten, wurde im Audit nicht ausgelöst. Szenen im Dashboard bevorzugt mit Code darstellen; Bildwerkzeuge für passende Bildassets hinzuziehen. Exportpipeline noch zu entwickeln und zu testen. |

Die Prüfungen verwenden temporäre Konfigurationen und Testprozesse. Im Audit wurden keine echten Spielserver gestartet und keine Discord-Nachrichten versendet. Keine Webhooks, Tokens oder Passwörter in Berichte, Screenshots oder Git übernehmen.

## Behobene Lücke und Werkzeugfallen

- Zur installierten Playwright-Version fehlten die passenden Browserdateien. `npx playwright install chromium` hat Chromium und Headless Shell Revision 1248 sowie FFmpeg 1013 ergänzt; `npm run test:browser` besteht jetzt ohne Browserpfad-Override. Nach Änderungen am Lockfile zunächst `npm ci --no-audit --no-fund`, dann den passenden Browser installieren. Alte Cachepfade nicht fest verdrahten.
- Codex CLI 0.162.1 führt `js_repl` als entfernt, und das Werkzeug wird in dieser Sitzung nicht angeboten. Der installierte Skill `playwright-interactive` setzt es weiterhin voraus. Deshalb hier den vorhandenen Browser-MCP oder die versionierten Playwright-Tests verwenden; kein entferntes Feature aufgrund dieser älteren Anleitung aktivieren.
- Der MCP namens `indy` ist ein Forgejo-Workflowdienst. Sein Status war gesund, seine Workspace-/Repository-Listen waren aber leer. Er ist damit kein eingerichteter GitHub-Workflow für dieses Projekt; `gh` und die GitHub-App verwenden.
- Der Plugin-Katalog meldete GitHub als installiert, eine Berechtigungsabfrage dagegen als `not_installed`. Der anschließende echte Lesezugriff funktionierte. Bei widersprüchlichen Metadaten eine ungefährliche konkrete Aktion prüfen, bevor eine Neuverbindung empfohlen wird. Keine Berechtigungserweiterung war nötig.
- Suchanfragen nach Discord und Nexus Mods lieferten keine passenden Plugins. Das behindert die Produktentwicklung nicht: bestehende Webhooks weiterverwenden und Modquellen über eigene Adapter anbinden. Ein Codex-Plugin ersetzt keine Integration im ausgelieferten Dashboard. Vercel/Sites werden für den lokalen Betrieb nicht benötigt.

## Konfiguration und Skills

Die Projektautorisierung steht in `AGENTS.md`; sie übersteht neue Sitzungen und wird nicht mit technischen Sandboxrechten verwechselt. Die gespeicherten Codex-Defaults waren `workspace-write`/`on-request`, diese Sitzung läuft mit Vollzugriff ohne Freigabeabfragen. Globale Sicherheitsdefaults wurden nicht geändert. Wenn eine andere Sitzung technisch eingeschränkt ist, deren tatsächliche Grenzen beachten und vorhandene Auftragsfreigaben beibehalten.

Der Projektskill enthält Host- und Spielwissen. Für Änderungen daran `skill-creator` verwenden, die Repository-Version pflegen, validieren und samt Referenzen in die installierte Kopie übernehmen. `openai-docs` bei Codex-Konfiguration und Selbstdiagnose verwenden; `plugin-management` bei einer konkreten Fähigkeitslücke. Den Bildskill bei geeigneten Bildassets lesen. Es braucht derzeit keine weiteren Plugins oder einen separaten Skill für jedes Backlog-Issue.

Für aktuelle Codex-Funktionen lokale CLI-/Werkzeugbefunde mit der [offiziellen Konfigurationsdokumentation](https://learn.chatgpt.com/docs/config-file/config-basic) und den [Projektanweisungen über AGENTS.md](https://learn.chatgpt.com/docs/agent-configuration/agents-md) abgleichen. Spiel-, Provider- und API-Eigenschaften vor der Umsetzung anhand ihrer aktuellen Herstellerdokumentation prüfen.

## Methoden für die beauftragten Themen

| Themen | Vorgehen und noch benötigte Nachweise |
| --- | --- |
| Bewertung und Refactoring (#27), Adapter (#5) | Bestehende Issues bewerten und Abhängigkeiten/Abnahmekriterien festhalten. Änderungen in kleinen funktionsfähigen Schritten am vorhandenen Stack. API- und Dateiformatkompatibilität, Migration bestehender Profile und getrennte Aktionen je Instanz prüfen. |
| Backups, Welten, Bereitschaft, Updates (#6, #7, #9, #13) | Prozesszustand, offene Spielsitzung und echten Beitritt getrennt prüfen. Sicherung erst nach regulärem Speichern; Wiederherstellung isoliert erproben. Echten ASKA-Stopp nicht aus Tests mit Stellvertretern ableiten. |
| Leistung, Logs und Shader-Flut (#8, #11, #12) | Unter echter Spielbelastung auf diesem Host messen. Originalfehler und Zeitpunkte erhalten, wiederholte Warnungen in der Anzeige zusammenfassen. Speicher-, CPU-, Upload- und Clientlast gemeinsam betrachten; keine pauschale Prozessorbindung aus Hardwaredaten ableiten. |
| Dauerbetrieb und Familienzugriff (#16, #17) | Windows-Betrieb, Dienstkonto und DPAPI unter dem richtigen Benutzer praktisch prüfen. Windows-Insider-Build 26220 ist beobachtet; Eignung für Dauerbetrieb im Host-Thema bewerten. Private Erreichbarkeit per Tailscale/HTTPS und serverseitige Identität/Rollen umsetzen und testen. Aktuelles Sitzungstoken ist keine Benutzeranmeldung. |
| Modquellen und Verteilung (#19–#21) | Fähigkeiten je Spiel und Quelle unterscheiden: Workshop, Nexus, RaftModding oder andere Hosts. Originaldownloads, Nutzungsbedingungen, Versionen und Hashes prüfen. Schlüssel oder Benutzeranmeldungen erst bei tatsächlichem Integrationsbedarf lokal einrichten. Installation, Rollback und Spieler-Synchronisierung isoliert testen; Clientmods können ohne Dedicated Server vorkommen. |
| Discord und Sturmklingen (#15, #22–#26) | Gemeinsame Ereignis-IDs, nachvollziehbare Quellen für Errungenschaften und wiederholbare Zustellung vorsehen. Mit Mocks prüfen; keine doppelten Erfolge oder Discord-Meldungen bei Neustarts. Animationen aus beständigen Ereignisdaten ableiten, reduzierte Bewegung berücksichtigen und Exporte separat prüfen. |

Auf dem Host waren automatische AC-Energiespar- und Ruhezustandswechsel sowie der Monitor-Timeout deaktiviert. Der aktuelle Energieplan war benutzerdefiniert. Das ist eine Bestandsaufnahme, kein fertig eingerichteter Dauerbetrieb. Im anschließenden Auftrag #17 wurde Tailscale 1.104.1 nativ unter Windows installiert und persönlich angemeldet; Dienst, unbeaufsichtigter Modus, private Serve-Konfiguration und Adapterregeln sind geprüft. Ein vorhandener Docker-Gateway auf Port 443 in gespiegeltem WSL verhindert den verlässlichen lokalen HTTPS-Selbsttest. Der anschließende Dashboardzugriff vom verbundenen Android-Handy über Mobilfunk ist vom Nutzer bestätigt; die persönliche Sitzung ist auch im privaten Zugriffsprotokoll belegt. Der Windows-Starttask ist seit 16.10.2026 im richtigen Besitzerkonto registriert und durch einen manuellen Aufruf bei laufendem Dashboard mit Ergebniscode 0 geprüft. Weitere Familienidentitäten, übrige Netzwerkports, Kaltstart und ASKA-DPAPI ohne Desktop-Anmeldung bleiben tatsächlich abzunehmen; Details im Projektskill und in `docs/family-access.md`.

Für die nächsten Themen reicht die vorhandene Werkzeugbasis. Die Betriebs- und Kontoeinrichtung für spätere Integrationen bleibt Teil ihrer Umsetzung. Dieses Audit startet keine Backlog-Implementierung.

## Symfony-Durchstich am 16.10.2026

Der Nutzer hat Symfony statt des bisherigen Anwendungskerns freigegeben; Epic #45 und `docs/symfony-migration.md` beschreiben die Teilmigration. PHP und Composer waren weder in WSL noch auf dem Windows-PATH vorhanden. Für den isolierten Durchstich liegen die Werkzeuge im privaten Linux-Verzeichnis `/home/korvegan/.local/share/gameserver-indy-tools/`: `php` startet eine separat extrahierte Ubuntu-PHP-8.5-CLI mit ihren Erweiterungen, `composer.phar` ist Composer 2.10.3 und `frankenphp-1.13.0/frankenphp` der anhand des veröffentlichten SHA256 geprüfte Linux-Webserver mit PHP 8.5.11. Keine globalen Paket-, Windows-Autostart- oder Produktionslisteneränderungen daraus ableiten. Die Tests verwenden `SLICE_PHP` und `SLICE_FRANKENPHP` zur Werkzeugauswahl; normale Installationen können PHP/FrankenPHP aus PATH verwenden.

Ein anfänglicher Aufruf von Composer über `frankenphp php-cli` übergab unter dieser PHP-8.5-Kombination den Skriptpfad als zusätzlichen Befehlsnamen. Die normale PHP-CLI führt Composer und Console-Befehle korrekt aus; deshalb Webserver und CLI-Werkzeug unterscheiden. Das ist keine Abnahme des nativen Windows-FrankenPHP-Pakets.

Die PowerShell-Umgebung konnte `Get-FileHash` im Teststarter nicht automatisch laden, passend zur bereits bekannten Modulpfadbesonderheit. Der reine Testprogramm-Compiler berechnet SHA256 deshalb direkt mit .NET. Die produktiven Skripte bleiben unverändert. Caddy-Admin ist im Durchstich aus; XDG-Konfigurations-/Speicherpfade werden ausdrücklich in den eigenen Testdatenordner gelegt. Ein früher Fixturestart schrieb sonst die globale Caddy-Autosave-Datei.

Die native Prüfung bestätigte HTTP-Annahme, Messenger-Worker, vollständige EXE-Pfadzuordnung, PID/Bereitschaft und regulären Stopp eines eigens kompilierten Stellvertreters. Symfony `ServerEvent` in 7.4 benötigt auch für einen Kommentar ein Datenargument; ein fehlendes Argument zeigte sich erst in der echten SSE-Browserprüfung. Live-Streams deshalb am realen Webserver prüfen. Bei verzögerten Browserrouten `unrouteAll({behavior: 'wait'})` nutzen, bevor eine neue Fixture beginnt; laufende Route-Handler können sonst nach dem Entfernen noch eine Antwort erfüllen. Testports vor dem Start auf Belegung prüfen und bestehende Listener niemals als erfolgreiche eigene Fixture übernehmen.

## Wiederholbare Prüfungen

```bash
python3 -m unittest discover -s tests -v
npm run check:js
npm run test:browser
GAMESERVER_WINDOWS_TEST=1 python3 -m unittest discover -s tests -p test_windows_bridge.py -v
```

Den Windows-Startertest mit nativer Windows PowerShell 5.1 und dem nach Windows umgewandelten vollständigen Pfad zu `tests/test-desktop-launcher.ps1` ausführen. Tests entsprechend dem Thema auswählen; nicht nach jedem Dokumentationssatz erneut die gesamte Suite starten.

Nach jedem erstellten PR gemäß `AGENTS.md` einen separaten Sub-Agenten mit dem tatsächlichen PR-Review beauftragen; vor dem Merge wesentliche Befunde beheben und den aktuellen Commit erneut prüfen lassen. Anschließend `gh pr checks <PR>` für diesen Commit prüfen und beim Merge `--match-head-commit <geprüfte-SHA>` verwenden. PR-Status, `main` und dessen CI kontrollieren. Die CI deckt Linux-/Windows-Backend, Windows-Prozessanbindung, Desktop-Starter und Chromium ab.
