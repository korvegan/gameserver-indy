# Wartung mit Vorlauf

Unter **Server → Updates → Wartung mit Vorlauf** lässt sich ein Stopp oder Neustart vormerken. Der Plan wartet zunächst immer auf ausdrückliche Besitzerfreigabe. Danach läuft der gewählte Vorlauf (60 bis 3600 Sekunden, Vorgabe fünf Minuten). Das ausgewählte Serverprofil zeigt den Hinweis auch außerhalb des Update-Tabs. Vor Auftragsannahme kann ein Besitzer abbrechen.

Nach Ablauf übernimmt die vorhandene FIFO-Warteschlange den Befehl. Erst dann ist er ein regulärer Auftrag; der Plan kann einen bereits angenommenen oder begonnenen Betriebssystembefehl nicht zurücknehmen. Der feste Anfragebezug verhindert doppelte Aufträge bei wiederholtem Planerschritt. Ein Neustart der Verwaltung oder Entzug/Änderung der persönlichen Rechte verwirft einen noch wartenden Plan. Andere Serverprofile werden durch einen gerade beschäftigten Zielserver nicht blockiert.

Die bisherigen direkten Start-/Stopp-/Neustartknöpfe nehmen Besitzerbefehle weiterhin unabhängig vom letzten Status an, einschließlich der persönlichen Besitzerzugänge von Axel und Tina. Wartungspläne sind ein zusätzlicher bewusster Ablauf; sie sperren diese Bedienung nicht.

## Spielerbelegung und offene Abnahme

Eine aktuelle native Beobachtung **Server und Starter gestoppt** belegt null Spieler auf diesem Prozessziel. Für laufende oder nicht eindeutig beobachtete Server bleibt die Zahl **unbekannt**. Eine Loglücke, ein Verwaltungsneustart oder ein alter Sitzungseintrag wird nicht als leere Runde interpretiert. Quelle und Beobachtungszeit sind sichtbar; Spielernamen und Steam-IDs werden dafür nicht gesammelt.

Die produktive ASKA-/Enshrouded-Spielerzählung ist noch nicht mit echten Ein-/Austritten verglichen. [Valve beschreibt die Anfrage-/Challenge-Folge des Serverbrowsers](https://steamcommunity.com/discussions/forum/14/2974028351344359625/); das beweist nicht die Belegungsqualität des jeweiligen Spiels. Eine Antwort oder ein offener Query-Port allein berechtigt deshalb noch keine unbeaufsichtigte Wartung. Solange diese Abnahme fehlt, gibt es keine automatische Freigabe „sicher leer“.

Warnungen erscheinen im Dashboard. Discord kann über den vorhandenen Mitteilungsbereich ausdrücklich informiert werden. Keine ungeprüfte Ingame-Chat-/RCON-Schnittstelle und keine echten Testnachrichten. Verlässliche Zählquelle plus Abgleich einer Viererrunde bleiben in #14 offen.
