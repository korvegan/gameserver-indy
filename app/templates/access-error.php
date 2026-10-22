<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zugriff gesperrt – Sturmklingen</title>
<link rel="stylesheet" href="/assets/access.css">
</head>
<body><div class="shell">
<?php require __DIR__.'/brand.php'; ?>
<main><h1><?= http_response_code() === 403 ? 'Zugriff gesperrt' : 'Anfrage nicht gespeichert' ?></h1>
<p><?= e($message) ?></p>
<a class="back" href="/">Zur Serverzentrale</a>
</main></div></body>
</html>
