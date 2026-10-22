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

## EP05: Dashboard und autorisierte Spielbedienung

Stand 22.10.2026, Arbeitsissue #161. `npm run test:app -- --idle` besteht:
Zustands-/Rechteverträge, echter HTTP-Start, direkte und bestätigte Spielaktionen,
Familienrechte und Entzug, derselbe Auftrag bei doppelten Anfragen, getrennte Spiele,
langsamer Status, Fehlstart, unklarer Ausgang, belegter Operationsabgleich sowie
verlorene/wartende Executorprozesse. Veraltete Antworten überschreiben keinen
neueren Cache; öffentliche Statusdaten enthalten keine PID, Pfade oder Befehle.

Die fünfsekündige Leerlaufstichprobe nach synthetischen HTTP-Aktionen zeigte
einen Serverprozess, keine Kind-PIDs, 14 Threads und RSS 102592 KiB. In diesem
Fenster kamen 0 gemessene CPU-Ticks hinzu (Auflösung 10 ms). Das ist keine
Zusicherung von exakt null Verbrauch oder eine Aussage über Produktionslast.

Native Windows-Prüfung unter PowerShell 7.6.7 bestanden: bisherige isolierte
Brückenabläufe plus PHP-/WSL-Transport und neu der vollständige Weg
HTTP → entkoppelter PHP-Prozess → PowerShell → beide eigenen Windows-EXEs.
Beide Starts laufen unabhängig, Status bleibt ohne Bereitschaftsnachweis `null`,
beide Stopps sind regulär bestätigt. Der Windows-Starter wurde separat mit
vollständig gemockten Prozess-/Netzaufrufen einschließlich `SLICE_PHP` geprüft.
Es wurden keine echten Spielserver, Spielkonfigurationen oder Welten benutzt.

Browser-MCP zuerst für Einstieg, Titel, DOM und Screenshot verwendet. Für Klicks,
mobile Viewports und Axe das vorhandene Projekt-Playwright 1.64.1 samt passendem
Chromium genutzt: Der allgemeine Browser-MCP bietet diese Methoden nicht.
`check-servers-browser.cjs` besteht mit beiden Spielen und tatsächlichen
Start-/Stoppabläufen bei 1280 und 390 px; Layout zusätzlich bei 320 px. Details,
Abbrechen, Escape, Tab-Grenzen, Fokusrückkehr, Kopieren samt Fallback, unbekannter
Ausgang, fehlgeschlagener Start, veralteter Status und verlorene Antwort geprüft.
Eine alte Familienseite löst nach Entzug keine neue Aktion aus; Axe prüft auch
den gesperrten Zustand. Kein unerwarteter Konsolen-/Seitenfehler, kein Axe-Befund.
Ein zusätzlicher gezielter Browsercheck bestätigt, dass unveränderte Beobachtungen
keine erneuten `aria-live`-Textänderungen erzeugen.

Akzeptierte EP02-Tafeln für Dashboard, beide Details und Stoppdialog sowie die
aktuellen Desktop-/Mobil-Screenshots wurden mit `view_image` verglichen. Erfasst
bei 1280×1080 und 390×844; die Referenztafeln sind zusammengesetzte Desktop-/Mobil-
Ansichten und kein einzelner exakt reproduzierbarer Browserviewport.

| Vergleichspunkt | Ergebnis |
| --- | --- |
| Texte/Hierarchie | Serverzentrale, Spielwahl, direkte Aktionen, Server öffnen, Beitreten und Dialogbeschriftung erhalten |
| Spielidentität | Alle vier Originalbilder/-logos anhand der EP02-Hashes unverändert geprüft; keine generischen Ersatzmotive oder Tönung |
| Aufbau | Enshrouded links/oben, ASKA rechts/unten; gleiche Karten und Details; Smartphone im normalen Seitenfluss |
| Flächen/Schrift | Ruhige dunkle Flächen, feine Kanten, sparsame Goldakzente, Systemschrift und vorhandene App-Marke |
| Status/Bedienung | Prozess und Bereitschaft getrennt, echte deaktivierte Buttons, Statuspunkte mit Text, mindestens 44 px Bedienflächen |
| Dialog/Fokus | Abbrechen sichtbar golden fokussiert, Stoppen dunkelrot, Hintergrund inaktiv, Escape und Fokusrückkehr geprüft |
| Bewusste Ergänzungen | ASKA-Servername und bestätigte Beitrittshinweise statt unbelegtem Code; Vorschauhinweis und notwendige Fehler-/Fortschrittstexte |

Die sichtbaren Texte wurden gegen die bestätigte Liste abgeglichen; zusätzliche
Texte sind nur die genannten funktionalen Hinweise. Die Bildränder der originalen
PNG-Logos wurden über CSS berücksichtigt; Ausgangsdateien bleiben unverändert.
Keine wesentlichen offenen Gestaltungsabweichungen. Screenshots liegen als lokale
QA-Ausgaben außerhalb des Repositories.

PHP-/JavaScript-Syntax, Dokumentationsverweise, Originalasset-Hashes und der
Projekt-Skill sind geprüft. CI und unabhängiger Review werden am endgültigen
PR-Head dokumentiert. Reale PHP-/Serve-Aktivierung, Freigabeübernahme, Spielspeichern,
Beitritt mit echten Personen, Smartphone über Mobilfunk und Boot ohne Anmeldung
bleiben EP06; die vorhandene Betriebsinstallation wurde nicht verändert.

## EP06 – technische Vorbereitung, noch keine Betriebsabnahme

Zu #146/#163, 22.10.2026. Geprüfte Laufzeit: offizielle FrankenPHP-1.13.2-Datei,
PHP **8.5.12** für HTTP und `php-cli`; Downloadhash gegen den festgelegten
Release-Hash geprüft. PowerShell **7.6.7** bleibt die vorhandene Store-Installation.

