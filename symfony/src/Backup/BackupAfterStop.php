<?php
declare(strict_types=1);
namespace App\Backup;

use App\Runtime\ServerStopped;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class BackupAfterStop
{
    public function __construct(private readonly WorldBackups $backups) {}
    public function __invoke(ServerStopped $event): void { $this->backups->afterStop($event->serverId); }
}
