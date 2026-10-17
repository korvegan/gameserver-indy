<?php
declare(strict_types=1);

namespace App\Job;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Backup\BackupOperations;
use App\Instance\InstanceRegistry;
use App\Integration\IntegrationOperations;
use App\Integration\DiscordWebhook;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class JobService
{
    public function __construct(private readonly JobRepository $jobs, private readonly SliceRuntime $runtime,
        private readonly MessageBusInterface $bus, private readonly AccessPolicy $policy,
        private readonly InstanceRegistry $registry, private readonly RuntimeLifecycle $lifecycle) {}

    public function submit(string $serverId, string $operation, string $requestId, ?Principal $actor = null, ?\stdClass $payload = null): array
    {
        $this->runtime->assertInitialized();
        if ($this->runtime->driver === 'managed') { $this->registry->get($serverId); } else { $this->runtime->server($serverId); }
        if (!in_array($operation, ['start', 'stop', 'restart', 'config', 'setup', 'world', ...BackupOperations::ALL, ...IntegrationOperations::ALL], true) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
            throw new \InvalidArgumentException('Befehl oder Anfragekennung ungültig.');
        }
        $actor ??= new Principal('local-owner', $this->runtime->role);
        $permission = self::permission($operation);
        $this->policy->require($actor, $permission, $serverId);
        if ($operation === 'config') {
            if (!$payload || !($payload->config ?? null) instanceof \stdClass || !is_string($payload->revision ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $payload->revision) || array_diff(array_keys(get_object_vars($payload)), ['config', 'revision'])) { throw new \InvalidArgumentException('Konfiguration und Dateistand erforderlich.'); }
        } elseif ($operation === 'world') {
            \App\Instance\Worlds::validate($payload);
        } elseif ($operation === 'community_discord') {
            \App\Community\DiscordPublication::validate($payload);
        } elseif ($operation === 'discord_card_create') {
            if ($payload === null || array_diff(array_keys(get_object_vars($payload)),['replace','generation']) || !is_bool($payload->replace ?? null)
                || !is_string($payload->generation ?? null) || !preg_match('/^(?:new|[a-f0-9]{32})$/D',$payload->generation)) { throw new \InvalidArgumentException('Kartenstand und ausdrückliche Ersatzauswahl erforderlich.'); }
        } elseif ($operation === 'discord_send') {
            if ($payload === null || array_keys(get_object_vars($payload)) !== ['message']) { throw new \InvalidArgumentException('Discord-Nachricht erforderlich.'); }
            DiscordWebhook::message($payload->message);
        } elseif (in_array($operation, BackupOperations::ALL, true)) { BackupOperations::validate($operation, $payload); }
        elseif ($payload !== null) { throw new \InvalidArgumentException('Dieser Befehl nimmt keine Nutzdaten an.'); }
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
            if (!IntegrationOperations::is($operation) && ((!in_array($operation, ['start', 'stop', 'restart'], true) && $this->jobs->pendingCount($serverId, 'control') > 0)
                || $this->jobs->hasExclusive($serverId))) { throw new \DomainException('Für dieses Ziel arbeitet bereits ein exklusiver Auftrag. Ergebnis abwarten.'); }
            if ($this->jobs->pendingCount() >= 16 || $this->jobs->pendingCount($serverId) >= 8) {
                throw new \DomainException('Auftragswarteschlange voll. Bitte Ergebnisse abwarten.');
            }
            return $this->jobs->transaction(function () use ($serverId, $operation, $requestId, $actor, $encoded, $epoch): array {
                $id = bin2hex(random_bytes(16));
                $this->jobs->insert($id, $requestId, $serverId, $operation, $actor, $encoded, $epoch);
                // Same DBAL connection: operation ledger and delivery are committed together.
                $this->bus->dispatch(new ExecuteServerJob($id), IntegrationOperations::is($operation) ? [new TransportNamesStamp(['metadata'])] : []);
                return $this->jobs->get($id);
            });
        } finally {
            $lock->release();
        }
    }
    public static function permission(string $operation): string
    {
        if (in_array($operation, BackupOperations::ALL, true)) { return 'backups.manage'; }
        return match ($operation) { 'config' => 'config.write', 'setup' => 'server.setup', 'check_updates' => 'updates.check',
            'world' => 'backups.manage', 'community_discord' => 'community.export',
            'refresh_connections' => 'connections.refresh', 'discord_send', 'discord_card_create', 'discord_card_update' => 'discord.write', default => 'server.'.$operation };
    }
}
