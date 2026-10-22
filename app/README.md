# Neue PHP-Anwendung

Zu [EP01 #141](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/141),
[Arbeitsissue #147](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/147)
und [Zielbild #140](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/140).

`app/` ist der führende neue App-Bereich. EP01 liefert eine PHP-gerenderte
Entwicklungsdiagnose, synthetischen Status und einen geprüften Zustandsvertrag.
EP02 hält den bestätigten Designstand unter `design/ep02/` fest. EP03 ergänzt
die isoliert geprüfte [native Windows-Brücke](docs/windows-bridge.md) für
PowerShell 7.6+, lokal 7.6.7. EP04 liefert [Identität und Zugangsverwaltung](docs/family-access.md)
mit isoliert geprüfter Serve-Vertrauensgrenze. EP05 verbindet
[Dashboard und Spielbedienung](docs/dashboard.md) mit autorisierten einmaligen
Brückenaufrufen. Der Entwicklungsstart bleibt synthetisch. Die installierte
Verwaltung wurde in EP06 mit bestehenden Freigaben und echten Spielbindungen
aktiviert. Beide Bestandswelten, Beitritt, Stopp und neue Speicherdateien sind
bestätigt; Familienabnahme und Bootprüfung sind noch offen.
Der [EP06-Releaseweg](docs/operation.md) ergänzt die dauerhafte Ablage, gezielte
Freigabeübernahme, feste Sicherheitslaufzeit und den Wartungs-/Rückweg.
Vorbereitung und Aktivierung sind getrennte Schritte; der aktuelle Betriebsnachweis
und verbleibende Abnahmen stehen in derselben Anleitung.

## Start und Prüfung

Die alten Entwicklungswerkzeuge sind PHP-CLI 8.5.4 und FrankenPHP 1.13.0 mit PHP 8.5.11.
EP06 verwendet FrankenPHP 1.13.2 mit PHP 8.5.12 für HTTP und CLI; auch die Entwicklung
vor weiterer Verwendung darauf umstellen. Der Code
benötigt PHP ab 8.4 mit PDO SQLite, mbstring und iconv; der WSL-Entwicklungsstarter zusätzlich
`pcntl`, `posix` und `ctype`, die einmalige Ausführung das vorhandene `setsid` aus util-linux;
der dauerhafte Start zusätzlich `flock` und GNU `env --chdir`.
Neue Composer-/npm-Pakete werden nicht benötigt.

```sh
export SLICE_PHP=/pfad/zum/geprüften-mvp-release/runtime/php
export SLICE_FRANKENPHP=/pfad/zum/geprüften-mvp-release/runtime/frankenphp
"$SLICE_PHP" app/bin/dev
```

Der Starter erzeugt ein neues privates Verzeichnis unter dem temporären
Systemverzeichnis und gibt dessen Pfad aus. Er ersetzt sich durch einen
FrankenPHP-Prozess auf `http://127.0.0.1:18765`; optional einen freien Port als
Argument angeben. Mit Strg+C beenden. Die eigene synthetische Datenablage kann
danach gelöscht werden. Es gibt keinen Parameter für echte Spieldaten.
Ein belegter Port wird nicht übernommen und kein vorhandener Dienst beendet.
Das Dashboard liegt unter `/`, Serverdetails unter `/servers/enshrouded` bzw.
`/servers/aska`, die lokale Zugangsverwaltung unter `/access`. Für das getrennte
synthetische Familienbackend und die Browserprüfung siehe [Zugänge](docs/family-access.md).

```sh
npm run test:app
npm run test:app -- --idle
```

Der erste Aufruf prüft PHP-Verträge, Brückentransport, vier konkurrierende PHP-Prozesse,
asynchrone Spielaktionen mit synthetischen Peers, aktuelle Rechte und den
echten HTTP-Start auf einem eigenen Port. Die HTTP-Prüfung entfernt ihre eigene
Datenablage und beendet ihren Server. `--idle` ergänzt unter Linux eine
fünfsekündige Stichprobe über `/proc` ohne weitere HTTP-Abfragen.
Die Forgejo-CI führt den PHP-Vertragstest mit PHP 8.5 aus. FrankenPHP-HTTP und
Leerlauf werden gezielt lokal geprüft; sie sind nicht Teil dieses CI-Jobs.
Zusätzlich prüft sie das vollständige Quellarchiv und die Release-Verpackung.
Die native Brückenprüfung läuft gezielt unter Windows; Aufruf und Voraussetzungen
stehen in [windows-bridge.md](docs/windows-bridge.md).

```sh
npm run check:archive
python3 app/tests/check-install.py
python3 app/tests/check-release.py
```

Der Verpackungstest benötigt einen Commit mit der neuen `app/`-Struktur.
Der Windows-Starter wird nativ mit `app/tests/check-launcher.ps1` isoliert
geprüft; WSL-Start, Netzwerk und Prozesse sind darin durch Testfunktionen ersetzt.
Der installierte Starter verwendet denselben PHP-Einstieg und liefert FrankenPHP
aus der privaten Konfiguration. Er akzeptiert nur den erwarteten Release-Stand.
Die [Umstellung und der Rückweg](docs/retirement.md) beschreiben die Installation.

`SLICE_PHP` bezeichnet auch für Kindprozesse den vollständigen CLI-Startweg.
Ein bloßes `PHP_BINARY` würde die Erweiterungsflags des lokalen Wrappers verlieren.

## Kleiner Startkontext

Für ein Folgeissue Zielbild #140, aktives Epic/Arbeitsissue, AGENTS.md und die
passende Skill-Referenz lesen. Hier genügen anschließend:

- [Architekturentscheidung](docs/architecture.md)
- [Zustands- und Brückenvertrag](docs/bridge-contract.md)
- [EP01-Prüfnachweis](docs/qualification.md)
- [Windows-Brücke und EP03-Prüfweg](docs/windows-bridge.md)
- [Tailscale-Vertrauensgrenze, Zugänge und EP04-Prüfweg](docs/family-access.md)
- [Dashboard, Ausführung und EP05-Prüfweg](docs/dashboard.md)
- [Release, Umstellung, Rückweg und EP06-Abnahme](docs/operation.md)

Technische Entscheidungen und Grenzen nach Änderungen dort knapp aktualisieren.
Der Entwicklungsstart erteilt keine realen Familienrechte und steuert keine echten Spiele.
