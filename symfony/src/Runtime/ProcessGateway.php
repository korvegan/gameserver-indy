<?php
declare(strict_types=1);
namespace App\Runtime;

interface ProcessGateway
{
    /** A fresh native observation, never a cached HTTP status. */
    public function call(string $serverId, string $action): array;
}
