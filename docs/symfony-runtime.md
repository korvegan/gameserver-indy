# Symfony-Laufzeit vorbereiten

Dieser Stand bereitet #54 vor. Die produktive Python-Verwaltung wurde nicht umgeschaltet. Datenübernahme, versionierte Installation, angepasster Windows-Starter und tatsächliche Betriebsabnahme folgen getrennt. Eine selbst angelegte Markierungsdatei ist keine geprüfte Datenmigration.

## Entscheidung für den ersten Betrieb

FrankenPHP 1.13.0 wurde unter WSL und nativem Windows geprüft. Das offizielle Windows-ZIP wurde gegen SHA256 `b26749ecc1fd03bef10c3e31fef467c7b9ec3cd64a95bcb39b54bd02e515e2ab` geprüft und ausschließlich unter `%LOCALAPPDATA%\GameServerIndy\tools\frankenphp-1.13.0` entpackt. `symfony/tools/probe_native_frankenphp.py` startete daraus einen eigenen NTFS-/Loopback-Testserver mit separaten privaten Caddy-/INI-Dateien und beendete ihn wieder. PHP 8.5.11, SQLite3, PDO SQLite und Intl/Unicode16 waren verfügbar. Der einfache HTTP-Test erreichte bei 20 Anfragen rund 0,83ms Median; das ist kein Benchmark des vollständigen Dashboards und kein Nachweis für Kaltstart oder Spielleistung.

Die erste Betriebsfassung verwendet den bereits geprüften WSL-Weg mit nativem PowerShell-Prozessadapter. Damit bleiben der bestehende Windows-Besitzerkontext, WSL-Starttask und Tailscale-Loopbackvertrag erhalten. Der PHP-Supervisor verwendet Linux-Signale und wird ausdrücklich unter WSL/Linux betrieben. Der native Windows-Webserver bleibt eine geprüfte spätere Alternative, keine implizite zweite Produktionsinstallation. Quellen: [FrankenPHP-Dokumentation](https://frankenphp.dev/docs/), [Release1.13.0](https://github.com/php/frankenphp/releases/tag/v1.13.0).

## Einzelner Supervisor

`symfony/bin/indy <absoluter-Datenordner>` ist der spätere feste Starter eines vorbereiteten Releases. Es verlangt eine ausdrücklich vorbereitete `.indy-managed-state` und startet `app:serve`. Code und veränderliche Daten haben getrennte Wurzeln; Bootstrap- und Web-/Worker-Caches sind an den jeweiligen Releasepfad gebunden. Es gibt keine automatische Übernahme des bisherigen Repositoryordners.

Die private `runtime.local.json` im Datenordner enthält ausschließlich absolute Pfade `php` und `frankenphp` sowie optional `port`, `family_port` und `threads`. Vorgaben sind 8765/8767 und sechs PHP-Webthreads. Beide Listener binden ausschließlich 127.0.0.1; Caddy-Admin und Konfigurationspersistenz sind deaktiviert. TLS bleibt beim vorhandenen Tailscale Serve; der vorhandene Gateway auf Port 443 wird nicht verändert. Pfade, Werkzeuge und Releaseauswahl werden nicht über HTTP angenommen.

Vor jeder Laufkennung oder Claimänderung hält der Supervisor `.indy-controller.lock` und prüft beide Ports. Ein zweiter Start übernimmt keinen vorhandenen Dienst. Erst danach werden alte wartende Aufträge unterbrochen und unklarer Versand abgeglichen. Zwei Steuerungsworker, ein Metadatenworker, ein Prozessbeobachter, ein einzelner Metadaten-/WhatsApp-Planer und FrankenPHP werden als eigene Unterprozesse gestartet. Der Planer hält eine weitere Lebenszeitsperre. Fremde Backlog-Implementierungen oder neue Spielaktionen gehören nicht zum Start.

Ein regulär beendeter Messenger-Worker kann nach bestätigtem Nachrichtenabschluss ersetzt werden, maximal dreimal je Minute und Worker. Ein fehlerhaft beendeter Unterprozess schließt die Laufkennung und beendet die eigenen Verwaltungsprozesse. Wartende Befehle werden nicht automatisch wiederholt; unklare Zustellungen bleiben unklar. Beim geordneten Beenden wird zuerst die Laufkennung geschlossen, dann erhalten alle eigenen Unterprozesse das Beendigungssignal mit gemeinsamem Zeitlimit. Der Supervisor ruft keinen Spielprozess-Stopp auf.

Private Protokolle stehen unter `runtime/logs/`; die Dateien und PHP-Prozesspuffer sind begrenzt. `runtime/children.json` dokumentiert nur eigene PIDs und Laufkennung. Die Familienlisteneranzeige bindet an diese aktuelle Laufkennung und die tatsächliche Listenerkonfiguration; sie bestätigt weiterhin keinen erfolgreichen externen Beitritt.

## Gemeinsame Daten als Voraussetzung des Rückwegs

Die vorbereitete Produktionsablage verwendet den bestehenden Datenvertrag unter `data/`, einschließlich Freigaben, `session-key`, Chronik, Sicherungen und WhatsApp-Zuordnungen. Nur diese ausdrücklich markierte Ablage verwendet für Zugriffe `data/access.sqlite3` und `data/session-key`; isolierte Durchstiche behalten ihre bisherigen Testpfade. So kann der spätere Rückweg dieselben neu entstandenen Daten weiterverwenden. Der eigentliche Datenkopier-/Aktivierungs- und Python-Rückweg ist in diesem Schritt noch nicht implementiert.

## Nachweise und offene Abnahme

Die vollständige Dashboard-Browserfixture verwendet jetzt den echten PHP-Supervisor. Alle HTTP- und CLI-Einstiege erhalten dieselben reinen Testadapter. Zusätzlich zu den Fach-/Chronik-/Wandteppichprüfungen werden doppelter Start vor Laufkennungsänderung, Ersatz eines regulär beendeten Workers, geordnetes Beenden, gleichbleibender Sitzungsschlüssel beim Neustart und ein erzwungener Ausfall des eigenen Beobachters geprüft. Der simulierte Spielprozesszustand bleibt jeweils laufend. Ein echter ASKA-Speicherabschluss oder ein Windows-Neustart wird daraus nicht abgeleitet.

Für #54 bleiben die konsistente Originalübernahme samt Rückweg, Installations-/Launcherprüfung, Lastvergleich, externe Familienabnahme und tatsächlicher Windows-Kaltstart im richtigen Besitzerkonto erforderlich. Bestehende WAHA-Sitzungsvolumes und aktive ASKA-Diagnosepfade bleiben bei dieser Übernahme gebunden; kein erneutes Pairing oder Testversand.
