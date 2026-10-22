# Windows und Familienzugriff

EP06: Der vorhandene Verwaltungstask verwendet noch Windows PowerShell 5.1.
Der vorbereitete reine Aktionswechsel nutzt die installierte PowerShell 7.6.7
im selben Besitzerkonto. Native lesende Profil-/Statusprüfung bestanden;
eine Bootabnahme lässt sich daraus nicht ableiten. Ablauf: `app/docs/operation.md`.

Anleitung: `docs/family-access.md`. WSL-Distribution `Indy`, Linux-/Windows-Benutzer `korvegan`; Launcher `%LOCALAPPDATA%\GameServerIndy`.

- Prozesse anhand kanonischem vollständigem EXE-Pfad, PID und Startidentität steuern. Windows liefert auch 8.3-Pfade. Windows-/UNC-Pfade nicht erneut mit `wslpath -w` umwandeln; das verursachte „Serverprogramm nicht gefunden“.
- WSL-Argumentnamen unquoted lassen, nur Werte quoten. Gequotetes `--distribution` ließ einen Kaltstart scheitern.
- Windows PowerShell 5.1 erbt hier PowerShell-7-Modulpfade. Für DPAPI Security-Modul explizit aus `$PSHOME\Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1` importieren; nur Modulname verursachte Typdatenfehler.
- Der neue EP03-Helfer setzt die installierte PowerShell 7.6+ voraus; lokal 7.6.7 als Store-Paket unter `Microsoft.PowerShell_8wekyb3d8bbwe/pwsh.exe`. Der 5.1-Befund oben betrifft den Altstand. Direkter ASKA-Start benötigt dessen Discord-DPAPI nicht.
- RemoteSigned lehnt hier `.ps1` auf `\\wsl.localhost\Indy\…` ab. Eigene Prüfscripte unter Windows-Temp bereitstellen; keine globale ExecutionPolicy-Änderung. Der endgültige Installationsweg folgt mit der Betriebsaktivierung.
- PowerShell-7-Start mit angepasster Umgebung vererbte Transporthandles und ließ WSL trotz beendetem Helfer weiter warten. EP03 startet mit `CreateProcess`, eigener Konsole und `inheritHandles=false`; Request/Reply bleiben dateibasiert, auch nach `FreeConsole`.
- ASKA-DPAPI/WSL benötigen das Besitzerkonto. S4U hat keinen Zugriff auf dessen verschlüsselte Dateien. Starttask nutzt Passwortanmeldung und SID; PIN ist kein Microsoft-Kontokennwort. Keine Zugangsdaten protokollieren.
- Tailscale wurde persönlich angemeldet, unbeaufsichtigter Modus aktiv. Starttask am 16.10.2026 manuell erfolgreich aufgerufen. Kaltstart ohne Desktop-Anmeldung und ASKA-DPAPI in diesem Zustand bleiben praktisch abzunehmen.
- Androidzugriff über Mobilfunk/Tailscale bestätigt. Auf Indy ist DNS-Übernahme absichtlich deaktiviert (`CorpDNS=false`); gewöhnliche DNS-Server liefern für private ts.net-Adresse NXDOMAIN. Lokal `127.0.0.1:8765` verwenden, keine globale DNS-Umstellung erzwingen.
- Andere Tailnets: **Machines → indy → Share machine** ließ Indy erst im Familienhandy erscheinen. Benutzereinladung allein verbindet kein Gerät. Netzwerk- und persönliche App-Freigabe sind getrennt.
- Axel und Tina (Akirra) haben auf Nutzerwunsch seit 16.10.2026 die Rolle Besitzer. Nicht wieder auf „nur starten“ einschränken. WhatsApp-Kontoeinrichtung bleibt ausschließlich lokalem uneingeschränktem Besitzer vorbehalten.
- Familienlistener `8767`; Fixtures eigene Ports, z. B. `18767`. Geschützte CGI-Variable `INDY_LISTENER_PORT` identifiziert Listener, niemals HTTP-/Forwarded-Header.
- EP04 im Neubau nutzt die geschützte CGI-Variable `INDY_LISTENER` (`local`/`family`), getrennte Loopback-Listener und den exakten `HTTP_HOST`. FrankenPHP lässt Host in `getallheaders()` aus. Browserformulare brauchen `Referrer-Policy: same-origin`; `no-referrer` führte unter Chrome zu `Origin: null` und korrekter Abweisung. Echte Serve-Aktivierung bleibt EP06.
- EP05 reicht für entkoppelte WSL-CLI-Aufrufe gezielt `WSL_INTEROP` und `WSL_DISTRO_NAME` weiter; native HTTP-Prüfung mit beiden Windows-Stellvertretern bestanden. Den vollständigen `SLICE_PHP`-Wrapperstartweg erhalten, nicht nur `PHP_BINARY`. Keine globale WSLENV-/Netzänderung dafür nötig.
- WSL `mirrored`; Docker-Caddy auf `0.0.0.0:443` verursachte Tailscale-Bindkonflikte. Linux-/Windows-Listener gemeinsam betrachten; fremden Gateway nicht still entfernen.

Aufnahme 15.10.2026: i9-12900KF, etwa 96 GB RAM, RTX 3080/10 GB, vier Spieler inkl. Host, Spielclient und Server teilen PC. Native Windows-Prozesse unterliegen nicht WSL 32 GB/12 vCPU. Gemessener Upload 10,6 Mbit/s. AC-Schlaf/Ruhezustand/Monitor-Timeout waren deaktiviert; heutige Werte bei Bedarf lesen, keine Dauerbetriebsgarantie ableiten.

Stand 17.10.2026: `docs/host-operation.md`. AC-Bildschirmtimer nach Export des bestehenden Energieplans auf 900 s gesetzt und zurückgelesen; Standby bleibt aus, keine Kern-/Prioritäts-/Betriebssystemumstellung. Rückweg gleicher Plan: `powercfg /change monitor-timeout-ac 0`; vollständige private `.pow` unter `%LOCALAPPDATA%\GameServerIndy\maintenance`. Startaufgabe weiterhin Besitzer-SID, Password/Boot/IgnoreNew/PT0S, letzter Code 0. Kein Kaltstartnachweis daraus ableiten. GPU-Leerlaufwert ist kein PC-Gesamtverbrauch. Echte Kaltstart-/ASKA-/Viererabnahme bleibt offen.
