<?php
declare(strict_types=1);

$directory = $argv[1];
$mode = $argv[2];
$nonce = $argv[4];
$request = json_decode(file_get_contents($directory.'/request-'.$nonce.'.json'), true, 8, JSON_THROW_ON_ERROR);
file_put_contents($directory.'/calls.txt', $request['action']."\n", FILE_APPEND);
if ($mode === 'ignore-term') {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
}
if (in_array($mode, ['timeout','ignore-term'], true)) {
    sleep(5);
}
$reply = [...$request, 'outcome' => 'succeeded', 'error' => '', 'process' => 'running', 'ready' => null,
    'observed_at' => '2026-10-22T01:02:03.1234567Z', 'identity' => ['pid' => 123, 'started' => '2026-10-22T01:02:02.1234567Z']];
switch ($mode) {
    case 'wrong-server': $reply['server'] = 'aska'; break;
    case 'wrong-operation': $reply['operation'] = str_repeat('f', 32); break;
    case 'wrong-identity': $reply['identity']['pid'] = '123'; break;
    case 'false-readiness': $reply['process'] = 'stopped'; $reply['identity'] = null; $reply['ready'] = true; break;
    case 'extra-field': $reply['private_path'] = 'synthetic-secret'; break;
    case 'missing-field': unset($reply['ready']); break;
}
fwrite(STDERR, 'synthetic-private-stderr');
file_put_contents($directory.'/reply-'.$nonce.'.json', json_encode(array_reverse($reply, true), JSON_THROW_ON_ERROR));
