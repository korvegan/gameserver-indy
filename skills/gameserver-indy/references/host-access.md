# Windows und Familienzugriff

Anleitung: `docs/family-access.md`. WSL-Distribution `Indy`, Linux-/Windows-Benutzer `korvegan`; Launcher `%LOCALAPPDATA%\GameServerIndy`.

- Prozesse anhand kanonischem vollständigem EXE-Pfad, PID und Startidentität steuern. Windows liefert auch 8.3-Pfade. Windows-/UNC-Pfade nicht erneut mit `wslpath -w` umwandeln; das verursachte „Serverprogramm nicht gefunden“.
- WSL-Argumentnamen unquoted lassen, nur Werte quoten. Gequotetes `--distribution` ließ einen Kaltstart scheitern.
- Windows PowerShell 5.1 erbt hier PowerShell-7-Modulpfade. Für DPAPI Security-Modul explizit aus `$PSHOME\Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1` importieren; nur Modulname verursachte Typdatenfehler.
- ASKA-DPAPI/WSL benötigen das Besitzerkonto. S4U hat keinen Zugriff auf dessen verschlüsselte Dateien. Starttask nutzt Passwortanmeldung und SID; PIN ist kein Microsoft-Kontokennwort. Keine Zugangsdaten protokollieren.
- Tailscale wurde persönlich angemeldet, unbeaufsichtigter Modus aktiv. Starttask am 16.10.2026 manuell erfolgreich aufgerufen. Kaltstart ohne Desktop-Anmeldung und ASKA-DPAPI in diesem Zustand bleiben praktisch abzunehmen.
- Androidzugriff über Mobilfunk/Tailscale bestätigt. Auf Indy ist DNS-Übernahme absichtlich deaktiviert (`CorpDNS=false`); gewöhnliche DNS-Server liefern für private ts.net-Adresse NXDOMAIN. Lokal `127.0.0.1:8765` verwenden, keine globale DNS-Umstellung erzwingen.
- Andere Tailnets: **Machines → indy → Share machine** ließ Indy erst im Familienhandy erscheinen. Benutzereinladung allein verbindet kein Gerät. Netzwerk- und persönliche App-Freigabe sind getrennt.
- Axel und Tina (Akirra) haben auf Nutzerwunsch seit 16.10.2026 die Rolle Besitzer. Nicht wieder auf „nur starten“ einschränken. WhatsApp-Kontoeinrichtung bleibt ausschließlich lokalem uneingeschränktem Besitzer vorbehalten.
- Familienlistener `8767`; Fixtures eigene Ports, z. B. `18767`. Geschützte CGI-Variable `INDY_LISTENER_PORT` identifiziert Listener, niemals HTTP-/Forwarded-Header.
- WSL `mirrored`; Docker-Caddy auf `0.0.0.0:443` verursachte Tailscale-Bindkonflikte. Linux-/Windows-Listener gemeinsam betrachten; fremden Gateway nicht still entfernen.

Aufnahme 15.10.2026: i9-12900KF, etwa 96 GB RAM, RTX 3080/10 GB, vier Spieler inkl. Host, Spielclient und Server teilen PC. Native Windows-Prozesse unterliegen nicht WSL 32 GB/12 vCPU. Gemessener Upload 10,6 Mbit/s. AC-Schlaf/Ruhezustand/Monitor-Timeout waren deaktiviert; heutige Werte bei Bedarf lesen, keine Dauerbetriebsgarantie ableiten.
