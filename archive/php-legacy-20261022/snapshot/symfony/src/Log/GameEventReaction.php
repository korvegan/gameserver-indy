<?php
declare(strict_types=1);
namespace App\Log;

use App\Runtime\RuntimeEvents;
use App\Runtime\ProcessObservation;
use App\Runtime\Readiness;
use App\WhatsApp\WhatsApp;

final class GameEventReaction
{
    public function __construct(private readonly RuntimeEvents $events, private readonly ProcessObservation $observation,
        private readonly WhatsApp $whatsapp) {}
    public function execute(string $id, int $sequence, callable $guard): array
    {
        $guard(); $event = $this->events->get($sequence);
        if ($event['server_id'] !== $id) { throw new \DomainException('Spielereignis gehört zu einem anderen Server.'); }
        $this->observation->refresh($id); $guard(); $status = $this->observation->view($id);
        $identity = Readiness::identity($status);
        if ($event['type'] === 'session.ready' && $identity && str_starts_with($event['event_key'], $identity[0].':') && ($status['ready'] ?? null) === true) {
            $this->whatsapp->tick($id);
        }
        return ['message'=>'Spielereignis ausgewertet: '.($event['type'] === 'session.ready' ? 'Sitzungsöffnung' : 'Sitzungsende').'.'];
    }
}
