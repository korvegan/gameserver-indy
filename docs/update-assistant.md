# Gesicherter Steam-Updateablauf

Unter **Server → Updates → Update-Assistent** führt der Besitzer die folgenden Schritte bewusst aus:

1. Spielrunde beenden oder Wartung mit Vorlauf ankündigen; Server regulär stoppen. Der Assistent stoppt keine Runde ungefragt.
2. **Update gesichert vorbereiten** erstellt und verifiziert einen angehefteten Checkpoint von Welt und Konfiguration. Server und Starter müssen eindeutig gestoppt sein; ein fehlgeschlagener Stopp oder eine fehlende Sicherung verhindert den Assistenten.
3. Im vorhandenen Windows-Steam-Client den zugehörigen Dedicated Server aktualisieren und Download/Installation vollständig abschließen. Den Server dabei nicht über Steam oder die Desktop-Verknüpfung starten.
4. **Steam-Abschluss prüfen und freigeben** vergleicht den lokalen Build desselben App-/Branch-/Installationsziels sowie die gespeicherte Konfiguration. Neue, entfernte und geänderte Feldnamen sowie Änderungen an ASKA-Starter/Preset werden gezeigt; Werte und Zugangsdaten bleiben ausgeblendet. Änderungen benötigen eine eigene Bestätigung und denselben Vergleichsstand. Es werden keine Bestandsfelder oder externen Dateien ersetzt.
5. Den Spielserver ausdrücklich starten. Bereitschaft im Serverlog/Dashboard und den tatsächlichen Beitritt prüfen. Die lokale Startbereitschaft beweist keine externe Routerfreigabe.

Während Schritt 3 bleibt der Assistent auch nach Verwaltungsneustart offen. Die normale Auftragsannahme bleibt erreichbar, aber Start, Neustart sowie Konfigurations-/Weltänderungen werden beim Ausführen mit nachvollziehbarer Meldung abgewiesen. Stopp, Status und Metadatenprüfungen bleiben möglich. Ein zweites Profil wird davon nicht gesperrt. Jeder Prüfschritt nutzt die bestehende exklusive Auftragsverarbeitung und aktuelle persönliche Rechte.

**Assistent bewusst beenden** hebt die Managersperre nach vollständig abgeschlossener Steam-Installation ausdrücklich auf, etwa wenn Steam gar keinen neuen Build angeboten hat. Das ist kein Programm-/Welt-Rollback. Ein noch unvollständiger Download oder nicht lesbarer Vergleich bleibt gesperrt und muss zuerst über Steam bzw. die private Konfiguration geklärt werden. Der Manager verspricht keinen beliebigen Steam-Downgrade; eine Spielstandrückkehr braucht eine dazu passende Programmversion und sollte an einer Kopie geprüft werden.

## Warum der Download zunächst manuell bleibt

Indy verwendet vorhandene Steam-Client-Installationen. [Keen dokumentiert die Installation des Dedicated Servers über die Steam-Bibliothek](https://enshrouded.zendesk.com/hc/en-us/articles/16051370691485-Dedicated-Server-Installation-on-Steam). Die getrennte SteamCMD-Metadatenabfrage beweist keine Downloadberechtigung und keinen konfliktfreien Updateweg in dieselbe Installation. Deshalb führt dieser erste Assistent kein unbestätigtes `app_update` gegen die bestehenden Ordner aus. Eine spätere Automatisierung benötigt einen geprüften spielbezogenen Updater, dessen Anmeldekontext und einen praktischen Client-/Manifest-Abgleich.

Private Daten: `data/update-sessions/<Profil>/session.json` und `before.config`; die Kopie enthält wie die ursprüngliche Konfiguration Zugangsdaten und hat private Dateirechte. Sie wird nicht an Familienleser ausgeliefert oder in Git gespeichert. Der Vergleich zeigt nur Feldnamen; das verifizierte Weltarchiv und der ursprüngliche Ordner bleiben erhalten.

Isoliert geprüft: Vorbereitung und Sicherung, Startblockade während Steam-Schritt, synthetischer Buildwechsel, Konfigurationsdifferenz ohne Geheimnisse, ausdrückliche Freigabe und unveränderte Weltbytes. Browser prüft Vorbereitung und bewusstes Beenden ohne echten Download. Kein produktives Spielupdate als Test ausgeführt.
