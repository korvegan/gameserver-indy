# EP01: Architekturentscheidung

Stand: 22.10.2026. Nutzerziel und MVP-Grenzen führen in Forgejo #140/#141.

## Aufbau

```text
Browser → PHP-Seiten/Fachlogik/Rechte unter WSL → kleine native Windows-Brücke
                    ↓
            lokale SQLite-Daten
```

Der neue App-Bereich liegt in `next/`. Die bestehende Anwendung läuft weiter
aus ihrem bisherigen Release. PHP bleibt Hauptanwendung; Spielprozesse bleiben
unabhängig davon native Windows-Prozesse. Installationen und Welten behalten
ihre bisherigen Pfade. Eine echte Anbindung beginnt in EP03, die Umstellung in EP06.

## Werkzeuge und Verantwortung

- PHP-Standardfunktionen und PDO SQLite genügen für das jetzige Gerüst.
  Der vorhandene große Symfony-Aufbau wird nicht in das neue Gerüst kopiert.
  Einzelne bewährte Komponenten können später bei konkretem Bedarf bewertet
  werden. EP01 hat keine externen PHP-Pakete und keinen eigenen Autoloader.
- Der vorhandene FrankenPHP-Server übernimmt HTTP mit zwei PHP-Threads.
  Der CLI-Starter ersetzt sich per `pcntl_exec`; es bleibt ein Serverprozess.
  Kein eigener Supervisor, Worker, Broker oder Windows-Dienst wird eingerichtet.
- PHP rendert die Seiten. JavaScript ergänzt später gezielt Status und Aktionen.
  EP01 verwendet noch kein JavaScript im Browser.
- SQLite hält Beobachtungen und kleine Operationsdatensätze. Echte Spiel- und
  Zugangsdaten werden weder übernommen noch als Fixture benutzt.
- PHP entscheidet später über Rechte, Profile und erlaubte Aktionen. Die native
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

HTTP bindet nur Loopback. Die PHP-Grenze prüft zusätzlich Peer und exakten Host,
ignoriert eingehende Rollen-/Tailscaleheader und bietet keine Schreibroute.
Unlesbare oder unvollständige Fixtures ergeben 503. Caddy-Admin, HTTPS-Automatik
und Konfigurationsspeicherung sind ausgeschaltet; Caddy-Daten liegen in der Fixture.
Der Entwicklungsstart übernimmt nur benötigte Umgebungswerte, keine Tokens.

Für EP03/EP04 festlegen: konkrete native Beobachtungs-/Weckquelle, Verhalten bei
veralteten Beobachtungen und der begrenzte Ausführungsweg langer Start-/Stoppaktionen.
Kein langsamer nativer Aufruf wird später ungeprüft in einen HTTP-Request gelegt.
Ein zusätzlicher Prozess braucht einen nachgewiesenen Bedarf. EP01 betreibt keine
Hintergrundabfrage oder Timer; sein Leerlaufnachweis bewertet nur dieses Gerüst.

## Prüfgrundlage

Die vorhandene FrankenPHP-Laufzeit wird wiederverwendet. PHPs CLI-Webserver
ist laut [PHP-Handbuch](https://www.php.net/manual/en/features.commandline.webserver.php)
für Entwicklung gedacht und blockiert bei langen Requests; er wurde nicht als
neue Betriebslaufzeit gewählt. Die Transaktionen verwenden SQL `BEGIN IMMEDIATE`
und benötigen keine erst ab PHP 8.5 verfügbare PDO-Transaktionsmodus-Konstante.
Context7 wurde ausschließlich mit öffentlichen PHP-Fragen befragt; ein exakter
8.4-Index war nicht angegeben. Konkrete Funktionen wurden auf den lokalen
PHP-Versionen geprüft, nicht pauschal für jede PHP-8.4-Installation abgenommen.
