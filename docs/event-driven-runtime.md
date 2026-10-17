# Ereignisbasierte Serververwaltung

Epic #118, Teilaufgaben #119–#122. Ziel: Im Leerlauf auf Ereignisse warten; neue Arbeit gezielt ausführen. SQLite bleibt die dauerhafte Quelle, ohne zusätzlichen Broker.

Abnahme: Aufträge nach erfolgreichem Commit wecken; neue vollständige Spiel-Logzeilen dedupliziert erkennen; native Datei-/Prozessereignisse nutzen; zeitgesteuerte Arbeit nur bei Fälligkeit; ein Livekanal im Dashboard; Rechte, FIFO und laufende Spielprozesse erhalten.

## Ereignisgrundlage (#119)

Unter WSL/Linux erhalten wartende Verbraucher private Unix-Datagramme. Sie melden sich vor dem Lesen der dauerhaften Daten an. Auftragsänderungen wecken erst nach dem äußeren erfolgreichen Commit. Die SQLite-Aufträge und Messenger-Zustellungen bleiben atomar und unverändert autoritativ. Im leeren Worker blockiert der Kernel; eine langsame Kontrolle nach höchstens einer Minute fängt verlorene Weckhinweise ab. Verzögerte Messenger-Zustellungen bestimmen einen früheren Wecktermin.

`runtime_events` hält ausschließlich typisierte bestätigte Spielereignisse mit Profil, eindeutiger Quellenkennung und Zeit fest. Derselbe Schlüssel erzeugt keinen zweiten Eintrag. Ein unbearbeiteter Eintrag bleibt bis zur bestätigten Folgebehandlung vorhanden. Rohlogs, Spielernamen und Zugangsdaten gehören nicht hinein.

Native Windows-PHP-Tests bleiben ohne Unix-Signale möglich. Der produktive Verwaltungsbetrieb läuft unter WSL; Windows ist Spielhost, keine zweite App-Laufzeit.
