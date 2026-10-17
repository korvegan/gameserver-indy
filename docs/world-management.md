# Welten innerhalb eines Serverprofils

Unter **Server → Sicherungen → Spielwelten** werden aktuelle Welt, Speicherort und gegebenenfalls Save-ID angezeigt. Pro Installation bleibt genau ein Prozessziel; für dieselbe EXE werden keine scheinbar unabhängigen Serverprofile erzeugt.

Enshrouded unterstützt **neue leere Kampagne** und **geprüfte Kopie**. Der Besitzer vergibt einen Namen. Der Manager erstellt einen eigenen zufälligen Ordner unter `indy-worlds` in der Installation und registriert ihn. Das aktiviert die Welt noch nicht. Ein separater, bestätigter Auftrag **Welt auswählen** setzt ausschließlich `saveDirectory`; der nächste ausdrückliche Serverstart lädt die gewählte Welt bzw. erzeugt die neue Welt. [Keen beschreibt `saveDirectory` als Speicherort der Dedicated-Welt](https://enshrouded.zendesk.com/hc/en-us/articles/16055441447709-Dedicated-Server-Configuration).

Alle Änderungen laufen als exklusive Besitzeraufträge. Server und Starter müssen eindeutig gestoppt sein. Vorher werden die ruhenden Weltdateien und exakten Konfigurationsbytes gesichert, per Prüfsumme geprüft und angeheftet. Eine Kopie wird aus diesem verifizierten Archiv erzeugt. Originalwelt und ältere Ordner werden nicht gelöscht oder überschrieben. Geänderte Konfiguration verwirft eine alte Auswahl; erneutes Laden ist nötig. Unbekannte Konfigurationsfelder und Passwörter bleiben erhalten.

Auch eine noch nicht gestartete, leere neue Welt lässt sich wieder verlassen: Ein dafür ausdrücklich markierter Checkpoint sichert den leeren Ordner und die zugehörige Konfiguration. Die normale Weltsicherung verlangt weiterhin Spielstanddateien. Angeheftete Checkpoints liegen außerhalb der rollenden Bereinigung; nach erfolgreicher persönlicher Abnahme können unnötige Pins unter Sicherungen bewusst gelöst werden.

Bei ASKA wird nur die fest gebundene Bestandswelt angezeigt. Der vorhandene externe Starter und dessen Save-ID werden durch diese Funktion nicht umgestellt. Eine freie ASKA-Wahl benötigt vorher eine bewusste Anpassung dieses Starters.

Kein allgemeiner ZIP-/Dateiupload, kein automatischer Programm-Downgrade und kein ungeprüfter Import fremder Spielstände. [Keen erläutert die zusammengehörenden Welt- und Indexdateien](https://enshrouded.zendesk.com/hc/en-us/articles/17764283835677-Save-Game-Management). Versionsabhängige Konvertierung muss nach einer neuen Spielversion auf einer Kopie mit dem echten Spiel geprüft werden; der Manager verspricht keine Rückwärtskompatibilität.

Umgesetzt und isoliert geprüft: Kopie, Auswahl und Rückkehr, leere Kampagne, Rückkehr vor erstem Spielstart, unveränderte Originalbytes, echte Windows-Dateipfade mit eigenen Prozessstellvertretern. Es wurden keine produktiven Welten angelegt oder umgeschaltet.
