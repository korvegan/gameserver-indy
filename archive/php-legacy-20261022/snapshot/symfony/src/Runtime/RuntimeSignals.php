<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Storage\PrivateFiles;

/** Local wake-up hints. SQLite/files remain authoritative; a missed hint is recoverable. */
final class RuntimeSignals
{
    private const TOPICS = ['jobs'=>1,'status'=>2,'performance'=>4,'logs'=>8,'settings'=>16,'access'=>32,'community'=>64,'game-event'=>128];
    public function __construct(private readonly SliceRuntime $runtime) {}
    private function directory(): string
    {
        $path = sys_get_temp_dir().'/indy-signals-'.getmyuid().'-'.substr(hash('sha256', $this->runtime->directory), 0, 20);
        PrivateFiles::directory($path);
        return $path;
    }
    /** Subscribe before reading durable state, so a concurrent commit cannot slip between read and wait. */
    public function listen(array $topics = []): array
    {
        if (PHP_OS_FAMILY !== 'Linux') { return [null, null]; }
        $mask = 0; foreach ($topics ?: array_keys(self::TOPICS) as $topic) { $mask |= self::TOPICS[$topic] ?? 0; }
        $path = $this->directory().'/'.getmypid().'-'.bin2hex(random_bytes(4)).'-'.dechex($mask).'.sock';
        $socket = @stream_socket_server('udg://'.$path, $errno, $error, STREAM_SERVER_BIND);
        if ($socket === false) { throw new \RuntimeException('Lokaler Ereignisempfänger nicht verfügbar.'); }
        chmod($path, 0600); stream_set_blocking($socket, false);
        return [$socket, $path];
    }
    public function publish(string $topic, ?string $serverId = null): void
    {
        if (PHP_OS_FAMILY !== 'Linux') { return; }
        $bytes = json_encode(['topic'=>$topic, 'server_id'=>$serverId], JSON_THROW_ON_ERROR);
        try { $directory = $this->directory(); } catch (\Throwable) { return; } // A hint cannot fail an already committed command.
        foreach (glob($directory.'/*.sock') ?: [] as $path) {
            preg_match('/-([a-f0-9]+)\.sock$/D', $path, $match);
            if (!(hexdec($match[1] ?? '0') & (self::TOPICS[$topic] ?? 0))) { continue; }
            $socket = @stream_socket_client('udg://'.$path, $errno, $error, 0, STREAM_CLIENT_CONNECT);
            if ($socket === false) { @unlink($path); continue; }
            stream_set_blocking($socket, false); @fwrite($socket, $bytes); fclose($socket);
        }
    }
    /** Blocks in the kernel, interrupted by a signal, notification or the next real deadline. */
    public function wait(array $listener, float $seconds): array
    {
        [$socket] = $listener;
        if (!is_resource($socket)) { usleep((int)(min(1, max(0, $seconds))*1000000)); return []; }
        $read = [$socket]; $write = $except = []; $seconds = max(0, $seconds);
        if (@stream_select($read, $write, $except, (int)$seconds, (int)(($seconds-(int)$seconds)*1000000)) !== 1) { return []; }
        $events = [];
        while (($bytes = stream_socket_recvfrom($socket, 2048)) !== false && $bytes !== '') {
            $event = json_decode($bytes, true);
            if (is_array($event) && is_string($event['topic'] ?? null)) { $events[] = $event; }
        }
        return $events;
    }
    public function close(array $listener): void
    {
        if (is_resource($listener[0])) { fclose($listener[0]); }
        if (is_string($listener[1])) { @unlink($listener[1]); }
    }
}
