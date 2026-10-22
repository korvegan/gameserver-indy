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
