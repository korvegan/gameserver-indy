# Prüfungen nach Änderungen auswählen

Die CI ermittelt den vollständigen Git-Diff des geprüften Commits. Unbekannte Pfade,
fehlende Vergleichsrevisionen und Änderungen an gemeinsamer Infrastruktur wählen
vorsichtshalber alle Prüfungen. Es gibt keine Workflow-Pfadfilter, die einen
erforderlichen Status dauerhaft offen lassen.

| Änderung | PR und `main` |
| --- | --- |
| Dokumentation / Projektskill | Auswahllogik und gemeinsamer Abschlussstatus |
| Webmodule, Bilder, Twig, Dashboard-Assetroute | Syntax, Dashboard/API-Test und Symfony-Browser |
| Symfony-Fachlogik | Linux-PHPUnit, Vertragsvergleiche und Symfony-Browser |
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
volle Plattformmatrix anzufordern. Ein vollständiger Lauf erfolgt täglich um
03:23 UTC und kann über **Actions → Checks → Run workflow** gestartet werden.
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
