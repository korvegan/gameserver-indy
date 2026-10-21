<?php
declare(strict_types=1);
namespace App\Command;

use App\Community\ChronicleSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'indy:chronicle:snapshot', description: 'Lokale Chronikkopie mit erhaltenem Ausgangsstand in ein neues geprüftes Paket migrieren, ohne Aktivierung.')]
final class ChronicleSnapshotCommand extends Command
{
    public function __construct(private readonly ChronicleSnapshot $snapshot) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('source', InputArgument::REQUIRED, 'Eigene Kopie der bisherigen platform.sqlite3.')->addArgument('destination', InputArgument::REQUIRED, 'Neuer privater Paketordner.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $manifest = $this->snapshot->copy($input->getArgument('source'), $input->getArgument('destination'));
            $output->writeln('Chronikkopie geprüft: '.$manifest['tables']['community_events']['rows'].' Ereignisrevisionen. Ausgangsstand bleibt im Paket; keine Aktivierung.'); return Command::SUCCESS;
        } catch (\Throwable) { $output->writeln('<error>Chronikkopie nicht übernommen. Version, Referenzen, Medien und neues Ziel prüfen.</error>'); return Command::FAILURE; }
    }
}
