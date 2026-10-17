# Laufzeit und Releases

`docs/symfony-runtime.md`, `docs/architecture.md`: Symfony/FrankenPHP unter WSL, Windows-Steuerung per PowerShell. Code-Releases und private Daten getrennt; alte Python-Cutoverpflichten aufgehoben.

Leistungsverlauf: `docs/performance.md`. Prozesszähler im vorhandenen nativen Abruf; rechnerweite WMI-Werte werden höchstens acht Sekunden zwischen Profilen geteilt. Ohne diesen kleinen Hostcache stieg der gemessene Statusaufruf von etwa 0,63 auf 1,65 Sekunden. Neue interne Prozessargumente auch in der Zulassungsliste von `symfony/tools/invoke-managed-server.ps1` ergänzen; sonst wird bereits der native Aufruf abgewiesen. Keine CPU-Affinität/Priorität aus den Hardwaredaten ableiten; echte Viererlast bleibt gesondert zu messen.

- `app:release:build`: sauberer vollständiger Commit, lockgleiche Composer-Installation, keine Originaldaten. Extrahierte Pfade/Blobs gegen Git-Tree prüfen: lokale Git-Attribute hatten Dateien mit `export-ignore` entfernt.
- Controllersperre, PID/Startidentität/Laufkennung erhalten. Nur nachgewiesenen eigenen Supervisor geordnet beenden, keine breite Prozessnamen-/Spielprozessgruppe.
- SIGKILL-Review reproduzierte verwaiste Verwaltungsprozesse; vorhandenen unabhängigen Wächter/Lease erhalten. Laufkennung erst nach Ende eigener Kinder freigeben.
- Rückweg: vorheriges PHP-Release mit **demselben aktuellen StateDirectory**, kein veralteter Datenbankrollback. Gemeinsame Daten lesend validieren, keine stillen Standardprofile/leere Zugriffsdatenbank erzeugen.
- Frische `slice:init`-Fixtures hatten fehlende spätere Metadatentabellen der Bestandsdaten verdeckt: erster HTTP-Erfolg, danach Planerabsturz. Supervisor ergänzt optionale Tabellen vor Kindstart additiv unter Controllersperre; Betriebsfixture enthält den alten Tabellenstand. Releasebereitschaft nach kurzer Stabilitätsphase erneut prüfen. Für Originaldaten niemals pauschal `slice:init` ausführen.
- Launcher-/Pfadänderungen über Shortcut-Installer, private RuntimeExecutable/StateDirectory erhalten. HTTP, Supervisor-cwd und Launcherzeiger gemeinsam prüfen.
- Beim letzten Wechsel lief der aufrufende Windows-Launcher-Harness in ein Timeout, obwohl neues Dashboard gesund war. Aufrufertimeout allein beweist keinen Startfehler; tatsächliche Bereitschaft vor Rückweg prüfen.
- Kommagetrennten Server-Header tokenweise erkennen: Windows kombinierte FrankenPHP-/App-Marker. Port-/Tokenprüfung erhalten.
- Native Ctrl+C-Stellvertretertests können umgeleiteten stdout verlieren; Testwrapper erfasst Pipeline in Antwortdatei. Produktivstopp daraus nicht ändern.
- PDO-Handles können über Exception-Traces Windows-Dateien sperren; eigene ungültige Fixture nach Tracefreigabe entfernen.

## Aufträge und Beobachtung

Ereignisarchitektur: `docs/event-driven-runtime.md`. Keine Acht-Sekunden-Statuspolls oder pro Auftrag laufenden Browserabfragen wieder einführen. Aufträge bleiben in SQLite; Weckhinweise nach äußerem Commit, Empfänger vor dem Lesen anmelden. Topic-Filter verhindern, dass Lograuschen Worker/Planer weckt. SSE initial und nach Wiederverbindung mit Snapshot abgleichen; ausgeblendete Tabs pausieren.

Windows-`FileSystemWatcher` auf `\\wsl.localhost\…` gab im Hosttest nur Fehler und keine Logänderungen. WSL-Dateien deshalb mit dem versionierten `inotify`-Helfer beobachten (`cc` einmal je Quellstand), Windows-Laufwerke nativ. Beide Quellen und Besitzer-EOF sind isoliert geprüft. Parser-Cursor bindet an Prozessstart; kopierte UTC-Startköpfe reproduzieren dieselben Ereignisschlüssel. Letztes Logende nach bekanntem Prozessstopp noch mit gespeicherter Identität lesen; dabei keine Bereitschaft neu bestätigen. Fehlende Logkonfiguration betrifft nur das Profil, nicht die ganze Verwaltung.

Bewusste verbleibende Fristen: aktive Messung/Startprüfung 30 s, langsamer Abgleich gestoppter Profile 300 s (eingeschränkte Ereignisquelle 60 s), leerer Auftragsworker höchstens 60 s bis zum Sicherheitsabgleich. Terminplaner wartet auf echte Fälligkeiten. Kurze Wächterprüfungen betreffen nur eigene Verwaltungsprozesse.

`ProcessObservation` ist eine Lesesicht mit begrenzter Gültigkeit passend zum Abgleichfenster; Aktionen prüfen frisch. Jede Cache-Schreibgeneration erhält Kennung, auch bei gleichen Daten derselben Sekunde. Externer Starter sperrt Konfigurationsschreiben.

Steuerungsjobs je Profil FIFO/exklusiv, Metadaten eigener Worker. DBAL-Schreibfehler dürfen Messengerzustellung nicht verlieren; echter Worker zeigte Problem, das direkter Handleraufruf verdeckte. Beanspruchte Aufträge nur abgleichen, nicht erneut ausführen. Persistierte Revision verbindet Annahme/Snapshot/SSE; nach Snapshot Status neu laden, weil enthaltener Abschluss hinter Cursor liegen kann. Beim Verwaltungsende keine wartenden Aufträge mehr beginnen.
Wartung: `docs/maintenance.md`. Ein Plan wartet auf ausdrückliche Besitzerfreigabe, dann Vorlauf und vorhandene FIFO. Abbruch nur vor Jobannahme; neuer Verwaltungslauf oder entzogenes Recht verwirft wartende Pläne. Laufende Spielerzahl ohne geprüfte Quelle bleibt unbekannt; nur eindeutig gestoppter Server samt Starter belegt null. Direkte Besitzer-Steuerknöpfe bleiben unabhängig vom Status bedienbar.
