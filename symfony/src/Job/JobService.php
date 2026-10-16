<?php
declare(strict_types=1);

namespace App\Job;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Instance\InstanceRegistry;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\MessageBusInterface;

final class JobService
{
    public function __construct(private readonly JobRepository $jobs, private readonly SliceRuntime $runtime,
        private readonly MessageBusInterface $bus, private readonly AccessPolicy $policy,
        private readonly InstanceRegistry $registry, private readonly RuntimeLifecycle $lifecycle) {}

    public function submit(string $serverId, string $operation, string $requestId, ?Principal $actor = null, ?\stdClass $payload = null): array
    {
        $this->runtime->assertInitialized();
        if ($this->runtime->driver === 'managed') { $this->registry->get($serverId); } else { $this->runtime->server($serverId); }
        if (!in_array($operation, ['start', 'stop', 'restart', 'config', 'setup'], true) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
            throw new \InvalidArgumentException('Befehl oder Anfragekennung ungültig.');
        }
        $actor ??= new Principal('local-owner', $this->runtime->role);
        $permission = self::permission($operation);
        $this->policy->require($actor, $permission, $serverId);
        if ($operation === 'config') {
            if (!$payload || !($payload->config ?? null) instanceof \stdClass || !is_string($payload->revision ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $payload->revision) || array_diff(array_keys(get_object_vars($payload)), ['config', 'revision'])) { throw new \InvalidArgumentException('Konfiguration und Dateistand erforderlich.'); }
        } elseif ($payload !== null) { throw new \InvalidArgumentException('Dieser Befehl nimmt keine Nutzdaten an.'); }
        $encoded = $payload === null ? null : PrivateFiles::json($payload);
        if ($encoded !== null && strlen($encoded) > 1048576) { throw new \InvalidArgumentException('Konfigurationsauftrag zu groß.'); }
        // Only acceptance is serialized here, never the long native operation.
        $lock = (new LockFactory(new FlockStore($this->runtime->directory.'/locks')))->createLock('acceptance');
        $lock->acquire(true);
        try {
            $this->policy->require($actor, $permission, $serverId); $epoch = $this->lifecycle->epoch();
            if ($existing = $this->jobs->byRequest($requestId)) {
                if ($existing['server_id'] !== $serverId || $existing['operation'] !== $operation
                    || Principal::fromClaim($existing['actor'])->id !== $actor->id || ($existing['payload'] ?? null) !== $encoded) {
                    throw new \DomainException('Diese Anfragekennung gehört zu einem anderen Befehl.');
                }
                return $existing;
            }
            if (($operation === 'config' || $operation === 'setup') && $this->jobs->pendingCount($serverId) > 0
                || $this->jobs->hasExclusive($serverId)) { throw new \DomainException('Für dieses Ziel arbeitet bereits ein exklusiver Auftrag. Ergebnis abwarten.'); }
            if ($this->jobs->pendingCount() >= 16 || $this->jobs->pendingCount($serverId) >= 8) {
                throw new \DomainException('Auftragswarteschlange voll. Bitte Ergebnisse abwarten.');
            }
            return $this->jobs->transaction(function () use ($serverId, $operation, $requestId, $actor, $encoded, $epoch): array {
                $id = bin2hex(random_bytes(16));
                $this->jobs->insert($id, $requestId, $serverId, $operation, $actor, $encoded, $epoch);
                // Same DBAL connection: operation ledger and delivery are committed together.
                $this->bus->dispatch(new ExecuteServerJob($id));
                return $this->jobs->get($id);
            });
        } finally {
            $lock->release();
        }
    }
    public static function permission(string $operation): string
    {
        return match ($operation) { 'config' => 'config.write', 'setup' => 'server.setup', default => 'server.'.$operation };
    }
}
