# Steam-Updates erkennen und Enshrouded 1.0

Das Dashboard vergleicht den abgeschlossenen Installationsstand mit dem von Steam für denselben Branch angebotenen Build. Es prüft beim Verwaltungsstart nach etwa einer Minute, anschließend alle 30 Minuten sowie über **Steam-Updates prüfen**. Wiederholte Prüfungen desselben Profils werden mindestens eine Minute auseinander gehalten. Fehler zählen nicht als erfolgreicher neuer Befund.

## Spiele und Quellen

| Spiel | Server-Download-App | Spiel-/Start-App | Installationsbeleg | Gegenstelle |
| --- | --- | --- | --- | --- |
| Enshrouded | 2278520 | 1203620 | Zugehöriges `appmanifest_2278520.acf` | SteamCMD, `depots/branches/<branch>/buildid` |
| ASKA | 3246670 | 1898300 | Zugehöriges `appmanifest_3246670.acf` | SteamCMD, `depots/branches/<branch>/buildid` |

Bei beiden Installationen auf Indy wurde am 15.10.2026 `public` ermittelt. Installiert und angeboten waren Enshrouded **25936466** und ASKA **25834493**. Das sind zeitgebundene Prüfergebnisse, keine im Code festgeschriebenen Sollversionen.

Der Leser prüft App-ID und vollständige Ordnerzuordnung. Er unterstützt Steam-Bibliotheken mit `steamapps/common/<Ordner>` und SteamCMD-Installationen mit einem Manifest in `<Installation>/steamapps/`. Ein anderer Bibliotheksordner mit gleichem Namen genügt nicht. Fehlende, mehrdeutige, unvollständige oder abweichende Manifeste ergeben einen unbekannten Stand. `UserConfig/BetaKey` beziehungsweise `MountedConfig/BetaKey` liefern den Branch; ohne abweichende Angabe ist dies `public`. Ein noch nicht abgeschlossener Branchwechsel wird nicht als vergleichbarer Installationsstand gewertet.

Optionale Profilfelder in `manager.local.json`:

```json
{
  "steam_manifest": "D:\\SteamLibrary\\steamapps\\appmanifest_2278520.acf",
  "steam_branch": "public"
}
```

Ein ausdrücklich gesetzter Branch muss zum Manifest passen. Private oder nicht anonym sichtbare Branches bleiben bei fehlenden Metadaten unbekannt. Es werden keine Branch-Passwörter oder Steam-Anmeldedaten angefordert oder gespeichert.

Die Build-ID ist eine Identität: Unterschiedliche IDs bedeuten einen anderen angebotenen Stand, unabhängig von ihrer numerischen Reihenfolge. Eine dokumentierte Spielversionsnummer wird getrennt angezeigt. Bei Enshrouded stammt sie aus `enshrouded_server_readme.txt` und ist als **dokumentierte** Version bezeichnet; sie beweist weder eine EXE-Version noch die Version eines laufenden Prozesses. Die installierte EXE auf Indy lieferte keine Windows-Dateiversion.

## SteamCMD einrichten

SteamCMD wird getrennt vom normalen Steam-Client verwendet. Der Manager lädt darüber keine Spiele herunter und übernimmt keine bestehende Steam-Anmeldung. Einmalig eine aktuelle SteamCMD-Installation bereitstellen, deren eigene Aktualisierung abgeschlossen ist; danach einen absoluten Pfad in den lokalen Managereinstellungen hinterlegen:

```json
{
  "steamcmd_path": "C:\\Users\\<Windowsbenutzer>\\AppData\\Local\\GameServerIndy\\tools\\steamcmd\\steamcmd.exe",
  "automatic_update_checks": true
}
```

