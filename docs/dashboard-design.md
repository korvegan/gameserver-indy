# Serverzentrale

Die Serverübersicht ist der Einstieg. Sie zeigt alle freigegebenen Spielserver als große Auswahlkarten. Eine Karte öffnet die eigene Detailansicht; „Alle Server“ führt zurück zur Übersicht. Es gibt eine globale Navigation und eine Arbeitsfläche. Auf kleinen Displays öffnet die Navigation als modaler Dialog, die Serverkarten stehen untereinander.

Kopfzeile, Steuerung, Beitrittsangabe und Auftragsannahme bleiben beim Tabwechsel erhalten. Übersicht, Einstellungen, Protokoll/Aufträge, Sicherungen, Updates und Mitteilungen teilen die Serverfunktionen auf. Sturmklingen, Spielekatalog, Serverprofile und persönliche Freigaben bleiben globale Ansichten.

Die bestehenden Ansichten werden beim Start einmal in die feste Hülle eingehängt. Statusabfragen ersetzen weder Formulare noch Gemeinschaftsszenen. Kacheln verwenden ausschließlich freigegebene Profil-IDs; zusätzliche Statusabfragen lesen die vorhandenen Metadaten und steuern keine Spielprozesse. Vor einer bestätigten Beobachtung steht der Status auf unbekannt. Die Gestaltung verwendet keine erfundenen Spielerzahlen oder Leistungswerte.

`web/views/workspace.js` hält Spielmotive und einen neutralen Rückfall für weitere Spiele bereit. Zusätzliche Serverinstanzen erhalten dieselbe Gestaltung anhand ihrer Spiel-ID, nicht ihrer Instanz-ID. Neue Bilder benötigen passende ausdrücklich erlaubte Assetpfade in beiden HTTP-Laufzeiten. Bilder werden lokal ausgeliefert; der Browser kontaktiert weder Steam noch andere Bilddienste.

## Bildquellen

Die folgenden Originalgrafiken wurden am 16.10.2026 für die vom Nutzer ausgewählte Darstellung von den offiziellen Steam-CDNs übernommen. Sie bleiben Eigentum der jeweiligen Rechteinhaber; diese Anwendung beansprucht keine Rechte daran und erteilt keine zusätzliche Nutzungslizenz.

