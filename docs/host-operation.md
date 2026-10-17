# Indy als Spiel-PC und Familienserver

Bestandsaufnahme und reversible Anpassung vom 17.10.2026, zu #16/#17. Die Verwaltung läuft ausschließlich mit PHP/Symfony/FrankenPHP; Spielserver bleiben native Windows-Prozesse. Laufendes Release und private Daten sind getrennt. Die neue Verwaltungsinstallation hat beide bereits gestoppten Spielserver übernommen, ohne sie zu starten oder ihre Welten umzuschalten.

## Tatsächlich geprüft

| Bereich | Befund |
| --- | --- |
| Windows | Version 10.0.26220, Insider-Build; letzter Hoststart 14.10.2026 |
| Startaufgabe | `GameServer Indy Verwaltung`, gleiche Besitzer-SID, Passwort-Anmeldetyp, Boot-Trigger, `IgnoreNew`, unbegrenzte Laufzeit; letzter Ergebniscode 0, Zustand Ready |
| Privater Zugang | Tailscale verbunden, unbeaufsichtigter Modus, Dienst automatisch; bestehende begrenzende Firewallregeln bestätigt |
| Lokaler Zugang | `http://127.0.0.1:8765`, Familienbackend nur Loopback 8767 |
| Mobilzugang | `https://indy.taila774ef.ts.net` mit verbundener persönlicher Tailscale-App; Gerätefreigabe und App-Rechte getrennt |
| Freier Speicher | Momentaufnahme: C etwa 446 GiB, D etwa 496 GiB, E etwa 766 GiB, F etwa 828 GiB |
| GPU ohne Spielrunde | NVIDIA-Abfrage: 35 °C, etwa 17,5 W GPU-Leistung, etwa 1,6 GiB Grafikspeicher; kein Gesamtverbrauch des PCs und kein Lasttest |
| AC-Standby | Weiterhin deaktiviert; vorhandener Energieplan erhalten |
| AC-Bildschirm | Von dauerhaft an auf Abschaltung nach 15 Minuten geändert; Rücklesen bestätigt 900 Sekunden |

Vor der Bildschirmänderung wurde der komplette aktive Energieplan nach `%LOCALAPPDATA%\GameServerIndy\maintenance\power-20261017.pow` exportiert (8192 Bytes). Aktiver Plan: `7ad4bd8f-3cf0-4f35-9382-02ad10c11a2e`. Ausschließlich der AC-Bildschirmtimer wurde angepasst. Der direkte Rückweg im selben Energieplan lautet `powercfg /change monitor-timeout-ac 0`. [Microsoft dokumentiert diese Einstellung und Energieplanexporte](https://learn.microsoft.com/en-us/windows-hardware/design/device-experiences/powercfg-command-line-options).

## Noch praktisch abzunehmen

Die registrierte Startaufgabe beweist keinen Kaltstart ohne Anmeldung. Im nächsten bewusst gewählten Betriebsfenster Windows neu starten, am Desktop zunächst **nicht** anmelden und den privaten Dashboardzugriff von einem anderen Tailscale-Gerät prüfen. Anschließend im tatsächlichen Aufgaben-/Besitzerkontext ASKA mit der richtigen Welt starten und einen regulären Speicher-/Stoppablauf nachweisen. ASKA-DPAPI und WSL gehören zum bestehenden Besitzerkonto; SYSTEM, S4U oder Desktop-Autologin ersetzen diesen Kontext nicht. Keine Kennwörter in Chat oder Skripte schreiben.

Erst nach dieser Abnahme eine optionale Spielserver-Autostartregel je Spiel ergänzen und einschalten. Der heutige Boot-Task startet ausschließlich die Verwaltung. Manuell gestoppte Spiele bleiben gestoppt. Noch kein automatischer Spielstart oder unbeaufsichtigter Windows-Neustart wurde eingerichtet.

Die Viererrunde mit gleichzeitigem lokalem Spielclient muss CPU, RAM, Datenträger, Upload, GPU und tatsächliches Ruckeln/Spielstandspeichern messen. Die Verlaufansicht und Markierungen aus #8 sind dafür vorbereitet. Ein GPU-Leerlaufwert ersetzt weder diese Messung noch ein Strommessgerät an der Steckdose. Keine pauschale Echtzeitpriorität, Kernzuweisung oder Änderung des bewährten Energieplans ableiten. DHCP-/Routerregeln bei einem wirklichen Außentest prüfen; ein lokaler Port oder DNS-Eintrag allein beweist keine externe Spielverbindung.

## Stabile Windows-Version und Wartung

Für den Familienbetrieb ist eine reguläre Windows-Ausgabe sinnvoll. Zuerst in **Windows Update → Windows-Insider-Programm** tatsächlichen Kanal und angebotenen Ausstieg prüfen. [Microsoft beschreibt den vorgesehenen Ausstieg](https://www.microsoft.com/en-us/windowsinsider/leave-program/); [die 2026 geänderten Kanäle und Wechselmöglichkeiten](https://blogs.windows.com/windows-insider/2026/04/10/improving-your-windows-insider-experience/) hängen vom verwendeten Windows-Kern/Kanal ab. Aus Build 26220 allein folgt kein sicherer direkter Wechsel. Kein Registry-Downgrade, keine Neuinstallation und kein Update-Stopp wurden vorgenommen.

Vor einem Betriebssystemwechsel braucht es ein separates Fenster und einen nachweislich verwendbaren Rückweg für Windows-Besitzerkonto/WSL, ASKA-DPAPI, beide Installationen, Konfigurationen und private Verwaltungsdaten. Ein konkretes wöchentliches Wartungsfenster sowie das gewünschte Verhalten nach Stromausfall sind noch nicht festgelegt. BIOS-/Firmwareänderungen erst anhand der tatsächlichen Geräteoptionen beurteilen.
