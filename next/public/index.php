<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Foundation.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

$port = getenv('INDY_NEXT_PORT');
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:'.$port) {
    http_response_code(403);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
try {
    $directory = (string) getenv('INDY_NEXT_FIXTURE');
    $store = new \IndyNext\FixtureStore($directory);
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['mode' => 'fixture', 'native_enabled' => false, 'instance' => basename($directory)], JSON_THROW_ON_ERROR);
    } elseif ($path === '/api/status') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['mode' => 'fixture', 'servers' => $store->snapshot()], JSON_THROW_ON_ERROR);
    } elseif ($path === '/') {
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__).'/templates/diagnostics.php';
    } else {
        http_response_code(404);
    }
} catch (\Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Isolierter Testmodus nicht verfügbar.';
}
