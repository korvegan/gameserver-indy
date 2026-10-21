<?php
declare(strict_types=1);

namespace App\Runtime;

interface ServerControl
{
    /** @return array{running: bool, ready: bool, pid: ?int} */
    public function status(string $serverId): array;
    public function execute(string $serverId, string $operation): void;
}
