<?php
declare(strict_types=1);
namespace App\Command;

use App\Instance\InstanceRegistry;
use App\Runtime\ProcessObservation;
use App\Runtime\RuntimeLifecycle;
use App\Runtime\SliceRuntime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'indy:observe', description: 'Geteilte, rein lesende native Prozessbeobachtung für registrierte Profile.')]
final class ObserveProcessesCommand extends Command implements SignalableCommandInterface
{
    private bool $stopping = false;
    public function __construct(private readonly SliceRuntime $runtime, private readonly InstanceRegistry $registry,
        private readonly ProcessObservation $observation, private readonly RuntimeLifecycle $lifecycle,
        private readonly \App\Log\GameLogIngestor $logs, private readonly \App\Log\GameEventJobs $eventJobs,
        private readonly \App\Runtime\NativeEventSource $native, private readonly \App\Runtime\RuntimeSignals $signals) { parent::__construct(); }
    protected function configure(): void { $this->addOption('once', null, InputOption::VALUE_NONE); }
    public function getSubscribedSignals(): array { return defined('SIGTERM') ? [SIGTERM, SIGINT] : []; }
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false { $this->stopping = true; return false; }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runtime->driver !== 'managed') { $output->writeln('<error>Nur für den ausdrücklich aktivierten nativen Profilmodus.</error>'); return Command::FAILURE; }
        $epoch = $this->lifecycle->epoch(); $listener = $this->signals->listen(['jobs','status','settings','game-event']);
        $due = []; $logPending = []; $degraded = true; $sourceStates = []; $nativeRetry = 0; $rebuild = !$input->getOption('once');
        try {
            do {
                try { if ($this->lifecycle->epoch() !== $epoch) { return Command::SUCCESS; } }
                catch (\DomainException) { return Command::SUCCESS; }
                $ids = array_keys($this->registry->executionLabels()); $now = microtime(true);
                if ($rebuild || (!$this->native->streams() && $now >= $nativeRetry && !$input->getOption('once'))) {
                    try { $this->native->start(); } catch (\Throwable) { $this->native->close(); $output->writeln('Native Ereignisquelle nicht verfügbar; langsamer Statusabgleich bleibt aktiv.'); }
                    $degraded = true; $sourceStates = []; $nativeRetry = $now+300; $rebuild = false;
                }
                foreach ($ids as $id) {
                    if ($this->stopping) { return Command::SUCCESS; }
                    if (($due[$id] ?? 0) <= $now) {
                        $this->observation->refresh($id, 360); $logPending[$id] = 0;
                        $status = $this->observation->view($id);
                        // Active servers need performance samples. Stopped profiles only have a slow safety reconciliation.
                        $due[$id] = microtime(true)+((($status['running'] ?? false) || ($status['starting'] ?? false)) ? 30 : ($degraded ? 60 : 300));
                    }
                    if (isset($logPending[$id]) && $logPending[$id] <= microtime(true)) {
                        while ($this->logs->ingest($id, $this->observation->view($id))) { if ($this->stopping) { return Command::SUCCESS; } }
                        unset($logPending[$id]); $this->signals->publish('logs', $id);
                    }
                }
                $due = array_intersect_key($due, array_flip($ids));
                $logPending = array_intersect_key($logPending, array_flip($ids));
                $this->eventJobs->enqueue();
                if ($input->getOption('once')) { return Command::SUCCESS; }
                $next = min([...array_values($due), ...array_values($logPending), $nativeRetry > microtime(true) ? $nativeRetry : microtime(true)+300]);
                $delay = max(0, $next-microtime(true)); $read = array_filter([$listener[0], ...array_values($this->native->streams())], 'is_resource'); $write = $except = [];
                if (!$read) { $this->signals->wait($listener, min(60, $delay)); continue; }
                @stream_select($read, $write, $except, (int)$delay, (int)(($delay-(int)$delay)*1000000));
                if ($this->stopping) { break; }
                foreach ($this->native->streams() as $stream) { if (in_array($stream, $read, true)) {
                    try {
                        foreach ($this->native->read($stream) as $event) {
                            $id = $event['server_id'] ?? null;
                            if ($event['kind'] === 'ready' || $event['kind'] === 'rescan') {
                                $sourceStates[$event['source']] = $event['kind'] === 'rescan' || ($event['degraded'] ?? true);
                                $degraded = count($sourceStates)<count($this->native->streams()) || in_array(true,$sourceStates,true); $due = [];
                            } elseif (in_array($id, $ids, true)) {
                                if ($event['kind'] === 'log') { $logPending[$id] ??= microtime(true)+.25; }
                                else { $due[$id] = 0; if ($event['kind'] === 'config') { $rebuild = true; } }
                            }
                        }
                    } catch (\Throwable) { $this->native->close(); $degraded = true; $due = []; $nativeRetry = microtime(true)+300; break; }
                } }
                foreach ($this->signals->wait($listener, 0) as $event) {
                    if ($event['topic'] === 'settings') { $rebuild = true; $due = []; }
                    if ($event['topic'] === 'status' && in_array($event['server_id'], $ids, true)) {
                        $due[$event['server_id']] = 0;
                    }
                }
            } while (!$this->stopping);
            return Command::SUCCESS;
        } finally { $this->native->close(); $this->signals->close($listener); }
    }
}
