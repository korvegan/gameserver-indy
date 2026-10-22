<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Foundation.php';
require dirname(__DIR__).'/src/WindowsBridge.php';

use IndyNext\FixtureStore;
use IndyNext\Role;
use IndyNext\WindowsBridge;

function verify(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$php = getenv('SLICE_PHP') ?: PHP_BINARY;
if (($argv[1] ?? '') === '--spawn-unavailable') {
    // Leave one descriptor for the request, fewer than proc_open needs for its three streams.
    $limit = max(array_map('intval', array_filter(scandir('/proc/self/fd'), 'ctype_digit'))) + 1;
    verify(posix_setrlimit(POSIX_RLIMIT_NOFILE, $limit, $limit), 'Limit only this disposable worker');
    $bridge = new WindowsBridge($argv[2], [$php, __DIR__.'/bridge-peer.php', $argv[2], 'normal']);
    echo json_encode(@$bridge->call('aska', 'stop', str_repeat('b', 32)), JSON_THROW_ON_ERROR);
    exit;
}
$directory = sys_get_temp_dir().'/indy bridge-'.bin2hex(random_bytes(12));
mkdir($directory, 0700);
$fixture = FixtureStore::create();
try {
    $client = fn (string $mode) => new WindowsBridge($directory, [$php, __DIR__.'/bridge-peer.php', $directory, $mode]);
    $worker = proc_open([$php, __FILE__, '--spawn-unavailable', $directory],
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']], $pipes);
    verify(is_resource($worker), 'Spawn isolated descriptor-limit worker');
    $unavailable = json_decode(stream_get_contents($pipes[1]), true, 16, JSON_THROW_ON_ERROR);
    fclose($pipes[1]);
    verify(proc_close($worker) === 0, 'Descriptor-limit worker finished');
    verify($unavailable['outcome'] === 'failed' && $unavailable['error'] === 'transport_failed',
        'A transport that never started must not leave the action uncertain');
    verify(!file_exists($directory.'/calls.txt') && count(glob($directory.'/request-*.json')) === 0,
        'No native effect or abandoned request after a failed spawn');
    $operation = str_repeat('a', 32);
    $result = $client('normal')->call('enshrouded', 'start', $operation);
    verify($result['outcome'] === 'succeeded' && $result['process'] === 'running' && $result['ready'] === null,
        'Reordered JSON fields and truthful process identity');
    verify(count(glob($directory.'/request-*.json')) === 0, 'Successful exchange cleaned');
    foreach (['wrong-server','wrong-operation','wrong-identity','false-readiness','extra-field','missing-field'] as $mode) {
        $result = $client($mode)->call('enshrouded', 'start', $operation);
        verify($result['outcome'] === 'unknown' && $result['error'] === 'invalid_reply' && $result['process'] === 'unknown', $mode);
        verify(!str_contains(json_encode($result), 'synthetic-private') && !isset($result['private_path']), 'No private native output');
    }
    $before = file_get_contents($directory.'/calls.txt');
    foreach ([['../escape','status',null], ['aska','shell',null], ['aska','start',null], ['aska','stop','arbitrary command']] as $bad) {
        try { $client('normal')->call(...$bad); throw new RuntimeException('Untrusted input accepted'); }
        catch (InvalidArgumentException) {}
    }
    verify($before === file_get_contents($directory.'/calls.txt'), 'Invalid request never starts transport');
    $store = new FixtureStore($fixture);
    $reservation = $store->reserve('enshrouded', 'start', 'synthetic-owner', Role::Administrator);
    $store->transition($reservation['id'], 'running');
    $started = microtime(true);
    $result = $client('timeout')->call('enshrouded', 'start', $reservation['id'], 0.15);
    verify(microtime(true) - $started < 1.5 && $result['error'] === 'transport_timeout', 'Bounded transport cancellation');
    $store->transition($reservation['id'], 'unknown');
    $store = new FixtureStore($fixture);
    try { $store->reserve('enshrouded', 'start', 'synthetic-owner', Role::Family); throw new RuntimeException('Unknown replay accepted'); }
    catch (RuntimeException $e) { verify($e->getMessage() === 'server_busy', 'Unknown reservation survives reopen'); }
    verify(count(glob($directory.'/request-*.json')) === 1, 'Timed-out exchange remains for diagnosis');
    $started = microtime(true);
    $result = $client('ignore-term')->call('aska', 'status', null, 0.15);
    verify(microtime(true) - $started < 1.5 && $result['error'] === 'transport_timeout', 'Uncooperative own transport remains bounded');
    echo "PASS: PHP bridge transport, strict binding/schema, no private output, bounded timeout and persistent unknown reservation\n";
} finally {
    unset($store);
    foreach ([$directory, $fixture] as $path) {
        foreach (glob($path.'/*') as $file) { unlink($file); }
        rmdir($path);
    }
}

if (($argv[1] ?? '') === '--windows') {
    $manifest = json_decode(file_get_contents($argv[2]), true, 8, JSON_THROW_ON_ERROR);
    $mapped = trim(shell_exec('wslpath -u '.escapeshellarg($manifest['root'])) ?? '');
    verify(is_dir($mapped) && str_contains($mapped, 'indy-bridge-test-'), 'Own Windows fixture directory');
    $bridge = new WindowsBridge($mapped, [$manifest['pwsh_wsl'], '-NoLogo','-NoProfile','-NonInteractive',
        '-File',$manifest['bridge'],'-StateDirectory',$manifest['root']]);
    foreach (['enshrouded','aska'] as $server) {
        $op = bin2hex(random_bytes(16));
        $started = $bridge->call($server, 'start', $op);
        verify($started['outcome'] === 'succeeded' && $started['process'] === 'running' && $started['ready'] === null,
            'Real WSL/PHP/native start: '.json_encode($started));
        $again = $bridge->call($server, 'start', $op);
        verify($again['outcome'] === 'succeeded' && $again['identity']['pid'] === $started['identity']['pid']
            && $again['identity']['started'] === $started['identity']['started'], 'Native receipt replay from PHP: '.json_encode($again));
        $stopped = $bridge->call($server, 'stop', bin2hex(random_bytes(16)));
        verify($stopped['outcome'] === 'succeeded' && $stopped['process'] === 'stopped', 'Real WSL/PHP/native graceful stop: '.json_encode($stopped));
    }
    echo "PASS: PHP under WSL -> PowerShell 7 -> native Windows fixtures, status, receipts and graceful stop\n";
}
