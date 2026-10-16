# GameServer Indy

Bei Arbeiten an diesem Repository den Skill [gameserver-indy](skills/gameserver-indy/SKILL.md) lesen. Er enthält die Besonderheiten des Windows-/WSL-Hosts und der Spieladapter. Neue belegte Fallstricke im Rahmen der jeweiligen Arbeit dort aktualisieren; Vermutungen als unbestätigt kennzeichnen. Die Repository-Version ist die Quelle der installierten Skill-Kopie.

## Autonomes Arbeiten

Korvegan hat am 15.10.2026 freigegeben, beauftragte oder ausdrücklich zur Umsetzung freigegebene Themen weitgehend autonom zu bearbeiten, einschließlich Commit, Push, Pull Request und selbstständigem Merge. Für diese Schritte keine erneute Freigabe einholen. Ein angelegtes Backlog-Issue allein ist kein Auftrag, es umzusetzen.

- Zu Beginn Umfang und überprüfbare Abnahmekriterien aus dem Auftrag und den verknüpften Issues ableiten. Routineentscheidungen selbst treffen; fehlende wesentliche Angaben gezielt klären und unabhängige Arbeit fortsetzen.
- Änderungen auf einem Themenbranch umsetzen, den vollständigen Diff prüfen und die zum Thema passenden lokalen Prüfungen ausführen. Bei Spielprozessänderungen die isolierten Windows-Tests einbeziehen; bei UI-Änderungen die Browserprüfung.
- Nach dem Erstellen jedes PRs einen separaten Sub-Agenten mit dem Review des tatsächlichen PR-Diffs und der relevanten Abläufe beauftragen (Nutzeranweisung vom 15.10.2026). Das Review muss vor dem Merge abgeschlossen sein. Wesentliche Befunde beheben und die Korrektur erneut prüfen lassen; nach inhaltlichen Folgeänderungen das Review auf den aktuellen Commit aktualisieren. Reviewer führen keine echten Spielaktionen oder Nachrichten aus. Ein grüner CI-Lauf ersetzt dieses Review nicht. Kann kein Sub-Agent-Review stattfinden, den PR offen lassen und die konkrete Einschränkung berichten.
- Vor dem Merge alle einschlägigen CI-Prüfungen für den aktuellen PR-Commit abwarten und Fehler oder wesentliche offene Befunde beheben. Branch-Regeln einhalten; keine Prüfungen oder Schutzregeln umgehen. Gegen den geprüften Commit mergen.
- Nach dem Merge den Zustand auf GitHub verifizieren, den lokalen Checkout auf `main` aktualisieren, sofern keine fremden Änderungen entgegenstehen, und auch den CI-Lauf auf `main` kontrollieren. Betriebsschritte ausführen, soweit sie zum beauftragten Thema gehören. Ergebnis, relevante Prüfungen und verbleibende Grenzen knapp berichten.

Die Freigabe bezieht sich auf das beauftragte Thema. Andere Backlog-Themen, reale Welten, Nachrichten an Spieler sowie Änderungen an Hostbetrieb oder externen Konten benötigen einen passenden Auftrag. Bestehende Aufträge und Freigaben berücksichtigen, statt pauschal erneut nachzufragen.

## Umfang und passende Prüfung

Vor dem ersten Eingriff wenige überprüfbare Abnahmekriterien und die betroffenen Komponenten festhalten. Bestehende Module bevorzugen; zusätzliche Arbeiten nur aufnehmen, wenn ein konkreter Befund den Auftrag sonst verfehlt.

| Änderung | Lokale Prüfung |
| --- | --- |
| Texte, Dokumentation, Arbeitsregeln | Diff, betroffene Verweise bzw. vorhandene Validatoren |
| Oberfläche | Syntax und betroffene Bedienabläufe auf Desktop/Mobil; weitere Breiten nur als Layoutcheck |
| Fachlogik oder API | Tests der geänderten Funktion und unmittelbaren Abhängigkeiten |
| Prozesse, Sicherungen, Migration | passende isolierte Integrationsprüfung, bei Windows-Verhalten nativ |

Für diese private Familien-App werden regelmäßig nur wesentliche, praktisch relevante Abläufe geprüft. Fälle mit geschätzter Wahrscheinlichkeit unter 5 % oder niedrigem/geringerem Risiko gehören nicht in reguläre Läufe. Ohne belastbare Messdaten keine exakten Wahrscheinlichkeiten behaupten. Seltene Fehlerketten, exotische Plattformvarianten und geringe Darstellungsrisiken nur bei konkretem Bedarf manuell prüfen. Neue Tests müssen einen relevanten Ablauf oder beobachteten Fehler absichern; keine vollständige SaaS-Absicherung anstreben.

Die CI-Auswahl steht in [docs/testing.md](docs/testing.md). Nach einer Korrektur zuerst den betroffenen Ablauf prüfen; eine erneute Vollprüfung braucht eine neue Änderung oder einen offenen Befund. Der unabhängige PR-Review läuft parallel zu den CI-Prüfungen, soweit deren Arbeit voneinander unabhängig ist. Nach inhaltlichen Korrekturen auf den endgültigen Commit beziehen.

Eine Aufgabe ist abgeschlossen, wenn die vereinbarte Funktion, der relevante Review, die passenden Prüfungen und nötige Installation samt kurzer Abnahme erledigt sind. Danach keine Suche nach weiteren hypothetischen Randfällen. Bei größeren Aufgaben Entwicklungs-, externe Warte- und Installationszeit gelegentlich getrennt berichten; ungefähre Phasen nicht als exakt gemessene Arbeitszeit ausgeben. Vor neuen Abstraktionen gegenprüfen, ob eine vorhandene Funktion, ein direkter Befehl oder eine kurze Regel genügt. Keine Erweiterungsplattform für einen einzelnen Anwendungsfall bauen.

Die Reasoning-Einstellung bleibt bis zu einer neuen ausdrücklichen Entscheidung des Nutzers unverändert.
