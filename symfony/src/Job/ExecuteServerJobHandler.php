<?php
declare(strict_types=1);

namespace App\Job;

use App\Runtime\OutcomeUnknown;
use App\Runtime\ServerControl;
use App\Runtime\SliceRuntime;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
final class ExecuteServerJobHandler
{
    public function __construct(private readonly JobRepository $jobs, private readonly SliceRuntime $runtime,
        private readonly ServerControl $control) {}

    public function __invoke(ExecuteServerJob $message): void
    {
        try {
            $this->handle($message);
        } catch (\Doctrine\DBAL\Exception $error) {
            // Retain delivery even with a persistent write failure. A durable running claim
            // can only be reconciled on retry, never execute the side effect again.
            throw new RecoverableMessageHandlingException('Auftragsspeicher nicht verfügbar; Zustellung wartet auf Abgleich.',
                previous: $error, retryDelay: 1000);
        }
    }

    private function handle(ExecuteServerJob $message): void
    {
        $trigger = $this->jobs->get($message->jobId);
        $serverId = $trigger['server_id'];
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('execute:'.$serverId);
        if (!$lock->acquire()) {
            // Delivery may wait again only before any operation is claimed or executed.
            throw new RecoverableMessageHandlingException('Dieses Testprofil arbeitet bereits.', retryDelay: 500);
        }
        try {
            if ($this->jobs->hasRunning($serverId)) {
                // Lock is free but ledger is running: previous worker died. Never replay it.
                $this->jobs->recover($serverId);
                return;
            }
            while ($job = $this->jobs->next($serverId)) {
                $this->jobs->transition($job['id'], 'running', 'Wird ausgeführt.');
                try {
                    $this->control->execute($serverId, $job['operation']);
                } catch (\Throwable $error) {
                    $this->jobs->failWithFollowers($job['id'], $error instanceof OutcomeUnknown ? 'interrupted' : 'failed',
                        $error instanceof OutcomeUnknown ? $error->getMessage() : 'Auftrag fehlgeschlagen. Testkonfiguration prüfen.');
                    return;
                }
                // A failed write leaves running; redelivery or the HTTP/SSE reconciler only interrupts it.
                $this->jobs->transition($job['id'], 'succeeded', match ($job['operation']) {
                    'start' => 'Startauftrag abgeschlossen.', 'stop' => 'Stoppauftrag abgeschlossen.',
                    'restart' => 'Neustartauftrag abgeschlossen.',
                });
            }
        } finally {
            $lock->release();
        }
    }
}
