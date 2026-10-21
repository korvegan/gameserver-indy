# Sturmklingen

Feste Kampagnen und Sammelziele: `docs/community-challenges.md`. Mitgliederkreis und Abschlussnachweise bleiben als Snapshots erhalten; unbekannt/privat ist nicht unerreicht. Der Abschluss erzeugt über beständige Ereignis-ID genau eine Szene und denselben Badge je Mitglied. Neue Regel = neues Ziel, falscher Abschluss = begründete Rücknahme mit neuer Chronikrevision. Keine Punkte ohne eigene Freigabe. Automatische Steam-/Spielbeobachtung ist noch nicht angebunden.

`docs/chronicle.md`, `symfony/src/Community/`: manuelle gemeinsame Momente, beständige Ereignis-IDs, nachvollziehbare Quellen. Keine noch unbelegte Spielereigniserkennung/Steam-Verknüpfung behaupten.

Mitglieder: `docs/member-profiles.md`. Profile nur vorhandenen persönlichen Freigaben zuordnen; lokale und eigene Tailscale-Identität können dieselbe Person sein. Namen und Mitgliedschaft ändern historische Moment-Snapshots nicht. Gemeinschafts-Schreibrecht erteilt keine Serverrechte. Steam-Link ist eigener einmaliger Browser-/Identitätsnachweis am festen Provider, keine Eingabe einer Steam-ID und keine Freigabe privater Erfolge. PHP normalisiert Punkte in Query-Schlüsseln; OpenID-Antworten aus dem begrenzten Rohquery eindeutig lesen. Reale Steam-Anmeldung bleibt persönliche Abnahme.

- Schemawechsel mit konsistentem Snapshot; Daten bei Programm-Rückweg erhalten. Natives `ext-intl`: alter Normalizer-Polyfill lieferte andere IDs. `ChronicleValues` erhält Unicode-16-ID-Vertrag auch unter neuerem ICU.
- Historische Wiederholungen auf Vorgängerversion aufbauen; Zeit nur für Lesesicht UTC-normalisieren. Nachladen erhält Neuigkeiten/Entwürfe.
- Gewählter illustrierter Fantasy-Wandteppich: sechs feste Plätze je Kapitel unabhängig von Chronikseite. Filter/Rollen/Entfernung verdichten keine Plätze. Vorlagen/alte milestone-Ableitungen erhalten; reduzierte Bewegung beachten.
- Kapitelwahl, Szenenfokus und Details entwerten gegenseitig alte Antworten. Beim Ziehen Tasten und Pointer-Ende außerhalb prüfen, sonst blieb Ziehen aktiv.
- Gemeinsame Errungenschaften und Discord-Karten aus beständigen Ereignissen; keine Duplikate nach Neustarts. Medienexport separat prüfen. Reale Gruppenmeldungen nur mit passendem Auftrag.
