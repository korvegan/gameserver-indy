<?php
declare(strict_types=1);
namespace App\Runtime;
final readonly class ServerActionCompleted
{
    public function __construct(public string $serverId, public string $operation) {}
}
