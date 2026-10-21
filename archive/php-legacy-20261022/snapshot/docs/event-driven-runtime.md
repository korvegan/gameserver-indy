# Ereignisbasierte Serververwaltung

Epic #118, Teilaufgaben #119–#122. Ziel: Im Leerlauf auf Ereignisse warten; neue Arbeit gezielt ausführen. SQLite bleibt die dauerhafte Quelle, ohne zusätzlichen Broker.

Abnahme: Aufträge nach erfolgreichem Commit wecken; neue vollständige Spiel-Logzeilen dedupliziert erkennen; native Datei-/Prozessereignisse nutzen; zeitgesteuerte Arbeit nur bei Fälligkeit; ein Livekanal im Dashboard; Rechte, FIFO und laufende Spielprozesse erhalten.

## Ereignisgrundlage (#119)

Unter WSL/Linux erhalten wartende Verbraucher private Unix-Datagramme. Sie melden sich vor dem Lesen der dauerhaften Daten an. Auftragsänderungen wecken erst nach dem äußeren erfolgreichen Commit. Die SQLite-Aufträge und Messenger-Zustellungen bleiben atomar und unverändert autoritativ. Im leeren Worker blockiert der Kernel; eine langsame Kontrolle nach höchstens einer Minute fängt verlorene Weckhinweise ab. Verzögerte Messenger-Zustellungen bestimmen einen früheren Wecktermin.

`runtime_events` hält ausschließlich typisierte bestätigte Spielereignisse mit Profil, eindeutiger Quellenkennung und Zeit fest. Derselbe Schlüssel erzeugt keinen zweiten Eintrag. Ein unbearbeiteter Eintrag bleibt bis zur bestätigten Folgebehandlung vorhanden. Rohlogs, Spielernamen und Zugangsdaten gehören nicht hinein.

Native Windows-PHP-Tests bleiben ohne Unix-Signale möglich. Der produktive Verwaltungsbetrieb läuft unter WSL; Windows ist Spielhost, keine zweite App-Laufzeit.

## Spielparser (#120)

Enshrouded bindet `HostOnline (up/down)` an den UTC-Startkopf des tatsächlich beobachteten Prozesses. ASKA erkennt die mit UTC-Zeit versehene Sitzungsöffnung aus dem eigenen Starterprotokoll. Für weitere Meldungen, etwa Spielerbeitritte, fehlen noch geprüfte Beispiele; daraus werden keine Zahlen oder Errungenschaften erfunden. Shaderwarnungen erzeugen keine Aufträge.

Der Leser speichert Byteposition, Startidentität, Dateikennung und einen kurzen Prüfsummenanker. Er verarbeitet höchstens 512 KiB pro Schritt und liest nur neue vollständige Zeilen. Dateitausch/Kürzung und ein neuer Prozess setzen die passende Leseposition zurück. Gleiche Zustandsmeldungen erzeugen keinen zweiten Übergang; gespeicherte Ereignisschlüssel sichern die Wiederaufnahme ab.

Unbearbeitete Ereignisse nehmen einen `game_event`-Auftrag in der vorhandenen Metadatenwarteschlange an. Seine Anfragekennung bleibt bei erneuter Annahme identisch. Der Worker bestätigt den aktuellen Prozesszustand; eine automatische WhatsApp-Meldung benötigt weiterhin die ausdrücklich aktivierte Einrichtung und die bestehende Versand-Deduplizierung. Historische Ereignisse lösen keine unbedingten Starts, Stopps oder unklaren Wiederholungsversand aus. Die technischen Meldungen sind im Dashboard sichtbar.

## Native Ereignisse und Termine (#121)

Ein eigener, rein lesender Windows-Helfer abonniert `FileSystemWatcher` für die bekannten Log-/Konfigurationsdateien und WMI-Prozessstart/-ende für registrierte EXE-Namen. Über seine Ausgabe gelangen nur Art und Profilkennung zu PHP. Ein geschlossener Besitzer-Eingabekanal beendet den Helfer auch nach einem Verwaltungsabbruch. Er steuert keine Spiele. Änderungen desselben gelesenen Pakets werden pro Profil zusammengefasst.

Der Beobachter wartet in `stream_select`. Logänderungen stoßen den Parser an; Prozessänderungen führen zur frischen Statusprüfung. Neue Profile und Konfigurationsänderungen erneuern die Abonnements. Zur langsamen Wiederherstellung nach verlorenen Windows-Ereignissen bleiben gestoppte Profile höchstens fünf Minuten ohne erneuten Abgleich; meldet der Ereignishelfer Einschränkungen, beträgt dieser Abstand eine Minute. Laufende/startende Server werden für Bereitschaft und Leistungsverlauf alle 30 Sekunden geprüft. Die gespeicherte Lesesicht hat ein ausdrücklich begrenztes Gültigkeitsfenster; Aktionen prüfen weiterhin frisch.

