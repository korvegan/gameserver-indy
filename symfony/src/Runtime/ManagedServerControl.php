<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Game\GameCatalog;
use App\Instance\InstanceRegistry;
use App\Instance\InstanceWorldGuard;
use App\Storage\PrivateFiles;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ManagedServerControl implements ServerControl
{
    public function __construct(private readonly ProcessGateway $gateway, private readonly InstanceRegistry $registry,
        private readonly GameCatalog $games, private readonly InstanceWorldGuard $worldGuard,
        private readonly ProcessObservation $observation, private readonly RuntimeLifecycle $lifecycle,
        private readonly EventDispatcherInterface $events) {}
    public function status(string $serverId): array { return $this->gateway->call($serverId, 'Status'); }
    public function execute(string $serverId, string $operation): void
    {
        if (!in_array($operation, ['start', 'stop', 'restart'], true)) { throw new \InvalidArgumentException('Unbekannter Serverbefehl.'); }
        $epoch = $this->lifecycle->epoch(); $profile = $this->registry->get($serverId);
        if ($operation !== 'stop') { $this->worldGuard->pending($serverId); }
        $state = $this->status($serverId); $attempted = false;
        try {
            if (($state['known'] ?? false) !== true) { throw new \DomainException('Der native Prozesszustand ist unbekannt.'); }
            if ($state['launcher_running'] && !$state['running']) {
                if ($operation === 'start') { $this->observation->record($serverId, $state); return; }
                throw new \DomainException('Der externe Serverstarter arbeitet noch. Starterprotokoll prüfen.');
            }
            if ($operation === 'start' && $state['running']) { $this->observation->record($serverId, $state); return; }
            if ($operation !== 'start' && $state['running']) {
                if ($this->lifecycle->epoch() !== $epoch) { throw new OutcomeUnknown('Verwaltungslauf wurde geändert. Befehl unterbrochen.'); }
                $attempted = true; $state = $this->gateway->call($serverId, 'Stop');
                if (($state['running'] ?? null) !== false || ($state['launcher_running'] ?? null) !== false) { throw new OutcomeUnknown('Der reguläre Stopp ist noch nicht eindeutig abgeschlossen.'); }
                $this->observation->record($serverId, $state);
                $this->events->dispatch(new ServerStopped($serverId));
            }
            if ($operation !== 'stop') {
                $adapter = $this->games->adapter($profile->game);
                $configuration = $adapter->decode(PrivateFiles::read($this->registry->configPath($profile)));
                $adapter->validate($configuration); $adapter->validateStart($configuration);
                $this->worldGuard->pending($serverId); $this->worldGuard->exclusive($profile, $configuration, $state);
                if ($this->lifecycle->epoch() !== $epoch) { throw new OutcomeUnknown('Verwaltungslauf wurde geändert. Kein anschließender Start ausgeführt.'); }
                $attempted = true; $state = $this->gateway->call($serverId, 'Start');
            }
            $this->observation->record($serverId, $state);
        } catch (\Throwable $error) {
            try { $this->observation->record($serverId, ['known' => false, 'running' => null, 'ready' => null, 'pid' => null]); } catch (\Throwable) {}
            if ($attempted && !$error instanceof OutcomeUnknown) { throw new OutcomeUnknown('Serveraktion wurde unterbrochen. Prozesszustand und Protokoll prüfen; kein automatischer Wiederholungsversuch.', previous: $error); }
            throw $error;
        }
    }
}
