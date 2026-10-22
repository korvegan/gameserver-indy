# Neuer Laufzeitstand

Aktiver Codebereich: `app/`. Aktuelle Anleitung und Rückweg:
`app/README.md` sowie `app/docs/retirement.md`. Der Entwicklungsstarter
`php app/bin/dev [Port]` erzeugt ausschließlich eine neue temporäre Fixture
und ersetzt sich durch einen FrankenPHP-Prozess. Er startet keine Spiele.

Der private Windows-Starter und die vorhandene Bootaufgabe verwenden
`%LOCALAPPDATA%\\GameServerIndy\\Start-Manager.ps1`. Die installierte Konfiguration
hat seit der freigegebenen EP06-Umstellung am 22.10.2026 `RuntimeEngine=php-mvp`,
ein unveränderliches Code-/Laufzeitpaket und getrenntes `AppStateDirectory`.
Release der Erstumstellung: `b6bbcc46744b3f942444f97461782a29a347ceec`, FrankenPHP 1.13.2 /
PHP 8.5.12. Aktuelle Werte immer aus `launcher.local.json` lesen. `/health` muss
Produktionsmodus, native Anbindung, erwarteten Build und Zustandskennung bestätigen.
Ein Aufrufertimeout allein beweist keinen Startfehler; den tatsächlichen Listener
und vorhandenen Auftrag prüfen, bevor ein weiterer Start versucht wird.

Die bisherige Symfony-Verwaltung ist auf ausdrücklichen Nutzerauftrag gestoppt.
Ihr zuletzt laufendes Release `df80d4abc61b015e08c1fb87e032abee8601eee5`,
private Daten und originale Launcherwerte bleiben als Rückweg erhalten.
Die Quellarchivierung verschiebt keine Welten, Konfigurationen oder privaten
Datenbanken. Rückweg nur anhand des lokalen Umstellungsnachweises und mit dem
aktuellen Datenstand planen; keine historischen Datenbanken zurückkopieren.

Der lokale Besitzerlistener liegt auf 8765, das Familienbackend auf 8767;
der bestehende private Tailscale-Serve-Weg bleibt unverändert. Beide echten Starts
und ein Verwaltungsneustart mit identischen Spiel-PIDs/Startzeiten sind belegt.
Der Besitzer bestätigt das neue Dashboard samt beiden Spielen auf dem Smartphone.
Der Nutzer bestätigt beide vertrauten Bestandswelten und Beitritte. Beide Stopps
sind nativ bestätigt; beide Weltordner enthalten danach neue inhaltlich geänderte
Speicherdateien. Das ersetzt keinen Nachweis mit anderer Familienidentität oder
gleichzeitig aktivem Spieler beim Verwaltungsneustart; diese Prüfungen bleiben offen.

Die bestehende Besitzer-/Passwort-/Bootaufgabe verwendet jetzt PowerShell 7.6.7
ohne ExecutionPolicy-Bypass. Ihr Aktionswechsel verlangte trotz administrativem
Kontext mit `0x8007052e` erneut das Windows-Kontokennwort. Der Nutzer führte
`Set-ScheduledTask` lokal mit `-User` und `-Password` aus; Konto und Trigger blieben
erhalten, XML-Sicherung liegt privat. Kennwort/PIN nie in Chat oder Repository erfassen.
Der aktuelle Startnachweis zeigt Windows-Sitzung 1. Boot ohne Desktopanmeldung
und ASKA in Sitzung 0 bleiben ungeprüft; Windows-Neustart braucht ausdrücklichen Auftrag.

Wenn ein eigener Prozess beendet werden muss: vollständigen Codepfad, PID und
Startidentität vor dem Signal prüfen. Keine Prozessnamen-Gruppen beenden.

Anleitung, private Nachweise und konkrete Betriebsgrenze: `app/docs/operation.md`.
Eine reine Vorbereitung aktiviert keinen Betrieb. Der verifizierte Weltcheckpoint
der Erstumstellung und `activation.json` liegen im privaten Releaseverzeichnis.
Rückweg setzt nur Codebindung zurück; aktuelle Zugänge, Schlüssel und native Belege
nie aus alten Datenbanken wiederherstellen. Erstumstellung fällt auf das synthetische
Gerüst zurück, nicht auf den archivierten Controller. Native Entwicklungstests bleiben
auf eigene Windows-Testprogramme begrenzt; reale Spielaktionen gehören ins Betriebsfenster.
