# EP03: Native Windows-Brücke

Zu [#143](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/143)
und [#157](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/157).
Die PHP-Klasse `src/WindowsBridge.php` ruft einmalig `windows/Bridge.ps1` mit
der vorhandenen **PowerShell 7.6+** auf. Lokal geprüft wird **7.6.7**. Die Brücke
benötigt weder einen Windows-Dienst noch einen laufenden Verwaltungsprozess.
Der kleine C#-Teil verwendet die Windows-APIs für kanonische Pfade, Besitzer
und sichere Konsolensignale. `Add-Type` kompiliert ihn im jeweiligen Helfer.
Der direkte `CreateProcess`-Aufruf verwendet eine eigene Konsole und vererbt
keine Handles. Der PowerShell-7-Startweg mit angepasster Umgebung ließ WSL sonst
auf vom Spiel geerbte Transporthandles warten, obwohl der Helfer beendet war.

## Lokale Bindung

Die private `profiles.local.json` liegt zusammen mit Journal und Austauschdateien
in einem eigenen Windows-Verzeichnis des Besitzers. Die Vorlage
[`profiles.example.json`](../windows/profiles.example.json) enthält die bestätigten
Installationspfade, aber absichtlich keine verwendbare Besitzer-/Weltbindung.
SID, vorhandene Save-ID und tatsächlich konfigurierte Weltpfade werden vor einer
späteren Betriebsaktivierung lokal eingesetzt. Pfade sind absolute Windows-Pfade,
keine Umgebungsvariablen oder Eingaben aus dem Browser. Brücke und Konfiguration
laufen im Windows-Besitzerkonto; auch gefundene Zielprozesse müssen diesem gehören.

Enshrouded startet seine EXE im Installationsverzeichnis. Dessen vorhandene
`enshrouded_server.json` muss mit `saveDirectory` auf den freigegebenen vorhandenen
Weltordner zeigen. ASKA erhält die externe Datei als korrekt gequotetes
`-propertiesPath`-Argument sowie SteamAppId/SteamGameId `1898300`. Ihre einzige
`save id` muss zum vollständigen Pfad unter dem tatsächlichen Windows-Besitzerprofil
passen: `AppData/LocalLow/Sand Sailor Studio/Aska/data/server/savegame_<id>`.
Ein gleichnamiger Ordner unter anderem Stammverzeichnis wird abgelehnt.
Die Brücke legt keine Welt an und ändert weder Spielkonfiguration noch Weltdateien.
Der bestehende ASKA-Discordstarter wird dabei nicht aufgerufen.

Die spätere Installation muss beide Skripte und die C#-Datei gemeinsam unter Windows
bereitstellen: Auf diesem Host lehnt RemoteSigned ein Skript auf dem WSL-UNC-Pfad
ab. Die native Prüfung kopiert ihre eigenen Dateien deshalb in Windows-Temp;
sie setzt weder ExecutionPolicy noch Hostberechtigungen herab.

## Transport und Zustände

PHP erhält zwei vertrauenswürdige lokale Werte: den WSL-Pfad dieses Verzeichnisses
und eine feste Argumentliste aus `pwsh.exe`, `-NoProfile`, `-NonInteractive`,
`-File`, lokalem Skriptpfad, `-StateDirectory` und dessen Windows-Pfad.
`call(server, action, operation)` ergänzt ausschließlich eine zufällige Request-ID.
Die eigentliche Nachricht enthält die vier Felder des [Vertrags](bridge-contract.md).
Es gibt keine frei übergebenen Befehle, Pfade oder Umgebungswerte.

Ein Aufruf schreibt `request-<nonce>.json`, der Helfer atomar
`reply-<nonce>.json`. PHP verwirft stdout/stderr, prüft Größe, Schema und exakte
Request-Bindung und liefert ausschließlich begrenzte Fehlercodes. Der Dateiweg
bleibt auch nach `FreeConsole` verwendbar und vermeidet vererbte Pipe-Wartezeiten.
Abgeschlossene Austauschdateien werden entfernt. Bei Transportabbruch bleiben
sie für den späteren Abgleich erhalten. Profile und Journal sind privat; sie
schützen nicht gegen andere Programme unter demselben Besitzerkonto.

Die Antwort enthält `version`, `server`, `action`, `operation`, `outcome`, `error`,
`process`, `ready`, `observed_at`, `identity`. Outcomes sind `succeeded`, `failed`,
`unknown`, `busy`; Identität ist PID plus UTC-Startzeit oder `null`.
`ready` bleibt ohne gesonderten Spielnachweis immer `null`.
Ein gespeichertes Operationsergebnis und die aktuelle Prozessbeobachtung können
verschieden sein: Ein alter erfolgreicher Start wird auch bei inzwischen
gestopptem Prozess nicht erneut ausgeführt.

Eine nicht wartende Dateisperre serialisiert Änderungen pro Spiel. Die teure
Prozessbeobachtung beginnt vor dieser Sperre; vor Änderungen wird darunter erneut
beobachtet. Reiner Prozessstatus nimmt keine Sperre; ein expliziter
Operationsabgleich versucht sie ohne Warten. Enshrouded und ASKA
besitzen getrennte Sperren und Journale. Vor jedem Seiteneffekt wird ein
unbekannter Ausgang dauerhaft gespeichert; abgeschlossene Operations-IDs bleiben
als Belege erhalten. Ein erneuter Aufruf derselben ID wiederholt den Effekt nicht.

Nach Timeout oder Helferabbruch sperrt eine unbekannte Operation weitere Befehle.
Ein Abgleich darf sie nur mit belegter Prozessidentität auflösen: gleicher
gestarteter Prozess bestätigt Start, verschwundenes Stoppziel bestätigt dessen
Ende. Ist bei einem Start noch keine Zielidentität gespeichert, bleibt die Sperre
erhalten. Eine spätere manuelle Klärung muss die privaten Belege und tatsächlichen
Prozesse prüfen; Journaldateien werden nicht automatisch gelöscht oder durch einen
neuen Start übergangen. Beschädigte oder fehlende aktive Belege sperren ebenfalls.

Stoppen prüft kanonische EXE, PID, Startzeit und Besitzer erneut. Der Helfer hängt
sich nur vorübergehend an die Zielkonsole und sendet Strg+C ausschließlich, wenn
deren Prozessliste nur Ziel und Helfer enthält. Eine geteilte Konsole wird mit
`unsafe_console` abgelehnt. Der Helfer wartet höchstens den lokal eingestellten
Zeitraum, maximal 45 Sekunden. Timeout bleibt `unknown`; es gibt keinen harten
Abbruch als Ersatz. PHP begrenzt den Transport separat und beendet dabei allenfalls
seinen einmaligen Helfer, niemals das Spiel. Reagiert dieser unter WSL nicht auf
SIGTERM, wird ausschließlich dieser eigene Transport nach kurzer Frist mit SIGKILL beendet.

## Prüfung und nächste Anbindung

```sh
"$SLICE_PHP" app/tests/check-bridge.php
INDY_PWSH='/WSL/Pfad/zur/installierten/pwsh.exe' \
  python3 app/tests/check-windows-bridge.py
```

Für den nativen Lauf sind `SLICE_PHP` auf den vorhandenen CLI-Startweg und
`SLICE_FRANKENPHP` auf die vorhandene FrankenPHP-Datei zu setzen. Der Test
verwendet den bereits installierten .NET-Framework-C#-Compiler
für eigene Konsolen-Stellvertreter mit den beiden EXE-Namen, isolierte Welten und
Konfigurationen mit Leerzeichen. Nur die Windows-Profilverzeichnisabfrage wird in
einer eigenen Skriptkopie durch den Temp-Pfad ersetzt; der originale OS-Lookup wird
separat geprüft. Keine Testwelt liegt im echten AppData-Spielpfad. Der Test prüft
anschließend den echten PHP-/WSL-Transport.
Nur diese selbst kompilierten, pfad- und hashgeprüften Prozesse werden nötigenfalls
zur Testbereinigung hart beendet; die produktive Brücke enthält keinen solchen Weg.

EP05 ergänzt außerdem den echten HTTP-Weg: zwei Aktionen werden über getrennte
CLI-Prozesse zu beiden Windows-Stellvertretern gestartet und regulär gestoppt.
Der Test bestätigt Beobachtung und `ready=null`; die private Runtime-Datei zeigt
ausschließlich auf diese eigenen temporären EXEs. Der normale Entwicklungsstart
bleibt beim synthetischen Peer und meldet `native_enabled=false`.
EP04 bindet bestätigte Identität, aktuelle Rechte und Reservierung vor Annahme/
Ausführung an den PHP-Aufruf. Echte Profilinstallation, Speicher-/Beitrittsprüfung
und Bootabnahme bleiben den Betriebsarbeiten vorbehalten.
