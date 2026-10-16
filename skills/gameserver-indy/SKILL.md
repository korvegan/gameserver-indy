---
name: gameserver-indy
description: GameServer Indy auf korvegans Windows-/WSL-Rechner weiterentwickeln und betreiben; bestätigte Besonderheiten von Enshrouded, ASKA, Familienzugriff und Sturmklingen berücksichtigen.
---

# GameServer Indy

Native Windows-Spielserver, verwaltet durch **PHP/Symfony und FrankenPHP unter WSL**. Hauptcheckout: `/home/korvegan/projects/gameserver-indy`; Repository: `korvegan/gameserver-indy`. Der frühere Projektpfad `enshrouded-indy` existiert nicht mehr.

Seit 17.10.2026 ist PHP die einzige Anwendungslaufzeit. Python-Anwendung und eigene Tests liegen unter `archive/`; weder starten noch als Rückweg prüfen. Einige isolierte Testhelfer sind weiterhin Python-Skripte. Ein Rückweg verwendet ein vorheriges PHP-Release mit derselben aktuellen Datenablage.

Aktive Code- und Datenpfade aus der privaten Windows-Datei `%LOCALAPPDATA%\GameServerIndy\launcher.local.json` lesen. Nicht den Checkout mit dem laufenden unveränderlichen Release verwechseln. Einstieg: `symfony/bin/indy <StateDirectory>`. Lokal: `http://127.0.0.1:8765`; privat mit verbundener Tailscale-App: `https://indy.taila774ef.ts.net`.

Arbeitsfreigaben, unabhängiges Review jedes PRs und angemessene Prüfungen stehen in `AGENTS.md`. Reasoning unverändert lassen. Regulär nur wesentliche Familienabläufe prüfen; seltene oder risikoarme Fälle bei konkretem Bedarf manuell. Keine echten Spielaktionen, Nachrichten oder Kontoeinrichtungen aus einer Testabsicht ableiten.

Nur die zur Aufgabe passende Referenz lesen:

| Aufgabe | Referenz |
| --- | --- |
| Entwicklung, Werkzeuge, Tests | [tooling.md](references/tooling.md) |
| Releases, Supervisor, Aufträge, Zustand | [runtime.md](references/runtime.md) |
| Windows, Tailscale, persönliche Rechte | [host-access.md](references/host-access.md) |
| Spielkonfiguration, Welten, Sicherungen, Updates | [games.md](references/games.md) |
| Serverzentrale, Navigation und Entwürfe | [frontend.md](references/frontend.md) |
| Discord, WhatsApp, Beitrittsadressen | [integrations.md](references/integrations.md) |
| Sturmklingen, Chronik und Geschichtsbild | [community.md](references/community.md) |

Genannte `docs/`-Pfade beziehen sich auf das Repository. Sie beschreiben die aktuelle Bedienung; frühere Migrationsschritte sind Git-Historie und keine heutigen Laufzeitpflichten.

Belegte neue Fallstricke am Abschluss relevanter Arbeiten in der passenden Referenz korrigieren oder knapp ergänzen: Ursache, Abhilfe, verbleibende Prüflücke. Vermutungen kennzeichnen. Die Repository-Version ist die Quelle; nach Änderungen validieren und Einstieg samt Referenzen nach `~/.codex/skills/gameserver-indy/` synchronisieren. Dies geschieht innerhalb beauftragter Arbeit, nicht durch einen Hintergrunddienst.