`check-release.py` arbeitet nur in einem eigenen privaten Testverzeichnis. Bestanden:
schreibgeschützter Import ohne Besitzerverlust, Sperrreaktivierung oder Erweiterung
eingeschränkter Serverlisten; Leser bleiben gesperrt; geänderte Quelle erhält anderen
Fingerprint. Erneute Provisionierung überschreibt keinen Bestand. Verzeichnis-/Dateimodi,
Schlüssel und persistente Zustände geprüft.

Mit echter FrankenPHP-HTTP-Laufzeit zusätzlich bestanden: Neustart mit gleichem Zustand
und CSRF-Schlüssel; zweite Instanz abgewiesen; Wartung blockiert Auftragsannahme;
alte Releasebindung abgewiesen. Langsamer Executor erbt keine Verwaltungslease.
Laufende/unklare Operationen und gehaltene Statussperren verhindern den Wechsel.
Tatsächlicher HTTP-Codewechsel, Verwaltungsneustart, bewusster Rückweg sowie Rückweg
bei absichtlich fehlender Startbestätigung bestanden. Nach dem Wechsel gesperrte
Freigaben und aktuelle Schlüssel/Operationsbelege bleiben beim Rückweg erhalten.
Windows-Taskaufrufe sind in dieser portablen Prüfung vollständig ersetzt.

`npm run test:app` mit derselben Laufzeit bestanden. Native Prüfung unter Windows:
gemockter Starter und Task-Aktionswechsel sowie eigene native Spielstellvertreter
einschließlich HTTP → einzelnes PHP → PowerShell → beide Spiele bestanden.
Die bestehenden Spielinstallationen wurden dafür nicht gestartet.

Lesende Hostprüfung separat: bestehende Besitzer-/Passwort-/Bootaufgabe und geplanter
PowerShell-7-Aktionswechsel passen; keine Taskänderung. Aus bisherigen privaten
Bindungen erzeugte Testprofile validieren beide vorhandenen Weltverzeichnisse über
`Assert-World`. Native Statusabfrage bestätigt beide Spiele gestoppt. Diese Profile
lagen nur in einem eigenen Windows-Tempverzeichnis und wurden danach entfernt.
Bestehendes Serve zeigt auf Familienbackend 8767; keine Netzänderung.

Browser-QA: eigene synthetische Installation mit Produktionsmarker und PHP-Peers,
keine echten Profile. Allgemeiner Browser-MCP zuerst für Navigation und Snapshot;
Klick-/Viewport-APIs werden dort nicht angeboten. Deshalb vorhandenen Projekt-Playwright
für die Interaktion verwendet, ohne Installation oder Änderung der Sandbox.

| Prüfung | Ergebnis |
| --- | --- |
| Identität/erster Inhalt | Richtige URL/Titel, beide Spielkarten, kein Overlay |
| Wartung | Alle Spielaktionen gesperrt, verständliche Meldung; erwartetes HTTP 409 beim Refresh |
| Desktop 1280 × 1000 | Nach Freigabe Start, bestätigter Prozessstatus, Navigation zu Details |
| Mobil 390 × 844 | Start, Stoppdialog mit Abbrechen-Fokus, Escape, kein horizontaler Überlauf |
| Rechte/Beitritt | Lokaler Verwaltungslink, fehlende Joinwerte nicht kopierbar |
| Axe/Konsole | Keine Axe-Befunde auf geprüften Ansichten, keine Appfehler; erwartete 409 erklärt |
| Produktionsansicht | Testdaten-Fußzeile fehlt, bestehendes Layout/Originalassets unverändert |

Screenshots außerhalb des Repositorys unter
`~/.codex/browser-tools/general/output/gameserver-ep06-{maintenance,desktop,mobile}.png`,
mit `view_image` geprüft. Eigene Browser-/Testinstanz anschließend beendet.

Die spätere freigegebene Aktivierung, Übernahme der drei bestehenden Zugänge,
echten Starts und der Verwaltungsneustart mit unveränderten Spielidentitäten sowie
der vom Nutzer bestätigte Smartphonezugriff sind im [Betriebsnachweis](operation.md#betriebsnachweis-vom-22102026)
getrennt dokumentiert. Der Nutzer bestätigt beide Bestandswelten und Beitritte;
reguläre native Stopps und danach inhaltlich geänderte Weltdateien sind belegt.
Das ist noch keine vollständige Betriebsabnahme: passende andere Familienidentität,
aktive Spielerunde beim Verwaltungsneustart und Windows-Boot ohne Anmeldung bleiben offen.

Eine fünfsekündige Stichprobe der aktiven Verwaltung während dieser Betriebsprüfung
zeigt genau einen Serverprozess, RSS 103572–103608 KiB und 16 Threads, an beiden
Messpunkten ohne Kindprozesse. Die CPU-Zeit steigt um zwei Ticks bei 10 ms Auflösung.
Das ist eine kurze Betriebsstichprobe, keine Last- oder Leerlaufmessung.

Die Main-CI nach dem Dokumentationsmerge #165 machte einen Lesewettlauf im
Startstatus sichtbar. Der ergänzte Regressionstest schreibt über eine zweite
SQLite-Verbindung zwischen SELECT und Auslesen der Statuszeilen: am alten Code
reproduziert er die Mischung aus altem Prozess und neuem Auftragsabschluss.
Ein gemeinsamer SELECT für Beobachtung und neuesten Auftrag erhält denselben
Datenbank-Snapshot; der nächste Aufruf sieht den abgeschlossenen Start.
Gezielter Regressionstest und `npm run test:app` mit derselben PHP-8.5.12-Laufzeit
bestanden; keine echten Spielaktionen für diese Korrektur.