Die vorhandenen übrigen Einstellungen erhalten. Alternativ wird `steamcmd`/`steamcmd.exe` im PATH verwendet. Auf Indy ist die separate Windows-Installation unter `%LOCALAPPDATA%\GameServerIndy\tools\steamcmd` eingerichtet. Der offizielle Bootstrap stammt von [Valves SteamCMD-Download](https://steamcdn-a.akamaihd.net/client/installer/steamcmd.zip). Bei einem unter WSL neu geschriebenen Windows-Bootstrap muss die Datei auch für WSL ausführbar sein. Die erste SteamCMD-Selbstaktualisierung kann länger dauern als eine normale Metadatenprüfung; sie erfolgt vor der Einbindung in den Manager.

Die feste Befehlsfolge lautet:

```text
steamcmd +login anonymous +app_info_update 1 +app_info_print <Server-App-ID> +quit
```

Die Befehle wurden zusätzlich mit der Hilfe der installierten SteamCMD-Version geprüft. Die Abfrage verlangt einen erfolgreichen Prozessabschluss, eine bestätigte anonyme Online-Verbindung und passende App-/Branchdaten. Ein reiner Cache-Ausdruck bei gescheiterter Verbindung gilt nicht als neue Bestätigung. Rohes SteamCMD-Protokoll wird nicht an Browser oder Aufträge weitergereicht. Es gibt ein Timeout von 45 Sekunden und keinen unbegrenzten Wiederholungsversuch. Die einzelne SteamCMD-Installation wird vor gleichzeitiger Verwendung geschützt; bei Belegung folgt eine verständliche Fehlermeldung.

Valves dokumentiertes [UpToDateCheck](https://partner.steamgames.com/doc/webapi/ISteamApps#UpToDateCheck) wurde für beide Server-Apps geprüft und lieferte hier jeweils keinen App-Befund. Es wird deshalb nicht als Ersatz für den Branchvergleich verwendet. Publisher-Methoden wie `GetAppBuilds` benötigen fremde Herausgeberschlüssel und werden nicht benutzt.

## Zustände und laufender Prozess

| Zustand | Bedeutung |
| --- | --- |
| Aktuell | Vollständiger lokaler Build entspricht dem zuletzt erfolgreich abgefragten Angebot desselben Branchs. |
| Update verfügbar | Vollständiger lokaler Build und Angebot desselben Branchs unterscheiden sich. |
| Installation/Update läuft | Steam meldet laufende Installationsschritte oder unvollständige Download-/Stagingdaten. |
| Veraltet | Letzter erfolgreicher Steam-Befund ist älter als 30 Minuten oder eine folgende Prüfung schlug fehl. Alter Build und Zeitpunkt bleiben sichtbar. |
| Unbekannt | Keine belastbare Zuordnung oder noch kein vergleichbarer Steam-Befund. |

„Aktuell“ beschreibt die Dateien auf dem Datenträger. Es wird kein laufender Build aus dem Manifest abgeleitet. Liegen Änderungen an Manifest/EXE nach der Prozessstartzeit, erscheint ein Hinweis auf den unbekannten laufenden Build und einen kontrolliert einzuplanenden Neustart. Eine Zeitangabe ersetzt dabei niemals den Build-/Branchvergleich.

Ergebnisse liegen als austauschbarer Metadaten-Cache mit Schema 1 in `data/steam-updates.json`. Konfiguration, Welten, Steam-Manifeste und Anmeldung werden durch die Erkennung nicht verändert. Lesende Prüfaufträge haben eine eigene Reservierung neben dem verändernden Instanzauftrag; Start/Status und andere Profile bleiben bedienbar. Es wird nichts automatisch installiert, gestoppt oder neu gestartet. Der verändernde Update-Assistent mit Weltsicherung bleibt #9 zugeordnet.

## Enshrouded 1.0 konfigurieren

Die mit Build 25936466 ausgelieferte `enshrouded_server_readme.txt` nennt Version **1.0.0.0**, `flameLevelFogRemoval` mit Standard **11** und Bereich **0–20** sowie die erweiterte Gegnerauswahl `Few`, `Normal`, `Automatic`, `Many`, `Extreme`. Die Typinformationen der installierten EXE ordnen das neue Feld `GameSettings` zu. Es gehört daher unter `gameSettings`, nicht an die Wurzel der JSON-Datei:

```json
{
  "gameSettings": {
    "flameLevelFogRemoval": 11,
    "randomSpawnerAmount": "Automatic"
  }
}
```

Das Beispiel ist ein Ausschnitt und ersetzt keine vorhandene Datei. Im Dashboard sind beide Felder bearbeitbar und serverseitig geprüft. Eigene Gameplay-Werte benötigen `gameSettingsPreset: "Custom"`; die bisherige Vorlage und Gegnerwahl werden bei einer bloßen Ergänzung des neuen Standardfelds erhalten. Der [Hersteller-Changelog zu 1.0](https://enshrouded.com/en-US/news/release-trailer-changelog-everything-you-need-to-wait-for-tomorrow) beschreibt die Freischaltung des Bauens im Miasma auf Flammenstufe 11.

Bestehende Konfigurationen werden beim Lesen und beim Setup nicht automatisch umgeschrieben. Eine beauftragte Ergänzung erfolgt nur bei gestopptem Server über die vorhandene Konfigurationssicherung; Weltpfad, Benutzergruppen, Passwörter und unbekannte Felder bleiben erhalten. Neue Konfigurationen bekommen den neuen Standardwert und die dokumentierte automatische Gegnerwahl. Ein echter Weltstart und eine mögliche Spielstandmigration sind davon getrennte Betriebsschritte.
