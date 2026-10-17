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
