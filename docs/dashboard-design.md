# Serverzentrale

Der am 16.10.2026 abgestimmte Entwurf verwendet Spielkacheln links und rechts den ausgewählten Server mit eigener Bildkopfzeile. Auf kleinen Displays stehen die kompakten Kacheln oben nebeneinander. Kopfzeile, Steuerung, Beitrittsangabe und Auftragsannahme bleiben beim Tabwechsel erhalten. Übersicht, Einstellungen, Protokoll/Aufträge, Sicherungen, Updates und Mitteilungen teilen die Serverfunktionen auf. Sturmklingen, Spielekatalog, Serverprofile und persönliche Freigaben bleiben globale Ansichten.

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

Die Kopfzeile priorisiert Spiel, Server, Prozessstand, Beitritt und Aktionen.
Auftragshinweise nennen knapp Annahme oder Ausführung; technische Hintergründe
stehen unter „Wie funktionieren Serverbefehle?“. Unbekannte Bereitschaft und
Spielerbelegung bleiben ausdrücklich unbestätigt.

## Neugestaltung mit Build Web Apps

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
