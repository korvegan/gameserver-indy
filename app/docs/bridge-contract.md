# Status- und Aktionsvertrag v1

Der Store in `src/Foundation.php` prüft diesen Vertrag mit synthetischen Daten.
EP03 liefert den nativen Adapter; EP04 die Identität/Rechte und EP05 die Bedienung.

## Native Grenze

Ein nativer Aufruf benennt Version, Server-ID (`enshrouded` oder `aska`) und
Aktion (`status`, `start`, `stop`). Schreibende Aufrufe besitzen zusätzlich die
eindeutige Operations-ID. Beispiel:

```json
{"version":1,"server":"aska","action":"start","operation":"d91ca5683c484f38936f915bfab132fe"}
```

Profile, erlaubte EXE-/Konfigurationspfade und Windows-Benutzerkontext stammen
aus vertrauenswürdiger lokaler Konfiguration. Der Helfer bindet jede Änderung
an die tatsächliche Prozessidentität einschließlich EXE-Pfad und Startkennung;
eine nackte PID oder ein Prozessname reicht nicht. Native Geheimnisse verlassen
den lokalen Ablauf nicht. EP03 verwendet einmalige PowerShell-7-Aufrufe mit
lokalen Request-/Reply-Dateien; [Transport und Journal](windows-bridge.md) halten
die native Grenze fest. Ein dauerhafter Helferdienst ist nicht erforderlich.

Antworten sind versionierte strukturierte Daten mit Server-/Operationsbezug,
beobachtetem Ergebnis oder begrenztem Fehlercode. Timeout ist kein Beleg für
einen fehlgeschlagenen Start oder Stopp. Ein verlorenes Ergebnis wird zunächst
als unbekannter Ausgang behandelt; die Aktion wird nicht blind wiederholt.

## Beobachtungen

`process`: `unknown`, `stopped`, `running`.
`ready`: `null`, `false`, `true`; ein Boolean ist nur bei `running` zulässig.
`observed_at`: Zeit der Beobachtung, anfangs `null`.

`running` bedeutet ausschließlich: Prozess beobachtet. Ohne eigene belastbare
Spielbeobachtung bleibt `ready=null`. Weder gestarteter Auftrag noch laufender
Prozess bestätigen allein die Bereitschaft zum Beitritt. Eine fehlende oder
veraltete Beobachtung wird später ausdrücklich als solche angezeigt.

## Operationszustände

| Zustand | Bedeutung | Zulässiger nächster Zustand |
| --- | --- | --- |
| `pending` | Angenommen, noch keine native Ausführung | `running`, `cancelled` |
| `running` | Native Ausführung begonnen | `succeeded`, `failed`, `unknown` |
| `unknown` | Ausgang nach Abbruch/Timeout ungeklärt | `succeeded`, `failed` nach belegtem Abgleich |
| `succeeded` | Ergebnis bestätigt | abgeschlossen |
| `failed` | Fehler bestätigt | abgeschlossen |
| `cancelled` | Vor Ausführung verworfen | abgeschlossen |

Eine aktive Operation ist `pending`, `running` oder `unknown`. Eine SQLite-
Transaktion mit eindeutiger aktiver Reservierung verhindert doppelte Starts
zwischen gleichzeitigen PHP-Prozessen. Derselbe aktive Start/Stopp liefert
dieselbe ID und behält den ursprünglichen Akteur. Eine Gegenaktion oder jeder
weitere Befehl bei `unknown` liefert `server_busy`. Unterschiedliche Server
blockieren einander nicht dauerhaft; kurze SQLite-Schreibtransaktionen sind serialisiert.

Vor jeder Annahme/Ausführung muss PHP bestätigte Identität, aktuelle Freigabe und
Profilbindung prüfen. Nach einem Executor-Abbruch werden begonnene Operationen
vor neuer Ausführung abgeglichen; keine automatische Wiederholung. Der
`FixtureStore` bleibt synthetisch. Sein Test belegt die erhaltene unbekannte
Reservierung nach erneutem Öffnen. EP03 prüft zusätzlich das native Journal,
Timeout und Abgleich nach Helferabbruch an isolierten Windows-Prozessen.

## Entwicklungs-HTTP in EP01

`GET/HEAD /`: PHP-gerenderte Diagnose. `GET/HEAD /health`: Fixture-/Instanzkennung
und `native_enabled=false`, dazu `build` als Release-Commit oder `development`.
`GET/HEAD /api/status`: synthetische Beobachtungen
beider Spiele, zunächst unbekannt. Andere Methoden ergeben 405, andere Pfade 404;
fremde Hosts/Peers 403 und fehlende oder strukturell unvollständige Fixture 503.
Es gibt keine schreibende HTTP-API.
