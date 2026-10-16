<?php
declare(strict_types=1);

namespace App\Job;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Backup\BackupOperations;
use App\Backup\WorldBackups;
use App\Instance\InstanceConfiguration;
use App\Integration\IntegrationOperations;
use App\Runtime\OutcomeUnknown;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\ServerControl;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsMessageHandler]
final class ExecuteServerJobHandler
{
    public function __construct(private readonly JobRepository $jobs, private readonly SliceRuntime $runtime,
        private readonly ServerControl $control, private readonly AccessPolicy $policy,
        private readonly RuntimeLifecycle $lifecycle, private readonly WorkerClaim $claim,
        private readonly InstanceConfiguration $configuration, private readonly WorldBackups $backups,
        private readonly ?IntegrationOperations $integrations = null) {}

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
        $lane = IntegrationOperations::is($trigger['operation']) ? 'metadata' : 'control';
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock(IntegrationOperations::lock($serverId, $lane));
        if (!$lock->acquire()) {
            // Delivery may wait again only before any operation is claimed or executed.
            throw new RecoverableMessageHandlingException('Dieses Testprofil arbeitet bereits.', retryDelay: 500);
        }
        try {
            if ($this->jobs->hasRunning($serverId, $lane)) {
                // Lock is free but ledger is running: previous worker died. Never replay it.
                $this->jobs->recover($serverId, $lane);
                return;
            }
            while ($job = $this->jobs->next($serverId, $lane)) {
                $this->jobs->transition($job['id'], 'running', 'Wird ausgeführt.');
                try {
                    $this->lifecycle->requireClaim($job);
                    $actor = Principal::fromClaim($job['actor']);
                    $this->policy->require($actor, JobService::permission($job['operation']), $serverId);
                    $result = $this->claim->run($job, function () use ($job, $actor, $serverId): ?array {
                        if (IntegrationOperations::is($job['operation'])) { return ($this->integrations ?? throw new \LogicException('Integrationsdienst fehlt.'))->execute($job, $actor); }
                        if ($job['operation'] === 'config') {
                            $payload = PrivateFiles::object($job['payload']);
                            return $this->configuration->save($actor, $serverId, $payload->config, $payload->revision);
                        }
                        if ($job['operation'] === 'setup') { return $this->configuration->setup($actor, $serverId); }
                        if (in_array($job['operation'], BackupOperations::ALL, true)) {
                            return $this->backups->execute($actor, $serverId, $job['operation'], $job['payload'] === null ? null : PrivateFiles::object($job['payload']));
                        }
                        $this->control->execute($serverId, $job['operation']); return null;
                    });
                } catch (\Throwable $error) {
                    $interrupted = $error instanceof OutcomeUnknown || $error instanceof AccessDeniedException;
                    $this->jobs->failWithFollowers($job['id'], $interrupted ? 'interrupted' : 'failed',
                        $interrupted || $error instanceof \DomainException || $error instanceof \InvalidArgumentException
                            ? $error->getMessage() : 'Auftrag fehlgeschlagen. Profilkonfiguration und Protokoll prüfen.');
                    return;
                }
                // A failed write leaves running; redelivery or the HTTP/SSE reconciler only interrupts it.
                $this->jobs->transition($job['id'], 'succeeded', $result['message'] ?? match ($job['operation']) {
                    'start' => 'Startauftrag abgeschlossen.', 'stop' => 'Stoppauftrag abgeschlossen.',
                    'restart' => 'Neustartauftrag abgeschlossen.', default => 'Auftrag abgeschlossen.',
                }, $result);
            }
        } finally {
            $lock->release();
        }
    }
}
