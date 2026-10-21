<?php
declare(strict_types=1);
namespace App\Command;
use App\Integration\MetadataScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:metadata:tick', description: 'Fällige lesende Metadatenaufträge für den einzelnen Supervisor annehmen.')]
final class MetadataTickCommand extends Command
{
    public function __construct(private readonly MetadataScheduler $scheduler) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->scheduler->tick().' Metadatenaufträge angenommen.'); return Command::SUCCESS;
    }
}
