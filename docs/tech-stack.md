# Technik- und Versionsstand

Inventur vom **16.10.2026** auf main nach PR #64. Versionsstände wurden gegen Composer/Packagist, npm, offizielle Release-Endpunkte und die tatsächlich vorhandenen Werkzeuge geprüft. Die Produktivverwaltung bleibt während Epic #45 Python; Symfony ist bis zur Betriebsabnahme #54 ein separates Zielsystem. Laufende Spiele, WhatsApp-Verknüpfung und Hostdienste werden durch Bibliotheksupdates nicht umgeschaltet.

## Anwendung und Bibliotheken

| Bereich | Tatsächlicher Stand | Entscheidung |
| --- | --- | --- |
| Symfony Framework, Security, Messenger, HTTP | Framework-Bundle **7.4.21**; Komponenten jeweils neuester verfügbarer Patch ihres Zweigs `7.4.*` | Aktuelle LTS beibehalten. Komponenten ohne neueren Patch tragen berechtigt kleinere Patchnummern. |
| Datenbankzugriff | Doctrine DBAL **4.5.0**, DoctrineBundle **3.3.2**, SQLite über PDO | Aktuell; kein separater Datenbankserver und bislang kein Doctrine ORM. |
| Templates | Twig **3.30.0** mit Symfony-Twig-Bundle | Aktuell. |
| Oberfläche | Produktiv native JavaScript-Module; separate Symfony-Twig-/SSE-Testoberfläche | Vue, TypeScript und weitere Symfony-UX-Komponenten sind noch keine installierten App-Abhängigkeiten. Ihren tatsächlichen Bedarf entscheidet #52. |
| PHP-Tests | PHPUnit **12.5.39 → 13.4.3** einschließlich zugehöriger Testbibliotheken | Aktuelle stabile Hauptversion übernehmen. Die Anwendung benötigt weiter PHP >=8.4; Entwicklung/Tests mit dieser PHPUnit-Version benötigen ausdrücklich PHP >=8.4.1. |
| Browser-Tests | Playwright und Playwright Core **1.64.1** | Bereits aktuell; Lockfile und dazugehörige Browserdateien beibehalten. |
| Python-Referenzanwendung | Standardbibliothek, keine externen pip-Laufzeitpakete | Bleibt bis #54 verfügbar; diese Inventur wechselt keinen laufenden Python-Prozess aus. |

