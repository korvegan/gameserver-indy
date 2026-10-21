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
