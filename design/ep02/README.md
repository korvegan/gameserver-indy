# EP02 – Gesicherter Designstand

Stand: 22.10.2026. Nutzer wünscht Pause bis morgen; keine weitere Designarbeit
oder automatische Fortsetzung. Epic [#142](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/142),
Arbeitsissue [#155](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/155).

## Akzeptiert

- **A – Dunkle Schmiede**: dunkler Stein, Stahl, warme Goldakzente.
- [Dashboard mit direkten Aktionen](concepts/dashboard.png), jeweils Desktop
  und Smartphone. Der Nutzer hat auch diese ergänzte Fassung bestätigt.
- **Starten** und **Stoppen** direkt bei Enshrouded und ASKA; kein Wechsel in
  die Detailansicht dafür nötig. **Server öffnen** bleibt zusätzlich erreichbar.
- Stoppen weiterhin mit Bestätigung. PHP unter WSL, kleine native Windows-Brücke;
  kein neuer Frontend-Stack und keine zusätzlichen MVP-Bereiche.

Der Entwurf ist ein Bild. Echte Oberfläche, Rechte und Spielaktionen bleiben
EP04/EP05 beziehungsweise EP03 vorbehalten. Die laufende PHP-Diagnose und reale
Spiele wurden durch die Designarbeit nicht verändert.

## Dateien

- [Dashboard-Prompt](dashboard-prompt.txt) für die bestätigte Überarbeitung.
- [Geplante Folgekonzepte mit vollständigen Prompts](concept-prompts.json).
- [Bildherkunft und Hash](sources.json). Erzeugung mit integriertem Image Gen;
  generierte Illustrationen, keine offiziellen Spiel-Screenshots.

Die drei ursprünglichen Stilproben bleiben lokal unter
`artifacts/design-ep02/style-studies/` im Hauptcheckout erhalten.
Der lokale Bildserver liefert die bestätigte Fassung unter
`http://127.0.0.1:18742/muster-a-direktaktionen.png`.
Dieser Vorschauprozess gehört zur aktuellen WSL-Laufzeit, nicht zum App-Autostart.

## Morgen fortsetzen

1. Nur dieses Dokument, #142/#155, AGENTS.md und nötige Skills laden.
   Arbeitsbranch `design/142-dark-forge`, Worktree `/tmp/gameserver-design-142`.
   Hauptcheckout bleibt auf unverändertem Main. Der Branch wird auf Forgejo gesichert.
2. Fünf weitere Ansichten waren angefordert, als der Nutzer unterbrach:
   Enshrouded-Details, ASKA-Details, Zugangsverwaltung, Stoppbestätigung,
   wichtige Operations-/Fehlerzustände. Der Code-Mode-Aufruf wurde abgebrochen;
   keine Ergebnisse daraus als fertig oder akzeptiert behandeln. Eventuell bereits
   gespeicherte Ergebnisse erst prüfen, bevor dieselbe Generierung erneut startet.
3. Im selben Stil vervollständigen, kompakte Beschriftungs-, Zustands-, Typografie-,
   Farb- und Responsive-Spezifikation ableiten und die Gesamtansichten abstimmen.
   Laufender Prozess bedeutet nicht automatisch Spielbereitschaft; unbekannter
   Operationsausgang blockiert erneute Aktionen gemäß `app/docs/bridge-contract.md`.
4. Erst den vollständigen Stand als PR prüfen lassen. Fixes Reviewmaximum:
   **zwei Runden**, bisher **keine gestartet**. Noch kein PR, Review oder Merge
   für diesen Zwischenstand; #142 und #155 bleiben offen.

Die Ablage liegt außerhalb `app/`, damit Designnachweise die Laufzeit nicht vergrößern.
