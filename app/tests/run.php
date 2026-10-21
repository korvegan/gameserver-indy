<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Foundation.php';

use IndyNext\FixtureStore;
use IndyNext\Role;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $operation, string $expected): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error->getMessage() === $expected, $error->getMessage().' != '.$expected);
        return;
    }
    throw new RuntimeException('Expected: '.$expected);
}

if (($argv[1] ?? '') === '--reserve') {
    $store = new FixtureStore($argv[2]);
    echo $store->reserve('aska', 'start', 'fixture-family', Role::Family)['id'];
    exit;
}

$directory = FixtureStore::create();
try {
    $store = new FixtureStore($directory);
    check(count($store->snapshot()) === 2, 'Two games');
    foreach ($store->snapshot() as $server) {
        check($server['process'] === 'unknown' && $server['ready'] === null, 'No invented state');
    }
    check(Role::Administrator->canManageAccess(), 'Admin access management');
    check(!Role::Family->canManageAccess() && Role::tryFrom('owner-header') === null, 'Family/unknown role boundary');
    fails(fn () => new FixtureStore(dirname(__DIR__)), 'isolated_fixture_required');
    symlink($directory, $directory.'-alias');
    fails(fn () => new FixtureStore($directory.'-alias'), 'isolated_fixture_required');
    unlink($directory.'-alias');

    $store->observe('enshrouded', 'running', null);
    check($store->snapshot()[1]['ready'] === null, 'Process running does not prove ready');
    $store->observe('enshrouded', 'running', true);
    check($store->snapshot()[1]['ready'] === true, 'Explicit observed readiness');
    fails(fn () => $store->observe('enshrouded', 'stopped', true), 'invalid_observation');
    fails(fn () => $store->reserve('../world', 'start', 'fixture-family', Role::Family), 'unknown_server');
    fails(fn () => $store->reserve('aska', 'shell', 'fixture-family', Role::Family), 'invalid_operation');

    foreach ([Role::Family, Role::Administrator] as $role) {
        foreach (['start', 'stop'] as $action) {
            $operation = $store->reserve('enshrouded', $action, 'fixture-actor', $role);
            $again = $store->reserve('enshrouded', $action, 'fixture-other', $role);
            check($operation === $again, 'Duplicate request retains operation and original actor');
            $other = $action === 'start' ? 'stop' : 'start';
            fails(fn () => $store->reserve('enshrouded', $other, 'fixture-actor', $role), 'server_busy');
            fails(fn () => $store->transition($operation['id'], 'succeeded'), 'invalid_transition');
            $store->transition($operation['id'], 'running');
            $store->transition($operation['id'], 'succeeded');
        }
    }
    $operation = $store->reserve('enshrouded', 'stop', 'fixture-family', Role::Family);
    $store->transition($operation['id'], 'cancelled');
    $operation = $store->reserve('enshrouded', 'start', 'fixture-family', Role::Family);
    $store->transition($operation['id'], 'running');
    $store->transition($operation['id'], 'unknown');
    $reopened = new FixtureStore($directory);
    fails(fn () => $reopened->reserve('enshrouded', 'start', 'fixture-family', Role::Family), 'server_busy');
    $reopened->transition($operation['id'], 'failed');
    check($reopened->reserve('enshrouded', 'stop', 'fixture-family', Role::Family)['state'] === 'pending', 'Resolved outcome releases server');

    $children = [];
    for ($index = 0; $index < 4; ++$index) {
        $process = proc_open([getenv('SLICE_PHP') ?: PHP_BINARY, __FILE__, '--reserve', $directory],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Child started');
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    $ids = [];
    foreach ($children as [$process, $pipes]) {
        $ids[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        check($exitCode === 0, 'Child exit '.$exitCode.': '.$error.' output='.end($ids));
    }
    check(count(array_unique($ids)) === 1 && strlen($ids[0]) === 32, 'Concurrent processes share exactly one reservation');
    echo "PASS: fixture isolation, roles, truthful observations, operation lifecycle, restart and four concurrent requests\n";
} finally {
    unset($store, $reopened);
    foreach (glob($directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
