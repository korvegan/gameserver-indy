# Sturmklingen – Gestaltung und Bedienung

Zu [EP02 #142](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/142)
und [#155](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/155).
Nach Nutzerfeedback ist die ruhigere Dashboardrichtung mit eigener Spielidentität
bestätigt. Am 22.10.2026 hat der Nutzer auch den vollständigen Stand der sechs
Ansichten für die spätere Umsetzung bestätigt.
Alle gezeigten Zustände und Personen sind fiktive Designbeispiele.

## Ansichten und Beschriftungen

| Ansicht | Inhalt und Aktionen |
| --- | --- |
| [Serverzentrale](concepts/dashboard-calm-v2.png) | `STURMKLINGEN`, `Serverzentrale`, `Wähle dein Spiel.`; Enshrouded und ASKA jeweils mit eigenem Logo/Bild, Status, `Starten`, `Stoppen`, `Server öffnen`. `Zugänge` ausschließlich für Administratoren. |
| [Enshrouded](concepts/enshrouded-calm-v2.png) | Zurück zur `Serverzentrale`; `Server läuft`, `Spielbereitschaft unbekannt`; Starten gesperrt, Stoppen verfügbar. `Beitreten`, `Beitrittsadresse`, `Noch nicht verfügbar`, Kopieren gesperrt. |
| [ASKA](concepts/aska-calm-v2.png) | Gleicher Aufbau; `Server gestoppt`, `Nicht spielbereit`; Starten verfügbar, Stoppen gesperrt. `Beitreten`, `Beitrittscode`, `Noch nicht verfügbar`, Kopieren gesperrt. |
| [Zugänge](concepts/zugaenge-calm-v3.png) | Nur Administration: Name, Identität, Rolle, Zugriff und einzelne Aktion. Rollen `Administrator` und `Familie`. `Freigegeben`/`Gesperrt`, `Freigeben`/`Sperren`; eigener Adminzugang als `Dein Zugang`. Kleines Formular `Person freigeben` mit Identität, Rolle und Freigeben. |
| [Stoppbestätigung](concepts/stoppbestaetigung-calm-v3.png) | Direkt vom Dashboard oder der Detailansicht: `Enshrouded stoppen?`, `Alle verbundenen Spieler werden getrennt.`, `Abbrechen`, `Server stoppen`. Für ASKA nur Spielname ändern. |
| [Zustände](concepts/zustaende-calm-v3.png) | Start läuft, unklarer Ausgang, bestätigter Fehlstart bei gestopptem Server und gesperrter Zugang. Die vier Beispiele sind getrennte Zustände. |

Familienmitglieder dürfen beide Spiele starten und stoppen. Die Serverdetails
zeigen ihnen keinen Verwaltungslink. Beitrittsdaten erscheinen nur nach
bestätigter Berechtigung und tatsächlicher Beobachtung; keine erfundenen Adressen,
Spielerzahlen oder Codes. Keine zusätzlichen App-Passwörter oder Einladungsverwaltung.
Die Beispieladressen mit `example.org` sind keine echte Zugangsliste.

## Zustände bestimmen die Bedienung

Der [Brückenvertrag](../../app/docs/bridge-contract.md) bleibt maßgeblich.
Sichtbare Start-/Stoppknöpfe sind keine Aussage über ihre aktuelle Verfügbarkeit.
Die folgende Zustandsregel gilt auf Dashboard und Detailseiten gleichermaßen.

| Beobachtung / Operation | Anzeige | Starten | Stoppen |
| --- | --- | --- | --- |
| Kein gesicherter Prozessstatus | `Status unbekannt` | gesperrt | gesperrt |
| Bestätigt gestoppt, keine aktive Operation | `Server gestoppt`, `Nicht spielbereit` | verfügbar | gesperrt |
| Bestätigt laufend, Bereitschaft unbekannt | `Server läuft`, `Spielbereitschaft unbekannt` | gesperrt | verfügbar, mit Bestätigung |
| Bestätigt laufend, noch nicht bereit | `Server läuft`, `Noch nicht spielbereit` | gesperrt | verfügbar, mit Bestätigung |
| Bestätigt laufend und bereit | `Server läuft`, `Spielbereit` | gesperrt | verfügbar, mit Bestätigung |
| Start angenommen / in Ausführung | `Start läuft …`, `Der Start wird geprüft.` | gesperrt | gesperrt |
| Stopp angenommen / in Ausführung | `Stopp läuft …`, `Der Stopp wird geprüft.` | gesperrt | gesperrt |
| Ausgang nach Timeout/Abbruch unklar | `Ausgang unklar`, `Der Ausgang der Aktion ist noch nicht bestätigt.` | gesperrt | gesperrt |
| Start nachweislich fehlgeschlagen und gestoppt | `Start fehlgeschlagen`, `Server gestoppt` | verfügbar | gesperrt |
| Beobachtung veraltet / Verbindung verloren | `Status veraltet` / `Verbindung unterbrochen` | gesperrt | gesperrt |
| Identität fehlt oder Freigabe fehlt/entzogen | `Zugriff gesperrt`, `Bitte wende dich an die Verwaltung.` | keine Spielansicht | keine Spielansicht |

`Status prüfen` liest bei unklarem Ausgang lediglich den Stand; es wiederholt
keinen Start oder Stopp und hebt die Reservierung nicht selbst auf. Eine
Prozessbeobachtung allein klärt eine unbekannte Operation nicht. Erst ein
bestätigter Operationsabgleich erlaubt weitere Aktionen. Bei laufender Operation
bleiben Details lesbar. Eine Operation bei einem Spiel sperrt das andere nicht.
Erfolgreicher Start bedeutet ohne weitere Beobachtung noch nicht `Spielbereit`.
`Nicht spielbereit` bei gestopptem Server ist Anzeigetext; der gespeicherte
Bereitschaftswert bleibt gemäß Vertrag `null`.

Kopieren wird erst mit vorhandenen, aktuellen und zum gewählten Spiel gehörenden
Beitrittsdaten freigegeben. Erfolgsmeldung: `Kopiert`; bei Fehler `Kopieren nicht
möglich`. Die Daten bleiben zum manuellen Auswählen lesbar. Fehlender Wert:
`Noch nicht verfügbar`. Keine geheimen Host- oder Brückendaten anzeigen.

## Gemeinsame Gestaltung

Die Bilder bestimmen Stimmung, Bildmotive und Hierarchie. Die folgenden Werte
sind daraus abgeleitete Startwerte für EP04/EP05, keine gemessenen Bildfarben.

| Zweck | Wert |
| --- | --- |
| Hintergrund / ruhige Textfläche | `#10171A` / `#182125` |
| Haupttext / Nebenbeschriftung | `#F1E5D1` / `#BBC0C1` |
| Gold für Akzente und primäre Flächen | `#D6A247` |
| Text auf Gold | `#17130D` |
| Dezente Trennlinie | `#69747A`, sparsam und ohne leuchtende Umrahmung |
| Bestätigter laufender Prozess | `#91CF76`, immer mit Text |
| Unklarer Ausgang | `#E4BB69`, immer mit Text |
| Destruktive Bestätigung | `#873C38` mit Haupttextfarbe |
| Fokus | sichtbare 2-px-Goldkontur mit 3 px Abstand |

Überschriften, Bedienung und Fließtext verwenden `system-ui`, `Segoe UI`,
`sans-serif`; keine nachzuladenden Schriften oder neuen Pakete. Der kleine
Sturmklingen-Schriftzug darf seine Serifenschrift behalten. Die Spielschriftzüge
stammen aus den originalen Logos und werden nicht mit einer Standardschrift ersetzt.
Haupttitel etwa 36–48 px am Desktop, 28–32 px mobil; Abschnittstitel 24–28 px,
Text und Steuerelemente 16–18 px, Zeilenhöhe mindestens 1,4. Lange Identitäten
dürfen umbrechen. Keinen Text durch Großschreibung oder Schmucklinien quetschen.

Ruhiger dunkler Hintergrund, weitgehend flache Bedienflächen, feine Trennlinien
und kleine Radien. Dungeon-Szenerie, Feuerstellen, Schmuckrauten, Goldfasen und
mehrfach verschachtelte Rahmen entfallen. Text liegt auf
ruhigen Flächen. Der primäre Startknopf ist gedämpft golden mit dunklem Text,
Stoppen zunächst dunkel mit klarer dezenter Kante. Erst
`Server stoppen` im Dialog ist dunkelrot. Gesperrte Knöpfe besitzen eine matte
Fläche, lesbare Beschriftung und ein echtes `disabled`; keine reine Farbkennung.
Abstände aus 4, 8, 12, 16, 24, 32 und 48 px; keine tief verschachtelten Rahmen.

Enshrouded verwendet seinen weißen/cyanfarbenen Originalschriftzug und die
eigene kühlgrüne/türkise Bildwelt mit den erkennbaren Abenteuerfiguren am Wasserfall.
ASKA verwendet seinen rauen hellen Blockschriftzug und das originale warm-gelblich/
violette Dorfmotiv mit der tätowierten Wikingerfigur und Axt. Die
[Markenreferenzen](brand-references.json) nennen die vorhandenen Dateien und Quellen.
Keine generischen Burg-/Dorfbilder als Ersatz. Die Originaldateien in der späteren
App unverändert einbinden; ein generiertes Konzept ersetzt kein Originalasset.
Beide Spiele erhalten dieselbe Kartenstruktur und vergleichbare Gewichtung.
Details verwenden einen flacheren Bildstreifen als die Spielauswahl. Status und
Knöpfe liegen unter dem Motiv auf einer ruhigen Fläche. Zugänge und reine
Fehlerhinweise brauchen keine Spielillustrationen. UI-Texte, Links, Felder,
Status und Knöpfe bleiben echtes HTML; die Konzeptbilder sind kein UI-Ersatz.

## Desktop, Smartphone und Tastatur

- Gemeinsamer Seitenrahmen, maximal etwa 1280 px; 24–32 px Rand am Desktop,
  16 px mobil. Zwei Spielkarten ab etwa 800 px nebeneinander, darunter gestapelt.
- Das Smartphone zeigt beide Spiele vollständig im normalen Seitenfluss.
  Bildhöhe reduzieren; Aktionen und Beitrittsinformationen nicht ausblenden.
  Kein horizontales Scrollen bei 320–430 px. Mindestens 44 px hohe Bedienflächen.
- Details haben auf beiden Spielen denselben Aufbau: kleine App-Marke, zurück zur
  Serverzentrale, eigenes Spiellogo im kurzen Bildstreifen, Status/Aktionen,
  Beitreten. Keine zusätzliche riesige Namensüberschrift neben dem Logo.
- Starten und Stoppen auf breiten Karten nebeneinander; bei zu wenig Platz
  untereinander, in derselben Reihenfolge. `Server öffnen` bleibt ein eigener Link.
- Zugänge erscheinen am Desktop als kompakte Tabelle, mobil als beschriftete
  Einträge mit identischen Werten und Einzelaktionen; Formular darunter.
- Stoppdialog mit zugehöriger Überschrift und Beschreibung, Fokus zunächst auf
  Abbrechen; Tab bleibt im Dialog, Escape bricht ab, danach Fokus zum Auslöser.
  Außerhalb des Dialogs keine bedienbaren Elemente. Kein Schließen durch bloßes
  Klicken außerhalb und keine automatische Bestätigung.
- Statuswechsel verständlich als Text melden (`aria-live="polite"`), Fokus nicht
  verschieben. Farbe und Animation ersetzen keinen Text. Reduzierte Bewegung
  respektieren. Links und Knöpfe besitzen einen sichtbaren Tastaturfokus.

Identität und Freigabe werden in EP04 serverseitig geprüft, auch bei jedem
schreibenden Aufruf. Ausgeblendete oder gesperrte Elemente ersetzen diese Prüfung
nicht. Keine Produktivfunktion, echte Identitätsprüfung oder Spielaktion wird
durch diese Designlieferung abgenommen.

## Abnahme und nächste Schritte

Der vollständige Designstand ist persönlich freigegeben. EP04/EP05 können ihn
für echte Oberfläche und Rechte verwenden; EP03 liefert die native Spielbeobachtung.
Die vorhandenen Originalbilder und Logos wiederverwenden, das kleine
Sturmklingen-Zeichen als separates Asset ausarbeiten und die reale
Oberfläche auf Desktop und Smartphone gegen die freigegebenen Konzepte prüfen.
Insbesondere Kontrast, Fokus, Umbruch und Zustandswechsel sind dann im Browser
zu belegen. Ein Bildentwurf allein kann diese Bedienprüfung nicht ersetzen.
