# Neuer Laufzeitstand

Aktiver Codebereich: `app/`. Aktuelle Anleitung und Rückweg:
`app/README.md` sowie `app/docs/retirement.md`. Der Entwicklungsstarter
`php app/bin/dev [Port]` erzeugt ausschließlich eine neue temporäre Fixture
und ersetzt sich durch einen FrankenPHP-Prozess. Er startet keine Spiele.

Der private Windows-Starter und die vorhandene Bootaufgabe verwenden
`%LOCALAPPDATA%\\GameServerIndy\\Start-Manager.ps1`. Die neue Konfiguration
hat `RuntimeEngine=php-foundation`, ein unveränderliches Code-Release und
`CodeCommit`. `/health` muss Fixture-Modus, deaktivierte native Anbindung und
passenden Build bestätigen. Ein Aufrufertimeout allein beweist keinen Startfehler;
den tatsächlichen Listener prüfen, bevor ein weiterer Start versucht wird.

Die bisherige Symfony-Verwaltung ist auf ausdrücklichen Nutzerauftrag gestoppt.
Ihr zuletzt laufendes Release `df80d4abc61b015e08c1fb87e032abee8601eee5`,
private Daten und originale Launcherwerte bleiben als Rückweg erhalten.
Die Quellarchivierung verschiebt keine Welten, Konfigurationen oder privaten
Datenbanken. Rückweg nur anhand des lokalen Umstellungsnachweises und mit dem
aktuellen Datenstand planen; keine historischen Datenbanken zurückkopieren.

Die neue Entwicklungsdiagnose bietet noch keinen Familienlistener, keine echte
Spielsteuerung und keine Spielserver-Autostartregel. Vorhandene Tailscale-
Netzeinstellungen werden beim Strukturwechsel nicht verändert. Ein echter
Windows-Neustart ohne Anmeldung und ASKA im Bootkontext sind dadurch weiterhin
nicht praktisch abgenommen; dafür bleiben EP03/EP04/EP06 zuständig.

EP05 ergänzt im Quellstand das Dashboard, Serverdetails und autorisierte einmalige
Aktionen. Die Vorschau verwendet weiterhin eigene synthetische Daten. Der installierte
Starter und seine private Konfiguration wurden dadurch nicht umgestellt; reale
Spiel-/Familienanbindung und Installation bleiben EP06. Native HTTP-Tests verwenden
ausschließlich eigene Windows-Testprogramme, keine vorhandenen Spielinstallationen.

Wenn ein eigener Prozess beendet werden muss: vollständigen Codepfad, PID und
Startidentität vor dem Signal prüfen. Keine Prozessnamen-Gruppen beenden.
