<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zugänge – Sturmklingen</title>
<link rel="stylesheet" href="/assets/access.css">
</head>
<body>
<div class="shell">
<?php require __DIR__.'/brand.php'; ?>
<main>
<a class="back" href="/"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20 12H4m7-7-7 7 7 7"/></svg>Serverzentrale</a>
<h1>Zugänge</h1>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p role="status">Zugang gespeichert.</p><?php endif; ?>
<div class="table-wrap">
<table>
<thead><tr><th scope="col">Name</th><th scope="col">Identität</th><th scope="col">Rolle</th><th scope="col">Zugriff</th><th scope="col">Aktion</th></tr></thead>
<tbody>
<?php
$rows = $grants;
if ($actor->id === 'local-owner') {
    array_unshift($rows, ['id'=>'local-owner','login'=>'Lokal auf diesem Rechner','name'=>$actor->name,
        'role'=>'administrator','enabled'=>true,'revision'=>0]);
}
foreach ($rows as $grant):
    $own = $grant['id'] === $actor->id;
?>
<tr>
<th scope="row" data-label="Name"><?= e($grant['name']) ?></th>
<td data-label="Identität"><?= e($grant['login']) ?></td>
<td data-label="Rolle">
<?php if ($own): ?>
<?= $grant['role'] === 'administrator' ? 'Administrator' : 'Familie' ?>
<?php else: ?>
<details class="edit-role"><summary aria-label="<?= $grant['role'] === 'administrator' ? 'Administrator' : 'Familie' ?> – Rolle ändern für <?= e($grant['name']) ?>"><?= $grant['role'] === 'administrator' ? 'Administrator' : 'Familie' ?></summary>
<form method="post" action="/access">
<input type="hidden" name="csrf" value="<?= e($token) ?>">
<input type="hidden" name="action" value="role">
<input type="hidden" name="id" value="<?= e($grant['id']) ?>">
<input type="hidden" name="revision" value="<?= $grant['revision'] ?>">
<label for="role-<?= e($grant['id']) ?>">Rolle für <?= e($grant['name']) ?></label>
<select id="role-<?= e($grant['id']) ?>" name="role">
<option value="family" <?= $grant['role'] === 'family' ? 'selected' : '' ?>>Familie</option>
<option value="administrator" <?= $grant['role'] === 'administrator' ? 'selected' : '' ?>>Administrator</option>
</select>
<button>Speichern</button>
</form>
</details>
<?php endif; ?>
</td>
<td data-label="Zugriff"><span class="state <?= $grant['enabled'] ? 'enabled' : 'blocked' ?>"><?= $grant['enabled'] ? 'Freigegeben' : 'Gesperrt' ?></span></td>
<td data-label="Aktion">
<?php if ($own): ?>Dein Zugang<?php else: ?>
<form method="post" action="/access" aria-label="Zugang für <?= e($grant['name']) ?>">
<input type="hidden" name="csrf" value="<?= e($token) ?>">
<input type="hidden" name="action" value="toggle">
<input type="hidden" name="id" value="<?= e($grant['id']) ?>">
<input type="hidden" name="revision" value="<?= $grant['revision'] ?>">
<input type="hidden" name="enabled" value="<?= $grant['enabled'] ? '0' : '1' ?>">
<button class="<?= $grant['enabled'] ? 'secondary' : 'primary' ?>"><?= $grant['enabled'] ? 'Sperren' : 'Freigeben' ?></button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<section class="person" aria-labelledby="add-person">
<h2 id="add-person">Person freigeben</h2>
<form method="post" action="/access">
<input type="hidden" name="csrf" value="<?= e($token) ?>">
<input type="hidden" name="action" value="create">
<label for="identity">Identität</label>
<input id="identity" name="login" placeholder="name@example.org" required maxlength="254" autocomplete="off" autocapitalize="none" spellcheck="false">
<label for="new-role">Rolle</label>
<select id="new-role" name="role"><option value="family">Familie</option><option value="administrator">Administrator</option></select>
<button class="primary">Freigeben</button>
</form>
</section>
</main>
</div>
</body>
</html>
