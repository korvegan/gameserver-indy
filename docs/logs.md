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

Suche und Kennzeichnungsfilter beziehen sich auf den gelesenen Ausschnitt. Nur
ausdrückliche Error-/Exception-/Warning-Wörter werden gefiltert; andere Zeilen
heißen „Ohne Kennzeichnung“. Aktive Filter und Trefferzahl bleiben sichtbar.
„Filter zurücksetzen“ zeigt wieder alle Zeilen. Originale Shadergruppen bleiben
getrennt, auch wenn eine dazwischenliegende Zeile ausgefiltert wird.

„Pause“ hält die Anzeige einschließlich geöffneter Shadergruppen fest; „Fortsetzen“
holt einen frischen Ausschnitt. Manueller Abruf und Quellenwechsel sind weiterhin
möglich. Eine gelesene Scrollposition bleibt bei automatischen Abrufen erhalten.
Dateistand, Profil, Prozessstart und vorhandene Zeitstempel werden angezeigt;
die Zuordnung einer beliebigen Logdatei zum aktuellen Prozess bleibt ausdrücklich
unbestätigt. Eine alte Datei wird dadurch nicht als neuer Startfehler ausgewiesen.

„Diagnose ↓“ liest den aktuellen bereinigten Ausschnitt der gewählten Quelle neu,
ohne Ansichtsfilter. Das ist unabhängig von der Pause. Der Textbericht enthält
Dateiname, Profil, Zeitangaben und die begrenzte Ausgabe, keine absoluten Pfade.
Download und Logabruf benötigen beide serverseitig `logs.read` für dieses Profil.
