# EP04: Identität und Zugänge

Zu [#144](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/144)
und [Arbeitsissue #159](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/159).
Die neue Zugangsverwaltung arbeitet ausschließlich auf der privaten synthetischen
Entwicklungsablage. Echte Freigaben, Serve und Firewall bleiben unverändert.
Aktivierung und persönliche Abnahme gehören zu EP06.

## Vertrauensgrenze

PHP prüft den unmittelbaren Peer `127.0.0.1`, den exakten Host und Caddys
geschützte CGI-Variable `INDY_LISTENER`. `HTTP_INDY_LISTENER`, `Forwarded`,
`X-Forwarded-*`, Rollen- und Profilbildheader vergeben keine Rechte.

- `local`: ausschließlich `127.0.0.1:<konfigurierter Port>`, bewusst als lokaler
  Besitzer mit Administratorrechten. Tailscale-Header werden ignoriert.
- `family`: getrennter Loopback-Listener und fest konfigurierter ts.net-Host.
  Nur `Tailscale-User-Login` aus dem vorgeschalteten Serve wird einer aktiven
  persönlichen App-Freigabe zugeordnet. Kein lokaler Besitzer-Fallback.

Laut [Tailscale-Dokumentation](https://tailscale.com/docs/features/tailscale-serve#identity-headers)
ersetzt Serve eingehende Identitätsheader durch die bestätigte Identität;
getaggte Geräte und Funnel liefern diese Identität nicht. Eine Netzfreigabe allein
erteilt keine App-Rechte. Backends dürfen ausschließlich auf Loopback lauschen,
Serve darf ausschließlich auf das Familienbackend zeigen. Lokale Prozesse des
Besitzerkontos gehören zur Vertrauensgrenze; dies ist keine Mehrbenutzergrenze
gegen lokale Schadsoftware.

Logins werden wie bisher mit UTF-8-Fallfaltung normalisiert; für Serve wird RFC2047
dekodiert. Identitätslisten, Steuerzeichen und Leerraum werden verworfen.
FrankenPHP stellt den geparsten Host in `HTTP_HOST` bereit, lässt ihn aber bei
`getallheaders()` aus. Doppelte Host-Header verwirft der HTTP-Server; doppelte
Origin-/Identitätswerte ergeben keinen exakten gültigen Wert.

## Freigaben und Aktionen

`access.sqlite` ist von `state.sqlite` getrennt. Sie hält zufällige stabile ID,
unveränderlichen Login, Anzeigenamen, Rolle, Aktivierung, erlaubte Server und
Revision. `csrf-key` enthält 32 zufällige Bytes. Beide Dateien haben Modus 0600
in der privaten Fixture mit 0700. Fehlende Dateien, falsches Schema oder ein
beschädigter Schlüssel führen zur Abweisung statt erneuter Initialisierung.

Administrator und Familie dürfen ihre freigegebenen Server sehen, starten und
stoppen; nur Administratoren verwalten Zugänge. Neue Freigaben gelten gemäß
bestätigtem MVP für beide Spiele. Bestehende Serverbeschränkungen bleiben im
Schema darstellbar und werden berücksichtigt. Der eigene aktive Zugang lässt
sich nicht selbst sperren oder herabstufen. Andere Administratoren sowie der
lokale Besitzer können ihn ändern.

`AuthorizedOperations` prüft aktuelle Identität, Revision und Serverbindung
unter derselben SQLite-Schreibsperre wie Freigabeänderungen. Vor Reservierung
und vor `pending → running` wird erneut geprüft. Die Reservierung speichert die
ursprüngliche Akteursrevision, auch bei doppelten Anfragen. Nach Sperre oder
Rollen-/Serveränderung wird eine wartende Aktion verworfen. Bereits begonnene
Aktionen werden nicht zurückgerollt. Der rohe `FixtureStore` bleibt der interne
synthetische Vertrag; EP05 muss den autorisierten Einstieg verwenden. EP04 bietet
keine HTTP-Spielaktionen und ruft keine native Brücke auf.

## HTTP und Bedienung

`GET /access` und `GET /api/access` benötigen Administratorrechte.
`POST /access` nimmt ausschließlich begrenzte, eindeutig benannte Formularfelder
für Anlage, Sperre/Freigabe oder Rolle an. Veraltete Zielrevision: 409;
unbekannte/gesperrte Personen, fehlende Rechte oder ungültiger CSRF-Nachweis: 403.
Erfolg leitet mit 303 zurück; es gibt keine automatische Wiederholung.

Jede Schreibanfrage benötigt die exakte Origin: lokal HTTP-Loopback, hinter Serve
die feste öffentliche HTTPS-Origin. Ein HMAC-CSRF-Token bindet Origin/Listener,
persönliche ID, Rolle, Revision und Serverbindung an den privaten Schlüssel.
Er ist keine Anmeldung. Es gibt keine zusätzliche Passwort- oder Cookie-Sitzung;
Tailscale-Identität und Freigabe werden pro Anfrage neu geprüft. Ein Neustart mit
derselben Ablage erhält den Schlüssel; eine neue Entwicklungsfixture erhält neue
Daten und einen neuen Schlüssel. `Referrer-Policy: same-origin` erhält bei
Browserformularen die nötige Origin.

Die Ansicht setzt den bestätigten
[Zugänge-Entwurf](../../design/ep02/concepts/zugaenge-calm-v3.png) um:
Desktop-Tabelle, mobil beschriftete Einträge, dunkle Flächen, sparsame Goldakzente
und HTML-Formulare ohne Browser-JavaScript. Das Schwert-/Kreis-Signet ist SVG.
Funktionsbedingte Ergänzung: Der Rollenname klappt die Bearbeitung am jeweiligen
Eintrag auf; der Entwurf zeigte diese noch nicht. Der lokale Besitzer
zeigt seine tatsächliche Zugangsart statt einer erfundenen E-Mail.

## Entwicklungsprüfung

Ohne weitere Argumente startet nur der lokale Listener. Für die isolierte Prüfung
zwei freie Testports und einen erfundenen Host verwenden, keine Betriebsports:

```sh
"$SLICE_PHP" app/bin/dev 18744 18745 fixture.tailnet.ts.net
npm run test:access:browser -- http://127.0.0.1:18744
```

Dieser Backend-Test ist keine echte Tailscale-Anmeldung. Er ändert Serve und
Netzfreigaben nicht. `npm run test:app` erzeugt eigene Ports/Daten und prüft beide
Listener, Rechte, Origin/CSRF, gefälschte Header, Entzug und veraltete Formulare.
Der PHP-Vertragstest prüft auch Entzug vor wartenden synthetischen Aktionen und
beschränkte Serverrechte. Der Browserlauf deckt Anlage, Sperren/Freigeben, Rolle,
eigenen Zugang, Fokus, Konsole, Axe und 1280/390/320 px ab. Keine realen Konten/Spiele.

## Übernahme in EP06

Die bisherigen privaten Freigaben werden jetzt weder gelesen noch verändert.
Bei der Umstellung anhand der vorhandenen persönlichen Logins gezielt zuordnen:

| Alter Stand | Neue Zuordnung |
| --- | --- |
| Besitzer/`owner` | `administrator`; vorhandene Besitzerfreigaben erhalten |
| Familienbediener/`family` | `family`; der bestätigte neue MVP erlaubt zusätzlich Stoppen mit Bestätigung |
| Leser/`viewer` | Nicht automatisch zur Familie machen; bis zur ausdrücklichen Entscheidung gesperrt lassen |
| Gesperrt | Gesperrt erhalten, niemals durch Import aktivieren |
| Eingeschränkte Serverliste | Unverändert erhalten; nicht auf beide Spiele erweitern |

Personen nicht am Anzeigenamen zusammenführen. Logins und Freigabestatus vor und
nach Übernahme privat abgleichen; keine persönlichen Werte ins Repository.
Zugänge/Schlüssel getrennt von Welt-/Chronikdaten sichern und beim Rückweg keine
alte Freigabedatenbank über den aktuellen Stand kopieren. Smartphone mit echter
Familienidentität, HTTPS-/Serve-Konfiguration und Installation bleiben EP06.
