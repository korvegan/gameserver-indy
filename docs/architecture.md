# Architektur

Die einzige unterstützte Anwendung ist PHP/Symfony. Native Windows-Spielserver
laufen unabhängig von der Verwaltung. Ein Ende des Dashboards beendet keine
Spielsitzung. Der frühere Python-Stand liegt ausschließlich unter `archive/`.

| Bereich | Einstieg |
| --- | --- |
| HTTP und Benutzerrechte | `symfony/src/Http/`, `symfony/src/Access/` |
| Spiele und Instanzen | `symfony/src/Game/`, `symfony/src/Instance/`, `profiles/` |
| Auftragsannahme und Ausführung | `symfony/src/Job/`, Symfony Messenger |
| Windows-Prozessbrücke und Supervisor | `symfony/src/Runtime/`, `scripts/windows-server.ps1` |
| Sicherungen | `symfony/src/Backup/` |
| Steam, Beitritt und Discord | `symfony/src/Integration/` |
| Persönliche WhatsApp-Anbindung | `symfony/src/WhatsApp/` |
| Chronik und Wandteppich | `symfony/src/Community/` |
| Oberfläche | Twig und bestehende ES-Module unter `web/` |

Der lokale Besitzerlistener liegt auf `127.0.0.1:8765`, der separate Familienlistener
hinter Tailscale Serve auf `127.0.0.1:8767`. HTTP liest zwischengespeicherte
Prozessbeobachtungen; schreibende Aufträge besitzen eine ausdrückliche Profil-ID.
Der Supervisor hält eine Laufzeitkennung und startet eigene Worker, Beobachter,
Planer und Webserver. Native Aktionen prüfen den vollständigen EXE-Pfad und die
Prozessidentität; sie suchen nicht bloß nach einem Prozessnamen.

Der Ereignisweg lautet: native Datei-/Prozessänderung → spielbezogener Parser →
dauerhaftes SQLite-Ereignis → gezielter Metadatenauftrag → Liveanzeige über SSE.
Lokale Weckhinweise ersetzen die schnellen Auftrags-/Statuspolls. Der Planer wartet
auf konkrete Fristen oder Änderungen. Verbleibende Mess- und Abgleichintervalle,
Startbindung und Deduplizierung stehen in [event-driven-runtime.md](event-driven-runtime.md).

Code wird als unveränderliches Release aus dem geprüften Git-Commit installiert.
Die aktuelle Datenablage enthält Profile, SQLite-Datenbanken, Freigaben und Schlüssel
und bleibt bei Releasewechseln dieselbe. Ein Rückweg verwendet ein vorheriges
PHP-Release; historische Datenkopien sind keine aktuelle Datenablage.

Die Serverzentrale hängt die vorhandenen Ansichten einmal in eine feste Hülle ein.
Profil- und Tabwechsel dürfen Entwürfe nicht unbemerkt verwerfen. Neue Spielprofile
nutzen die Spiel-ID für Motiv und Adapter; fehlende Fähigkeiten bleiben unbekannt.

Diese App ist für den privaten Familienbetrieb ausgelegt. Der Umfang neuer
Abstraktionen und Prüfungen richtet sich nach [AGENTS.md](../AGENTS.md) und
[docs/testing.md](testing.md), nicht nach einer SaaS-Plattform.
