# EP05: Dashboard und Spielbedienung

Zu [#145](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/145)
und [#161](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/161).
Der Entwicklungsstart zeigt die PHP-gerenderte Serverzentrale mit beiden Spielen.
Er erzeugt weiterhin ausschließlich neue synthetische Daten; echte Konten,
Installationen und Welten werden nicht übernommen. Die Ansicht kennzeichnet
dies mit `Lokale Vorschau mit Testdaten.` Die Betriebsaktivierung bleibt EP06.

## Oberfläche und Zustand

`/` enthält direkte Start-/Stoppaktionen und je einen Link nach
`/servers/enshrouded` bzw. `/servers/aska`. Beide Details verwenden denselben Aufbau.
`Zugänge` erscheint im Dashboard nur für Administratoren. PHP rendert den ersten
Stand; gezieltes JavaScript ergänzt Status, Aktionen, Kopieren und den Stoppdialog.
Die Originalbilder/-logos bleiben unverändert; Herkunft steht in
[game-assets.md](game-assets.md).

`ServerRuntime` bestimmt die erlaubten Zustände und Aktionen für HTML und API
gemeinsam. Prozessbeobachtung und Bereitschaft bleiben getrennt. Nach 30 Sekunden
oder bei mehr als fünf Sekunden Vorlauf gilt eine Beobachtung nicht als aktuell;
Start/Stopp und Beitrittsdaten werden gesperrt. `pending`/`running` zeigen die
laufende Aktion, `unknown` sperrt weitere Befehle bis zum Operationsabgleich.
Ein bestätigter Fehlstart bleibt bei gesichert gestopptem Server wiederholbar.
Es gibt keine erfundenen Spielerzahlen oder aus einem laufenden Prozess
abgeleitete Spielbereitschaft. Die native Brücke liefert weiterhin `ready=null`.

Der Dialog nennt das gewählte Spiel und die Trennung aller Spieler. Abbrechen hat
zunächst Fokus; Tab bleibt im Dialog, Escape bricht ab, Fokus kehrt zurück.
Ein Klick außerhalb bestätigt oder schließt den Dialog nicht. Start-/Stoppknöpfe
werden vor dem HTTP-Aufruf gesperrt. Ein verlorenes Ergebnis wird ausdrücklich
als fehlende Antwort gemeldet und nie automatisch erneut gesendet.

## HTTP und Rechte

Die [Identitäts-/Rechtegrenze aus EP04](family-access.md) gilt weiterhin.
Alle Schreibwege benötigen aktuelle Freigabe und exakte Origin plus gebundenes
CSRF-Token. Es gibt weder freie Befehle noch Browserpfade oder wählbare EXEs.

| Weg | Verhalten |
| --- | --- |
| `GET /api/status` | Ausschließlich Cache und eigene freigegebene Server; keine native Abfrage im Request |
| `POST /api/refresh` | URL-Formular mit `csrf`; einmalige Leseabfrage für die erlaubten Server anstoßen; 202 |
| `POST /api/actions` | `csrf`, `server`, `action=start/stop`; Stoppen zusätzlich `confirmed` mit derselben Server-ID; 202 und Operations-ID |

Veraltete/nicht passende Serverzustände oder Gegenaktionen ergeben 409;
fehlende Freigabe, Origin, CSRF oder Stoppbestätigung 403. Unbekannte Felder,
Server oder Aktionen werden abgelehnt. Die native Ausführung prüft den
ursprünglichen Akteur einschließlich Freigaberevision unmittelbar vor Beginn
erneut. Ein Freigabeentzug bricht wartende Aktionen ab, nicht bereits begonnene.

## Einmalige Ausführung und Rückmeldung

Der HTTP-Aufruf reserviert atomar eine Aktion und startet `bin/execute` mit dem
vorhandenen PHP-CLI-Startweg. `setsid --fork` aus dem vorhandenen Systempaket
util-linux entkoppelt den einmaligen Prozess; kein zusätzlicher dauerhafter
Dienst, Broker oder Supervisor. Der Worker endet nach der begrenzten Aufgabe.
Quelle: [setsid-Manpage](https://man7.org/linux/man-pages/man1/setsid.1.html).

Je Spiel verhindern Dateisperre und persistierte Operations-ID doppelte
Ausführung. Die bestehende Windows-Brücke erhält dieselbe ID und ihren eigenen
Operationsbeleg. Status und Mutation haben getrennte Sperren, damit eine langsame
Leseabfrage keinen Start/Stopp blockiert. Beobachtungen werden auf UTC normalisiert;
ältere Antworten überschreiben keinen neueren Cache.

Ein offenes Browserfenster stößt höchstens etwa alle fünf Sekunden eine
zusammengefasste Abfrage an; ohne HTTP-Anfragen gibt es keinen Hintergrundpoller.
Versteckte Tabs pausieren. Native Aufrufe bleiben auf 60 Sekunden begrenzt.
Ein nicht begonnener Auftrag wird nach 30 Sekunden ohne Worker verworfen; ein
verlorener laufender Executor wird nach 105 Sekunden `unknown`. Späte Worker
beginnen verworfene Aufträge nicht. Unbekannte Operationen werden ausschließlich
mit `status` und derselben ID am nativen Beleg abgeglichen; Prozessstatus allein
löst die Reservierung nicht auf. Ungültige Profile oder Transportfehler gelten
nicht als bestätigtes Operationsergebnis. Kein harter Spielabbruch als Ersatz.

`runtime.json` liegt mit Modus 0600 in der privaten Ablage. Sie hält `kind`
(`fixture`/`windows`), vollständigen `php`-Startweg, `bridge_directory`, feste
`bridge_command`-Argumente und `connections` je Spiel. Sie wird nicht per HTTP
bearbeitet. Der normale Starter erzeugt nur den synthetischen Peer
`tests/game-peer.php`; es gibt keinen CLI-Parameter für echte Spielpfade.
Der isolierte Windows-Test setzt die private Datei bewusst auf seine eigenen
geprüften Windows-Testprogramme. Die reale Konfiguration erstellt erst EP06.

WSL-Interop benötigt im entkoppelten Prozess zusätzlich `WSL_INTEROP` und
`WSL_DISTRO_NAME`; nur diese notwendigen Werte sowie die bisherige kleine
Umgebungsfreigabe werden übernommen. Der Windows-Starter reicht `SLICE_PHP`
aus seiner bereits validierten Konfiguration weiter. Ein bloßes `PHP_BINARY`
würde die Erweiterungsflags des lokalen Wrappers verlieren.

## Beitritt

Nur freigegebene Personen sehen Werte ihres Spiels bei frischer Beobachtung eines
laufenden Servers ohne aktive Aktion. Der konfigurierte Wert ist kein Netzwerk-
oder Bereitschaftstest. Fehlende Werte bleiben `Noch nicht verfügbar`, Kopieren
gesperrt. Bei Clipboard-Fehler bleibt der Text auswählbar.

- Enshrouded: geprüfte lokale Beitrittsadresse mit Query-Port in
  `connections.enshrouded`, ohne Passwort; IP-/Domain-Suche im Spiel.
- ASKA: tatsächlicher Servername in `connections.aska`; Suche in der Serverliste.
  Der bestätigte bisherige Beitrittsweg belegt keinen direkt nutzbaren Code.
  Deshalb lautet das Feld gegenüber der Konzepttafel `Servername`.

Die Entwicklung setzt keine echten Beitrittswerte. Browsertests verwenden
ausdrücklich erfundene Werte ausschließlich in ihrer eigenen Fixture. Es gibt
keine IP-Ermittlung, DNS-, Router-/Firewalländerung oder neue Spielportfreigabe.

## Prüfen und übergeben

`npm run test:app` prüft Verträge und den echten HTTP-Weg mit synthetischen Peers.
`check-servers.php` deckt auch langsame Beobachtung, doppelte Annahme, Rechteentzug,
unbekannte Ergebnisse und Executorverlust ab. Die native Prüfung aus
[windows-bridge.md](windows-bridge.md) ergänzt den gesamten Weg HTTP → einmaliger
PHP-Prozess → PowerShell 7 → beide eigenen Windows-Stellvertreter.

```sh
"$SLICE_PHP" app/bin/dev 18746 18747 fixture.tailnet.ts.net
# Den ausgegebenen eigenen Fixture-Pfad verwenden:
npm run test:servers:browser -- http://127.0.0.1:18746 18747 /tmp/indy-next-<Kennung>
```

Der Browserlauf prüft Desktop/Mobil, beide Spiele, Dialog/Fokus, Navigation, Axe,
veralteten Status, Fehlstart, unklaren Ausgang, Kopierfehler, verlorene Antwort
und gesperrte alte Familienseite. Sein eigener Serve-Stellvertreter ist ausschließlich
Testcode. Keine parallel offene Vorschau desselben Testservers pollen lassen,
wenn für den Stale-Test gezielt alte Beobachtungen eingesetzt werden.

EP06 übernimmt ein geprüftes Release, aktuellen Zustand samt nativen Belegen,
persönliche Freigaben und passende Betriebswerte gezielt. Vor der
Betriebsaktivierung auch die vorhandene PHP-Laufzeit auf den aktuellen
Sicherheitsstand bringen. Reale Speicher-/Beitritts-, Mobil-/Familien- und
Bootabnahme stehen weiterhin aus; keine Freigabe für echte Spielaktionen aus
diesen synthetischen Prüfungen ableiten.
