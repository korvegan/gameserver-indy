<?php
declare(strict_types=1);

namespace App\Runtime;

final class SimulatedServerControl implements ServerControl
{
    public function __construct(private readonly SliceRuntime $runtime) {}

    public function status(string $serverId): array
    {
        $this->runtime->server($serverId);
        $this->runtime->assertInitialized();
        $path = $this->runtime->directory.'/simulated/'.$serverId.'.json';
        return is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
            : ['running' => false, 'ready' => false, 'pid' => null];
    }

    public function execute(string $serverId, string $operation): void
    {
        $state = $this->status($serverId);
        if (!in_array($operation, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException('Unbekannter Befehl.');
        }
        usleep(700000); // Only the simulator delays; no real game is involved.
        $running = $operation !== 'stop';
        $path = $this->runtime->directory.'/simulated/'.$serverId.'.json';
        $temporary = $path.'.'.bin2hex(random_bytes(4));
        $content = json_encode(['running' => $running, 'ready' => $running,
            'pid' => $running ? ($operation === 'start' && $state['running'] ? $state['pid'] : random_int(10000, 50000)) : null], JSON_THROW_ON_ERROR);
        try {
            if (@file_put_contents($temporary, $content) !== strlen($content) || !@chmod($temporary, 0600)
                || !@rename($temporary, $path)) {
                throw new \RuntimeException('Teststatus konnte nicht gespeichert werden.');
            }
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }
    }
}
