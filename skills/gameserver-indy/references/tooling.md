# Entwicklung und Werkzeuge

Stand: 17.10.2026. Stack und Prüfwahl: `docs/tech-stack.md`, `docs/testing.md`; Architektur: `docs/architecture.md`.

- PHP-CLI: `/home/korvegan/.local/share/gameserver-indy-tools/php`; Composer daneben als `composer.phar`; FrankenPHP unter `frankenphp-1.13.0/frankenphp`. System-PATH kann andere oder keine PHP-Werkzeuge enthalten. CLI für Console/Composer, FrankenPHP für HTTP verwenden.
- Node-Version: `.node-version`. Vorhandene npm-Befehle und `gh` bevorzugen. PR-Texte über Dateien mit echten Zeilenumbrüchen übergeben.
- `npm run test:php` prüft `regular`, bei Fehlern mit Diagnostik; `npm run test:ui` kompakte Familien-/Serverzentralenabläufe. Weitere Tests nach betroffenem Verhalten auswählen, native Änderungen mit isolierten Windows-Stellvertretern.
- Generische reguläre PHP-Tests laufen auf Linux, Windows zusätzlich für den nativen Ablauf. Erweiterte Fälle sind manuell. Keine archivierten Python-App-Tests oder Sprachvergleiche aktivieren.
- Gemessene Composer-Installation: etwa 3 s Linux/9 s Windows; Chromium-Setup etwa 23 s. Aktuell kein zusätzliches Image/Cache-System gerechtfertigt. Neue Entscheidungen brauchen Messbefunde.
- Playwright benötigt zum Lockfile passende Browser: bei fehlender Revision `npx playwright install chromium`. Keine alten Cachepfade fest verdrahten. `js_repl` war nicht verfügbar; versionierte Browserläufe verwenden.
- Fixtures belegen eigene Ports/Datenordner. Keine produktiven Listener übernehmen. Caddy-Admin ausschalten und XDG-Pfade in die eigenen Testdaten legen.
- Native PHP-Tests auf kurzer NTFS-Kopie: lange WSL-UNC-Pfade scheiterten an PHPUnit-Dateinamen. Caches sind nach Betriebssystem getrennt; für neue isolierte Builds `INDY_CACHE_DIR` setzen, damit keine alten Konstruktoren aus Produktionscaches verwendet werden.
- PowerShell-Testmocks müssen auch Modulimporte und Netz-/Task-Cmdlets ersetzen: echte Modulimporte hatten Mocks überschrieben.
- Für Skill-Änderungen `skill-creator` verwenden. Neue Plugins nur bei konkreter fehlender Fähigkeit. GitHub, Browser, PowerShell und HTTP genügen derzeit.

Review jedes PRs und CI seines endgültigen Commits gemäß `AGENTS.md`. Frühere Audit-Testzahlen sind kein heutiger Prüfauftrag.
Während Fixture- oder Releasehelfer den Checkout kopieren, keine Dateien/Branches/Stashes verändern. Ein solcher Wechsel verursachte eine unvollständige Browserfixture und eine unnötige Wiederholung. Erst Abschluss abwarten; unabhängige Recherche oder lesende Bestandsaufnahme kann parallel laufen. Commit, Push-Abschluss und PR-Erstellung bleiben abhängig und sequenziell.
