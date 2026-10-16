<?php
declare(strict_types=1);
namespace App\Command;
use App\WhatsApp\WhatsApp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:whatsapp:tick',description:'Bereitschaftsmeldungen durch den einzelnen aktiven Supervisor prüfen.')]
final class WhatsAppTickCommand extends Command
{
    public function __construct(private readonly WhatsApp $whatsapp) { parent::__construct(); }
    protected function execute(InputInterface $input,OutputInterface $output): int { $this->whatsapp->tick(); return Command::SUCCESS; }
}
