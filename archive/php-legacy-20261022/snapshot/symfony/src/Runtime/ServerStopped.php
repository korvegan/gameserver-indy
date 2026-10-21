<?php
declare(strict_types=1);
namespace App\Runtime;

/** Only emitted after a fresh, complete native stop; backup listeners follow in #50. */
final readonly class ServerStopped
{
    public function __construct(public string $serverId) {}
}
