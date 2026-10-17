# Laufzeit und Releases

`docs/symfony-runtime.md`, `docs/architecture.md`: Symfony/FrankenPHP unter WSL, Windows-Steuerung per PowerShell. Code-Releases und private Daten getrennt; alte Python-Cutoverpflichten aufgehoben.

Leistungsverlauf: `docs/performance.md`. Prozesszähler im vorhandenen nativen Abruf; rechnerweite WMI-Werte werden höchstens acht Sekunden zwischen Profilen geteilt. Ohne diesen kleinen Hostcache stieg der gemessene Statusaufruf von etwa 0,63 auf 1,65 Sekunden. Neue interne Prozessargumente auch in der Zulassungsliste von `symfony/tools/invoke-managed-server.ps1` ergänzen; sonst wird bereits der native Aufruf abgewiesen. Keine CPU-Affinität/Priorität aus den Hardwaredaten ableiten; echte Viererlast bleibt gesondert zu messen.

- `app:release:build`: sauberer vollständiger Commit, lockgleiche Composer-Installation, keine Originaldaten. Extrahierte Pfade/Blobs gegen Git-Tree prüfen: lokale Git-Attribute hatten Dateien mit `export-ignore` entfernt.
- Controllersperre, PID/Startidentität/Laufkennung erhalten. Nur nachgewiesenen eigenen Supervisor geordnet beenden, keine breite Prozessnamen-/Spielprozessgruppe.
- SIGKILL-Review reproduzierte verwaiste Verwaltungsprozesse; vorhandenen unabhängigen Wächter/Lease erhalten. Laufkennung erst nach Ende eigener Kinder freigeben.
- Rückweg: vorheriges PHP-Release mit **demselben aktuellen StateDirectory**, kein veralteter Datenbankrollback. Gemeinsame Daten lesend validieren, keine stillen Standardprofile/leere Zugriffsdatenbank erzeugen.
- Launcher-/Pfadänderungen über Shortcut-Installer, private RuntimeExecutable/StateDirectory erhalten. HTTP, Supervisor-cwd und Launcherzeiger gemeinsam prüfen.
- Beim letzten Wechsel lief der aufrufende Windows-Launcher-Harness in ein Timeout, obwohl neues Dashboard gesund war. Aufrufertimeout allein beweist keinen Startfehler; tatsächliche Bereitschaft vor Rückweg prüfen.
- Kommagetrennten Server-Header tokenweise erkennen: Windows kombinierte FrankenPHP-/App-Marker. Port-/Tokenprüfung erhalten.
- Native Ctrl+C-Stellvertretertests können umgeleiteten stdout verlieren; Testwrapper erfasst Pipeline in Antwortdatei. Produktivstopp daraus nicht ändern.
- PDO-Handles können über Exception-Traces Windows-Dateien sperren; eigene ungültige Fixture nach Tracefreigabe entfernen.

## Aufträge und Beobachtung

`ProcessObservation` ist veraltbare Lesesicht; Aktionen prüfen frisch. Jede Cache-Schreibgeneration erhält Kennung, auch bei gleichen Daten derselben Sekunde. Externer Starter sperrt Konfigurationsschreiben.

Steuerungsjobs je Profil FIFO/exklusiv, Metadaten eigener Worker. DBAL-Schreibfehler dürfen Messengerzustellung nicht verlieren; echter Worker zeigte Problem, das direkter Handleraufruf verdeckte. Beanspruchte Aufträge nur abgleichen, nicht erneut ausführen. Persistierte Revision verbindet Annahme/Snapshot/SSE; nach Snapshot Status neu laden, weil enthaltener Abschluss hinter Cursor liegen kann. Beim Verwaltungsende keine wartenden Aufträge mehr beginnen.
Wartung: `docs/maintenance.md`. Ein Plan wartet auf ausdrückliche Besitzerfreigabe, dann Vorlauf und vorhandene FIFO. Abbruch nur vor Jobannahme; neuer Verwaltungslauf oder entzogenes Recht verwirft wartende Pläne. Laufende Spielerzahl ohne geprüfte Quelle bleibt unbekannt; nur eindeutig gestoppter Server samt Starter belegt null. Direkte Besitzer-Steuerknöpfe bleiben unabhängig vom Status bedienbar.
