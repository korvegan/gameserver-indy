# Umsetzungsstand vom 17.10.2026

Die offenen Themen wurden einzeln geprüft und so weit umgesetzt, wie es ohne persönliche Anbieteranmeldung, gewähltes Modpaket, echte Mehrspielerrunde oder Host-Neustart möglich ist. Teilfertige Issues bleiben offen. Erfolgreiche Fixturetests ersetzen diese Abnahmen nicht.

| Issue | Nutzbarer Stand | Noch erforderlich |
| --- | --- | --- |
| #8 Leistung | 24-Stunden-Verlauf, native CPU/RAM-/Prozess-I/O-Werte, Rucklermarker und Auftragstimeline | Viererrunde mit lokalem Client; Datenträger-/Upload-/GPU-/Gesamtenergieabgleich |
| #9 Updates | Verifizierter Checkpoint, persistente Startsperre, geführter Steam-Schritt und geschützter Konfigurationsvergleich | Persönlicher Steam-Download und echter Beitritt; spielbezogener automatischer Updater erst nach bestätigtem Installations-/Anmeldeweg |
| #13 Welten | Enshrouded: separate neue Kampagne, geprüfte Kopie, Auswahl und Rückkehr; ASKA-Zielanzeige | Geprüfter Fremdimport, Versionsmigration an Kopie; freie ASKA-Wahl benötigt bewusste Starteranpassung |
| #14 Wartung | Vormerken, ausdrückliche Besitzerfreigabe, Vorlauf, Dashboardwarnung, Abbruch vor Jobannahme | Verlässliche Zählquelle mit echten Ein-/Austritten vergleichen; unbekannt löst keine automatische Leerfreigabe aus |
| #16 Dauerbetrieb | Besitzer-Startaufgabe und Tailscale-Status geprüft, Bildschirmtimer 15 Minuten mit Sicherung/Rückweg | Kaltstart/Abmeldung/ASKA-Speichern im echten Kontext; erst danach Spiel-Autostart, konkretes Wartungsfenster und möglicher Windows-Ausstieg |
| #17 Familie | Privates HTTPS, persönliche Rechte, Gerätefreigabe; Axel/Tina behalten Besitzerrechte und normale FIFO-Steuerung | Außentest der persönlichen Zugänge nach Kaltstart ohne Desktop-Anmeldung |
| #19 Modverwaltung | Quellenkatalog, feste gemeinsame Profile und manuelle Rückmeldungen | Erstes tatsächlich gewünschtes Spiel/Modpaket auswählen; Installation, Aktivierung, Datei-Rückweg und echte kompatible Clients |
| #20 Quellen | Workshop, Nexus, RaftModding und weitere Modseiten; normalisierte Originalreferenzen und manuelle Pins | Automatische Metadaten-/Downloadadapter nur bei bestätigtem Weg; konkrete Paket-/Viererabnahme |
| #21 Verteilung | Unveränderliche Revisionen, Vergleich, Manifestexport, Rückzug, persönliche manuelle Prüfmeldung | Geprüfter Modmanager-/Loaderimport und lokaler Installationsabgleich für das ausgewählte Paket |
| #23 Errungenschaften | Feste Mitglieder-/Kampagnenkreise, manuelle Sammelziele, gleiche Auszeichnung und idempotenter Chronikabschluss | Automatische Steam-/Spielquellen nach persönlichen Kontoverknüpfungen und Datenfreigaben |
| #25 Wandteppich | Dauerhafte Szenen, Enthüllung, Kapitel, Zoom, Tastatur, reduzierte Bewegung, SVG-Standbild | Physisches Familienhandy sowie native CPU/GPU-Last während echter Runde |
| #37 Mitglieder | Persönliche Profile, Identitätszuordnung, Schreib-/Leserrolle und verifizierbarer Steam-OpenID-Ablauf | Personen anlegen/zuordnen und jeweils selbst bei Steam anmelden; Privatsphäre-/Datenverfügbarkeit prüfen |
| #22 / #18 Epics | Nutzbare Gemeinschaft und Betriebsfunktionen integriert | Offen halten, solange die oben genannten echten Restpunkte bestehen |

Abgeschlossen wurden die Effizienzthemen #82–#90/#92 sowie Logwerkzeuge #11, Shaderzusammenfassung #12, Fähigkeiten #5, Bereitschaft #7, Discord-Statuskarte #15 und die erste ausdrückliche Gemeinschaftsveröffentlichung #26. Kein Zugangsschlüssel, keine Anmeldung, kein aktueller Spielerstand und keine Geräteabnahme wurden erfunden. Discord-/WhatsApp-Testmeldungen oder produktive Moddownloads wurden nicht ausgelöst.

## Einstieg für die nächste Abnahme

Dashboard lokal `http://127.0.0.1:8765`, privat `https://indy.taila774ef.ts.net` mit Tailscale. Nach einem Update die Seite einmal neu laden.

Die höchste verbleibende Betriebspriorität ist der kurze bewusste Kaltstart-/ASKA-Speichertest aus [Hostbetrieb](host-operation.md). Danach eine normale Viererrunde mit einer Rucklermarkierung messen. Für Mods reicht zunächst die Wahl eines konkreten Spiels/Pakets; erst damit lassen sich Loader, Bezugsrechte, Installation und Viererbeitritt sinnvoll abschließen. Mitglieder verwenden ihre eigenen Steam-Anmeldungen; keine Passwörter im Chat.

Bedienung und Grenzen: [Weltverwaltung](world-management.md), [Wartung](maintenance.md), [Update-Assistent](update-assistant.md), [Mods](mods.md), [Mitglieder](member-profiles.md), [Sammelziele](community-challenges.md), [Gemeinschafts-Discord](community-discord.md).

## Aufwand und Prüfungen

Die normalen PHP-Prüfungen dauern lokal etwa 0,3 Sekunden; sie decken aktuell 26 normale Familienabläufe ab. Browserabläufe prüfen konkrete Bedienung mit eigenen PHP-Daten und synthetischen Integrationen; native Windows-Prozesse werden separat und nur bei betroffenen Komponenten geprüft. Der neue native Weltwechselnachweis dauerte lokal etwa 90 Sekunden. CI-Gesamtzeit enthält zusätzlich Runner- und Einrichtungszeit und ist nicht mit reiner Testzeit gleichzusetzen.

Jeder PR erhielt ein unabhängiges Review seines finalen Commits vor dem Merge. Konkrete Befunde betrafen unter anderem veraltete Discord-Kartenzustände, Mitglieder-/Kampagnenzuordnung, Modhinweise, lesbare Modhistorien und leere Weltcheckpoints; sie wurden gezielt behoben. Keine Vollabsicherung seltener SaaS-Fälle und keine Änderung der Reasoning-Einstellung. Für weitere Arbeit: gleiche Komponenten bündeln, genau einen CI-Beobachter verwenden, während Fixture-/Releasekopien den Quellbaum unverändert lassen und nach einer Korrektur zuerst den betroffenen Ablauf nachprüfen.
