# Quellarchiv

[PHP/Symfony bis 22.10.2026](php-legacy-20261022/source.json) enthält unter
`snapshot/` alle 426 versionierten Dateien von Commit
`5b4e29ba0e0e0ce667a2e3bd9a605b9c283057cb`, mit unveränderten Git-Blobs und
Dateimodi. Das frühere Python-Archiv ist Teil dieses vollständigen Snapshots.

Die führende Anwendung entsteht unter [`app/`](../app/README.md).
Das Archiv dient als Quellnachweis und gezielte Referenz für bestätigte
Spieleigenheiten. Es wird nicht als aktive Anwendung oder CI ausgeführt.
`node app/tests/check-archive.cjs` vergleicht jeden archivierten Blob und Modus
mit dem benannten Git-Commit und lehnt fehlende oder zusätzliche Dateien ab.

Die zuletzt laufende Verwaltung verwendete das unveränderliche Release
`df80d4abc61b015e08c1fb87e032abee8601eee5`. Es bleibt lokal für den Rückweg
erhalten. Spielinstallationen, Welten, Launcher-Geheimnisse und private
Verwaltungsdaten sind kein Bestandteil dieses Quellarchivs. Ihr bisheriger
Speicherort bleibt erhalten; lokale Umstellungsnachweise liegen separat.
