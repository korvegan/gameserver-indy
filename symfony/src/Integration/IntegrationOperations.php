<?php
declare(strict_types=1);
namespace App\Integration;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Job\JobService;
use App\Runtime\RuntimeLifecycle;
use App\Storage\PrivateFiles;

final class IntegrationOperations
{
    public const ALL = ['check_updates', 'refresh_connections', 'discord_send'];
    public function __construct(private readonly SteamUpdates $updates, private readonly Connections $connections,
        private readonly Discord $discord, private readonly RuntimeLifecycle $lifecycle, private readonly AccessPolicy $policy) {}
    public static function is(string $operation): bool { return in_array($operation, self::ALL, true); }
    public static function predicate(string $lane): string
    {
        if (!in_array($lane, ['control', 'metadata'], true)) { throw new \InvalidArgumentException('Unbekannte Auftragsgruppe.'); }
        return 'operation '.($lane === 'metadata' ? 'IN' : 'NOT IN')." ('check_updates','refresh_connections','discord_send')";
    }
    public static function lock(string $id, string $lane): string { return 'execute:'.($lane === 'control' ? '' : 'metadata:').$id; }
    public function execute(array $job, Principal $actor): array
    {
        $guard = function () use ($job, $actor): void { $this->lifecycle->requireClaim($job); $this->policy->require($actor, JobService::permission($job['operation']), $job['server_id']); };
        $guard();
        return match ($job['operation']) {
            'check_updates' => $this->updates->check($job['server_id']),
            'refresh_connections' => $this->connections->refresh(),
            'discord_send' => $this->discord->send($job['id'], PrivateFiles::object($job['payload'])->message, $guard),
            default => throw new \InvalidArgumentException('Unbekannte Integration.'),
        };
    }
}
