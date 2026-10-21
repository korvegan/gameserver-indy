# GameServer Indy

Neubau einer kleinen privaten Serverzentrale für Enshrouded und ASKA.
PHP unter WSL übernimmt Oberfläche, Fachlogik und Rechte; notwendige native
Windows-Aktionen erhalten eine kleine Brücke. Desktop und Smartphone sind
gleichrangig vorgesehen, mit Sturmklingen-/Fantasy-Design.

**Aktueller Stand:** Das [PHP-Grundgerüst](app/README.md) läuft ausschließlich
mit synthetischen Daten und einer lokalen Entwicklungsdiagnose. Echte
Spielsteuerung, Familienanmeldung und Dashboard folgen in ihren Epics.
Die bisherige Verwaltung wurde auf Nutzerauftrag gestoppt und ihr Code
[vollständig archiviert](archive/README.md). Installationen und Welten bleiben
an ihren bisherigen Pfaden erhalten.

- [Zielbild und Epic-Plan #140](https://git.sturmklingen.dedyn.io/Korwerk/gameserver-indy/issues/140)
- [App starten und prüfen](app/README.md)
- [Architektur](app/docs/architecture.md)
- [Status-/Brückenvertrag](app/docs/bridge-contract.md)
- [Umstellung und Rückweg](app/docs/retirement.md)

Entwicklung: `npm run test:app` mit den in der App-Anleitung genannten vorhandenen
PHP-/FrankenPHP-Werkzeugen. Unter Windows verwendet `start.cmd` den installierten
privaten Starter. Die Verwaltungsapp startet keine Spielserver automatisch.

Forgejo `Korwerk/gameserver-indy` ist führend; GitHub ist das Backup.
Arbeitsumfang, Prüfungen und Review stehen in [AGENTS.md](AGENTS.md).
