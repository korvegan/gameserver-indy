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
    public const ALL = ['check_updates', 'refresh_connections', 'discord_send', 'discord_card_create', 'discord_card_update', 'community_discord', 'game_event'];
    public function __construct(private readonly SteamUpdates $updates, private readonly Connections $connections,
        private readonly Discord $discord, private readonly RuntimeLifecycle $lifecycle, private readonly AccessPolicy $policy,
        private readonly DiscordStatusCard $cards,private readonly \App\Community\DiscordPublication $community,
        private readonly \App\Log\GameEventReaction $gameEvents) {}
    public static function is(string $operation): bool { return in_array($operation, self::ALL, true); }
    public static function predicate(string $lane): string
    {
        if (!in_array($lane, ['control', 'metadata'], true)) { throw new \InvalidArgumentException('Unbekannte Auftragsgruppe.'); }
        return 'operation '.($lane === 'metadata' ? 'IN' : 'NOT IN')." ('".implode("','",self::ALL)."')";
    }
    public static function lock(string $id, string $lane): string { return 'execute:'.($lane === 'control' ? '' : 'metadata:').$id; }
    public function execute(array $job, Principal $actor): array
    {
        $guard = function () use ($job, $actor): void { $this->lifecycle->requireClaim($job); $this->policy->require($actor, JobService::permission($job['operation']), $job['server_id']); };
        $guard();
        return match ($job['operation']) {
            'game_event' => $this->gameEvents->execute($job['server_id'], PrivateFiles::object($job['payload'])->event_sequence, $guard),
            'check_updates' => $this->updates->check($job['server_id']),
            'refresh_connections' => $this->connections->refresh(),
            'discord_send' => $this->discord->send($job['id'], PrivateFiles::object($job['payload'])->message, $guard),
            'discord_card_create' => $this->cards->execute($job['server_id'],true,PrivateFiles::object($job['payload'])->replace,$guard,PrivateFiles::object($job['payload'])->generation),
            'discord_card_update' => $this->cards->execute($job['server_id'],false,false,$guard),
            'community_discord' => $this->community->execute($actor,PrivateFiles::object($job['payload']),$guard),
            default => throw new \InvalidArgumentException('Unbekannte Integration.'),
        };
    }
}
