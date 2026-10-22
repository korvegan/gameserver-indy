---
name: gameserver-indy
description: GameServer Indy auf korvegans Windows-/WSL-Rechner weiterentwickeln; neuen PHP-Aufbau, minimale Windows-Brücke und bestätigte Spielbesonderheiten berücksichtigen.
---

# GameServer Indy

Führendes Repository: `Korwerk/gameserver-indy` auf Forgejo, Hauptcheckout
`/home/korvegan/projects/gameserver-indy`. GitHub ist nur Backup. Neue Issues,
PRs und Reviews gehören nach Forgejo. Projektregeln in `AGENTS.md` lesen.

Seit dem ausdrücklichen Neubau-/Archivierungsauftrag vom 22.10.2026 ist `app/`
der führende App-Bereich. PHP läuft unter WSL; native Windows-Aktionen erhalten
eine möglichst kleine Brücke. Der Entwicklungsstart verwendet synthetische Daten.
Die installierte Verwaltung wurde am 22.10.2026 in EP06 auf `php-mvp` mit echten
Spielbindungen und bestehenden Freigaben umgestellt. Smartphonezugriff, beide
Bestandswelten/Beitritte, reguläre Stopps und neue Speicherdateien sind bestätigt;
Verwaltungsneustart erhält die Spielprozesse. Nach freigegebenem Windows-Neustart
sind Verwaltungsstart, Smartphonezugriff und ASKA-Start/-Stopp in Sitzung 0 bestätigt.
Andere Familienidentität, Neustart mit aktivem Spieler und Welt-/Speicherabnahme
nach diesem Boot bleiben offen.
Aktueller Nachweis und Rückweg: `app/docs/operation.md`.
Vorbereitung und isolierte Prüfungen aktivieren keinen Betrieb;
die konkrete Betriebs-/Neustartfreigabe und persönliche Abnahme separat festhalten.
Einstieg: `app/README.md`, Zielbild Forgejo #140.

Die alte Verwaltung wurde geordnet gestoppt. Vollständiger Quellnachweis:
`archive/php-legacy-20261022/snapshot/`, Herkunft in `source.json` daneben.
Die vorhandenen Spielinstallationen, Welten und privaten State-Dateien bleiben
an ihren bisherigen Orten. Sie werden nicht als Testdaten oder neue leere
Initialisierung verwendet. Aktuelle Launcherwerte aus der privaten Datei
`%LOCALAPPDATA%\\GameServerIndy\\launcher.local.json` lesen.

| Aufgabe | Gezielter Kontext |
| --- | --- |
| Entwicklung und Prüfungen | [tooling.md](references/tooling.md) |
| Aktueller Startstand, Rückweg | [runtime.md](references/runtime.md) |
| Bestätigte Windows-/Tailscale-Eigenheiten | [host-access.md](references/host-access.md) |
| Bestätigte Spiel-/Pfadbesonderheiten | [games.md](references/games.md) |
| Alte Oberfläche, Integrationen oder Chronik gezielt nachschlagen | [frontend.md](references/frontend.md), [integrations.md](references/integrations.md), [community.md](references/community.md) |

Die letzten fünf Referenzen bewahren Fachwissen zum bisherigen Betrieb.
Ihre `docs/`-Verweise beziehen sich auf den Archiv-Snapshot; neue Entscheidungen
und Umsetzungen führen in `app/` und im aktiven Forgejo-Issue. Nicht pauschal
die alte Architektur oder den gesamten Altcode laden.

Belegte neue Fallstricke im zuständigen Abschnitt knapp ergänzen: Ursache,
Abhilfe und verbleibende Grenze. Die Repositoryfassung dieses Skills bleibt
führend; nach Änderungen validieren und mit der installierten Kopie unter
`~/.codex/skills/gameserver-indy/` abgleichen. Reale Spiel-/Hostaktionen nur
innerhalb des konkreten Nutzerauftrags ausführen.
