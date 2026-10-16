<?php
declare(strict_types=1);
namespace App\Command;

use App\Runtime\StateInstallation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:state:install',description:'Geprüftes Offlinepaket in neue gemeinsame Datenablage übernehmen und Tabellen vorbereiten; nichts aktivieren.')]
final class StateInstallCommand extends Command
{
    public function __construct(private readonly StateInstallation $installation) { parent::__construct(); }
    protected function configure(): void
    {
        foreach (['package','destination','release','commit','php','frankenphp'] as $argument) { $this->addArgument($argument,InputArgument::REQUIRED); }
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        try {
            $result=$this->installation->install(...array_map(fn(string $key): string=>$input->getArgument($key),['package','destination','release','commit','php','frankenphp']));
            $output->writeln('Gemeinsame Datenablage vorbereitet. Aktivierung und Starter bleiben unverändert.'); return Command::SUCCESS;
        } catch (\Throwable) { $output->writeln('<error>Daten nicht installiert. Neues unverändertes Offlinepaket, gestopptes Original, geprüftes Release und neues getrenntes Ziel prüfen.</error>'); return Command::FAILURE; }
    }
}
