# EP06: Release und Betriebsabnahme

Zu [EP06 #146](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/146)
und [#163](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/163).
Eine erfolgreiche Vorbereitung ist keine Aktivierung oder Boot-/Smartphoneabnahme.
Die Freigabe des konkreten Wartungsfensters wird im Arbeitsissue festgehalten.

## Ablage und Laufzeit

Der geprüfte Main-Commit wird aus Git-Blobs nach
`~/.local/share/gameserver-indy/mvp-releases/<Commit>/code` verpackt.
Daneben liegt die feste Projektlaufzeit: offizielles FrankenPHP **1.13.2**, PHP
**8.5.12**, SHA-256 `46b62a9cd98ce3cccd403f2183bfa4f3c4a8c53a1f16bef488f426d95f654897`.
`runtime-source.json` hält die Herkunft fest; `runtime/php` verwendet dieselbe
Datei über `php-cli`. Die [offizielle portable Distribution](https://frankenphp.dev/docs/#standalone-binary)
enthält HTTP-Server, PHP und Erweiterungen. Es werden keine Systempakete in ein
Benutzerverzeichnis entpackt. Das [Sicherheitsrelease](https://github.com/php/frankenphp/releases/tag/v1.13.2)
enthält die PHP-Korrekturen; spätere Versions-/Hashwechsel werden gemeinsam geprüft.

Der dauerhafte Zustand liegt getrennt unter `mvp-state-<Kennung>` neben den Releases,
Verzeichnis 0700, Dateien 0600. Der Marker `installation` unterscheidet ihn von den
wegwerfbaren Fixtures. Starten initialisiert keine fehlenden Daten neu.
Freigaben, Schlüssel, Aufträge und native Operationsbelege bleiben bei Codewechseln erhalten.
Windows-Skripte liegen unter `%LOCALAPPDATA%\GameServerIndy\mvp-releases\<Commit>`,
private Profile/Belege getrennt unter `mvp-state-<Kennung>\bridge` mit ACL für Besitzer,
SYSTEM und Administratoren. Skripte bleiben wegen RemoteSigned auf Windows, keine Policyänderung.
Installationen und Welten bleiben an ihren bestehenden Pfaden.

## Vorbereitung ohne Aktivierung

Im sauberen Main-Checkout nach Review, grüner CI und Merge:

```sh
curl --fail --location --output /tmp/indy-frankenphp-1.13.2 \
  https://github.com/php/frankenphp/releases/download/v1.13.2/frankenphp-linux-x86_64
python3 app/tools/release.py prepare <vollständiger-Main-Commit> \
  /mnt/c/Users/korvegan/AppData/Local/GameServerIndy \
  /mnt/c/Users/korvegan/AppData/Local/Microsoft/WindowsApps/Microsoft.PowerShell_8wekyb3d8bbwe/pwsh.exe \
  /tmp/indy-frankenphp-1.13.2
```

Der Download wird vor Ausführung über den festgelegten Hash geprüft. Bestehende
Pakete werden nicht überschrieben. Die Ausgabe nennt den privaten `release-plan.json`.
Bei unvollständiger Vorbereitung nur deren eigene neuen Dateien prüfen; keine
aktiven Datenverzeichnisse löschen oder historische Datenbanken zurückkopieren.

`Prepare-Profiles.ps1` liest bisherige Bindungen und Spielkonfigurationen, verlangt
vorhandene Weltdateien und verwendet dieselbe ASKA-/Enshrouded-Weltprüfung wie die Brücke.
Eine anschließende native Statusabfrage sendet kein Spielsignal. Beitrittswerte stammen
aus bisheriger Domain/Query-Port bzw. ASKAs Servernamen; keine Passwort-/Tokenausgabe.

Der Import liest nur die separate aktuelle Zugangsdatenbank, schreibgeschützt in einer
konsistenten Transaktion. Login-Normalisierung, Namen, Revisionen und Serverlisten bleiben
erhalten; IDs werden deterministisch zugeordnet. Besitzer werden Administratoren,
Familie bleibt Familie, gesperrte Zugänge bleiben gesperrt, Leser zunächst ebenfalls.
Der Hash des importierten Satzes verhindert einen veralteten Import beim Aktivieren.
Die Quelle bleibt unverändert; der neue CSRF-Schlüssel ist separat.

`Update-Startup.ps1` prüft ohne `-Apply` nur den geplanten Aktionswechsel der vorhandenen
Besitzer-/Passwort-/Bootaufgabe. Kontokennwort und PIN werden weder gelesen noch gespeichert.
Beim realen Aktionswechsel verlangte Windows trotz administrativem Kontext mit
`0x8007052e` erneut das Kontokennwort. Der Nutzer änderte die Aktion lokal über
`Set-ScheduledTask -Action ... -User ... -Password ...`, mit einem lokalen
`Get-Credential`-Dialog und ohne Ausgabe des Kennworts. Konto und Trigger blieben
erhalten. Vor erneutem Aktivieren die gespeicherte Aktion prüfen; passt sie bereits,
überspringt der Helfer die Änderung und erhält die ursprüngliche XML-Sicherung.

## Vereinbartes Wartungsfenster

Vor echten Schritten bestätigen: beide Spiele frei, keine anderen Hostarbeiten betroffen,
Smartphone/Tailscale und echte Familienidentität für die Abnahme verfügbar.
Einen Windows-Neustart ausdrücklich vereinbaren. Der EP06-Auftrag ersetzt diese
Betriebsfreigabe gemäß #146 nicht.

`python3 app/tools/release.py activate <privater-Plan>` führt im bestätigten Fenster aus:

1. Unveränderte Launcher, Freigaben, Familien- und Spielkonfigurationen prüfen;
   archivierter Controller weiterhin inaktiv. Vorhandenen Serve-Weg lesend abgleichen:
   lokal 8765, Familienbackend 8767, privates HTTPS/443. Keine Netzänderung.
2. Neue Aufträge unter derselben Annahmesperre wie HTTP sperren und laufende Executor
   abwarten. Unklare Operationen blockieren; kein Löschen oder erneutes Ausführen.
3. Erstumstellung nur bei gestoppten Spielen: vollständige Weltverzeichnisse privat
   kopieren und sämtliche Dateien per SHA-256 vergleichen. Teilkopien sind kein Erfolg.
4. Bootaufgabe nach XML-Sicherung nur auf die installierte PowerShell 7.6+ und denselben
   Starter umstellen. Konto, Passwortanmeldung und Trigger erhalten, kein Policy-Bypass.
   Falls Windows persönliche Anmeldung/erhöhte Rechte verlangt: konkreten lokalen
   Schritt an den Nutzer geben; keine Berechtigungen ändern.
5. Nur den über Port, EXE, Arbeitsverzeichnis, Argumente und Startidentität geprüften
   Verwaltungsprozess geordnet beenden. Bindung und Starter atomar wechseln, Windows-
   Einstieg ohne Browser starten. Health muss Build, Produktionsmodus und Zustandskennung bestätigen.
6. Unveränderte Spielprozessidentitäten prüfen, dann Auftragssperre entfernen.
   Verwaltungsstart startet keine Spiele.

Die eine HTTP-Instanz hält eine Zustandslease auf Deskriptor 9. Einmalige Executor
schließen sie vor `setsid`; andere Sperren öffnen mit Close-on-exec. Unter ZTS setzt
`env --chdir` das tatsächliche Arbeitsverzeichnis vor dem Exec; PHPs virtuelles `chdir()` genügt nicht.

## Rückweg und Wiederanlauf

Fehlende Bereitschaft nach dem Wechsel löst einen Versuch mit dem vorigen verwalteten
Stand aus; die Aktivierung meldet dennoch Fehler. Ein fremder Listener wird nicht beendet.
Kein harter Abbruch, kein Spiele-Stopp. Bewusster Rückweg:
`python3 app/tools/release.py rollback <privater-Plan>`.
Nur Starter, Launcher und Laufzeitbindung werden zurückgesetzt. **Aktuelle Zugänge,
Schlüssel, Aufträge und Welten werden nicht zurückgespielt.** Bei der Erstumstellung
ist der Rückweg das synthetische PHP-Gerüst, niemals die archivierte Verwaltung.
Das Familienbackend kann dann ausfallen; es aktiviert keine alten Freigaben.
Die PowerShell-7-Taskaktion bleibt bestehen; ihr XML ist ein privater Nachweis.

Bei Abbruch vor dem Stop kann die bisherige Verwaltung mit Wartungsmarker weiterlaufen.
Ursache und Aufträge zuerst klären; nur bei unverändertem aktivem Stand den eigenen
Marker `<AppStateDirectory>/maintenance` entfernen. Unbekannte Operationen nicht ändern.
`python3 app/tools/release.py restart <privater-Plan>` startet ausschließlich die aktive
Verwaltung nach Auftragssperre/Abwarten neu und vergleicht die Spielidentitäten.

## Echte Abnahme – erst nach Freigabe abhaken

- [x] Lokal am Desktop und auf dem Smartphone über privates HTTPS erreichbar,
  bestätigter Build; Smartphone vom Nutzer bestätigt.
- [ ] Echte Familienidentität bedient beide Spiele; gesperrter Zugang abgewiesen;
  Besitzer einschließlich Verwaltungsrechten erhalten.
- [x] Beide vorhandenen Welten im Spiel wiedererkannt, beigetreten und bestätigt
  gestoppt; neue inhaltlich geänderte Weltdateien nachgewiesen.
- [ ] Verwaltung während Spielrunde neu gestartet: gleiche PID/Startzeit, Runde läuft weiter.
- [ ] Nach vereinbartem Windows-Neustart am Anmeldebildschirm bleiben. Smartphonezugriff,
  ASKA-Start im tatsächlichen Bootkontext, Welt/Beitritt/Speichern bestätigen; erst dann Desktopanmeldung.
- [x] Privaten `startup-proof.json` mit Boot-/Bereitschaftszeit, Build und Windows-Sitzung 0
  abgleichen. Ein Desktopaufruf überschreibt den vorhandenen Nachweis derselben Boot-Sitzung 0 nicht.

Taskregistrierung, manueller Taskstart, synthetische Header und Besitzer-Selbsttest
ersetzen weder echten Bootnachweis noch die persönliche Smartphone-/Familienabnahme.

## Betriebsnachweis vom 22.10.2026

Die Freigabe „ja, du kannst umstellen“ und der persönliche Windows-Schritt sind in
[#163](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/163#issuecomment-2808)
festgehalten. Die Erstumstellung aktivierte das unabhängig geprüfte Release
`b6bbcc46744b3f942444f97461782a29a347ceec` mit PHP 8.5.12. Beide Spiele waren bei
der Erstumstellung gestoppt. Vollständige Weltkopien wurden Datei für Datei per SHA-256
verifiziert; `world-checkpoint/verified.json`, Task-XML und `activation.json` bleiben privat.
Die drei bestehenden aktivierten Besitzerzugänge wurden als Administratoren übernommen.
Installationen, Weltpfade und Serve blieben erhalten; keine Netzänderung.

Beide echten Starts über die HTTP-App sind nativ bestätigt. Anschließender
Verwaltungsneustart bestanden: beide Spiel-PIDs und Startzeiten unverändert,
passender Produktions-Health wieder verfügbar, `restart.json` vorhanden.
Das belegt Prozessfortbestand; eine aktive Spielerunde war dabei noch nicht bestätigt.
Lokaler Browser zeigt Dashboard und beide Spielkarten, der Nutzer bestätigt sie
auf seinem Smartphone mit Tailscale. Der Nutzer ist beiden Spielen beigetreten
und bestätigt die vertrauten Welten. Danach beide Stopps über die HTTP-App regulär
und nativ bestätigt; kein erzwungenes Beenden. Der Offline-Vergleich zum Checkpoint
zeigt 18 inhaltlich geänderte ASKA-Dateien und vier Enshrouded-Dateien, jeweils mit
Schreibzeiten nach dem Start und ohne neue/entfernte Dateien im abschließenden
Dateisatz. `game-cycle-check.json` bleibt privat; der zusammengefasste
[Spielnachweis](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/163#issuecomment-2809)
steht im Arbeitsissue. Das belegt geschriebene Weltdateien, keine semantische
Prüfung des Speicherformats oder Wiederherstellung.

Die Prüfung mit anderer Familienidentität ist auf Nutzerwunsch verschoben.
Sie und der Verwaltungsneustart bei gleichzeitig aktivem Spieler bleiben offen;
Prozessfortbestand und Beitritt nach dem bisherigen Neustart sind belegt.
Der damalige private Startnachweis zeigte Sitzung 1 und ersetzte keinen Bootnachweis.
Der anschließend separat freigegebene Windows-Neustart ist unten festgehalten.

Die anschließende Main-CI fand einen reproduzierten Lesewettlauf: Prozessbeobachtung
und neuester Auftrag stammten aus getrennten Abfragen. Ein dazwischen abgeschlossener
Start konnte dadurch kurz als abgeschlossen mit altem gestopptem Prozess erscheinen.
Die Korrektur liest beides in einem gemeinsamen SQLite-Snapshot; sie verändert keine
Spielbefehle oder Datenbankstruktur. Der gezielte Test lässt einen zweiten Schreiber
während der Statusabfrage abschließen. Aktuellen installierten Codecommit stets aus
`launcher.local.json` und `/health` abgleichen; der Betriebsnachweis oben beschreibt
die Erstumstellung. Auch die Korrektur wird nur als geprüftes Release installiert.

## Bootprüfung und Brückenstartfehler vom 22.10.2026

Der Nutzer gab den Windows-Neustart mit „ok, dann jetzt neustarten“ ausdrücklich frei.
Der erhaltene private `startup-proof.json` bestätigt Boot um 14:30:22 UTC und
Bereitschaft um 14:30:58 UTC in **Windows-Sitzung 0**, mit Build
`ce32136fdd8e5fe8e6db7cdbde9dc8dd9dfbe92a` und derselben Zustandskennung.
Der Nutzer bestätigt Smartphonezugriff und ASKA-Start vor der Desktopanmeldung.
Native Beobachtung bestätigt den ASKA-Prozess in Sitzung 0.

Der anschließende ASKA-Stopp blieb in der Oberfläche als unklar gesperrt.
Der Executor hatte `transport_failed` gespeichert: `proc_open` hatte keinen
Brückenprozess erzeugt. Der ursprüngliche Request lag vor, ein nativer Stoppbeleg
und ein noch laufender zugehöriger Helfer fehlten. ASKA reagierte laut Windows;
ein Zusammenhang mit seiner Spielinitialisierung ist nicht nachgewiesen.
Der nachweislich nicht zugestellte ursprüngliche Stopp wurde im Bootkontext unter
derselben Operations-ID zugestellt. Die Brücke bestätigte den regulären Stopp
genau des beobachteten ASKA-Prozesses; der HTTP-Statusabgleich löste die Sperre.
Danach beide Spiele gestoppt, keine Datenbankzeilen manuell zurückgesetzt.

[#167](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/167)
unterscheidet den sicher fehlgeschlagenen Brückenstart von einem unklaren
Ausführungsausgang. Ohne gestarteten Helfer schlägt der Auftrag fehl; nach frischer
Prozessbeobachtung ist die Bedienung wieder möglich. Timeouts und verlorene oder
ungültige Antworten bleiben unklar. Es gibt keine automatische Wiederholung.
Ein eigener Testprozess begrenzt seine Dateideskriptoren und reproduziert den
fehlgeschlagenen `proc_open`-Aufruf; die Ursache der damaligen Startstörung ist damit
nicht bestimmt.

Verwaltungsstart, Smartphonezugriff und ASKA-Start/-Stopp im Bootkontext sind belegt.
Welt/Beitritt/Speichern nach diesem Boot, andere Familienidentität und ein
Verwaltungsneustart während einer aktiven Spielerunde bleiben offen.
