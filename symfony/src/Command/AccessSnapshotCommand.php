<?php
declare(strict_types=1);
namespace App\Command;

use App\Access\AccessSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'indy:access:snapshot', description: 'Freigaben und Schlüssel nur lesend in ein neues privates Kopierpaket übernehmen.')]
final class AccessSnapshotCommand extends Command
{
    public function __construct(private readonly AccessSnapshot $snapshot) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED, 'Eigene Bestandskopie des bisherigen data-Ordners.')
            ->addArgument('destination', InputArgument::REQUIRED, 'Neuer noch nicht existierender Paketordner.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $manifest = $this->snapshot->copy($input->getArgument('source'), $input->getArgument('destination'));
            $output->writeln('Privates Zugangspaket geprüft: '.$manifest['users'].' Freigaben. Keine Aktivierung oder Quelländerung.');
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Zugangskopie fehlgeschlagen. Quelldaten, Struktur und neues Ziel prüfen.</error>');
            return Command::FAILURE;
        }
    }
}
