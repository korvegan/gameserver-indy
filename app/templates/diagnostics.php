<!doctype html>
<html lang="de">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GameServer Indy – Entwicklungsdiagnose</title>
<h1>PHP-Grundgerüst</h1>
<p>Isolierter Testmodus mit synthetischen Daten. Die Windows-Anbindung und das Dashboard folgen in eigenen Epics.</p>
<p><a href="/health">Laufzeitstatus</a> · <a href="/api/status">Synthetischer Serverstatus</a></p>
<?php if ($actor->role->canManageAccess()): ?><p><a href="/access">Zugänge</a></p><?php endif; ?>
</html>
