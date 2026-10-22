# Entwicklung und Werkzeuge

Neuer App-Bereich: `app/README.md`, Architektur und Brückenvertrag unter
`app/docs/`. Das aktive Forgejo-Arbeitsissue und Zielbild #140 begrenzen den
Auftrag. Für ein Issue nur die nötigen Quellen und Nachweise laden.

- PHP-CLI: `/home/korvegan/.local/share/gameserver-indy-tools/php`;
  FrankenPHP: `/home/korvegan/.local/share/gameserver-indy-tools/frankenphp-1.13.0/frankenphp`.
  `SLICE_PHP` und `SLICE_FRANKENPHP` wählen diese vorhandenen Werkzeuge.
- PHP-Kindprozesse beachten `SLICE_PHP`: `PHP_BINARY` übernimmt die mit
  `-n/-d` gesetzten Erweiterungen des lokalen CLI-Wrappers nicht.
- `npm run test:app`: gezielter Vertrags-/HTTP-Test mit eigenen synthetischen
  Daten und eigenem Port. `-- --idle` nur für eine offene Leerlauffrage;
  bestandene Stichproben nicht ohne Anlass wiederholen.
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
