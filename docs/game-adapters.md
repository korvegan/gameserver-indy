# Spiele und geprüfte Fähigkeiten

Ein Katalogspiel ist noch kein verwaltbarer Dedicated Server. Zusätzlich zum
JSON-Spielprofil benötigt es einen registrierten PHP-Konfigurationsadapter und
eine geprüfte native Steuerung. Die bestehenden Schnittstellen `ConfigurationAdapter`,
`ProcessGateway` und `ServerControl` trennen Dateiformat, Betriebssystem und Aufträge.
Es gibt bewusst keine weitere Pluginplattform für die beiden vorhandenen Spiele.

## Profilschema

`profiles/<id>.json` enthält ID, Anzeigename, EXE-Dateiname, Konfigurationsdateiname,
Standardinstallation und Formularfelder. `GameCatalog::validateSchema` prüft
diese Angaben einschließlich der optionalen `capabilities`-Beschreibung:

```json
{
  "capabilities": {
    "process_control": {"state":"unknown","reason":"Start und Stopp noch nicht geprüft."},
    "readiness": {"state":"unknown","reason":"Kein Marker an einen Prozessstart gebunden."},
    "world_backup": {"state":"unknown","reason":"Speicherpfad und Offline-Rückweg fehlen."}
  }
}
```

Bekannte Fähigkeiten: `configuration`, `process_control`, `readiness`, `save`,
`world_backup`, `update_detection`, `players`, `rcon`, `mods`. Jede Beschreibung
enthält `state` (`supported`, `unsupported`, `unknown`) und einen nicht leeren
`reason` (maximal 600 Zeichen). Optional benennt `method` mit 1–80 kleinen
Buchstaben/Ziffern/Unterstrichen/Bindestrichen das geprüfte Verfahren. Es ist eine
Beschreibung, kein dynamisch ausführbarer Klassen-/Programmname. Fehlende
Fähigkeiten bleiben unbekannt; ein reiner Katalogeintrag hat keinen Prozessadapter.

Der Katalog zeigt Status und Begründung. Start-/Stoppbefehle benötigen ausdrücklich
freigegebene Prozesssteuerung, auch serverseitig. Ein JSON-EXE-Eintrag schaltet
diese Funktion nicht allein frei. Aktuelle Belegung, Beitritt, Versionsstand und
Instanzkonfiguration bleiben dynamische Beobachtungen, keine Profilgarantien.

## Ein weiteres Spiel ergänzen

1. Installierte Herstelleranleitung prüfen: EXE/Argumente, Dateiformat, Weltpfad,
   Speichervorgang und regulären Stopp feststellen. RCON/Spielerliste nicht vermuten.
2. JSON-Profil nach den vorhandenen Beispielen erstellen. Fähigkeiten zunächst
   unbekannt lassen. Keine privaten Originalpfade, Tokens oder Passwörter einchecken.
3. `ConfigurationAdapter` implementieren; Services werden automatisch über
   `indy.game_adapter` gesammelt. Dekodieren, Validieren, Änderungen und öffentliche
   Darstellung müssen unbekannte Felder erhalten und Zugangsdaten ausblenden.
4. Bei normaler Windows-Konsole den vorhandenen `WindowsProcessGateway` verwenden;
   Argumente über Profilmetadaten ergänzen. Abweichendes RCON-/Dienstverfahren an
   `ProcessGateway`/`ServerControl` implementieren, bevor die Fähigkeit freigegeben
   wird. HTTP-Routen und Formulare benötigen dafür keine Spielspezialfälle.
5. Bereitschaftserkennung an PID/Start und passenden Loglauf binden. Weltbindung
   in `Instance/WorldBinding`, Offline-Archiv/Rückweg in `Backup` ergänzen. Bei Steam
   passen Manifest/App-/Server-ID und Branch zum installierten Dedicated Server.
6. Mit temporärer Konfiguration und eigenem Stellvertreter Start, Beobachtung,
   regulären Stopp und Byte-Rückweg prüfen. Erst danach geprüfte Fähigkeiten
   freigeben. Ein echter Speicher-/Beitrittstest bleibt gesondert zu belegen.

Enshrouded und ASKA sind die vollständigen Beispiele. ASKA behält externe
Konfiguration, fest gebundene Save-ID und tatsächlichen Ordner `data/server/`.
Details: [Weltsicherungen](world-backups.md), [Updates](steam-updates.md),
[Protokolle](logs.md). Ein konfigurierbarer Autosave ist kein separater Speicherbefehl.
