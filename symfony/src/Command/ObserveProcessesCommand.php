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
        private readonly ProcessObservation $observation, private readonly RuntimeLifecycle $lifecycle) { parent::__construct(); }
    protected function configure(): void { $this->addOption('once', null, InputOption::VALUE_NONE); }
    public function getSubscribedSignals(): array { return defined('SIGTERM') ? [SIGTERM, SIGINT] : []; }
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false { $this->stopping = true; return false; }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runtime->driver !== 'managed') { $output->writeln('<error>Nur für den ausdrücklich aktivierten nativen Profilmodus.</error>'); return Command::FAILURE; }
        $epoch = $this->lifecycle->epoch();
        do {
            $next = microtime(true) + 8;
            foreach (array_keys($this->registry->executionLabels()) as $id) {
                if ($this->stopping) { return Command::SUCCESS; }
                try { if ($this->lifecycle->epoch() !== $epoch) { return Command::SUCCESS; } }
                catch (\DomainException) { return Command::SUCCESS; }
                $this->observation->refresh($id);
            }
            if ($input->getOption('once')) { return Command::SUCCESS; }
            while (!$this->stopping && microtime(true) < $next) { usleep(100000); }
        } while (!$this->stopping);
        return Command::SUCCESS;
    }
}
