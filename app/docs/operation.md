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

- [ ] Desktop und Smartphone über privates HTTPS erreichbar, bestätigter Build.
- [ ] Echte Familienidentität bedient beide Spiele; gesperrter Zugang abgewiesen;
  Besitzer einschließlich Verwaltungsrechten erhalten.
- [ ] Beide vorhandenen Welten im Spiel wiedererkannt, beigetreten und bestätigt
  gestoppt; tatsächliches Speichern geprüft.
- [ ] Verwaltung während Spielrunde neu gestartet: gleiche PID/Startzeit, Runde läuft weiter.
- [ ] Nach vereinbartem Windows-Neustart am Anmeldebildschirm bleiben. Smartphonezugriff,
  ASKA-Start im tatsächlichen Bootkontext, Welt/Beitritt/Speichern bestätigen; erst dann Desktopanmeldung.
- [ ] Privaten `startup-proof.json` mit Boot-/Bereitschaftszeit, Build und Windows-Sitzung 0
  abgleichen. Ein Desktopaufruf überschreibt den vorhandenen Nachweis derselben Boot-Sitzung 0 nicht.

Taskregistrierung, manueller Taskstart, synthetische Header und Besitzer-Selbsttest
ersetzen weder echten Bootnachweis noch die persönliche Smartphone-/Familienabnahme.
