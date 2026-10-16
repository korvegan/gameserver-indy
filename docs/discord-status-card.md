# Discord-Statuskarte

Im Serverbereich **Mitteilungen → Discord → Statuskarte für diesen Server** wird eine Karte ausdrücklich angelegt. Voraussetzung ist ein eingerichteter Dashboard-Webhook. Die Funktion ist nach der Installation aus; es wird keine Nachricht allein durch einen Verwaltungsneustart erzeugt.

Die Karte zeigt Spiel, Servername, lokal bestätigte Bereitschaft, Beitrittsadresse beziehungsweise die Suche nach dem Servernamen und den letzten Stand. Ein laufender Wartungs-/Sicherungsauftrag und eine fehlgeschlagene Sicherungsaktion werden als kurze Hinweise ergänzt. Zugangsdaten, Auftragsnutzdaten und interne Dateipfade werden nicht übernommen. Die externe Erreichbarkeit bleibt ungeprüft.

Die vorhandene Metadatenwarteschlange prüft aktivierte Karten frühestens alle 60 Sekunden. Zustandsänderungen und spätestens ein fünfminütiger Zeitnachweis bearbeiten dieselbe gespeicherte Nachrichten-ID. Mit aktiver Karte unterdrückt die Verwaltung ihre zusätzlichen Start-/Stoppmeldungen; der externe ASKA-Starter behält seine eigene Startmeldung und seinen eigenen Windows-Webhook.

**Aktualisierung ausschalten** beendet weitere automatische Bearbeitungen. Die Nachricht bleibt im Discord-Kanal bestehen. Eine bereits laufende Anfrage kann noch abschließen. Eine neue Karte ist ein ausdrücklich bestätigter Versand; sie löscht die alte Nachricht nicht.

Der erste POST wird vor dem Netzaufruf dauerhaft vermerkt. Nach einem unklaren Ausgang oder einem Verwaltungsabbruch wird er nicht wiederholt. Zuerst im Kanal nachsehen; nur bei Bedarf ausdrücklich eine neue Karte anlegen. Für eine bereits bekannte ID ist PATCH wiederholbar: Netzfehler warten fünf Minuten, HTTP 429 mindestens die gemeldete `Retry-After`-Zeit. Andere Ablehnungen, entfernte Nachrichten und ein geänderter Webhook sperren weitere automatische Versuche. Das Dashboard zeigt den Zustand, ohne Webhook-Token auszugeben. Fehler dieses Metadatenjobs verändern keinen Spielprozess.

Grundlage sind die offiziellen Discord-Endpunkte [Execute/Edit Webhook Message](https://docs.discord.com/developers/resources/webhook) und die [Rate-Limit-Regeln](https://docs.discord.com/developers/topics/rate-limits). Auch Bearbeitungen setzen `allowed_mentions.parse=[]`; Discord übernimmt diese Einstellung nicht automatisch vom ursprünglichen Versand.

Prüfung: isolierter PHP-Auftrag mit synthetischem Transport für Anlegen, identische Polls und Aktualisierung derselben ID; Browserablauf mit eigener Fixture für Anlegen und Abschalten. HTTP-PATCH und Rate-Limit-Verhalten gezielt mit `MockHttpClient` geprüft. Es werden keine echten Nachrichten als Test gesendet.

Separate Ereignisabonnements, ein Spielabendbericht und die Sturmklingen-Erfolgskarten sind eigene Erweiterungen; letztere gehören zu #26. Eine Wartungsankündigung mit längerem Vorlauf folgt dem Wartungsablauf in #14.
