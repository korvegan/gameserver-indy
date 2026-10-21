# Leistung und Ruckler

Unter Übersicht → „Leistung & Ruckler“ erscheinen Server- und Rechnerwerte mit
24 Stunden lokaler Aufbewahrung. Der vorhandene native Prozessabruf liefert die
Zähler mit, ohne PowerShell für jede Kennzahl zu starten. Beobachtung ungefähr
alle acht Sekunden; gespeichert wird der letzte Messwert jeder Minute, maximal
1440 Werte und 200 eigene Markierungen je Profil. Die Detailansicht lädt nur,
wenn sie geöffnet ist. Ein fehlender Messwert bleibt unbekannt; Diagrammlücken
werden nicht überbrückt.

Rechnerweite WMI-Werte werden für höchstens acht Sekunden zwischen Profilen
geteilt; Prozesszähler bleiben frisch. Der erste native Vergleich zeigte sonst
etwa 1,65 statt 0,63 Sekunden je Statusaufruf. Der kleine private Hostcache vermeidet
diese wiederholte Messwartezeit; ein fehlender Cache wird regulär neu gemessen.

CPU des Servers = Differenz seiner CPU-Sekunden / vergangene Sekunden / logische
Windows-Prozessoren × 100. Ein vollständig belegter logischer Prozessor auf einem
24-Thread-Rechner entspricht daher etwa 4,2 %. Die erste Probe, ein neuer PID/Start
oder eine längere Messlücke haben noch keinen gültigen Differenzwert. Rechner-CPU
und RAM sind Windows-Gesamtwerte einschließlich Spielclient und anderer Programme.
Prozess-RAM ist Working Set. Prozess-I/O enthält Lese-/Schreibtransfers und ist
keine getrennte physische Datenträger- oder Netzwerkmessung.

„Gerade ruckelt’s“ speichert einen Zeitpunkt mit optionaler Notiz, etwa „Hostclient
aktiv, vier Spieler“. Markierungen und vorhandene Start-/Stopp-/Backup-/Updateprüf-
und Konfigurationsaufträge erscheinen zeitlich gemeinsam. Ein Konfigurationsauftrag
ist kein Nachweis eines automatischen Welt-Speichervorgangs. Beobachtete
Prozesswechsel nennen die Beobachtungszeit, keine erfundene genaue Stoppzeit.

Lesen benötigt `status.read`, Markieren `config.write` am gewählten Profil, jeweils
serverseitig. Keine Prozessargumente, persönlichen Konten oder absoluten Pfade im
Verlauf. Fehler des optionalen Verlaufs unterbrechen keine Serveraktion. An CPU-
Affinität, Prioritäten und Energieplan wird nichts geändert.

Noch praktisch offen in #8: Viererrunde mit Hostclient als Vergleichslast,
Datenträger-/Uploadmessung und tatsächlicher Sicherungs-/Speicherlast. Ohne eigene
Messquelle werden weder Server-Tickrate noch Client-FPS behauptet. Die begrenzte
Historie ist ein Werkzeug für diesen Vergleich, keine bereits erfolgte Geräteabnahme.

Quellen: [Windows-Prozesszähler](https://learn.microsoft.com/en-us/windows/win32/cimwin32prov/win32-process),
[Rechner-CPU](https://learn.microsoft.com/en-us/windows/win32/cimwin32prov/win32-processor),
[Windows-Arbeitsspeicher](https://learn.microsoft.com/en-us/windows/win32/cimwin32prov/win32-operatingsystem).
