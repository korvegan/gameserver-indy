# Adressen und Benachrichtigungen

Modquellen: `docs/mods.md`, pro Spiel auch ohne Dedicated-Instanz. Vier Quellenadapter normalisieren Originalseiten, keine allgemeinen Downloads/Installation. Nexus-Keys bleiben beim Spieler; RaftModding-Profile derzeit eingeschränkt. Quellen-/Dateikennung nicht durch Modnamen ersetzen. Persönliche Downloadlinks nicht teilen; echte Mehrspieler-Abnahme braucht ein ausgewähltes Paket.

Modprofile: immutable JSON-Schnappschüsse mit manuellen Pins/Hashes, Versionsvergleich und Rückzug. Export nur Referenzen, keine Moddateien/Schlüssel. Persönliche Abgleichmeldung ist manuell und nach 24 h älter, niemals automatisch als lokal installiert/beitrittsbereit ausgeben. Kein lokaler Installationsagent eingerichtet.

Gemeinschafts-Discord: `docs/community-discord.md`. Eigener Webhook, explizite Ereignis-/Medienfreigabe; kleine vorhandene JPEG-Vorschau direkt als Anhang, private Szenenlinks bleiben zugangsgeschützt. Metadatenauftrag speichert Ereignisrevision und Nachrichten-ID. Unklare erste Veröffentlichung nicht wiederholen; bekannte ID nur bearbeiten. Keine echten Gruppenmeldungen beim Deployment anlegen.

Anleitungen: `docs/connection-info.md`, `docs/steam-updates.md`, `docs/whatsapp.md`.

Öffentliche Domain `sturmklingen.dedyn.io`, Enshrouded Query-Port `15637`. `indy-dyndns.timer` im Projekt `indy-infrastructure` pflegt DNS alle fünf Minuten. Adressen vom Windows-Host, nicht WSL ermitteln. DNS/IP beweisen keine Routerfreigabe/Spielbereitschaft. Enshrouded: bestätigte IP/Domain-plus-Port-Suche; ASKA bisher Servername.

Statuspolls lesen Caches; Netz-/Steamabfragen als Metadatenjobs. Wiederholungen/volle Queue dürfen keine Profile überspringen oder Nachrichten duplizieren.

Discord eigener HTTP-Client ohne URL-Logger: Review fand vollständige Webhooks im allgemeinen Symfony-Logger. ASKA-Starter sendet bereits nach `The session is now open!`, keine doppelte Dashboardmeldung. Keine echten Nachrichten/Webhooks als Test.

Statuskarte: `docs/discord-status-card.md`. Erster Versand nur ausdrücklich; unklarer POST wird nicht erneut gesendet. Automatische Metadatenjobs bearbeiten ausschließlich bekannte Nachrichten-IDs, beachten `Retry-After` und sperren entfernte/geänderte Webhooks. Auch PATCH muss `allowed_mentions.parse=[]` setzen. Die Karte bleibt standardmäßig aus; nach Deployment keine echte Karte als Test anlegen.

WhatsApp auf Nutzerwunsch kostenlos über lokalen WAHA/GOWS mit persönlich gescanntem QR verknüpft. Desktop-App ist separates Gerät, kein Automatisierungsadapter. Sitzungsdaten privat. QR-/Konto-/Gruppenaktionen auch für entfernte Besitzer sperren. Gruppenwahl an ID binden. Kurzmeldungen: Spiel, Server, Adresse, Bereitschaft.

Bereitschaft an Prozesslauf binden: Enshrouded hat relative Logzeiten und Kopf `Current UTC date: YYYY.MM.DD HH:MM:SS`, am 16.10.2026 passend zur nativen Startsekunde. mtime allein genügt nicht. Bei Leselücken nur Übergänge aus lückenlosem Ende; alte up-Meldung hatte neueres down überdeckt. Zusammenhängende Byteblöcke ohne künstlichen Zeilenumbruch verbinden.

Freigabeentzug gilt auch für wartende Integrationsjobs. Logs bereinigen; Tokens/Webhooks/Passwörter/QR nie in Berichte oder Artefakte.
