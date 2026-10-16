<?php
declare(strict_types=1);

namespace App\Job;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Runtime\SliceRuntime;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\MessageBusInterface;

final class JobService
{
    public function __construct(private readonly JobRepository $jobs, private readonly SliceRuntime $runtime,
        private readonly MessageBusInterface $bus, private readonly AccessPolicy $policy) {}

    public function submit(string $serverId, string $operation, string $requestId, ?Principal $actor = null): array
    {
        $this->runtime->assertInitialized();
        $this->runtime->server($serverId);
        if (!in_array($operation, ['start', 'stop', 'restart'], true) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
            throw new \InvalidArgumentException('Befehl oder Anfragekennung ungültig.');
        }
        $actor ??= new Principal('local-owner', $this->runtime->role);
        $this->policy->require($actor, 'server.'.$operation, $serverId);
        // Only acceptance is serialized here, never the long native operation.
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('acceptance');
        $lock->acquire(true);
        try {
            if ($existing = $this->jobs->byRequest($requestId)) {
                if ($existing['server_id'] !== $serverId || $existing['operation'] !== $operation
                    || Principal::fromClaim($existing['actor'])->id !== $actor->id) {
                    throw new \DomainException('Diese Anfragekennung gehört zu einem anderen Befehl.');
                }
                return $existing;
            }
            if ($this->jobs->pendingCount() >= 16 || $this->jobs->pendingCount($serverId) >= 8) {
                throw new \DomainException('Auftragswarteschlange voll. Bitte Ergebnisse abwarten.');
            }
            return $this->jobs->transaction(function () use ($serverId, $operation, $requestId, $actor): array {
                $id = bin2hex(random_bytes(16));
                $this->jobs->insert($id, $requestId, $serverId, $operation, $actor);
                // Same DBAL connection: operation ledger and delivery are committed together.
                $this->bus->dispatch(new ExecuteServerJob($id));
                return $this->jobs->get($id);
            });
        } finally {
            $lock->release();
        }
    }
}