[Symfony 7.4](https://symfony.com/releases/7.4) erhält Fehlerkorrekturen bis November 2028 und Sicherheitskorrekturen bis November 2029. [Symfony 8.1.9](https://symfony.com/releases/8.1) ist zwar die neuere stabile Hauptlinie, endet aber bereits im Januar 2027. Für die vereinbarte Familienverwaltung bleibt deshalb die gepflegte LTS die Grundlage. Maßgeblich für den aktuellen Patch ist auch der [offizielle JSON-Endpunkt](https://symfony.com/releases/7.4.json), der beim Audit 7.4.21 lieferte; Suchtreffer zeigten teilweise noch 7.4.20.

[PHPUnit 13](https://phpunit.de/supported-versions.html) erhält Fehlerkorrekturen bis Februar 2028. Das konkrete Paket [13.4.3 verlangt PHP >=8.4.1](https://github.com/sebastianbergmann/phpunit/blob/13.4.3/composer.json), präziser als die allgemeine Hauptversionstabelle. Die Entwicklung hier und die CI verwenden PHP 8.5 und erfüllen dies. Der Wechsel wird mit den bestehenden Tests einschließlich der nativen Windows-CI geprüft. Die Runtime-Pakete und deren Mindestversion bleiben beim selben Stand; die Änderungen im Composer-Lockfile betreffen die Testwerkzeuge.

## Laufzeiten und lokale Werkzeuge

| Werkzeug | Beobachteter Stand / Ziel | Einordnung |
| --- | --- | --- |
| PHP für Windows-Prüfungen | **8.5.11** | Entspricht dem [aktuellen PHP-8.5-Release](https://www.php.net/releases/?json&version=8.5). Privates Werkzeug unter `%LOCALAPPDATA%\GameServerIndy\tools\php85`, kein globaler PATH-Eingriff. |
| PHP CLI in WSL | **8.5.4-0ubuntu1.3** | Separat entpackte Ubuntu-Sicherheitsrevision. Der frisch abgerufene Ubuntu-Index und der SHA256 des vorhandenen DEBs bestätigen diese aktuelle Paketrevision. Nicht mit einem ungepatchten Upstream-8.5.4 gleichsetzen; Upstream 8.5.11 und Distributionsrevision getrennt führen. |
| Webserver | FrankenPHP **1.13.0**, eingebettetes PHP **8.5.11** | [Aktuelles Release](https://github.com/php/frankenphp/releases/tag/v1.13.0), Linux-Artefakt per SHA256 geprüft und in der CI festgelegt. |
| SQLite | WSL/Python/PDO **3.46.1** (`libsqlite3-0` **3.46.1-9ubuntu0.3**), natives Windows-PDO **3.53.4** | Gebündelte bzw. vom Betriebssystem gelieferte Bibliotheken; kein einzeln gestarteter SQLite-Dienst. Upgrades erfolgen mit der jeweiligen unterstützten Laufzeit/Distribution. Kompatibilität der Datenkopien bleibt #54. |
| Composer | **2.10.3** | Aktuelles stabiles Release laut [Hersteller-Endpunkt](https://getcomposer.org/versions). |
| Node.js | Lokal **24.18.0 → 24.22.0 LTS** | Offizielles Linux-Artefakt per SHA256 geprüft. `.node-version` bindet Entwicklung und Browser-CI an denselben Stand; `engines.node` beschreibt die unterstützte LTS-Hauptlinie. |
| npm | Lokal **11.16.0 → 11.19.1** | Zur gewählten Node-LTS-Version gehörender Paketmanager. Die gesondert angebotene npm-Hauptversion 12.2.0 ist für dieses Projekt nicht erforderlich. |
| Python | Lokal **3.14.4**, CI bisher **3.11**; zusätzlich **3.15** | [3.15.0](https://www.python.org/downloads/release/python-3150/) ist aktuell stabil. Die CI prüft künftig sowohl die bisherige Mindestversion als auch 3.15 unter Linux und Windows. Produktive Interpreter bleiben während der Migration bestehen. |

Die [Node-Releaseübersicht](https://nodejs.org/en/about/previous-releases) und der [Downloadindex](https://nodejs.org/dist/index.json) bestätigen 24.22.0 als aktuelle LTS. 26.12.0 ist zum Auditzeitpunkt noch „Current“. Node dient hier den Entwicklungs-/Browserprüfungen, nicht als zusätzlicher produktiver Dashboardserver.

Der WSL-PHP-Stand wurde mit dem [Ubuntu-Paketindex](https://archive.ubuntu.com/ubuntu/dists/resolute-updates/main/binary-amd64/Packages.xz) und dem mitgelieferten Debian-Changelog abgeglichen. Vor einem späteren Werkzeugupdate die komplette Paketrevision und Prüfsummen vergleichen; keine unkontrollierte eigene PHP-Kompilierung oder fremde Paketquelle als vermeintlich notwendigen App-Schritt hinzufügen.

## Integrationen und CI

WAHA/GOWS läuft aus dem festgelegten Image `devlikeapro/waha@sha256:280bdc8f860267a2488a45d00ec88529646f7468c0bf0283c595d6efdd1b5e3b`. Der beobachtete Image-Quellcommit `90ba445a0e765701c8910f0fac08176250c3e0fd` entspricht dem [aktuellen WAHA-Tag 2026.9.2](https://github.com/devlikeapro/waha/releases/tag/2026.9.2). Daher kein Containerwechsel, neues QR-Pairing oder Testversand. Docker Client/Server wurden lesend mit 29.9.0 erfasst; die Inventur aktualisiert keine gemeinsam verwendete Hostinfrastruktur.

Die verwendeten GitHub Actions sind aktuell: Checkout 7.0.1, Setup Node 7.1.0, Setup Python 7.0.0, Upload Artifact 7.0.2 und Setup PHP 2.40.0. Der vorhandene SHA-Pin von Setup PHP bleibt erhalten. Die Backend-Matrix ergänzt Python 3.15; die bisherigen Checknamen der 3.11-Prüfungen bleiben bestehen. Insgesamt entstehen acht CI-Prüfungen statt bisher sechs.

## Wiederholbarer Abgleich

```bash
cd symfony
composer validate --strict
composer outdated --locked --minor-only
composer outdated --locked
composer audit --locked
php vendor/bin/phpunit
cd ..
npm ci --no-audit --no-fund
npm outdated
npm audit
npm run check:js
npm run test:browser
npm run test:symfony-browser
```

`composer outdated` zeigt auch bewusst ausgeschlossene Symfony-8-Pakete. Nur innerhalb der gewählten Linie prüfen und den unbeschränkten Bericht daneben bewerten. `composer.lock` und `package-lock.json` enthalten die exakten Auflösungen; `install`/`ci` verwenden diese Auflösungen und sind keine Updatesuche. Native Prozessprüfungen nutzen ausschließlich eigene Stellvertreter. Nach jedem Update-PR gilt weiterhin unabhängiges Sub-Agent-Review am endgültigen Head und sämtliche zugehörige CI vor dem Merge.

Composer-Audit einschließlich Entwicklungsabhängigkeiten und npm-Audit meldeten beim Audit keine bekannten Paket-Schwachstellen oder aufgegebenen Composer-Pakete. Diese Paketberichte ersetzen keine Aussage über alle Hostprogramme oder Container-Inhalte.
