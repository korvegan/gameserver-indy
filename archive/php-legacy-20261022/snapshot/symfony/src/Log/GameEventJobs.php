<?php
declare(strict_types=1);
namespace App\Log;

use App\Access\Principal;
use App\Job\JobService;
use App\Runtime\RuntimeEvents;

/** Durable event -> existing metadata queue. A restart retries acceptance, never invents a command. */
final class GameEventJobs
{
    public function __construct(private readonly RuntimeEvents $events, private readonly JobService $jobs) {}
    public function enqueue(): void
    {
        foreach ($this->events->pending() as $event) {
            try {
                $this->jobs->submit($event['server_id'], 'game_event', substr(hash('sha256', 'game-event:'.$event['sequence'].':'.$event['event_key']), 0, 32),
                    new Principal('local-owner', 'owner'), (object)['event_sequence'=>(int)$event['sequence']]);
                $this->events->acknowledge((int)$event['sequence']);
            } catch (\DomainException) { /* Queue capacity/lifecycle: keep the event until acceptance is possible. */ }
        }
    }
}
