# Kurze WhatsApp-Meldungen

Issue #58 ergänzt eine kostenlose lokale WhatsApp-Web-Anbindung an die bestehende private Gruppe. Der Nutzer hat am 16.10.2026 sein vorhandenes Konto und persönliche QR-Verknüpfung gewählt. Die Windows-Desktop-App bleibt ein eigenes verknüpftes Gerät; sie stellt keine API für diese Automatik bereit. Einrichtung: WhatsApp auf dem Handy → Verknüpfte Geräte → Gerät hinzufügen. Ein persönliches Konto kann durch inoffizielle Clients gesperrt werden; diese Einschränkung wurde vor der Auswahl erläutert.

## Bedienung

Unter **WhatsApp** im lokalen Besitzerdashboard zuerst die Geräteverknüpfung vorbereiten und den kurzlebigen QR-Code scannen. Danach die Zielgruppe ausdrücklich auswählen und die gewünschten Serverprofile markieren. Gleichnamige Gruppen erhalten zur Unterscheidung einen kurzen ID-Zusatz. Gruppenlisten enthalten nur IDs und Namen, keine Teilnehmer oder Nachrichten.

**Ziel und Meldungen speichern** aktiviert die gewählten automatischen Bereitschaftsmeldungen. Ein schon bereiter Server wird beim Aktivieren einmal gemeldet. **Bereitschaft jetzt melden** verwendet dieselbe Duplikatsperre; ein schon gemeldeter Start wird nicht erneut verschickt. Bei unklarem Versand zuerst in WhatsApp nachsehen. Das Verwaltungsgerät kann separat abgemeldet werden; die automatische Freigabe wird bereits vor dem Abmeldeversuch entfernt. Andere Geräte einschließlich der Desktop-App werden dadurch nicht abgemeldet.

Beispiel:

```text
Enshrouded · Indy
sturmklingen.dedyn.io:15637
✅ Bereit zum Beitritt
```

Spielname, Servername und öffentliche Adresse stammen aus demselben Beitrittsvertrag wie Dashboard/Discord. Für ASKA ist kein direkter IP-Beitritt bestätigt; dessen zweite Zeile verweist auf die Serverliste. Es gibt keine WhatsApp-Stoppmeldungen, Zugangsdaten, Erwähnungen oder Linkvorschauen. Ein WhatsApp-Fehler verändert weder die Spielprozesse noch die bestehende Discord-Anbindung.

## Dienst und private Daten

`python3 scripts/install-whatsapp.py` installiert ausschließlich den eigenen Docker-Compose-Dienst. Das Image ist über SHA256 gebunden. Port 18768 bindet nur an `127.0.0.1`; der API-Schlüssel liegt privat unter `data/whatsapp/credentials.json`, im Container wird sein SHA512-Hash verwendet. Dashboard/Swagger und Containerprotokollierung sind ausgeschaltet. Kein Docker-Socket im Webdienst, kein öffentlicher Proxy und keine beliebige URL aus Browseranfragen.

Der GOWS-Adapter verwendet keinen zusätzlichen Chromium-Prozess. Medien-Downloads, optionale Nachrichten-/Chat-/Kontakt-Speicherung und Vollsynchronisierung sind deaktiviert; Einstellungen zur historischen Synchronisierung sind Hinweise an WhatsApp, keine Garantie, dass ein verbundenes Gerät nie Nachrichten empfängt. Der Manager liest keine Chat-Historie und übernimmt nur die ausdrücklich benötigten Gruppenmetadaten. Die privaten Sitzungsdateien unter `data/whatsapp/sessions` sind dennoch Kontozugangsdaten und gehören weder nach Git noch in einen Diagnoseexport.

Der Installer prüft den Port und einen bereits existierenden gleichnamigen Container, bevor er ihn verwaltet. Zugangsdaten werden bei Wiederholung erhalten. Die normale Verwaltung braucht nur den lokalen HTTP-Zugang, keine Docker-Berechtigungen. Vorhandene fremde Container, Routerregeln und Spielserver werden nicht umgestellt.

## Bereitschaft und Versandvertrag

