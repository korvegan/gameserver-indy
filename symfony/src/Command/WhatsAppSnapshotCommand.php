<?php
declare(strict_types=1);
namespace App\Command;
use App\WhatsApp\WhatsAppSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:whatsapp:snapshot',description:'Geprüfte neue Kopie privater WhatsApp-Einstellungen und Claims ohne Sitzungszugänge erstellen.')]
final class WhatsAppSnapshotCommand extends Command
{
    public function __construct(private readonly WhatsAppSnapshot $snapshots) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('source',InputArgument::REQUIRED)->addArgument('target',InputArgument::REQUIRED); }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        try { $result = $this->snapshots->copy($input->getArgument('source'),$input->getArgument('target')); }
        catch (\Throwable) { $output->writeln('WhatsApp-Kopie nicht übernommen. Quelle, Struktur und neues Ziel prüfen.'); return Command::FAILURE; }
        $output->writeln('WhatsApp-Kopie geprüft: '.$result['claims'].' Claims; keine Kontoverknüpfung geändert.'); return Command::SUCCESS;
    }
}
