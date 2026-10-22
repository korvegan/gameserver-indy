# EP02 – Designstand

Stand: 22.10.2026. Epic [#142](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/142),
Arbeitsissue [#155](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/155).

## Bestätigte Richtung

Die ursprüngliche Richtung A – Dunkle Schmiede war dem Nutzer in der ausgearbeiteten
Fassung zu dominant. Die Aufteilung passt; Status und Bedienung sollen mehr
Gewicht erhalten und beide Spiele sofort an ihrer eigenen Gestaltung erkennbar sein.
Der Nutzer hat das [ruhigere Dashboard](concepts/dashboard-calm-v2.png) bestätigt
und die Überarbeitung der übrigen Ansichten in diesem Stil beauftragt.

- Dunkle, ruhige Bedienflächen; Gold nur sparsam, kein großer Fantasyrahmen.
- Enshrouded und ASKA mit eigenen Logos, Farben und wiedererkennbaren Spielmotiven.
- **Starten** und **Stoppen** direkt je Spiel; **Server öffnen** zusätzlich.
  Stoppen mit Bestätigung. Prozessstatus und Spielbereitschaft getrennt.
- Desktop und Smartphone gleichrangig. Kleine Zugangsverwaltung mit zwei Rollen.

Die [Browserübersicht](index.html) zeigt die Konzepte; die [Spezifikation](specification.md)
legt Beschriftungen, Zustände, Gestaltung und responsives Verhalten fest.
Der Nutzer hat am 22.10.2026 auch den vollständigen Stand aller sechs Ansichten
für die spätere Umsetzung bestätigt.

## Dateien und Herkunft

- [Dashboard-Prompt](dashboard-calm-prompt.txt), [Prompts der übrigen Ansichten](calm-concept-prompts.json).
- [Bildherkunft, Abnahmestatus und Hashes](sources.json),
  [offizielle Markenreferenzen aus dem vorhandenen Archiv](brand-references.json).
- Die frühere Dashboardfassung bleibt als Vergleich erhalten; sie ist keine
  aktuelle Umsetzungsvorgabe. Weitere verworfene Tafeln liegen lokal bei den Stilproben.

Alle Konzepttafeln sind mit dem integrierten Image Gen erstellt. Die neue Runde
nutzt vorhandene offizielle Logos und Spielbilder als Referenzen; die generierten
Tafeln sind keine Spiel-Screenshots. Die spätere App soll die originalen
Markenassets unverändert einbinden, mit echten HTML-Texten und Bedienelementen.
Keine neuen Pakete und keine Änderung an App-Laufzeit, Rechten oder Spielprozessen.

Die Vorschau läuft lokal unter `http://127.0.0.1:18742/` und ist kein App-Autostart.
Die Bildkopien liegen dauerhaft unter `artifacts/design-ep02/preview/` im
Hauptcheckout; die ursprünglichen Stilproben daneben unter `style-studies/`.
Ein temporärer Worktree kann nach einem WSL-Neustart fehlen; den gesicherten
Branch `design/142-dark-forge` dann wiederherstellen. Aktueller Worktree:
`/tmp/gameserver-design-142`. Hauptcheckout auf Main belassen.

## Prüfung und Übergabe

Alle sechs Tafeln sind mit `view_image` auf Beschriftungen, Status, aktive/gesperrte
Knöpfe, Desktop-/Mobilaufbau und Übereinstimmung mit der bestätigten Richtung
geprüft. Bildhashes, PNG-Dimensionen, Quellen-/Promptverweise, JSON, lokale
Markdown-/HTML-Verweise und die HTTP-Auslieferung sind geprüft. Die Vorschau
liefert ausschließlich ihre Bild-/HTML-Pfade; die Startseite ist auch aus Windows
erreichbar. Der Browser-MCP zeigt die Galerie und die verlinkten Bilder.
Die vorhandene Projekt-Playwright-Version prüft Navigation, Originalbildöffnung,
Bildauslieferung und fehlenden horizontalen Überlauf bei 1280 und 390 px;
320 px ist zusätzlich als Layoutcheck geprüft. Browserfehler wurden dabei nicht
beobachtet. Dafür wurde der Projektweg ergänzt, weil die angebotenen MCP-Methoden
keine Viewportänderung oder Linkklicks bereitstellen.

Festes Reviewmaximum für den PR: **zwei Runden**; vor PR-Erstellung wurde noch
keine gestartet. CI und unabhängiges Review sind im PR nachzuhalten. Nach Merge
liefert dieser Stand die Gestaltung für EP04/EP05; die native Beobachtung folgt
in EP03. Als nächstes das beauftragte Folgeepic mit Zielbild #140 lesen.
EP02 enthält keine produktive UI- oder Spielumsetzung.
