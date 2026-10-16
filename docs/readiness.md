# Prozess und Spielbereitschaft

Die Serverzentrale zeigt Prozessstatus und Spielbereitschaft getrennt. Ein Prozess
kann laufen, während die Welt lädt oder die Steam-Sitzung fehlt. „Sitzung bereit ·
lokal bestätigt“ bezeichnet einen passenden Spielmarker, keinen erfolgreichen
Beitritt über Internet oder Router. Quelle und Beobachtungszeit stehen in der Übersicht.

`Runtime/Readiness` wird gemeinsam von Prozessbeobachter und WhatsApp verwendet.
Der Beobachter speichert den Nachweis; HTTP-Statuspolls lesen ausschließlich den
30 Sekunden gültigen Cache. Ein Verwaltungsneustart erfindet keinen neuen Start.
Fehlende/veraltete Prozessdaten ergeben unbekannt.

- Enshrouded: UTC-Startkopf muss zur nativen Prozessstartzeit passen; der letzte
  zuverlässig gelesene HostOnline-Übergang muss „up“ sein. Fehlende Abschnitte oder
  ein alter Startkopf liefern keinen positiven Nachweis.
- ASKA: Zeitgestempelte Sitzungsöffnung im eigenen Starterlog muss nach dem
  aktuellen Prozessstart liegen. Die Shaderdarstellung beeinflusst den Nachweis nicht.

Ein belegter aktueller Start ohne Bereitschaftsmarker zeigt eine Startphase, nach
fünf Minuten einen Hinweis auf das Startprotokoll. Der Hinweis startet keinen
weiteren Prozess, beendet keinen laufenden Server und erklärt unbekannte Daten
nicht als Fehler. Laufende Stopp-/Neustartaufträge bleiben getrennt im Auftragsstatus.

Der Nachweis enthält `revision`, `state`, Quelle, Beobachtungszeit und intern eine
Prozessbindung. Gleicher Beleg erzeugt dieselbe Revision, auch bei wiederholter
Beobachtung. Benachrichtigungen können diese Kennung für ihre eigene dauerhafte
Duplikatsperre verwenden; die Beobachtung versendet selbst keine Meldungen.
Konkrete Nachrichtenzustellung und Discord-Statuskarte gehören zu #15.
