# Historischer Python-Stand

Seit der Nutzerentscheidung vom 17.10.2026 wird ausschließlich PHP/Symfony betrieben.
`python/` enthält die ehemalige Anwendung, ihre ausschließlich dafür gedachten
Tests und den früheren WhatsApp-Installer. `migration-tools/` erhält die einmaligen
Sprachvergleiche als historischen Nachweis. Diese Dateien sind kein startbares oder
unterstütztes Produktpaket und werden weder regulär noch im erweiterten PHP-Lauf getestet.

Ein Rückweg verwendet ein vorheriges **PHP-Release mit derselben aktuellen Datenablage**.
Keine alten Datenbanksnapshots oder Python-Laufzeiten aktivieren.

Aktive Python-Helfer unter `symfony/tools/` starten isolierte PHP-Testfixtures oder
bereiten eine eigene native PHP-Testkopie vor. Sie importieren die alte Anwendung
nicht und sind keine zweite Anwendungslaufzeit. Die aktuellen Betriebswege stehen
in der [README](../README.md), Prüfungen in [docs/testing.md](../docs/testing.md).