`readiness.py` prüft ausschließlich eine eindeutig erkannte PID mit UTC-Startzeit. Enshrouded liefert im Log `Current UTC date` mit Sekundenauflösung; der letzte entsprechende Startkopf muss zur nativen Startsekunde oder deren unmittelbarer Folgesekunde gehören. Nur eine darauf folgende letzte `HostOnline (up)`-Meldung bestätigt Bereitschaft; `down`, fremde Startköpfe oder fehlende Belege ergeben unbekannt. Gelesen werden begrenzt Anfang und Ende der aktuellen Logdatei. Bei ASKA zählen ausschließlich UTC-zeitgestempelte Sitzungsöffnungen im bereinigten profilbezogenen `server.log`, die nach dem aktuellen Prozessbeginn liegen. Das alte Unity-Player.log wird dafür nicht verwendet.

Bei großen Enshrouded-Logs mit ausgelassenem Mittelteil zählt nur eine Bereitschaftstransition im lückenlos gelesenen Ende; eine alte Meldung im Kopf genügt dann nicht. Dies ist eine lokale Startbestätigung mit den Grenzen dieser Spielquellen, kein externer Verbindungstest oder Liveness-Check. Die vollständige allgemeine Zustands-/Timeoutdarstellung aus #7 bleibt ein eigenes Thema. Vor dem Versand wird die native Prozessidentität erneut gelesen; ein zwischenzeitlicher Stopp/Neustart verwirft den alten Befund.

Alle 15 Sekunden prüft der eigene Hintergrunddienst die ausdrücklich aktivierten Profile. Vor dem Netzwerkaufruf entsteht ein dauerhafter Claim für `(server_id, PID/Startzeit, group_id)` in `data/whatsapp/deliveries.sqlite3`. Unbestätigte Claims werden nach einem Neustart unklar, nicht erneut ausgeführt. Schreibfehler nach dem Versand sperren ebenfalls eine Wiederholung. Zustände und die Vorschau stehen im Dashboard. Eine Einstellungsrevision verhindert, dass ein verzögerter manueller oder automatischer Auftrag nachträglich eine andere Gruppe beschickt. Abschalten und Änderungen werden vor dem Versand erneut geprüft.

Die App lädt den Dienst nur beim normalen `serve`-Start in den Hintergrund; HTTP-/Browserfixtures starten keine echten Timer. Der vorhandene Discord-Versand und der externe ASKA-Discord-Starter bleiben unverändert. Die Grenzen sind für die Symfony-Migration #53 als eigener Transport zu übernehmen; dieser Ausbau zieht den Cutover nicht vor.

## HTTP und Prüfung

Alle WhatsApp-Routen sind auf den lokalen, unbeschränkten Besitzer beschränkt. Die persönliche WhatsApp-Sitzung wird nicht automatisch für andere Dashboard-Besitzer im Familienlistener freigegeben. POSTs benötigen weiterhin Origin-/CSRF-Prüfung; QR-Bilder sind kurzlebig und werden nicht gecacht.

- `GET /api/whatsapp`: Status, gespeicherte Freigabe, Vorschau und Versandstand.
- `GET /api/whatsapp/qr`: ausschließlich das PNG der anstehenden Geräteverknüpfung.
- `GET /api/whatsapp/groups`: begrenzte Auswahl an Gruppen-ID und Name.
- `POST /api/whatsapp/connect`, `/disconnect`: persönliche Geräteverknüpfung vorbereiten bzw. entfernen; Freigaben zurücksetzen.
- `POST /api/whatsapp/settings`: `{group_id, server_ids, enabled, revision}`; aktuelle Mitgliedschaft und Profile prüfen.
- `POST /api/whatsapp/send`: `{server_id, revision}`; gespeichertes Ziel und aktuelle Bereitschaft verwenden. Kein frei formulierbarer Empfänger oder Nachrichtentext.

`tests/test_whatsapp.py` verwendet temporäre Installationen, synthetische Logs und einen Transportstub. `tests/browser-whatsapp.cjs` prüft den QR-/Gruppenablauf mit einem synthetischen Konto und PNG sowie Entwurfsrevisionen, private Zugriffsgrenze und Mobilansicht. Kein Test verbindet ein echtes Konto oder sendet eine echte Nachricht.

Quellen: [WAHA-Sitzungen und QR](https://waha.devlike.pro/docs/how-to/sessions/), [Gruppen und Ausschluss von Teilnehmerdaten](https://waha.devlike.pro/docs/how-to/groups/), [lokale Sicherheitsoptionen](https://waha.devlike.pro/docs/how-to/security/), [GOWS und Speicher-/Synchronisierungsoptionen](https://waha.devlike.pro/docs/engines/gows/).
