# Persönliche Sturmklingen-Profile

Unter **Sturmklingen → Mitglieder & Steam** gibt ein Besitzer Mitglieder frei. Er wählt bestehende persönliche Zugänge aus; für dieselbe Person können beispielsweise der lokale Besitzerzugang und ihr eigener Tailscale-Zugang zusammengehören. Ein Zugang gehört nur zu einem Mitgliederprofil. Es werden keine fremden Konten automatisch aus gleichen Namen abgeleitet.

Jedes Profil erhält eine unveränderliche Mitglieder-ID. Anzeigename, aktive Mitgliedschaft und Gemeinschaftsrolle bleiben änderbar. Die Rolle **Momente festhalten** erlaubt auch einem ansonsten nur lesenden oder startberechtigten Familienzugang, Chronikbeiträge für seine sichtbaren Server oder ohne Serverzuordnung anzulegen. Sie erteilt keine Serververwaltung. Besitzer behalten ihre bisherige Schreib-/Verwaltungsbefugnis.

Im Momentformular lassen sich Mitglieder auswählen und weiterhin freie Namen eintragen. Autorenprofil und Beteiligte sind getrennt: Das Erfassen eines Moments trägt den Autor nicht automatisch als Beteiligten ein. Der Beitrag speichert Namen und IDs als eigenen historischen Stand. Umbenennen oder Deaktivieren eines Profils schreibt frühere Beiträge nicht um. Eine Korrektur mit Begründung kann frühere freie Namen bewusst durch ausgewählte Profile ersetzen; es gibt keine automatische Zusammenführung gleichnamiger Personen. Dieselbe Ereignisrevision erzeugt weiterhin nur eine Szene und dieselben Auszeichnungen.

## Optionale Steam-Verknüpfung

Das Mitglied öffnet sein eigenes Profil und wählt **Mit Steam verknüpfen**. Die Anmeldung findet ausschließlich bei `steamcommunity.com` statt. Der Manager empfängt keine Steam-Passwörter. Eine manuell eingegebene Steam-ID wird nicht als Kontonachweis angenommen.

Die Implementierung ist auf den bekannten Steam-Provider und dessen dokumentierten Steam-ID-Namensraum begrenzt. Sie ist kein allgemeiner OpenID-Anbieter. Der Rücksprung ist an den exakten Dashboard-Ursprung, ein kurzlebiges einmaliges Anliegen, die vorhandene persönliche Identität/Freigaberevision, das Mitgliederprofil und ein separates HttpOnly-/SameSite-Lax-Browsercookie gebunden. Steam bestätigt die unveränderten OpenID-Felder serverseitig über `check_authentication`. Rücksprung, signierte Pflichtfelder, Provider, Identität und Zeitstempel werden geprüft; wiederholte Anliegen werden nicht angenommen. Die direkte Bestätigung verwendet einen eigenen HTTP-Client ohne URL-Tracing.

Ein Steam-Konto kann nur zu einem Profil gehören. Vor einem Wechsel wird die bisherige Verknüpfung ausdrücklich getrennt. Trennen entwertet noch wartende Verknüpfungen. Bestehende Chroniknachweise bleiben erhalten.

**Steam-Verknüpfung bestätigt die Identität, keine privaten Erfolge oder gemeinsame Spielzeit.** Persönliche Spieldaten stehen zunächst auf **unbekannt**. Spätere Abfragen müssen verfügbar, privat, unbekannt und fehlerhaft unterscheiden. Manuelle Erinnerungen funktionieren weiterhin ohne Steam.

Grundlagen: [Steamworks: OpenID](https://partner.steamgames.com/doc/features/auth#website), [OpenID 2.0: direkte Verifikation](https://openid.net/specs/openid-authentication-2_0.html#check_auth).

Prüfung: eigene Mitglieder/Freigaben und lokale HTTP-Stubs; stabiler historischer Name, getrennte Gemeinschafts-/Serverrechte, einmalige Steam-Bestätigung und Abweisung eines fremden Browserrücksprungs. Der Browserablauf legt ein eigenes Testmitglied an und erfasst einen Moment mit Profilbeteiligung. Die Anmeldung mit echten persönlichen Steam-Konten bleibt eine manuelle Abnahme; keine Konten wurden eigenmächtig verknüpft.

Feste Kampagnenroster und Sammelziele werden mit #23 ergänzt. #37 bleibt bis dahin und bis zur persönlichen Steam-Abnahme offen.
