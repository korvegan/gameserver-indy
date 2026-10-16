# PHP-Betrieb und Releases

Seit 17.10.2026 wird ausschließlich Symfony unterstützt. Die
[frühere Cutover-Dokumentation](../archive/migration-tools/symfony-runtime-history.md)
ist historisch; insbesondere ihre Python-Rückwegschritte nicht mehr verwenden.

## Aktiver Betrieb

Die Windows-Desktop-Verknüpfung und Bootaufgabe verwenden
`%LOCALAPPDATA%\GameServerIndy\Start-Manager.ps1`. Die danebenliegende private
`launcher.local.json` enthält WSL-Distribution/-Benutzer, `ProjectDirectory`,
`RuntimeEngine=symfony`, `RuntimeExecutable`, `StateDirectory` und `Port`.
Der aktuelle Code liegt in einem unveränderlichen Release, die Daten separat.
Aktuelle Pfade aus dieser Konfiguration lesen, nicht einen alten Checkout oder
eine frühere Migrationskopie als aktiven Stand annehmen.

Der Starter verwendet das bestehende Windows-Konto und führt
`<RuntimeExecutable> symfony/bin/indy <StateDirectory>` in der konfigurierten
WSL-Distribution aus. Ein vorhandenes Dashboard wird wiederverwendet.
`start.cmd` im Checkout verwendet dieselbe private Startkonfiguration.

Der Installer `scripts/install-desktop-shortcut.ps1` kann bei einem geprüften
Releasewechsel nur `-ProjectDirectory` ändern und erhält die übrigen bestehenden
Einstellungen. Er registriert weder die Bootaufgabe noch das Benutzerkonto neu.

## Vorbereitung und Rückweg

`app:release:build <sauberer Checkout> <neues Releaseziel> <Commit-SHA>` erstellt
mit den lockgleichen Composer-Bibliotheken ein geprüftes, getrenntes Codepaket.
Das aktiviert weder einen Dienst noch eine Datenkopie. Bei einer frischen lokalen
Cacheprüfung einen passenden separaten `INDY_CACHE_DIR` wählen.

Vor einer Aktivierung laufende Aufträge abwarten und den vorherigen Codepfad
erhalten. Nur den über PID/Startzeit eindeutig zugeordneten Verwaltungs-Supervisor
geordnet beenden; keine Spielprozesse stoppen. Nach dem Wechsel Antwort,
aktivierten Code und dauerhafte Launcher-Konfiguration gemeinsam prüfen.

Ein Rückweg startet das vorige PHP-Release **mit derselben aktuellen Datenablage**.
Kein Zurückkopieren historischer Datenbanken und keine Aktivierung des archivierten
Python-Codes. Laufende Welten, Konfigurationen und Zugangsschlüssel bleiben erhalten.

## Releasewechsel mit einem Befehl

Nach Review und grüner CI im sauberen Checkout des geprüften Commits unter WSL:

```bash
node scripts/release.cjs <vollständige-Commit-SHA> /mnt/c/Users/korvegan/AppData/Local/GameServerIndy/launcher.local.json
```

Der Befehl baut bzw. verifiziert das unveränderliche Paket und das vorherige
PHP-Release. Bei noch laufenden Aufträgen bricht er vor dem Wechsel ab; später
erneut ausführen. Er stoppt nur den identifizierten Verwaltungssupervisor,
aktualisiert den bestehenden Launcher und startet diesen ohne Browser.
HTTP-Antwort, Supervisorpfad und dauerhafter Launcher müssen übereinstimmen.
Auch bei einem Aufrufertimeout entscheidet die tatsächliche Bereitschaft.

Spielprozessidentitäten, Spielkonfigurationen und Sitzungsschlüssel werden vor
und nach dem Wechsel verglichen. Bei fehlender Bereitschaft wird das vorherige
PHP-Release mit denselben aktuellen Daten gestartet und geprüft. Ein erfolgreicher
Rückweg liefert trotzdem Exitcode 1, eine Aktivierung Exitcode 0. Der private
Nachweisordner wird ausgegeben; keine Tokens oder Spielkonfigurationen ausgeben.
Bei Fehlern während des Rückwegs private Laufzeitprotokolle prüfen, keine alten
Datenbanken zurückkopieren. Der Befehl setzt eine laufende Verwaltung und die
bestehende Windows-/WSL-Einrichtung voraus; er ist kein Erstinstallationsassistent.

Isolierte Prüfungen und tatsächlichen Originalbetrieb unterscheiden. Ein
bestätigter normaler Start belegt noch keinen unbeaufsichtigten Windows-Kaltstart.

Isolierte Prüfung: `node --test tests/release.test.cjs`. Die reguläre CI prüft den
normalen Wechsel; Startfehler und Aufrufertimeout bleiben gezielte manuelle Fälle.
