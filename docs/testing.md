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

Grundlagen: [GitHub-Workflow-Syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)
und [übersprungene erforderliche Prüfungen](https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks).
