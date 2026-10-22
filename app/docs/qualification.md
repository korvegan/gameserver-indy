# EP01: Gezielter Prüfnachweis

Stand: 22.10.2026, Arbeitsissue #147, ursprünglich unter `next/` an Commit
`465492a32d30ee0e33101fccd331ab9dcc76e922`, jetzt unverändert unter `app/` aufrufbar.
Lokal unter WSL/Linux geprüft:
PHP-CLI 8.5.4, FrankenPHP 1.13.0/PHP 8.5.11, Node 24.22.0.

`npm run test:app -- --idle` besteht:

- Fixture-Isolation und Symlinkabwehr, Rollen, erlaubte Eingaben und wahrheitsgemäße Beobachtungen.
- Start/Stopp für beide Rollen, Idempotenz, Gegenaktion, erlaubte Zustandswechsel,
  Abbruch vor Ausführung und unbekannter Ausgang nach erneutem Öffnen der Daten.
- Vier tatsächlich parallele PHP-Prozesse erhalten genau eine Operations-ID.
- Echter FrankenPHP-Start mit PHP-Seite und Status-API; Schreibzugriffe 405,
  fremder Host 403, nicht vorhandene Pfade/Quellpfade 404, fehlende Fixture 503.
- Eigener Listener und eigene temporäre Daten werden nach der Prüfung entfernt.

Eine Stichprobe nach dem HTTP-Test, fünf Sekunden ohne weitere Anfragen:
ein Serverprozess, keine Kindprozesse, 14 Threads, RSS 101440 KiB (rund 99 MiB).
Die CPU-Zeit nahm in diesem Fenster um 0 gemessene Ticks zu; die Tickauflösung
beträgt hier 10 ms. Das ist keine Aussage über exakt null CPU-Verbrauch.

Der Nachweis gilt für das isolierte EP01-Gerüst. Native Adapter, produktive Daten,
Familienanmeldung, Lastbetrieb, fertiges Design und Windows-Boot sind damit nicht
abgenommen. Diese Arbeiten besitzen eigene Epics. Keine echten Spiele oder Hosts
wurden für diese Prüfungen gestartet, gestoppt oder neu gestartet.
Der spätere ausdrückliche Archivierungs-/Umstellungsauftrag #149 ist in
[retirement.md](retirement.md) getrennt dokumentiert; er stoppte die alte Verwaltung.

## EP03: Native Grenze

Stand 22.10.2026, Arbeitsissue #157: PowerShell **7.6.7** unter dem vorhandenen
Windows-Besitzerkonto, PHP-CLI **8.5.4** unter WSL. Der native Test verwendet
ausschließlich selbst kompilierte Testprogramme in Windows-Temp mit synthetischen
Konfigurationen und Weltdateien.

- Beide direkten Starts, neue Helfer für Status und dieselbe Operations-ID,
  anschließender Strg+C-Stopp mit gespeichertem Abschlussmarker.
- ASKA erhält die externe Properties-Datei mit Leerzeichen als ein Argument
  und SteamAppId/SteamGameId 1898300; falsche Save-ID und Besitzer-SID werden abgelehnt.
  Ein gleichnamiger Save-Ordner unter falschem Stamm wird ebenfalls abgelehnt.
  Nur der Profilverzeichnis-Lookup ist in der privaten Testkopie auf Windows-Temp
  ersetzt; der originale Windows-Lookup wird separat geprüft.
- Zwei parallele Starts erzeugen genau einen zusätzlichen Zielprozess.
- Während eines langsamen Stopps bleiben Status und das andere Spiel benutzbar.
  Timeout bleibt unbekannt, neue Befehle bleiben gesperrt, Wiederholung sendet
  kein weiteres Stoppsignal. Der beobachtete Zielaustritt löst die Sperre auf.
- Ein gezielt beendeter einzelner Helfer verliert die unbekannte Operation nicht;
  der Testserver läuft weiter und sein späteres Ende wird abgeglichen.
- Gleichnamige EXE an fremdem Pfad bleibt unbehelligt. Eine zusätzliche eigene
  Test-EXE in der Zielkonsole führt zu `unsafe_console` ohne Signal.
- Ursprüngliche Konfigurationen, Weltdaten und EXEs bleiben bytegleich.
- Anschließend startet und stoppt PHP unter WSL beide Windows-Stellvertreter
  über den tatsächlichen Datei-/Prozessaufruf; alte Operations-IDs werden erkannt.

