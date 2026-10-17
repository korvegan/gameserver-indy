# GameServer Indy

Privates Familien-Dashboard für native Windows-Dedicated-Server. Die Anwendung
läuft mit **PHP/Symfony und FrankenPHP unter WSL**; aktuell werden Enshrouded und
ASKA verwaltet. Die frühere Python-Anwendung ist [archiviert](archive/README.md).

## Dashboard aufrufen

Auf dem Server-PC: **http://127.0.0.1:8765** oder die Desktop-Verknüpfung
**GameServer Indy**. Verbundene und freigegebene Familiengeräte verwenden den
privaten Tailscale-Zugang **https://indy.taila774ef.ts.net**.
Auf dem Server-PC bleibt die lokale Adresse der direkte Weg, insbesondere wenn
die Übernahme der Tailscale-DNS-Einstellungen dort deaktiviert ist.

Der vorhandene Windows-Starter liest seine private Konfiguration unter
`%LOCALAPPDATA%\GameServerIndy\launcher.local.json`. `RuntimeEngine` ist `symfony`;
Code, PHP-Werkzeug und aktuelle Datenablage sind getrennt. Installation und
Releasewechsel stehen in [docs/symfony-runtime.md](docs/symfony-runtime.md).

## Funktionen und Weiterentwicklung

Die Serverzentrale verbindet Spielkacheln, individuelle Spielkopfzeilen, Steuerung,
Beitrittsinformationen und sechs Server-Tabs. Hinzu kommen Familienzugriff,
Discord/WhatsApp, Sicherungen und die Sturmklingen-Chronik.

- [Architektur und Erweiterung](docs/architecture.md)
- [Serverzentrale und Bildquellen](docs/dashboard-design.md)
- [Familienzugriff](docs/family-access.md)
- [Hostbetrieb und noch nötige persönliche Abnahme](docs/host-operation.md)
- [Weltsicherungen](docs/world-backups.md)
- [Getrennte Welten und Testkopien](docs/world-management.md)
- [Wartung mit Vorlauf](docs/maintenance.md)
- [Geführter Steam-Updateablauf](docs/update-assistant.md)
- [Modquellen und gemeinsame Profile](docs/mods.md)
- [Beitrittsinformationen](docs/connection-info.md)
- [Chronik und Wandteppich](docs/chronicle.md)
- [Kleine, gezielte Prüfungen](docs/testing.md)
- [Gesamter Umsetzungsstand vom 17.10.2026](docs/backlog-status-2026-10-17.md)

Regulär: `npm run test:php` und bei UI-Änderungen `npm run test:ui`.
PHP, Composer und bei Browserprüfungen Node/Playwright werden als
Entwicklungswerkzeuge benötigt. `SLICE_PHP`/`SLICE_FRANKENPHP` wählen abweichende
lokale Werkzeuge. Einige isolierte PHP-Teststarter verwenden Python; das ist kein
weiteres Backend und benötigt die archivierte Anwendung nicht.

Neue Spiele erhalten ein Profil unter `profiles/` und einen passenden Symfony-
Konfigurationsadapter. Bedienrechte, Spielstände und der bestätigte externe
ASKA-Konfigurations-/Weltpfad bleiben erhalten. Keine echten Welten oder
Nachrichten als Testfixtures verwenden. Die Arbeitsregeln stehen in [AGENTS.md](AGENTS.md).
