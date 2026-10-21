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
- Node-Version steht in `.node-version`. Browserprüfungen erst für tatsächliche
  UI-Arbeit; verfügbare Browserlaufzeit zuerst prüfen. Bei Playwright benötigen
  Axe-Tests `browser.newContext()` und dessen `newPage()`.
- Das Archiv und seine alten Workflows/Tests werden nicht als aktuelle Anwendung
  gestartet. Frühere Werkzeugbefunde bei konkretem Bedarf dort nachlesen.

Ein Arbeitsissue pro Branch. Selbstcheck und unabhängigen PR-Review auf den
endgültigen Head beziehen; Rundenlimit vorher dokumentieren. Keine echten
Spiele, Originaldaten oder Kontoeinrichtungen als Testfixtures verwenden.
