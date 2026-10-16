<?php
declare(strict_types=1);
namespace App\Command;

use App\Instance\InstanceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'indy:profiles:import', description: 'Explizite Kopie der Profilmetadaten ohne Überschreiben übernehmen; startet keine Prozesse.')]
final class ImportProfilesCommand extends Command
{
    public function __construct(private readonly InstanceRegistry $registry) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('source', InputArgument::REQUIRED, 'Kopierte manager.local.json'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try { $result = $this->registry->import((string) $input->getArgument('source')); }
        catch (\Throwable) { $output->writeln('<error>Profilkopie ungültig oder Ziel bereits vorhanden. Es wurde nichts überschrieben.</error>'); return Command::FAILURE; }
        $output->writeln($result['profiles'].' Profile übernommen. SHA256: '.$result['sha256']); return Command::SUCCESS;
    }
}
