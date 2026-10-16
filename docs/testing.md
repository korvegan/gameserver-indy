# Prüfungen nach Änderungen auswählen

Die App dient dem privaten Familienbetrieb. Die reguläre Prüfung konzentriert sich
auf häufige, wesentliche Bedienabläufe und Fehler mit mehr als niedrigem Risiko.
Nach Nutzerentscheidung vom 17.10.2026 gehören Fälle mit geschätzter Eintrittswahrscheinlichkeit
unter 5 % **oder** niedrigem bzw. geringerem Risiko nicht in reguläre Läufe.
Es existieren keine belastbaren Häufigkeitsmessungen; keine erfundenen Prozentwerte
angeben. Seltene Fehlerketten, Crash-Simulationen, exotische Dateisystem-/Unicodefälle
und wenig folgenreiche Layoutvarianten sind nur manuell zu prüfen.

Die PHPUnit-Gruppe `regular` enthält zwölf ausgewählte Prüfungen: Oberfläche/API,
Auftragsannahme und Profiltrennung, Familienrechte, Konfigurationsspeicherung,
Sicherung/Rückweg und Aufbewahrung, ASKA-Weltzuordnung, Chronikbeitrag, Beitrittsinformationen
sowie den normalen nativen Prozessablauf. Das ist eine Auswahl nach praktischem
Nutzen, keine pauschale Klassifizierung aller bisherigen Tests als wertlos.
Allgemeine PHP-Fälle laufen regulär nur unter Linux. Windows führt nur den echten
nativen Prozessablauf aus; dieselben HTTP-/Konfigurations-/Sicherungsfälle werden
dort nicht nochmals regulär ausgeführt. Der manuelle erweiterte Lauf kann beide
Plattformen vollständig vergleichen.

Die CI ermittelt den vollständigen Git-Diff des geprüften Commits. Unbekannte Pfade,
fehlende Vergleichsrevisionen und Änderungen an gemeinsamer Infrastruktur wählen
vorsichtshalber alle Prüfungen. Es gibt keine Workflow-Pfadfilter, die einen
erforderlichen Status dauerhaft offen lassen.

| Änderung | PR und `main` |
| --- | --- |
| Dokumentation / Projektskill | Auswahllogik und gemeinsamer Abschlussstatus |
| Webmodule, Bilder, Twig, Dashboard-Assetroute | Syntax, Dashboard/API-Test und Symfony-Browser |
| Symfony-Fachlogik | reguläre Linux-PHPUnit-Gruppe und Symfony-Browser |
| Native Brücke, Prozesse, Spielkonfiguration, Sicherungen, Releases | zusätzlich Windows-Symfony |
| Archivierte Python-Anwendung | keine aktiven Prüfungen |
| Gemeinsame Infrastruktur / unbekannt | sämtliche Prüfungen |

Die überschaubaren Regeln stehen in `.github/ci-plan.cjs`; ihre Regressionen laufen
mit `node --test tests/ci-plan.test.cjs`. Die GitHub-Zusammenfassung nennt Auswahl
und Gründe. `Relevant checks` läuft auch nach einem Fehler und verlangt einen
erfolgreichen Auswahljob sowie erfolgreiche ausgewählte Prüfungen. Der unabhängige
PR-Review bleibt zusätzlich notwendig. Vorhandene Repository-Schutzregeln werden
durch diese Änderung nicht umgestellt; bei einer späteren Einrichtung eignet sich
der stabile Abschlussstatus als erforderliche Prüfung.

PRs und `main` prüfen jeweils ihren eigenen getesteten Tree mit derselben Auswahl.
Der Squash-Merge wird damit geprüft, ohne für einen reinen UI-Merge wieder die
volle Plattformmatrix anzufordern. Täglich um 03:23 UTC werden alle Komponenten
mit den regulären Fällen geprüft. Erweiterte Testfälle laufen ausschließlich über
**Actions → Checks → Run workflow**; kein nächtlicher automatischer Volltest.
Auf ausdrücklichen Nutzerwunsch vom 17.10.2026 ist Symfony die einzige unterstützte
Anwendungslaufzeit. Python-Backendmatrix und Sprachvergleiche entfallen auch im
Volllauf; die archivierte Anwendung wird nicht weiterentwickelt oder getestet.
Python-Hilfsprogramme zum Start isolierter PHP-Testfixtures sind davon getrennte
Entwicklungswerkzeuge, kein zusätzlich unterstütztes Backend.

## Zeitvergleich

Referenz PR #81: acht Jobs, letzter Windows-PHP-Lauf 8:15 Minuten; PHPUnit darin
4:53 Minuten, unter Linux 30 Sekunden. Die neue UI-Auswahl spart diesen kompletten
Windows-Pfad; der tatsächliche Gesamtgewinn hängt von Runnerwartezeit und Browserlauf ab.
Ergebnisse der ersten selektiven UI-Abnahme und eines nativen Beispiels im Fachissue
#86 festhalten. Caches und weitere Parallelisierung erst nach verbleibenden Messkosten
ergänzen.

Messung der ersten PHP-Auswahl (#91): Linux-Job 15 Sekunden, Windows-Job 3:20
statt zuvor 8:15 Minuten. Unter Windows entfallen 2:03 auf die regulären PHP-Fälle
und 35 Sekunden auf den nativen Ablauf. Composer benötigt 3 Sekunden unter Linux
und 9 Sekunden unter Windows; Chromium-Einrichtung etwa 23 Sekunden. Ein eigener
Image-Bau oder zusätzliche Composer-Cacheverwaltung hat bei diesen Anteilen
derzeit wenig Hebel. npm-Downloads werden bereits gecacht. Browser-Caching wird
von [Playwright](https://playwright.dev/docs/ci#caching-browsers) wegen vergleichbarer
Download-/Wiederherstellungszeiten nicht pauschal empfohlen. Keine Testdatenbanken,
Zugriffsschlüssel oder privaten Laufzeitordner über CI-Läufe hinweg cachen.

## Lokale Befehle

- `npm run test:php`: nur die regulären Familienfälle, ruhige Ausgabe.
- `npm run test:php -- --filter ConfigurationTest`: betroffene reguläre PHP-Fälle.
- `SLICE_WINDOWS_TEST=1 npm run test:php -- --filter Native`: normaler nativer
  Prozessablauf mit eigenen Stellvertretern; unter WSL die vorhandene private
  native PHP-Testkopie aus `symfony/tools/run_native_php_tests.py` verwenden.
- `npm run test:ui`: Syntax und kompakte Browserabnahme gegen eigene PHP-Fixtures;
  zwei Spiele, wesentliche Tabs, Entwürfe, Rechte sowie Desktop/Mobil.
- Nur bei konkretem Bedarf: `npm run test:php -- --extended --filter <Fall>` oder
  `npm run test:symfony-dashboard` für die ausführlichen Simulationen.

`SLICE_PHP` und `SLICE_FRANKENPHP` wählen lokal vorhandene Werkzeuge; normale
Installationen verwenden `php`/`frankenphp` aus PATH. Der PHPUnit-Helfer zeigt
standardmäßig die Testzusammenfassung. stderr bleibt in `artifacts/phpunit-stderr.log`
und wird bei Fehlern eingeblendet. `--verbose` schaltet die ausführliche Ausgabe
bewusst zu. Tests und Assertions werden dadurch nicht unterdrückt.

Grundlagen: [GitHub-Workflow-Syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)
und [übersprungene erforderliche Prüfungen](https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks).
