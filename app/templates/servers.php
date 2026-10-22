<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="indy-csrf" content="<?= e($token) ?>">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<title><?= $selected === null ? 'Serverzentrale' : ($selected === 'aska' ? 'ASKA' : 'Enshrouded') ?> – Sturmklingen</title>
<link rel="stylesheet" href="/assets/access.css">
<link rel="stylesheet" href="/assets/servers.css">
<script src="/assets/servers.js" defer></script>
</head>
<body class="server-page">
<div class="shell">
<?php
if ($selected !== null) { $navigation=['href'=>'/','label'=>'Serverzentrale']; }
elseif ($actor->role->canManageAccess()) { $navigation=['href'=>'/access','label'=>'Zugänge']; }
require __DIR__.'/brand.php';
?>
<main data-server-page>
<?php if ($selected === null): ?>
<div class="dashboard-heading"><h1>Serverzentrale</h1><p>Wähle dein Spiel.</p></div>
<?php endif; ?>
<div class="notice" role="status" aria-live="polite" data-notice><?= ($_GET['action'] ?? '') === 'denied' ? 'Die Aktion wurde nicht ausgeführt. Bitte prüfe den aktuellen Status.' : '' ?></div>
<div class="<?= $selected === null ? 'game-grid' : 'server-detail' ?>">
<?php usort($servers, fn ($a, $b) => ($a['id'] === 'enshrouded' ? 0 : 1) <=> ($b['id'] === 'enshrouded' ? 0 : 1));
foreach ($servers as $server): if ($selected !== null && $selected !== $server['id']) { continue; } ?>
<section class="game <?= $selected !== null ? 'detail-game' : '' ?>" data-game="<?= e($server['id']) ?>" aria-label="<?= e($server['name']) ?>">
<div class="game-art">
<img class="hero" src="/assets/games/<?= e($server['id']) ?>-hero.jpg" alt="">
<<?= $selected === null ? 'h2' : 'h1' ?> class="game-logo"><img src="/assets/games/<?= e($server['id']) ?>-logo.png" alt="<?= e($server['name']) ?>"></<?= $selected === null ? 'h2' : 'h1' ?>>
</div>
<div class="game-controls">
<div class="observation" aria-live="polite" aria-atomic="true">
<p class="state-title <?= e($server['tone']) ?>" data-status><?= e($server['status']) ?></p>
<p class="state-detail" data-detail><?= e($server['detail']) ?></p>
</div>
<div class="game-actions">
<button class="primary" data-action="start" data-server="<?= e($server['id']) ?>" <?= $server['can_start'] ? '' : 'disabled' ?>>Starten</button>
<button class="secondary" data-action="stop" data-server="<?= e($server['id']) ?>" <?= $server['can_stop'] ? '' : 'disabled' ?>>Stoppen</button>
</div>
<button class="refresh" data-refresh <?= $server['tone'] === 'unknown' || $server['tone'] === 'error' ? '' : 'hidden' ?>>Status prüfen</button>
<?php if ($selected === null): ?><a class="open-server" href="/servers/<?= e($server['id']) ?>">Server öffnen</a><?php endif; ?>
</div>
<?php if ($selected !== null): ?>
<section class="join" aria-labelledby="join-heading">
<h2 id="join-heading">Beitreten</h2>
<label for="connection"><?= e($server['connection_label']) ?></label>
<div class="connection-line">
<input id="connection" readonly data-connection value="<?= e($server['connection'] ?? 'Noch nicht verfügbar') ?>">
<button data-copy <?= $server['connection'] === null ? 'disabled' : '' ?>>Kopieren</button>
</div>
<p class="connection-help"><?= e($server['connection_help']) ?></p>
</section>
<?php endif; ?>
</section>
<?php endforeach; ?>
</div>
<noscript><p>Für Statusaktualisierung und Spielsteuerung bitte JavaScript aktivieren.</p></noscript>
<?php if ($runtime->mode() === 'fixture'): ?><p class="preview-note">Lokale Vorschau mit Testdaten.</p><?php endif; ?>
</main>
</div>
<dialog aria-labelledby="stop-title" aria-describedby="stop-description">
<h2 id="stop-title">Server stoppen?</h2>
<p id="stop-description">Alle verbundenen Spieler werden getrennt.</p>
<div class="dialog-actions"><button data-cancel autofocus>Abbrechen</button><button class="danger" data-confirm>Server stoppen</button></div>
</dialog>
</body>
</html>