Der portable PHP-Test prüft gebundene und begrenzte Antworten, ungültige Eingaben,
verworfenes stderr, Transporttimeout auch bei ignoriertem SIGTERM und die persistierende unbekannte Reservierung.
Er läuft auch in Forgejo-CI. Der Windows-Test benötigt diesen Host und läuft lokal.

Der Nachweis bestätigt die Brücke an Stellvertretern. Echte Speicher- und
Beitrittsprüfung beider Spiele, Familienrechte und Windows-Boot sind weiterhin
gesondert abzunehmen. Es wurden keine echten Spielserver gestartet oder gestoppt;
die HTTP-Diagnose aktiviert keine native Steuerung.

## EP04: Identität und Zugangsverwaltung

Stand 22.10.2026, Arbeitsissue #159. `npm run test:app` besteht einschließlich
`check-access.php`: getrennte Listener, exakter Host/Peer, explizite Freigaben,
Rollen/Serverbindung, Origin/CSRF, gefälschte Header, Zielrevisionen und Entzug vor
Annahme sowie Beginn wartender synthetischer Aktionen. Ein echter HTTP-Aufruf am
Familienbackend bestätigt auch die feste HTTPS-Origin und Administratorrechte;
ein danach herabgestufter Administrator kann seinen alten Auftrag nicht senden.

Browser-MCP: Einstieg `/access`, richtiger Titel, DOM und Desktop-Screenshot
geprüft. Für Formulare, Viewports und Axe ergänzend vorhandenes Playwright 1.64.1
mit passendem Chromium, ohne Displayvariablen: Der allgemeine Browser-MCP bietet
keine Klick-/Viewportmethoden. `check-access-browser.cjs` besteht auf 1280, 390 und
320 px; Anlage, Sperren/Freigeben, Rolle, geschützter eigener Zugang, Tastaturfokus,
keine horizontalen Überläufe, Axe ohne Befund und keine Konsolen-/Seitenfehler.
320 px ist zusätzlich der Layoutcheck; Kernabläufe laufen auf Desktop und Mobil.

Ein erster Browserlauf zeigte `Origin: null` bei `no-referrer`; auf `same-origin`
korrigiert und denselben Formularablauf erneut bestanden. Die Origin-Prüfung
wurde nicht gelockert. Eine spätere Rollen-Disclosure beseitigt zusätzliche
Zeilen im mobilen Entwurf; der zugängliche Name enthält den sichtbaren Rollennamen.

Die akzeptierte zusammengesetzte Konzepttafel
`design/ep02/concepts/zugaenge-calm-v3.png` und aktuelle Desktop-/Mobil-Screenshots
wurden in derselben QA mit `view_image` verglichen. Die Tafel enthält Desktop und
Smartphone nebeneinander, ist also kein einzelner Browserviewport. Die realen
Ansichten wurden bei 1280×1080 und 390×844 erfasst; zusätzlich Layout bei 320 px.

| Vergleichspunkt | Ergebnis |
| --- | --- |
| Beschriftungen/Hierarchie | Marke, zurück zur Serverzentrale, Zugänge, fünf Spalten und Person freigeben erhalten |
| Layout | Desktop-Tabelle und mobile beschriftete Einträge; Formular folgt im Seitenfluss |
| Palette/Flächen | Dunkle ruhige Flächen, feine Kanten, Gold für Freigeben und Fokus; keine Spielbilder auf Zugänge |
| Typografie/Bedienung | Systemschrift, klare Titel, mindestens 44 px bedienbare Flächen, sichtbarer Fokus |
| Status/Aktionen | Text plus Statuspunkt, einzelne Sperren/Freigeben-Aktion, eigener Zugang geschützt |
| Notwendige Abweichungen | Lokaler Besitzer mit wahrer lokaler Zugangsart; Rollenname klappt Bearbeitung auf; skalierbares Schwert-/Kreis-SVG |

Die sichtbare Beschriftung wurde gegen die freigegebene Liste geprüft. Ergänzt
sind nur funktionsbezogene Bestätigung/Fehler und die oben benannten Abweichungen.
Die Gestaltung ist gegen den bestätigten Entwurf geprüft; keine offenen
funktionalen oder wesentlichen visuellen Abweichungen. Screenshots bleiben lokale
QA-Ausgaben, keine persönlichen Freigaben oder Laufzeitdateien werden versioniert.

Keine echten Konten, Serve-/Firewalländerungen, Spielaktionen oder Installation.
Der bestehende persönliche Mobilzugang und Bootbetrieb sind damit nicht neu
abgenommen; dafür bleibt EP06 zuständig.