Der Planer schläft bis zur nächsten Wartungsfrist, Steam-/Netzwerkprüfung oder Discord-Fälligkeit. Status-/Einstellungsereignisse wecken ihn früher. Gemeinschaftsziele werden bei Start und Änderungen abgeglichen. WhatsApp-Bereitschaft wird über Spielereignisaufträge statt einen 15-Sekunden-Rundlauf ausgelöst. Discord-Karten reagieren auf Änderungen und behalten einen Fünf-Minuten-Zeitstempel sowie ihre Wiederholungsfristen. Topic-Filter verhindern, dass Lograuschen die Auftragsworker oder den Planer weckt.

Die kurzen lokalen Prüfungen des vorhandenen Supervisor-Wächters betreffen ausschließlich dessen eigene Verwaltungsprozesse. Sie sind keine Spielstatus-/Netzwerkabfragen und bleiben zum Schutz vor verwaisten Workern erhalten.

Windows-`FileSystemWatcher` meldete für `\\wsl.localhost\…` im Hosttest lediglich einen Fehler und anschließend keine Änderungen. Deshalb beobachtet ein kleiner nativer `inotify`-Helfer WSL-lokale Dateien direkt. Nur Windows-Laufwerke gehen an den Windows-Dateiwächter. Der Linux-Helfer enthält keine Spiellogik, blockiert im Kernel und endet ebenfalls bei Besitzer-EOF. Sein versionierter C-Quelltext wird einmal je Quellstand mit dem vorhandenen `cc` in die private Laufzeitablage gebaut; kein zusätzlicher Nachrichtendienst und keine Python-Laufzeit. Linux/WSL benötigt dafür einen C-Compiler. Ein Hosttest prüft beide Ereignisquellen gemeinsam über den tatsächlichen WSL-Pfad.

## Liveoberfläche (#122)

Die Oberfläche lädt einen Ausgangsstand und folgt danach einem gemeinsamen SSE-Kanal für Aufträge und erlaubte Änderungsarten. Statuswechsel aktualisieren die betreffende Übersicht, Logänderungen die sichtbare Konsole, Messpunkte die sichtbare Leistung und Gemeinschaftsänderungen einen Hinweis unter Erhalt der Leseposition. Es gibt keine Acht-Sekunden-Refreshschleife und keine Einzelabfragen wartender Aufträge. Ein kurzer Einmal-Timer fasst gleichzeitig eintreffende Änderungen zusammen.

Der Server wartet auf lokale Ereignisse. Ein Protokoll-Heartbeat nach 15 Sekunden hält die Verbindung und Rechteprüfung lebendig; dabei wird nur der dauerhafte Auftragsstand abgeglichen, kein Spielprozess oder Netzwerkdienst abgefragt. Nach höchstens fünf Minuten wird die Verbindung erneuert. Ausgangsstand, Cursor und Auftragsrevisionen schließen Wiederverbindungslücken. Auch ein Abschluss hinter dem neuen Cursor löst den zuvor wartenden UI-Auftrag. Ausgeblendete Tabs schließen ihren Kanal; beim Wiederanzeigen wird abgeglichen.

WhatsApp fragt nur während einer offenen QR-/Bestätigungseinrichtung weiter nach. Eine verbundene oder gestoppte Einrichtung besitzt keinen regelmäßigen UI-Timer. Start/Stopp/Neustart bleiben unabhängig vom angezeigten Status als Aufträge bedienbar.

Technische Grundlagen: [Symfony Messenger 7.4](https://symfony.com/doc/7.4/messenger.html), [Windows FileSystemWatcher](https://learn.microsoft.com/en-us/dotnet/api/system.io.filesystemwatcher), [WMI-Ereignisse](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.management/register-wmievent).

Lograuschen wird vor dem Parser je Profil über ein einmaliges 250-ms-Fenster gebündelt; die sichtbare Konsole lädt bei fortlaufenden Änderungen höchstens ungefähr einmal pro Sekunde nach. Ohne neue Ereignisse läuft kein solcher Timer. Wenn ein lange ausgeblendeter Tab noch einen Auftrag außerhalb des jüngsten Verlaufs kennt, liest er dessen Ergebnis beim Wiederverbinden einmal gezielt nach; daraus entsteht keine Pollschleife.

Livekanäle belegen PHP-Threads. Die Zahl gleichzeitiger Kanäle ist deshalb auf die konfigurierte Threadzahl minus zwei begrenzt. Bei den vorhandenen sechs Threads stehen höchstens vier Livekanäle offen; zusätzliche Tabs erhalten einen Hinweis und versuchen es später erneut. Normale API-Aufrufe und Bedienbefehle behalten Kapazität. Ein isolierter Überlastnachweis mit sieben zusätzlichen Kanälen prüft diese Grenze ohne Spielaktionen.

Beim ersten Wechsel fehlte der Bestandsdatenbank die erst später ergänzte Discord-Kartentabelle. Ein frisch initialisierter Teststand hatte diese Lücke verdeckt. Der Supervisor ergänzt die optionalen Metadatentabellen jetzt additiv unter seiner exklusiven Sperre, bevor Planer und Worker starten. Die Betriebsfixture beginnt entsprechend ohne diese neuen Tabellen. Die Aktivierung bestätigt nach kurzer Stabilitätsphase nochmals HTTP und Laufzeitbesitzer; ein einmaliger früher HTTP-Erfolg reicht nicht.
