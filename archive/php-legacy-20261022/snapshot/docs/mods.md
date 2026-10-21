# Modquellen und gemeinsame Profile

Unter **Spiele / Mods → Spiel → Modquellen und feste Modstände** ordnet ein Besitzer mehrere Anbieter zu. Das funktioniert auch für Raft ohne Dedicated-Serveradapter. Steam verwendet die **Spiel-App-ID**, Nexus das Spielkürzel seiner Modseiten, RaftModding `raft`; weitere Seiten erhalten eine eigene Spielkennung.

Über **Spiel ohne eigenen Dedicated Server hinzufügen** werden beispielsweise Kennung `raft` und Anzeigename `Raft` eingetragen. Das legt nur einen Katalogeintrag an: keinen Installationsordner, keine Serverdateien und keine angebliche Dedicated-Unterstützung. Nur ein uneingeschränkter Besitzer darf neue globale Katalogspiele hinzufügen.

Der Katalog importiert Originalseiten und eindeutige Quellenkennungen. Gleiche Anzeigenamen führen nicht zum Zusammenlegen verschiedener Anbieter. Modversion, Dateikennung, SHA-256, Loader, Client-/Server-Ziel, Ladefolge, quellenübergreifende Abhängigkeiten über die angezeigte Modkennung und gemeinsame Hinweise werden bewusst manuell erfasst. Zum Aktualisieren denselben Quellen-/Dateieintrag erneut speichern. Ein neuer Dateistand bleibt ein eigener Eintrag.

Es gibt keine verdeckten Downloads, keine Installation, keinen Datei-Cache und keine automatischen Metadatenabrufe. Abrufbarkeit steht zunächst auf **ungeprüft**. Originallinks werden im Browser des jeweiligen Spielers geöffnet. Persönliche Parameter und kurzlebige Downloadlinks werden nicht akzeptiert; keine Passwörter oder persönlichen Konfigurationen in Hinweise schreiben. Versionen ohne Hash oder Loader sind noch kein geprüftes Gruppenprofil. Der Browser kann andere Spielordner nicht inventarisieren.

Recherche am 17.10.2026:

- [Valve Workshop-Integration](https://partner.steamgames.com/doc/features/workshop/implementation?l=english): Dedicated-Downloads benötigen die jeweilige Spielintegration und passende Rechte. Der Linkimport prüft weder Besitz noch Workshop-Unterstützung.
- [Nexus API-Regeln](https://help.nexusmods.com/article/114-api-acceptable-use-policy): persönliche API-Schlüssel nicht auf einem eigenen Gruppenserver sammeln. Individueller Originaldownload; [Collection-Downloadbedingungen](https://nexus-mods.github.io/NexusMods.App/users/gettingstarted/DownloadACollection/) hängen vom eigenen Zugang ab.
- [RaftModding Modpacks](https://www.raftmodding.com/mods/modpacks): Profilfunktionen werden aktuell als deaktiviert ausgewiesen. [Loader 2.8.0](https://www.raftmodding.com/launcher/2.8.0) beschreibt Änderungen am Netzwerk und Dedicated-Unterstützung. Ein verfügbarer Modlink belegt keine funktionierende Vierergruppe.

Nächste Abnahme: konkretes Spiel und Modpaket auswählen, Versionen und Loader prüfen, an einer Weltkopie und mit vier eigenen Clients testen. Bis dahin keine automatische Installation oder kompatiblen Modmanager-Import behaupten.

## Profilrevision für die Gruppe

Im selben Spielbereich unter **Gemeinsame Modprofile** wählt ein Besitzer die gespeicherten Modstände und gibt Profilname, Spielbuild und Loader an. Jede Moddatei benötigt exakte Version, Datei-/Revisionskennung, SHA-256 und Loader. Alle angegebenen Abhängigkeiten müssen mit ausgewählt werden; pro Mod ist ein Dateistand vorgesehen. Ein optionaler Client-Mod ist als solcher gekennzeichnet und keine Serveranforderung.

Die freigegebene Revision ist ein unveränderlicher Schnappschuss: spätere Katalogänderungen verändern sie nicht. Eine neue Freigabe erzeugt die nächste Revision. Vergleich mit der vorherigen Revision nennt hinzugefügte, entfernte und geänderte Quellenidentitäten, Spielbuild und Loader. Zurückziehen verhindert weitere Exporte dieser Revision, ändert aber keine Dateien auf Spielerrechnern.

Mitspieler laden ein kleines JSON-Manifest herunter und beziehen Dateien über die ursprünglichen Modseiten mit ihren eigenen Zugängen. Es wird kein funktionierender Workshop-/Modmanager-Import vorgetäuscht. Der SHA-256 jeder Moddatei kann lokal mit `Get-FileHash -Algorithm SHA256 <Datei>` geprüft werden. Dateien, persönliche Einstellungen und Spielstände werden nicht übertragen oder verändert. Der Profil-Inhaltshash bezeichnet die kanonischen JSON-Daten, nicht die Bytes der eingerückten Exportdatei.

**Eigene Versionen und Hashes geprüft** bzw. **Bei mir abweichend** speichert ausschließlich die eigene manuelle Rückmeldung mit Profilhash und Prüfzeit. Ohne Rückmeldung bleibt der lokale Stand unbekannt, nach 24 Stunden erscheint sie als älter. Sie bestätigt weder eine automatische Inventur noch einen erfolgreichen Spielbeitritt. Andere Spieler sehen keine fremden Geräteinformationen oder persönlichen Zugänge; die Gruppe sieht nur die Zahl aktueller manueller Bestätigungen.

Es gibt derzeit keinen lokalen Installationsagenten und keine eingehende Fernwartung. Ein verifizierter Import-/Installationsweg erfordert zuerst ein konkret ausgewähltes Spiel, dessen Loader sowie eine Abnahme mit vier echten Clients.
