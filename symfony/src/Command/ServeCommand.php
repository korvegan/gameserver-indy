<?php
declare(strict_types=1);
namespace App\Command;

use App\Runtime\RuntimeSupervisor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:serve',description:'Einzelne Verwaltung mit Loopback, Workern, Beobachtung und Planer aus privater Laufzeitkonfiguration starten.')]
final class ServeCommand extends Command implements SignalableCommandInterface
{
    public function __construct(private readonly RuntimeSupervisor $supervisor) { parent::__construct(); }
    public function getSubscribedSignals(): array { return defined('SIGTERM') ? [SIGTERM,SIGINT] : []; }
    public function handleSignal(int $signal,int|false $previousExitCode=0): int|false { $this->supervisor->stop(); return false; }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        try { $this->supervisor->run(); return Command::SUCCESS; }
        catch (\Throwable) { $output->writeln('<error>Verwaltung beendet. Private Laufzeitkonfiguration und Protokolle prüfen.</error>'); return Command::FAILURE; }
    }
}
