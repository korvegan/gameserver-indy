# EP01: Architekturentscheidung

Stand: 22.10.2026. Nutzerziel und MVP-Grenzen führen in Forgejo #140/#141.

## Aufbau

```text
Browser → PHP-Seiten/Fachlogik/Rechte unter WSL → kleine native Windows-Brücke
                    ↓
            lokale SQLite-Daten
```

Der neue App-Bereich liegt in `app/`. Auf ausdrücklichen Nutzerauftrag wurde
die bisherige Verwaltung gestoppt und ihr vollständiger Quellstand archiviert.
PHP bleibt Hauptanwendung; Spielprozesse bleiben
unabhängig davon native Windows-Prozesse. Installationen und Welten behalten
ihre bisherigen Pfade. EP03 ergänzt die isoliert geprüfte
[Windows-Brücke](windows-bridge.md); die Betriebsaktivierung bleibt ausstehend. Der lokale Einstieg
zeigt vorzeitig auf das Gerüst; die vollständige MVP-/Bootabnahme bleibt EP06.

## Werkzeuge und Verantwortung

- PHP-Standardfunktionen und PDO SQLite genügen für das jetzige Gerüst.
  Der vorhandene große Symfony-Aufbau wird nicht in das neue Gerüst kopiert.
  Einzelne bewährte Komponenten können später bei konkretem Bedarf bewertet
  werden. EP01 hat keine externen PHP-Pakete und keinen eigenen Autoloader.
- Der vorhandene FrankenPHP-Server übernimmt HTTP mit zwei PHP-Threads.
  Der CLI-Starter ersetzt sich per `pcntl_exec`; es bleibt ein Serverprozess.
  Kein dauerhafter Supervisor, Broker oder Windows-Dienst wird eingerichtet.
  EP05 startet bei Bedarf begrenzte einzelne CLI-Prozesse für langsame Brückenaufrufe.
- PHP rendert die Seiten. Seit EP05 ergänzt JavaScript gezielt Status, Aktionen,
  Stoppdialog und Kopieren; keine eigenständige JavaScript-App.
- SQLite hält Beobachtungen und kleine Operationsdatensätze. Echte Spiel- und
  Zugangsdaten werden weder übernommen noch als Fixture benutzt.
- PHP entscheidet über Rechte, Profile und erlaubte Aktionen. Die native
  Brücke prüft zusätzlich ihre eigenen Prozessziele und Eingaben. Sie erhält
  freigegebene Server-IDs und Aktionen, keine beliebigen Shellbefehle.

## Status und Aktionen

Der [Vertrag](bridge-contract.md) trennt Prozessbeobachtung, ausdrücklichen
Bereitschaftsnachweis und den Zustand einer Aktion. SQLite reserviert atomar
höchstens eine aktive Operation pro Server. Wiederholungen derselben laufenden
Aktion erhalten dieselbe ID; eine Gegenaktion wartet nicht in einer verborgenen
Queue, sondern erhält `server_busy`. Unbekannter Ausgang blockiert weitere
Aktionen bis zum geprüften Abgleich. Enshrouded und ASKA sind unabhängig reservierbar.

`FixtureStore` ist der überprüfbare synthetische Zustandsvertrag, kein nativer
Executor und keine vollständige Autorisierung. `Role` kennt Administrator und
Familie; beide dürfen steuern, nur Administratoren Zugänge verwalten. EP04
bindet bestätigte Identität und aktuelle Freigaben vor Annahme und Ausführung
an diese Rechte. Der HTTP-Einstieg nimmt in EP01 ausschließlich Lesezugriffe an.

## Isolation und Daten

Der Starter erzeugt ausschließlich neue Verzeichnisse `indy-next-<Zufallswert>`
im temporären Systemverzeichnis, mit Verzeichnismodus 0700 und Dateien 0600.
Der Store verlangt diesen Ort, Namen, eine Fixture-Markierung und eine vorhandene
SQLite-Datei; direkte Symlinkziele werden abgelehnt. Diese Kontrolle begrenzt den
Entwicklungsstart und ist keine Sandbox gegen andere Prozesse desselben Kontos.
Ein echter Datenpfad/Adapter ist in EP01 nicht konfigurierbar.

HTTP bindet nur Loopback. Die PHP-Grenze prüft zusätzlich Peer und exakten Host.
EP04 trennt lokalen Besitzer- und Serve-Zugang; HTTP-Rollenheader vergeben keine
Rechte, bestätigte Tailscale-Identität benötigt eine ausdrückliche Freigabe.
Fehlende Fixture-Markierung, Datenbank, Serverzeilen oder Operationsspalten
ergeben auch bei `/health` 503. Dies ersetzt keine vollständige SQLite-Integritätsprüfung.
Caddy-Admin, HTTPS-Automatik
und Konfigurationsspeicherung sind ausgeschaltet; Caddy-Daten liegen in der Fixture.
Der Entwicklungsstart übernimmt nur benötigte Umgebungswerte, keine Tokens.
Installierte Releases enthalten ausschließlich die versionierten `app/`-Dateien
und eine `REVISION` mit Commitkennung. Der Windows-Starter vergleicht diese
Kennung über `/health` mit seiner privaten Konfiguration. Das ist eine lokale
Zuordnungsprüfung, keine Authentifizierung oder signierte Herkunftsprüfung.

EP04 ergänzt [geprüfte Listeneridentität und getrennte persönliche Freigaben](family-access.md).
Schreibende Zugangsänderungen prüfen feste Origin, gebundenes CSRF-Token und aktuelle
Rechte. `AuthorizedOperations` prüft Freigaberevision und Serverbindung vor Annahme
und Beginn einer Aktion. EP05 verbindet die [HTTP-Spielbedienung](dashboard.md)
mit einmaligen Brückenaufrufen; der Standardstart bleibt synthetisch und die
reale Serve-/Spielanbindung wird nicht aktiviert.

EP03 fragt native Prozesse bei Bedarf über einen einmaligen PowerShell-7-Helfer
ab. Es gibt keinen Hintergrundpoller; ohne Spielnachweis bleibt `ready=null`.
Per Spiel sichern native Sperre und dauerhafte Operationsbelege die Ausführung.
Längere Aufrufe laufen seit EP05 in begrenzten CLI-Prozessen außerhalb der HTTP-
Threads. Der Statusrequest liest nur Cache; ein sichtbares Browserfenster stößt
zusammengefasste Aktualisierungen an. Ohne Anfragen gibt es keinen Hintergrundpoller.
Der historische EP01-Leerlaufnachweis bewertet nur das damalige Gerüst.

## Prüfgrundlage

Die vorhandene FrankenPHP-Laufzeit wird wiederverwendet. PHPs CLI-Webserver
ist laut [PHP-Handbuch](https://www.php.net/manual/en/features.commandline.webserver.php)
für Entwicklung gedacht und blockiert bei langen Requests; er wurde nicht als
neue Betriebslaufzeit gewählt. Die Transaktionen verwenden SQL `BEGIN IMMEDIATE`
und benötigen keine erst ab PHP 8.5 verfügbare PDO-Transaktionsmodus-Konstante.
Context7 wurde ausschließlich mit öffentlichen PHP-Fragen befragt; ein exakter
8.4-Index war nicht angegeben. Konkrete Funktionen wurden auf den lokalen
PHP-Versionen geprüft, nicht pauschal für jede PHP-8.4-Installation abgenommen.
