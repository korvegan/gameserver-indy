# Lokale Umstellung auf das PHP-Gerüst

Nutzerauftrag vom 22.10.2026, [#149](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/149):
alte Verwaltung stoppen, Quellstand archivieren und auf die neue Struktur wechseln.
Die alte Verwaltung wurde anhand von PID, Startkennung, Arbeitsverzeichnis und
Lifecycle-Eigentümer geordnet beendet; ihre sechs verwalteten Kindprozesse endeten.
Die native Prozessprüfung meldete anschließend keine Enshrouded-/ASKA-Prozesse.
Es wurden keine Spielprozesse signalisiert, Welten verschoben oder Daten neu angelegt.

## Quellstand und Grenzen

`archive/php-legacy-20261022/snapshot/` bewahrt alle 426 versionierten Dateien von
`5b4e29ba0e0e0ce667a2e3bd9a605b9c283057cb`, einschließlich des früheren Python-Archivs.
`npm run check:archive` prüft Git-Blob-Identität, Dateimodi und Vollständigkeit.
Das zuletzt gestartete Release `df80d4abc61b015e08c1fb87e032abee8601eee5`
bleibt zusätzlich lokal unverändert erhalten.

`app/` ist jetzt die aktive Quelle. Sie besitzt lediglich eine lokale Diagnose
mit synthetischem Status. Echte Steuerung, Anmeldung, Familienzugang und das
Sturmklingen-Dashboard entstehen in EP03, EP04 und EP02/EP05. Der vorhandene
Tailscale-Serve-Weg zur alten Familienoberfläche wird nicht umkonfiguriert;
er erhält durch diese Umstellung keine neue Anwendung. Die Diagnose bindet nur
an Loopback. Windows-Boot ohne Desktopanmeldung ist noch nicht abgenommen.

## Installation nach geprüftem Merge

Den sauberen Hauptcheckout auf den geprüften Merge-Commit aktualisieren.
`app/tools/install.py` verlangt genau diesen HEAD und dessen Erreichbarkeit von
`origin/main`, eine ausführbare FrankenPHP-Datei, einen inaktiven alten Lifecycle
und einen freien lokalen Verwaltungsport.

Der Portcheck prüft direkt die Bindbarkeit an Loopback. Ein ausgehender
Verbindungstest wurde beim ersten realen Versuch durch ein Timeout abgebrochen,
obwohl kein Listener vorhanden war; dabei blieb der installierte Stand unverändert.

Beispiel mit dem vorhandenen Host:

```sh
python3 app/tools/install.py <vollständiger-merge-commit> \
  /mnt/c/Users/korvegan/AppData/Local/GameServerIndy \
  /home/korvegan/.local/share/gameserver-indy-tools/frankenphp-1.13.0/frankenphp
```

Der Installer erstellt ein neues Release unter
`~/.local/share/gameserver-indy/foundation-releases/<commit>/code` direkt aus
Git-Blobs von `app/` und schreibt `REVISION`. Ein vorhandenes Release wird nicht
überschrieben. Private Konfiguration und Windows-Starter werden vor dem Wechsel
unverändert in einem neuen privaten Ordner unter `GameServerIndy/archives/`
gesichert. Der ausgegebene Datensatz nennt diesen Rückweg.

Nur Engine, Codepfad, Commitkennung und FrankenPHP-Pfad ändern sich in der
Konfiguration. Benutzer, WSL-Distribution, Port, alter State-Pfad und zusätzliche
Einstellungen bleiben erhalten. Der neue Code liest die alten State-Dateien nicht.
Der Installer startet keinen Prozess und ändert weder Aufgabenplanung noch
Tailscale, Zugänge oder Spielprofile. Bei einem Schreibfehler stellt er die
beiden ursprünglichen Launcherdateien wieder her; die alte Verwaltung bleibt aus.

Anschließend den installierten `Start-Manager.ps1 -NoBrowser -PassThru` nativ
unter Windows aufrufen. Der bestehende Desktop-/Aufgaben-Einstieg bleibt erhalten.
Der Starter reicht den privaten FrankenPHP-Pfad ausdrücklich an den PHP-Einstieg
weiter. Bereits laufende passende Releases werden wiederverwendet; ein fremder
HTTP-Dienst wird abgelehnt. `/health` muss `mode=fixture`, `native_enabled=false`
und den installierten Commit bestätigen. Der erreichbare Einstieg ist beim
bisherigen Verwaltungsport `http://127.0.0.1:8765`.

## Rückweg

Zuerst nur den neuen FrankenPHP-Prozess anhand von Release-Arbeitsverzeichnis,
Kommandozeile und Startkennung identifizieren und geordnet beenden. Keine
Spielprozesse oder pauschalen Prozessnamen beenden. Die beiden Dateien aus dem
vom Installer genannten Sicherungsordner an ihre ursprünglichen Launcherpfade
zurückkopieren. Damit zeigt der Einstieg wieder auf das erhaltene alte Release
und dessen unveränderten State. Ein erneuter Start der alten Verwaltung ist ein
bewusster Betriebsschritt; die Sicherung selbst startet sie nicht.

## Gezielte Prüfung zu #149

- App-Verträge und echter FrankenPHP-HTTP-Start nach dem Pfadwechsel bestanden;
  eine leere SQLite-Datei ergibt für Health und Status 503, nach Wiederherstellung 200.
- Vollständiger historischer Snapshot: alle 426 Blobs und ausführbaren Modi identisch.
- Isolierter nativer PowerShell-Test: CheckOnly, Startargumente einschließlich
  FrankenPHP, Wiederverwendung und Ablehnung der alten Engine; keine echten Prozesse.
- Release-Test: versionierte App-Dateien, Modi, Commitkennung und erhaltene
  private Konfigurationsfelder; keine Installation im Test.

Den tatsächlichen Installations- und lokalen Erreichbarkeitsnachweis im zugehörigen
PR/Issue festhalten. Diese Prüfung ersetzt keine vollständige MVP- oder Bootabnahme.
