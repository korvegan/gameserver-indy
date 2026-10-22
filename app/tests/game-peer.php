<?php
declare(strict_types=1);
// Explicit synthetic transport: no game processes, configuration or network access.
require dirname(__DIR__).'/src/Foundation.php';
$directory = $argv[1];
new \IndyNext\FixtureStore($directory);
$nonce = $argv[3];
if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) { exit(2); }
$request = json_decode(file_get_contents($directory.'/request-'.$nonce.'.json'), true, flags: JSON_THROW_ON_ERROR);
$server = $request['server'];
if (!in_array($server, ['aska','enshrouded'], true)) { exit(2); }
$scenario = is_file($directory.'/scenario.json') ? json_decode(file_get_contents($directory.'/scenario.json'), true, flags: JSON_THROW_ON_ERROR) : [];
$scenario = $scenario[$server] ?? [];
$action = $request['action'];
usleep(min(10000, max(0, (int) ($scenario[$action.'_delay_ms'] ?? 0))) * 1000);
function save(string $file, array $value): void {
    $temp = $file.'.'.bin2hex(random_bytes(8));
    file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR));
    chmod($temp,0600);
    rename($temp,$file);
}
$stateFile = $directory.'/game-'.$server.'.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR) : ['process'=>'stopped','identity'=>null];
$receiptFile = $directory.'/receipt-'.$server.'-'.($request['operation'] ?? 'none').'.json';
$receipt = is_file($receiptFile) ? json_decode(file_get_contents($receiptFile), true, flags: JSON_THROW_ON_ERROR) : null;
$outcome = 'succeeded'; $error='';
if ($action !== 'status' && $receipt === null) {
    file_put_contents($directory.'/effects-'.$server.'.txt', $action."\n", FILE_APPEND | LOCK_EX);
    $outcome = $scenario['outcome'] ?? 'succeeded';
    if ($outcome !== 'failed') {
        $state = $action === 'start' ? ['process'=>'running','identity'=>['pid'=>$server === 'aska' ? 1001:1002,
            'started'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')]] : ['process'=>'stopped','identity'=>null];
        save($stateFile,$state);
    }
    $error = $outcome === 'failed' ? 'process_exited' : ($outcome === 'unknown' ? 'outcome_unresolved' : '');
    $receipt = ['outcome'=>$outcome,'error'=>$error];
    save($receiptFile,$receipt);
}
if ($request['operation'] !== null && $receipt !== null) {
    if ($receipt['outcome'] === 'unknown' && ($scenario['reconcile'] ?? false)) { $receipt=['outcome'=>'succeeded','error'=>'']; save($receiptFile,$receipt); }
    $outcome=$receipt['outcome']; $error=$receipt['error'];
} elseif ($request['operation'] !== null) { $outcome='unknown'; $error='outcome_unresolved'; }
$reply=[...$request,'outcome'=>$outcome,'error'=>$error,'process'=>$state['process'],'ready'=>null,
    'observed_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),'identity'=>$state['identity']];
save($directory.'/reply-'.$nonce.'.json',$reply);
