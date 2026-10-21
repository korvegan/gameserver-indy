# Neues PHP-Grundgerüst

Zu [EP01 #141](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/141),
[Arbeitsissue #147](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/147)
und [Zielbild #140](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/140).

`app/` ist der führende neue App-Bereich. EP01 liefert eine PHP-gerenderte
Entwicklungsdiagnose, synthetischen Status und einen geprüften Zustandsvertrag.
Fantasy-Design, native Spielaktionen und Tailscale-Anmeldung folgen in eigenen
Epics. Das bestehende Release und seine Daten werden hier nicht geladen.

## Start und Prüfung

Vorhanden sind PHP-CLI 8.5.4 und FrankenPHP 1.13.0 mit PHP 8.5.11. Der Code
benötigt PHP ab 8.4 mit PDO SQLite; der WSL-Entwicklungsstarter zusätzlich
`pcntl` und `ctype`. Neue Composer-/npm-Pakete werden nicht benötigt.

```sh
export SLICE_PHP=/home/korvegan/.local/share/gameserver-indy-tools/php
export SLICE_FRANKENPHP=/home/korvegan/.local/share/gameserver-indy-tools/frankenphp-1.13.0/frankenphp
"$SLICE_PHP" app/bin/dev
```

Der Starter erzeugt ein neues privates Verzeichnis unter dem temporären
Systemverzeichnis und gibt dessen Pfad aus. Er ersetzt sich durch einen
FrankenPHP-Prozess auf `http://127.0.0.1:18765`; optional einen freien Port als
Argument angeben. Mit Strg+C beenden. Die eigene synthetische Datenablage kann
danach gelöscht werden. Es gibt keinen Parameter für echte Spieldaten.
Ein belegter Port wird nicht übernommen und kein vorhandener Dienst beendet.

```sh
npm run test:app
npm run test:app -- --idle
```

Der erste Aufruf prüft PHP-Verträge, vier konkurrierende PHP-Prozesse und den
echten HTTP-Start auf einem eigenen Port. Die HTTP-Prüfung entfernt ihre eigene
Datenablage und beendet ihren Server. `--idle` ergänzt unter Linux eine
fünfsekündige Stichprobe über `/proc` ohne weitere HTTP-Abfragen.
Die Forgejo-CI führt den PHP-Vertragstest mit PHP 8.5 aus. FrankenPHP-HTTP und
Leerlauf werden gezielt lokal geprüft; sie sind nicht Teil dieses CI-Jobs.
Zusätzlich prüft sie das vollständige Quellarchiv und die Release-Verpackung.

```sh
npm run check:archive
python3 app/tests/check-install.py
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

Technische Entscheidungen und Grenzen nach Änderungen dort knapp aktualisieren.
Die Diagnose ist kein freigegebenes Dashboard und vergibt keine Familienrechte.
