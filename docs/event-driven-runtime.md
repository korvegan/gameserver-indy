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
