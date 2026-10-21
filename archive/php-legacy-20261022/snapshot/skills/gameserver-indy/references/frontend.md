# Serverzentrale und Frontend

`docs/dashboard-design.md`: eigene Serverübersicht (`fleet`) und getrennte Serverdetails mit sechs Tabs. Zustand in `web/state.js`; `web/views/workspace.js` hängt vorhandene Ansichten einmal in die feste Hülle. Mobil liegt die globale Navigation in einem nativen modalen Dialog.

- Tabs/Liveereignisse erzeugen Formulare und Chronik nicht neu. Entwürfe erhalten. Besitzerkonfiguration nur bei Bedarf laden. Ein SSE-Kanal, keine parallelen Status-/Auftragspolls; Wiederverbindung gleicht wartende Aufträge auch dann ab, wenn ihr Abschluss hinter dem neuen Cursor liegt.
- „Alle Server“ erhält Entwürfe; erneutes Öffnen desselben Profils darf die Auswahl nicht zurücksetzen. Erst ein tatsächlicher Profilwechsel verwendet die vorhandenen Verwerfungsrückfragen. Browserprüfungen nutzen dafür den gemeinsamen Navigationshelfer statt unsichtbare Detail-Tabs oder Auswahlkarten anzuklicken.
- Auswahl pro Browser; Aktionen mit Profil-ID. Alte Antworten durch Auswahlgeneration, Anfrageziel und passende Protokollquelle verwerfen. Auswahl während Poll/Auftrag bedienbar halten; auch defekte Konfiguration darf Wechsel nicht sperren.
- Navigation beim Wechsel zu Gemeinschaft/Spiele positionsstabil halten; früher ausgeblendeter Selektor ließ sie springen.
- Start/Stopp/Neustart bei unbekanntem Status als Aufträge annehmen. Annahme, Ausführung und Prozessstatus trennen. Persistenter Sitzungsschlüssel übersteht Verwaltungsneustart.
- Familie/Lesezugriff sieht eigene Aufträge/Fehler: Tab und Link benötigen `jobs.read`; eigentliche Logs/Quellen/Abrufe benötigen `logs.read`.
- Späte Speicherbestätigung bestätigt nur abgeschickte Entwurfsrevision. Späte Adressantwort darf neuere Daten nach Portänderung nicht überschreiben: zusätzlich Renderrevision beachten.
- Startfehlernavigation nur bei unverändertem Auswahl-/Navigationskontext; alte wiederentdeckte Aufträge übernehmen keine Ansicht.
- Rechteänderungen zusätzlich über `grant_revision` erkennen, nicht nur sichtbare Rolle.
- Gemeinschaftseditor erst mit bestätigten Schreibrechten und Katalog aktivieren. Früher sichtbarer Button verwarf Klick während verzögerter Sitzungsladung.

Passende Desktop-/Mobilabläufe einmal gesammelt prüfen; keine vollständige Wiederholung pro Textsatz.