| Dateien unter `web/artwork/` | Offizielle Quelle | Rechteinhaber |
| --- | --- | --- |
| `enshrouded-hero.jpg`, `enshrouded-logo.png` | [Steam-Seite](https://store.steampowered.com/app/1203620/Enshrouded/), [Motiv](https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1203620/library_hero.jpg), [Schriftzug](https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1203620/logo.png) | Keen Games |
| `aska-hero.jpg`, `aska-logo.png` | [Steam-Seite](https://store.steampowered.com/app/1898300/ASKA/), [Motiv](https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1898300/library_hero.jpg), [Schriftzug](https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/1898300/logo.png) | Sand Sailor Studio / Thunderful Publishing |

Die vier Dateien werden unverändert verwendet; Ausschnitt und Abdunklung erfolgen in CSS.
## Profilkontext

Die Spielkacheln sind die einzige sichtbare Profilwahl. Der frühere Dropdown ist
nur als verborgenes internes Auswahlfeld erhalten; die bestehende Profilbindung
bleibt dadurch unverändert. In der Seitenleiste zeigt ein kompakter Kontextknopf
das aktuelle Profil und führt aus globalen Ansichten zur Spielauswahl zurück.
Er bleibt beim Ansichtswechsel stehen, damit die Hauptnavigation nicht springt.

Die Kopfzeile priorisiert Server, Spiel, Prozessstand, Beitritt und Aktionen.
Auftragshinweise nennen knapp Annahme oder Ausführung; technische Hintergründe
stehen unter „Wie funktionieren Serverbefehle?“. Unbekannte Bereitschaft und
Spielerbelegung bleiben ausdrücklich unbestätigt.

## Erster Gestaltungsstand mit Build Web Apps

Der Auftrag vom 20.10.2026 zu #134 übernimmt einen mit Build Web Apps und
Imagegen erstellten Entwurf in die bestehende Oberfläche. Neutrale dunkle
Flächen, eine breitere Hauptnavigation und größere Systemschrift ersetzen
die bisherige kleinteilige Darstellung. Die Spielmotive und die Akzentfarben
von Enshrouded und ASKA bleiben erhalten. Status und technische Angaben stehen
in zusammenhängenden Leisten; auf schmalen Displays ordnen sie sich in zwei
Spalten. Serveraktionen und Tabs erhalten ausreichend hohe Bedienelemente.

Die Umsetzung ändert ausschließlich CSS und die Browser-Themenfarbe. Symfony,
Twig, native JavaScript-Module, Profilbindung, Berechtigungen, Entwürfe und
Liveereignisse verwenden dieselben vorhandenen Abläufe. Der vom Plugin
bevorzugte Browser-Skill fehlt in dieser Sitzung; die Prüfung verwendet
deshalb das vorhandene Playwright mit der isolierten PHP-Dashboard-Fixture.

## Vollständige Überarbeitung vom 21.10.2026

Zu #136 ersetzt die Überarbeitung den vorherigen Aufbau. Die Gestaltung
orientiert sich an der festen [Carbon UI Shell](https://www.carbondesignsystem.com/building-blocks/core/components/ui-shell-left-panel/guidelines)
und dem [Primary-/Detail-Pattern von PatternFly](https://www.patternfly.org/patterns/primary-detail/design-guidelines/).
Die Umsetzung verwendet weiterhin native JavaScript-Module und Symfony/Twig;
es wird kein Designframework als zusätzliche Laufzeit installiert.

- **Übersicht → Detail:** Der Einstieg zeigt die Serverauswahl. Die Detailansicht
  nutzt die volle Arbeitsfläche; daneben bleibt keine zweite Auswahlsäule.
- **Stufenweise Details:** Spielbereitschaft, Spielerbelegung, Version und
  Sicherungen stehen vorn. Leistung, Installation, Netzwerk und Befehlserklärung
  sind aufklappbare Bereiche.
- **Ein Designsystem:** Navigation, Formulare, Protokolle und globale Ansichten
  teilen Schrift, Abstände, Flächen, Konturen und Bedienelemente.

| Element | Festlegung |
| --- | --- |
| Hintergrund / Navigation | Graphit `#101214` / `#151719` |
| Inhaltsfläche / Kontur | `#1c2023` / `#30363a` |
| Text / ergänzender Text | `#f1f4f0` / `#a5afa9` |
| Primäre Aktion / Aktionsschrift | Hellgrün `#c2e58b` / Dunkelgrün `#19251a` |
| Schrift | Systemschrift; Hauptüberschrift 44 px, mobil 31 px; Eingaben 15 px |
| Komponenten | Konturierte Buttons, Linienicons, Serverkarten, native Tabs und Details |
| Mobil | Navigationsdialog bis 900 px, einspaltige Serverauswahl bis 680 px |

Die drei Imagegen-Entwürfe zeigen Einstieg, Desktopdetails und mobilen Einstieg.
Die vorhandenen Original-Spielbilder ersetzen bewusst die beispielhaften
Bildmotive im Entwurf. Profilnamen, Status, Versionen und Zeitangaben stammen
aus den jeweiligen Daten; Beispielwerte werden nicht als Livezustand übernommen.

Der neue Navigationszustand `fleet` trennt Übersicht und Serverbereiche.
Der Rückweg entfernt keine Formularknoten. Erneutes Öffnen desselben Servers
erhält den Entwurf; ein tatsächlicher Profilwechsel behält die vorhandene
Rückfrage. Das mobile Menü verwendet `dialog.showModal()`. Escape schließt
es und gibt den Fokus an den Menüknopf zurück; ein Bereichswechsel schließt
es und fokussiert die neue Überschrift.

Die Browserprüfung verwendet zuerst den konfigurierten allgemeinen Browser
zum Öffnen, Lesen und für Screenshots. Für Interaktionen und verschiedene
Viewportgrößen dient das bestehende Projekt-Playwright: Der Browser-Plugin-Skill
ist in dieser Sitzung nicht verfügbar, und der konfigurierte Browser-MCP
bietet keine Klick- oder Resize-Werkzeuge. Es werden keine zusätzlichen
Browserpakete oder schwächeren Sandbox-Einstellungen verwendet.
