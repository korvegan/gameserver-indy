<?php
declare(strict_types=1);
namespace App\Job;

use App\Runtime\RuntimeSignals;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/** Keep Doctrine's durable transport and sleep on a local notification instead of polling it. */
final class IdleWorker
{
    private ?array $listener = null;
    private float $deadline = INF;
    public function __construct(private readonly RuntimeSignals $signals, private readonly Connection $db) {}
    #[AsEventListener]
    public function command(\Symfony\Component\Console\Event\ConsoleCommandEvent $event): void
    {
        if ($event->getCommand()?->getName() !== 'messenger:consume') { return; }
        $limit = (float)$event->getInput()->getOption('time-limit');
        $this->deadline = $limit > 0 ? microtime(true)+$limit : INF;
    }
    #[AsEventListener]
    public function started(WorkerStartedEvent $event): void { $this->listener = $this->signals->listen(['jobs']); }
    #[AsEventListener(priority: -1024)]
    public function idle(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle() || $this->listener === null || PHP_OS_FAMILY !== 'Linux') { return; }
        // Include delayed recoverable deliveries. The common empty queue waits one minute.
        $queues = array_map(fn ($name) => $name === 'async' ? 'default' : $name, $event->getWorker()->getMetadata()->getTransportNames());
        $next = $this->db->fetchOne('SELECT MIN(available_at) FROM messenger_messages WHERE delivered_at IS NULL AND queue_name IN (?)',
            [$queues], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        $delay = $next ? max(.05, strtotime($next.' UTC')-microtime(true)) : 60;
        $this->signals->wait($this->listener, max(0, min(60, $delay, $this->deadline-microtime(true))));
    }
    #[AsEventListener]
    public function stopped(WorkerStoppedEvent $event): void
    {
        if ($this->listener !== null) { $this->signals->close($this->listener); $this->listener = null; }
    }
}
