# Gemeinschaft in Discord teilen

Unter **Sturmklingen → Gemeinschaft in Discord teilen** wird ein eigener Gemeinschafts-Webhook gespeichert. Er ist von Servermeldungen und vom externen ASKA-Starter unabhängig. Das Feature veröffentlicht nichts allein durch Seitenaufrufe, Animationen, neue Chronikmomente oder Verwaltungsneustarts.

Ein Besitzer öffnet einen Chronikmoment, wählt **In Discord teilen** und gibt die gewünschte Ereignisversion frei. Die gestaltete Karte enthält gemeinsame Auszeichnung, Spiel/Kampagne, Beteiligte, Erinnerung und eine direkte private Szenenadresse. Bei unbekanntem Erlebniszeitpunkt steht die Erfassungszeit ausdrücklich unter „Erfasst“. Historische Steam-Importe bleiben als solche gekennzeichnet.

Screenshot-Vorschauen benötigen zwei bewusste Freigaben: Medienübertragung für den Gemeinschaftskanal und die Auswahl für den konkreten Moment. Übertragen wird ausschließlich die bereits verkleinerte JPEG-Vorschau bis 256 KiB. Discord erhält den Anhang direkt, keine öffentlich freigegebene Bildroute. Ohne Screenshot bleibt die gestaltete Textkarte möglich. GIF-/Video-/Kapitelexporte werden in dieser Stufe nicht erzeugt.

Die **Gemeinschaftskarte** zeigt die letzten fünf sichtbaren Chronikmomente. Der Besitzer bestätigt deren Veröffentlichung gemeinsam; erneutes Aktualisieren bearbeitet dieselbe gespeicherte Nachricht. Gildenstufen und zusätzliche Punkte sind noch nicht eingerichtet.

Jede Freigabe wird als beständiger Metadatenauftrag angenommen. Ereignis-ID, Revision, Vorlagenversion, Medienauswahl, anfordernde Identität und bestätigte Discord-Nachrichten-ID bleiben zugeordnet. Gleiche bereits bestätigte Versionen erzeugen keine neue Nachricht. Eine bewusst freigegebene Korrektur bearbeitet die bekannte ID; eine Zurücknahme entfernt dabei den bisherigen Anhang. Das reine Korrigieren der privaten Chronik veröffentlicht nicht automatisch erneut.

Unklare erste Sendungen bleiben gesperrt: zuerst im Kanal prüfen. Eine neue Nachricht benötigt die ausdrückliche Auswahl **Neue Nachricht anlegen**; die alte bleibt im Kanal erhalten. Bearbeitungen mit bekannter ID können nach erneuter Freigabe wiederholt werden. Rate-Limits speichern die früheste weitere Freigabe anhand `Retry-After`. Änderungen an Ziel, privater Adresse oder Medienfreigabe entwerten noch wartende Aufträge. Fehler verändern keine Chroniknachweise oder Spielprozesse.

Der private Szenenlink enthält keine Zugangstoken. Zum Öffnen bleibt der eigene freigegebene Tailscale-Zugang erforderlich. Discord-Crawler erhalten dadurch keinen Zugang. Webhooks werden nicht zurückgegeben; `allowed_mentions.parse=[]` gilt für Erstellung und Bearbeitung. Discord-Identitäten werden nicht aus ähnlich klingenden Namen abgeleitet.

Quellen: [Discord-Webhooks](https://docs.discord.com/developers/resources/webhook), [Anhänge und Uploads](https://docs.discord.com/developers/reference#uploading-files), [Rate-Limits](https://docs.discord.com/developers/topics/rate-limits). Der kleine JPEG-Export liegt weit unter den Plattformgrenzen; nur fest vorgegebene Multipart-Felder und Dateinamen werden erzeugt.

Prüfung mit lokalem Transport/HTTP-Stub: Viererkarte mit Anhang und Szenenlink, wiederholte Version ohne Duplikat, korrigierte Version an gleicher ID, Multipart/Erwähnungen. Der Browser durchläuft Momentfreigabe und Gemeinschaftskarte ausschließlich mit synthetischem Transport. Keine echten Nachrichten versendet.

Automatische Ereignisabonnements, Kapitel-/Animationsmedien und eigene Discord-Interaktionen bleiben spätere Erweiterungen. Ein echter Test im gewünschten Kanal wird erst ausdrücklich durch den Besitzer ausgelöst.
