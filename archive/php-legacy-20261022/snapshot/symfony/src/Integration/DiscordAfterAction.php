<?php
declare(strict_types=1);
namespace App\Integration;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Instance\InstanceRegistry;
use App\Job\WorkerClaim;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\ServerActionCompleted;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class DiscordAfterAction
{
    public function __construct(private readonly Discord $discord, private readonly Connections $connections,
        private readonly InstanceRegistry $registry, private readonly WorkerClaim $claim,
        private readonly RuntimeLifecycle $lifecycle, private readonly AccessPolicy $policy, private readonly LoggerInterface $logger,
        private readonly DiscordStatusCard $cards) {}
    public function __invoke(ServerActionCompleted $event): void
    {
        $job = $this->claim->forServer($event->serverId);
        if ($job === null) { return; }
        try {
            $profile = $this->registry->get($event->serverId);
            if (!$this->discord->automatic() || $this->cards->active($event->serverId) || $event->operation === 'start' && ($profile->launcher_script ?? '') !== '') { return; }
            $actor = Principal::fromClaim($job['actor']);
            $guard = function () use ($job, $actor, $event): void {
                $this->lifecycle->requireClaim($job); $this->policy->require($actor, 'server.'.$job['operation'], $event->serverId);
            };
            $guard(); $details = $this->connections->details($event->serverId);
            $message = $event->operation === 'start' ? 'Serverprozess gestartet. Verbindungsbereitschaft im Serverlog prüfen.'."\n\n".$details['share_message']
                : 'Server gestoppt: '.$details['server_name'];
            $this->discord->send(substr(hash('sha256', $job['id'].':'.$event->operation), 0, 32), $message, $guard);
        } catch (\Throwable) {
            // Game action already completed; retain delivery claim and a sanitized warning.
            $this->logger->warning('Automatische Discord-Meldung fehlgeschlagen oder unklar; Versandstatus prüfen.');
        }
    }
}
