# Serverprotokolle

Unter „Protokoll & Aufträge“ sind Spielserver und Starter getrennt auswählbar.
Der Abruf liest höchstens 4000 Zeilen aus den letzten 256 KiB und bereinigt
Zugangsdaten vor Übertragung. Das ist ein Ausschnitt, kein vollständiges Laufarchiv.

Nur die exakt bekannte Unity-Meldung „Trying to access a shader … Dedicated
Server Optimizations is enabled.“ wird bei direkt aufeinanderfolgenden Zeilen
zusammengefasst. Zeitstempel zählen nicht zum Vergleich; andere Zeilen beenden
die Gruppe. Zähler und erster/letzter Zeitstempel beziehen sich auf den gelesenen
Ausschnitt. Aufklappen zeigt die bereinigten Einzelzeilen, „Einzelzeilen“ den
gesamten gelesenen Ausschnitt. Die normale Ansicht zeigt dessen letzte 200 Einträge,
wobei eine Shadergruppe als ein Eintrag zählt. Andere Fehler bleiben sichtbar.

Dateien bleiben unverändert; Spielbereitschaft wird unabhängig vom sichtbaren
Logausschnitt geprüft. Eine Shaderwarnung allein belegt keinen fehlgeschlagenen Start.
