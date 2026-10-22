# Entwicklung und Werkzeuge

Neuer App-Bereich: `app/README.md`, Architektur und Brückenvertrag unter
`app/docs/`. Das aktive Forgejo-Arbeitsissue und Zielbild #140 begrenzen den
Auftrag. Für ein Issue nur die nötigen Quellen und Nachweise laden.

- PHP-CLI: `/home/korvegan/.local/share/gameserver-indy-tools/php`;
  FrankenPHP: `/home/korvegan/.local/share/gameserver-indy-tools/frankenphp-1.13.0/frankenphp`.
  `SLICE_PHP` und `SLICE_FRANKENPHP` wählen diese vorhandenen Werkzeuge.
- PHP-Kindprozesse beachten `SLICE_PHP`: `PHP_BINARY` übernimmt die mit
  `-n/-d` gesetzten Erweiterungen des lokalen CLI-Wrappers nicht.
- EP06 bindet projektbezogen die offizielle FrankenPHP-1.13.2-Datei mit PHP 8.5.12
  und geprüftem SHA-256; `runtime/php` verwendet deren `php-cli`. Die alten
  Werkzeugpfade oben sind der erhaltene Entwicklungs-/Rückwegstand, vor neuer
  Verwendung die geprüfte Sicherheitslaufzeit setzen. Keine Systempakete dafür entpacken.
- ZTS-`php-cli`: PHPs `chdir()` änderte hier nicht das vom Exec übernommene
  OS-Arbeitsverzeichnis (HTTP 404 trotz gestarteten Servers). Gemeinsamer Start
  über GNU `env --chdir=<app>`; mit echter HTTP-Prüfung abgesichert.
- FrankenPHP `php-cli` mit PHP 8.5.12 unterstützt `-l` hier nicht als Lint:
  die angegebene Datei wurde ausgeführt. Für reine Syntaxprüfung
  `token_get_all(file_get_contents($argv[1]), TOKEN_PARSE)` über `-r` verwenden.
- `check-release.py` prüft eigene dauerhafte Ablage und Freigabeübernahme;
  mit `SLICE_FRANKENPHP` zusätzlich HTTP-Neustart/-Wechsel/Rückweg und fehlende
  Bereitschaft. Sperren mit `ce` öffnen; Kindprozesse dürfen die feste
  Verwaltungslease auf FD 9 nicht erben (sonst Selbstblockade bzw. verwaiste Lease).
- `npm run test:app`: gezielter Vertrags-/HTTP-Test mit eigenen synthetischen
  Daten und eigenem Port. `-- --idle` nur für eine offene Leerlauffrage;
  bestandene Stichproben nicht ohne Anlass wiederholen.
- EP04: `php app/tests/check-access.php` prüft Identität/Rechte und Entzug vor
  wartenden synthetischen Aktionen. `npm run test:access:browser -- http://127.0.0.1:TESTPORT`
  prüft die eigene Entwicklungsfixture mit vorhandenen Playwright-/Axe-Paketen;
  Desktop/Mobil, Formulare und Fokus. Der allgemeine Browser-MCP bietet keine
  Klicks oder Viewportänderung, dafür den Projektweg nutzen. Anleitung in
  `app/docs/family-access.md`; keine echte Zugangsdatenbank als Testziel verwenden.
- EP05: `check-servers.php` prüft die einmalige asynchrone Ausführung und den
  Operationsabgleich. `npm run test:servers:browser -- URL FAMILIEN_TESTPORT FIXTURE_PFAD`
  prüft eigene synthetische Spiele und Familienseiten; Ablauf in `app/docs/dashboard.md`.
  Keine parallel offene Browser-MCP-Vorschau denselben Testserver pollen lassen,
  wenn der Test gezielt alte Beobachtungen setzt. Native Prüfung benötigt jetzt
  zusätzlich `SLICE_FRANKENPHP` für HTTP → entkoppeltes PHP → Windows-Stellvertreter.
- `npm run check:archive`: alle historischen Git-Blobs/Dateimodi vergleichen.
  Nach Änderungen am Archiv oder an der Struktur ausführen.
- Windows-Starter: `app/tests/check-launcher.ps1` ersetzt Prozess-/HTTP-Aufrufe
  vollständig durch Mocks und arbeitet in einem eigenen Windows-Tempordner.
  Native WSL-Prüfung bestätigte: unnötige Quotes um den Distributionswert
  werden wörtlich übernommen (`WSL_E_DISTRO_NOT_FOUND`). Der lokale Starter
  übergibt validierte Werte ohne Quotes; seine Linux-Pfade enthalten keinen Leerraum.
- EP03: `"$SLICE_PHP" app/tests/check-bridge.php` prüft den portablen Transport.
  `INDY_PWSH=/WSL/Pfad/zur/installierten/pwsh.exe python3 app/tests/check-windows-bridge.py`
  prüft unter PowerShell 7.6+ eigene native Stellvertreter samt PHP-/WSL-Aufruf.
  `SLICE_PHP` auch dabei setzen. Bestehenden .NET-Framework-Compiler nur für
  die eigenen Test-EXEs verwenden; die Brücke nutzt `Add-Type`, keine neue Installation.
  JSON-Startzeiten mit `ConvertFrom-Json -DateKind String` lesen, damit
  der exakte Identitätsvergleich unverändert bleibt; Standardkonvertierung erzeugt DateTime-Werte.
- Node-Version steht in `.node-version`. Browserprüfungen erst für tatsächliche
  UI-Arbeit; verfügbare Browserlaufzeit zuerst prüfen. Bei Playwright benötigen
  Axe-Tests `browser.newContext()` und dessen `newPage()`.
- Das Archiv und seine alten Workflows/Tests werden nicht als aktuelle Anwendung
  gestartet. Frühere Werkzeugbefunde bei konkretem Bedarf dort nachlesen.

Ein Arbeitsissue pro Branch. Selbstcheck und unabhängigen PR-Review auf den
endgültigen Head beziehen; Rundenlimit vorher dokumentieren. Keine echten
Spiele, Originaldaten oder Kontoeinrichtungen als Testfixtures verwenden.
